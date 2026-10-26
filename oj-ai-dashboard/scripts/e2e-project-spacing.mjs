import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = new URL('../', import.meta.url);
const platform = new URL('../oj-integration-rebuild/oj-platform/', root);
const measureOnly = process.argv.includes('--measure-only');
const styles = await Promise.all([
  readFile(new URL('resources/ui/css/oj-ui-system.css', platform), 'utf8'),
  ...['admin-shell', 'worksheet-authoring-workbench', 'report-dashboard', 'external-ai-consent-settings', 'assessment-projects', 'assignment-workbench', 'worksheet-workflow']
    .map(name => readFile(new URL(`resources/css/${name}.css`, root), 'utf8')),
]);
const projectScript = await readFile(new URL('resources/js/assessment-projects.js', root), 'utf8');
const project = { project_id: '01K00000000000000000000100', title: '디지털 사회 탐구 평가', revision_id: '01K00000000000000000000101', revision_no: 1,
  current_revision_id: '01K00000000000000000000101', state: 'draft', archived: false, lock_version: 0,
  configuration: { learning_goals: [], achievement_standards: [], criteria: [], total_points: '0' }, worksheets: [], history: [] };
const projects = Array.from({ length: 6 }, (_, index) => ({ ...project, project_id: project.project_id.slice(0, -1) + index,
  title: `${index + 1}학년 디지털 사회 탐구 평가`, worksheet_count: 1 }));
async function listSpacing(page) {
  return page.evaluate(() => {
    const meta = document.querySelector('[data-project-content] > header .meta').getBoundingClientRect();
    const actions = document.querySelector('[data-project-content] > header .actions').getBoundingClientRect();
    const search = document.querySelector('.list-toolbar .search').getBoundingClientRect();
    const tabs = document.querySelector('.list-switch').getBoundingClientRect();
    const table = document.querySelector('[data-list-table]').getBoundingClientRect();
    return { headingToFilters: Math.min(search.top, tabs.top) - Math.max(meta.bottom, actions.bottom),
      filtersToList: table.top - Math.max(search.bottom, tabs.bottom),
      searchMargin: getComputedStyle(document.querySelector('.list-toolbar .search')).marginBlock,
      horizontalOverflow: document.documentElement.scrollWidth > innerWidth + 1 };
  });
}
function assertListSpacing(metrics) {
  assert.ok(metrics.headingToFilters >= 12 && metrics.headingToFilters <= 20, `Heading/filter gap: ${metrics.headingToFilters}`);
  assert.ok(metrics.filtersToList >= 8 && metrics.filtersToList <= 16, `Filter/list gap: ${metrics.filtersToList}`);
  assert.equal(metrics.searchMargin, '0px');
  assert.equal(metrics.horizontalOverflow, false);
}
function markup(name, native) {
  return execFileSync(process.env.OJAI_PHP || 'php', ['tests/fixtures/render-workflow-page.php', name === 'detail' ? 'projects' : name,
    JSON.stringify(name === 'detail' ? { project_id: project.project_id, stage: 'criteria' } : {}), JSON.stringify(project)], {
    cwd: fileURLToPath(root), encoding: 'utf8', env: { ...process.env, OJAI_PLATFORM_LOCAL_NAV: native ? '1' : '0' }, maxBuffer: 1024 * 1024,
  });
}
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const artifacts = new URL('../tests/artifacts/project-spacing/', import.meta.url);
await mkdir(artifacts, { recursive: true });
try {
  for (const [size, viewport] of [['desktop', { width: 1440, height: 1000 }], ['tablet', { width: 1024, height: 768 }], ['mobile', { width: 390, height: 844 }]]) {
    for (const native of [true, false]) {
      const measurements = {};
      for (const name of ['worksheets', 'report', 'projects', 'detail', 'assignment']) {
        const page = await browser.newPage({ viewport });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const nativeNav = native ? '<nav class="ojp-local-nav" aria-label="이 기능의 화면"><a class="is-current" href="/projects">평가 프로젝트</a><a href="/worksheets">활동지 보관함</a><a href="/report">보고서</a></nav>' : '';
        const body = markup(name, native);
        const html = `<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${styles.join('\n')}</style></head><body class="ojp-app"><div class="ojp-shell"><main class="ojp-console-main">${nativeNav}${body}</main></div></body></html>`;
        await page.route('https://example.test/**', async route => {
          const url = new URL(route.request().url());
          if (url.pathname.startsWith('/wp-json/')) return route.fulfill({ json: { data: url.pathname.endsWith('/assessment-projects')
            ? { projects: projects.map(item => ({ ...item, archived: url.searchParams.get('archived') === '1' })) } : { project } } });
          if (url.pathname.endsWith('.svg')) return route.fulfill({ body: await readFile(new URL('resources/icons/lucide.svg', root)), contentType: 'image/svg+xml' });
          return route.fulfill({ body: html, contentType: 'text/html' });
        });
        await page.goto(`https://example.test/${name}${name === 'detail' ? '?project_id=' + project.project_id : ''}`);
        if (name === 'projects' || name === 'detail') {
          await page.addScriptTag({ content: projectScript });
          await page.locator(name === 'projects' ? '[data-action="new"]' : '[data-action="add-goal"]').waitFor();
        }
        measurements[name] = await page.evaluate(name => {
          const nav = document.querySelector('.ojp-local-nav, .oj-ai-workspace-nav');
          const header = document.querySelector(name === 'detail' ? '[data-project-header] > a' : '.ojp-page-header');
          return { gap: nav ? header.getBoundingClientRect().top - nav.getBoundingClientRect().bottom : null,
            paddingTop: parseFloat(getComputedStyle(document.querySelector('.oj-ai-projects, .oj-ai-assignment') || header).paddingTop),
            horizontalOverflow: document.documentElement.scrollWidth > innerWidth + 1,
            headerMargin: parseFloat(getComputedStyle(header).marginTop) };
        }, name);
        if (name === 'projects') measurements[name].internal = await listSpacing(page);
        if (name === 'assignment') measurements[name].internal = await page.evaluate(() => {
          const scope = document.querySelector('.oj-ai-workflow-scope').getBoundingClientRect();
          const filter = document.querySelector('.oj-ai-assignment__filters label').getBoundingClientRect();
          return { scopeToFilters: filter.top - scope.bottom,
            emptyNoticeHidden: getComputedStyle(document.querySelector('[data-role="publication-notice"]')).display === 'none',
            statusMargin: getComputedStyle(document.querySelector('.oj-ai-assignment__notice')).marginBlock };
        });
        if (!measureOnly) {
          assert.equal(measurements[name].horizontalOverflow, false, `${name}/${size}: horizontal overflow`);
          if (name === 'projects') {
            assert.equal(await page.locator('[data-list-table] tbody tr').count(), 6);
            assertListSpacing(measurements[name].internal);
            assert.equal(measurements[name].paddingTop, 0);
            assert.equal(measurements[name].headerMargin, 0);
            assert.ok(Math.abs(measurements[name].gap - measurements.worksheets.gap) <= 1, 'Project header must start at the same offset as the worksheet library.');
            assert.ok(Math.abs(measurements[name].gap - measurements.report.gap) <= 8, 'Project header must stay within one spacing step of reports.');
            await page.locator('[data-action="new"]').click();
            assert.equal(await page.locator('[data-project-dialog]').evaluate(e => e.open), true);
            const dialogHeaderMargin = await page.locator('[data-project-dialog] header').evaluate(e => getComputedStyle(e).marginTop);
            assert.equal(dialogHeaderMargin, '14px', 'Do not change dialog interior spacing.');
            await page.locator('[data-project-dialog] [data-action="close-dialog"]').first().click();
            await page.locator('[name="project-list-view"][value="archived"]').check();
            await page.getByText('보관된 평가 6개', { exact: true }).waitFor();
            assertListSpacing(await listSpacing(page));
            await page.getByRole('searchbox').fill('없는 평가');
            await page.getByText('검색 결과가 없습니다.', { exact: true }).waitFor();
            assertListSpacing(await listSpacing(page));
            await page.getByRole('searchbox').fill('');
            await page.locator('[name="project-list-view"][value="active"]').check();
            await page.getByText('활성 평가 6개', { exact: true }).waitFor();
          }
          if (name === 'detail') assert.equal(measurements[name].paddingTop, 0);
          if (name === 'assignment') {
            assert.equal(measurements[name].paddingTop, 0);
            assert.ok(Math.abs(measurements[name].gap - measurements.worksheets.gap) <= 1, 'Assignment header must start at the same offset as the worksheet library.');
            assert.equal(measurements[name].internal.scopeToFilters, 16);
            assert.equal(measurements[name].internal.emptyNoticeHidden, true);
            assert.equal(measurements[name].internal.statusMargin, '0px');
            const notice = page.locator('[data-role="publication-notice"]');
            await notice.evaluate(e => { e.textContent = '발행된 활동지를 확인해 주세요.'; });
            assert.equal(await notice.isVisible(), true, 'Nonempty publication notices must remain visible.');
            await notice.evaluate(e => { e.textContent = ''; });
          }
        }
        if (['projects', 'detail', 'assignment'].includes(name)) await page.screenshot({ path: fileURLToPath(new URL(`${measureOnly ? 'before' : 'after'}-${name}-${native ? 'native' : 'legacy'}-${size}.png`, artifacts)), fullPage: true });
        assert.deepEqual(errors, []);
        await page.close();
      }
      console.log(JSON.stringify({ size, navigation: native ? 'native' : 'legacy', measurements }));
    }
  }
  console.log(measureOnly ? 'Baseline measurements captured.' : 'PASS project/assignment spacing: native/legacy navigation, desktop/tablet/mobile, populated/archive/empty lists, internal gaps, notices, library/report comparison, details, dialogs and no horizontal overflow.');
} finally { await browser.close(); }

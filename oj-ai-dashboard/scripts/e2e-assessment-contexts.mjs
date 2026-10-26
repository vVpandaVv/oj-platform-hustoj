import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = new URL('../', import.meta.url);
const markup = execFileSync(process.env.OJAI_PHP || 'php', ['tests/Contract/submission-bulk-review-report.php'], {
  cwd: fileURLToPath(root), env: { ...process.env, OJAI_BULK_FIXTURE: '1' }, encoding: 'utf8', maxBuffer: 1024 * 1024,
});
const html = `<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/resources/css/admin-shell.css"><link rel="stylesheet" href="/resources/css/submission-evaluation-review-workbench.css"><link rel="stylesheet" href="/resources/css/worksheet-workflow.css">
<style>body{margin:0;font:14px Arial,"Malgun Gothic",sans-serif;background:#fff}main{max-width:1280px;margin:auto;padding:20px}.oj-ai-icon{width:18px;height:18px;display:inline-block;vertical-align:middle;fill:none;stroke:currentColor;stroke-width:2;pointer-events:none}@media(max-width:600px){main{padding:8px}}</style></head>
<body><main id="fixture" class="oj-ai-assessment-surface">${markup}</main><script>const app=document.querySelector('#oj-ai-submission-review-app');document.querySelector('#fixture').replaceChildren(app);app.dataset.iconSprite='/resources/icons/lucide.svg';</script>
<script src="/resources/js/worksheet-workflow.js"></script><script src="/resources/js/learner-labels.js"></script><script src="/resources/js/submission-evaluation-review-workbench.js"></script></body></html>`;
const worksheetId = 'KZ881VH9H2JGZYAY0VWQA1H2RG';
function installFixture({ worksheetId, mode }) {
  window.__contextMode = mode;
  window.__contextReads = [];
  window.__contextPosts = [];
  const contexts = [10, 11, 12, 13, 14, 15, 3].map(id => ({
    type: id === 3 ? 'class' : 'group', id: `${id === 3 ? 'class' : 'group'}:${id}`,
    label: id === 3 ? '이전 수업 배포' : `${id}반`, revision: `revision-${id}`, selectable: id !== 14,
  }));
  const response = (items, next_cursor = '') => new Response(JSON.stringify({ data: { items }, meta: { next_cursor, server_time_utc: '2026-10-02 01:00:00' } }), { status: 200 });
  window.fetch = async (url, options = {}) => {
    const value = new URL(url);
    const path = value.pathname.replace('/wp-json/oj-ai/v1', '');
    if (options.method === 'POST') { window.__contextPosts.push(path); throw Error('No write is allowed in context tests.'); }
    window.__contextReads.push(path + value.search);
    const query = value.searchParams;
    const kind = window.__contextMode;
    if (path === '/contexts') return query.has('cursor') ? response(contexts.slice(3)) : response(contexts.slice(0, 3), 'more-contexts');
    if (path === '/assignments') {
      const id = Number(query.get('context_id').split(':')[1]);
      if (id === 13 || (kind === 'error' && id === 15)) return new Response(JSON.stringify({ message: id === 13 ? 'denied' : '배포 조회 실패', data: { status: id === 13 ? 403 : 503 } }), { status: id === 13 ? 403 : 503 });
      if (kind === 'empty' || id === 12 || (kind === 'removed' && id === 10) || (kind === 'single' && id !== 10)) return response([]);
      const assignment = { id: `assignment-${id}`, worksheet_id: id === 11 ? 'another-worksheet' : worksheetId,
        title: `${id}반 디지털 사회 평가`, state: [3, 15].includes(id) ? 'closed' : 'open' };
      if (id === 15 && !query.has('cursor')) return response([{ ...assignment, worksheet_id: 'another-worksheet' }], 'more-assignments');
      return response([assignment]);
    }
    if (path === '/submission-evaluation-roster') return response([{ id: 'target-1', assigned: true, current_member: true,
      learner: { user_id: 1, display_name: '검증 학생', login: '10101', management_name: '1반 1번' }, status: 'not_started', submission: null }]);
    if (['/submission-evaluations', '/submission-evaluation-candidates', '/student-notes'].includes(path)) return response([]);
    throw Error('Unexpected fixture route: ' + path);
  };
}
const { chromium } = await loadPlaywright();
const browser = await chromium.launch(browserLaunchOptions());
const artifacts = new URL('../tests/artifacts/', import.meta.url);
await mkdir(artifacts, { recursive: true });
async function pageFor({ stage = 'assessment', mode = 'normal', scoped = true, context = 'group:10', revision = 'revision-10', viewport = { width: 1440, height: 1000 } } = {}) {
  const page = await browser.newPage({ viewport });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('https://example.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.startsWith('/resources/')) return route.fulfill({ contentType: path.endsWith('.js') ? 'text/javascript' : path.endsWith('.css') ? 'text/css' : 'image/svg+xml', body: await readFile(new URL('.' + path, root)) });
    return route.fulfill({ contentType: 'text/html', body: html });
  });
  await page.addInitScript(installFixture, { worksheetId, mode });
  const query = new URLSearchParams({ stage });
  if (scoped) query.set('worksheet_id', worksheetId);
  if (context) { query.set('context_type', context.split(':')[0]); query.set('context_id', context); query.set('context_revision', revision); }
  await page.goto(`https://example.test/contexts?${query}`);
  await ready(page);
  return { page, errors };
}
const contextControl = page => page.locator('[data-review-field="context"]');
const labels = page => contextControl(page).locator('option').allTextContents();
async function ready(page) {
  await page.waitForFunction(() => {
    const notice = document.querySelector('[data-review-role="notice"]');
    const context = document.querySelector('[data-review-field="context"]');
    return context && !context.disabled && !notice.textContent.includes('불러오는 중');
  });
}
async function refresh(page, mode) {
  if (mode) await page.evaluate(value => { window.__contextMode = value; }, mode);
  await page.locator('[data-review-action="refresh"]').click();
  await ready(page);
}
async function finish(page, errors) {
  assert.deepEqual(errors, []);
  assert.deepEqual(await page.evaluate(() => window.__contextPosts), []);
  await page.close();
}
try {
  for (const [name, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile', { width: 390, height: 844 }]]) {
    const { page, errors } = await pageFor({ viewport });
    assert.deepEqual(await labels(page), ['그룹(반) 선택', '10반', '15반']);
    assert.equal(await contextControl(page).locator('option:checked').textContent(), '10반');
    assert.match(await page.locator('[data-role="submission-summary"]').textContent(), /10반/);
    assert.equal((await page.evaluate(() => window.__contextReads)).filter(path => path.startsWith('/assignments?') && path.includes('group%3A10')).length, 1, 'Reuse initial assignments.');
    await contextControl(page).selectOption({ label: '15반' });
    await page.waitForFunction(() => document.querySelector('[data-role="submission-summary"]').textContent.includes('15반'));
    await refresh(page);
    assert.equal(await contextControl(page).locator('option:checked').textContent(), '15반', 'Refresh retains selected group.');
    await refresh(page, 'error');
    assert.equal(await contextControl(page).inputValue(), '');
    await refresh(page, 'normal');
    assert.equal(await contextControl(page).locator('option:checked').textContent(), '15반', 'Recovery retains the latest selection, not the original URL group.');
    await page.screenshot({ path: fileURLToPath(new URL(`assessment-contexts-${name}.png`, artifacts)) });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    await page.locator('[data-context-type]').selectOption('class');
    assert.deepEqual(await labels(page), ['수업 선택', '이전 수업 배포']);
    await contextControl(page).selectOption({ label: '이전 수업 배포' });
    await page.waitForFunction(() => document.querySelector('[data-role="submission-summary"]').textContent.includes('이전 수업 배포'));
    assert.equal(await page.locator('[data-review-role="content"]').isVisible(), true);
    await finish(page, errors);
  }
  for (const [context, revision] of [['group:11', 'revision-11'], ['group:10', 'old-revision']]) {
    const { page, errors } = await pageFor({ context, revision });
    assert.equal(await contextControl(page).inputValue(), '');
    assert.match(await page.locator('[data-review-role="notice"]').textContent(), /다시 선택/);
    assert.ok(!(await page.evaluate(() => window.__contextReads)).some(path => path.startsWith('/submission-')), 'No unrelated assessment is loaded for an invalid deep link.');
    await finish(page, errors);
  }
  {
    const { page, errors } = await pageFor({ mode: 'single', context: '' });
    assert.deepEqual(await labels(page), ['그룹(반) 선택', '10반']);
    assert.equal(await contextControl(page).locator('option:checked').textContent(), '10반');
    await finish(page, errors);
  }
  {
    const { page, errors } = await pageFor({ scoped: false });
    assert.deepEqual(await labels(page), ['그룹(반) 선택', '10반', '11반', '15반']);
    await finish(page, errors);
  }
  for (const mode of ['empty', 'error']) {
    const { page, errors } = await pageFor({ mode });
    assert.deepEqual(await labels(page), ['선택할 그룹(반) 없음']);
    assert.match(await page.locator('[data-review-role="notice"]').textContent(), mode === 'empty' ? /배포된 대상이 없습니다/ : /배포 조회 실패/);
    assert.equal(await page.locator('[data-review-field="assignment"]').isDisabled(), true);
    await refresh(page, 'normal');
    assert.deepEqual(await labels(page), ['그룹(반) 선택', '10반', '15반']);
    assert.equal(await contextControl(page).locator('option:checked').textContent(), '10반');
    await refresh(page, 'removed');
    assert.deepEqual(await labels(page), ['그룹(반) 선택', '15반']);
    assert.equal(await contextControl(page).inputValue(), '', 'A removed selected group must not silently switch to another group.');
    assert.equal(await page.locator('[data-review-role="content"]').isHidden(), true);
    await finish(page, errors);
  }
  for (const stage of ['report', 'release']) {
    const { page, errors } = await pageFor({ stage });
    assert.deepEqual(await labels(page), ['그룹(반) 선택', '10반', '11반', '12반', '13반', '15반']);
    assert.equal((await page.evaluate(() => window.__contextReads)).filter(path => path.startsWith('/assignments?')).length, 1, 'Other work stages keep their existing query scope.');
    await finish(page, errors);
  }
  console.log('Assessment context browser checks passed: deployed-only groups, pagination, desktop/mobile, context refresh, empty/error recovery, stale links, report/release unchanged.');
} finally {
  await browser.close();
}

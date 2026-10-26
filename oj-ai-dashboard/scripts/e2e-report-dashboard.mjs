import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/report-dashboard-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];

await mkdir(artifactDir, { recursive: true });

function intersects(a, b) {
  return a.left < b.right - 1 && a.right > b.left + 1 && a.top < b.bottom - 1 && a.bottom > b.top + 1;
}

const sameKeys = (value, keys) => JSON.stringify(Object.keys(value || {}).sort()) === JSON.stringify([...keys].sort());

async function waitForTitle(page, title) {
  await page.waitForFunction((expected) => document.querySelector('[data-role="view-title"]')?.textContent === expected, title);
  await page.waitForFunction(() => document.querySelector('[data-role="content"]')?.getAttribute('aria-busy') === 'false');
}

async function inspectLayout(page, label, stage) {
  const layout = await page.evaluate(() => {
    const rect = (element) => {
      const value = element.getBoundingClientRect();
      return { left: value.left, right: value.right, top: value.top, bottom: value.bottom, width: value.width, height: value.height };
    };
    const visible = (element) => element.getClientRects().length > 0;
    const rootElement = document.querySelector('#oj-ai-report-app');
    const filterItems = [...document.querySelectorAll('.oj-ai-report__filters > label, .oj-ai-report__state-filter, .oj-ai-report__filter-actions')]
      .filter(visible).map(rect);
    const titleButtons = [...document.querySelectorAll('.oj-ai-report__table-link')].filter(visible).map(rect);
    const clippedControls = [...document.querySelectorAll('#oj-ai-report-app button, #oj-ai-report-app input, #oj-ai-report-app select')]
      .filter(visible)
      .some((element) => {
        const value = element.getBoundingClientRect();
        return value.left < -1 || value.right > window.innerWidth + 1;
      });
    return {
      viewport: window.innerWidth,
      bodyOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      rootOverflow: rootElement ? rootElement.scrollWidth > rootElement.clientWidth + 1 : true,
      clippedControls,
      duplicateLocalNavigation: document.querySelectorAll('[data-oj-ai-local-navigation]').length,
      titleButtonUndersized: titleButtons.some((value) => value.height < 37),
      filterOverlap: filterItems.some((item, index) => filterItems.slice(index + 1).some((candidate) => (
        item.left < candidate.right - 1 && item.right > candidate.left + 1 && item.top < candidate.bottom - 1 && item.bottom > candidate.top + 1
      ))),
      headerTitle: document.querySelector('.oj-ai-report__header > div') ? rect(document.querySelector('.oj-ai-report__header > div')) : null,
      baseline: document.querySelector('.oj-ai-report__baseline:not([hidden])') ? rect(document.querySelector('.oj-ai-report__baseline')) : null,
      viewTitle: document.querySelector('.oj-ai-report__view-header > div') ? rect(document.querySelector('.oj-ai-report__view-header > div')) : null,
      viewCount: document.querySelector('[data-role="view-count"]') ? rect(document.querySelector('[data-role="view-count"]')) : null,
      reportTextLength: rootElement?.innerText.trim().length || 0,
    };
  });

  if (layout.bodyOverflow || layout.rootOverflow || layout.clippedControls) errors.push(`${label}/${stage}: horizontal clipping ${JSON.stringify(layout)}`);
  if (layout.duplicateLocalNavigation !== 0) errors.push(`${label}/${stage}: duplicate local task navigation returned`);
  if (layout.titleButtonUndersized) errors.push(`${label}/${stage}: report row target is undersized`);
  if (layout.filterOverlap) errors.push(`${label}/${stage}: report filters overlap`);
  if (layout.headerTitle && layout.baseline && intersects(layout.headerTitle, layout.baseline)) errors.push(`${label}/${stage}: report heading overlaps baseline`);
  if (layout.viewTitle && layout.viewCount && intersects(layout.viewTitle, layout.viewCount)) errors.push(`${label}/${stage}: view heading overlaps count`);
  if (layout.reportTextLength < 80) errors.push(`${label}/${stage}: report surface is blank`);
  return layout;
}

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const reportWarnings = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') pageErrors.push(message.text()); });
  page.on('console', (message) => {
    if (message.type() === 'warning' && message.text().startsWith('OJ AI report request failed ')) reportWarnings.push(message.text());
  });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await waitForTitle(page, '활동지별');
  await page.waitForFunction(() => document.querySelectorAll('.oj-ai-report__table tbody tr').length === 18);
  if ((await page.getByRole('tablist', { name: '보고서 보기', exact: true }).getByRole('tab').count()) !== 3) errors.push(`${label}: the three report lenses are missing`);
  await page.getByRole('tab', { name: '배포 대상별', exact: true }).click();
  await waitForTitle(page, '배포 대상별');
  if ((await page.locator('.oj-ai-report__table tbody tr').count()) !== 1 || !(await page.locator('.oj-ai-report__table tbody tr').innerText()).includes('2학년 1반')) errors.push(`${label}: group lens projection drifted`);
  if (!(await page.getByRole('button', { name: '파일 만들기', exact: true }).isDisabled())) errors.push(`${label}: export remained enabled outside the worksheet lens`);
  await page.getByRole('tab', { name: '학생별', exact: true }).click();
  await waitForTitle(page, '학생별');
  if ((await page.locator('.oj-ai-report__table tbody tr').count()) !== 18) errors.push(`${label}: student lens did not retain worksheet selection entry points`);
  await page.getByRole('tab', { name: '활동지별', exact: true }).click();
  await waitForTitle(page, '활동지별');

  await page.fill('[data-field="period-from"]', '2026-08-01');
  await page.fill('[data-field="period-through"]', '2026-08-31');
  await page.locator('.oj-ai-report__state-filter summary').click();
  await page.uncheck('[data-filter="assignment"][value="scheduled"]');
  await page.uncheck('[data-filter="submission"][value="not_started"]');
  await page.uncheck('[data-filter="submission"][value="in_progress"]');
  await page.getByRole('button', { name: '조회', exact: true }).click();
  await page.waitForFunction(() => window.__reportRequests.at(-1)?.url.includes('period_to=2026-09-01'));
  await waitForTitle(page, '활동지별');
  await page.locator('.oj-ai-report__state-filter').evaluate((element) => { element.open = false; });

  const filteredUrl = await page.evaluate(() => window.__reportRequests.at(-1)?.url || '');
  const filtered = new URL(filteredUrl);
  if (filtered.searchParams.get('period_from') !== '2026-08-01' || filtered.searchParams.get('period_to') !== '2026-09-01') errors.push(`${label}: inclusive date filter drifted`);
  if (filtered.searchParams.get('assignment_states') !== 'open,closed' || filtered.searchParams.get('submission_states') !== 'official') errors.push(`${label}: report state filters drifted`);
  const listLayout = await inspectLayout(page, label, 'worksheets');

  const worksheet = page.getByRole('button', { name: '분수 비교 활동지', exact: true });
  await worksheet.scrollIntoViewIfNeeded();
  const savedScroll = await page.evaluate(() => window.scrollY);
  if (savedScroll < 200) errors.push(`${label}: fixture did not exercise scroll restoration`);
  await worksheet.click();
  await waitForTitle(page, '분수 비교 활동지');
  const worksheetDetailUrl = await page.evaluate(() => window.__reportRequests.at(-1)?.url || '');
  if (!worksheetDetailUrl.includes('/reports/worksheets/worksheet-report-10?')) errors.push(`${label}: Q21 worksheet detail route was not used`);
  if (!worksheetDetailUrl.includes('period_to=2026-09-01') || !worksheetDetailUrl.includes('submission_states=official')) errors.push(`${label}: Q20 filters were not carried into Q21`);

  await page.getByRole('button', { name: '2학년 1반 8월 배포', exact: true }).click();
  await waitForTitle(page, '2학년 1반 8월 배포');
  const studentListUrl = await page.evaluate(() => window.__reportRequests.at(-1)?.url || '');
  if (!studentListUrl.includes('assignment_id=assignment-report-1')) errors.push(`${label}: Q21 assignment scope was not applied`);

  await page.getByRole('button', { name: '김하늘', exact: true }).click();
  await waitForTitle(page, '김하늘');
  const historyState = await page.evaluate(() => JSON.stringify(window.history.state));
  if (historyState.includes('김하늘') || !historyState.includes('student-report-1')) errors.push(`${label}: student history state contains display data or omits the opaque id`);
  const detailLayout = await inspectLayout(page, label, 'student');
  await page.screenshot({ path: path.join(artifactDir, `report-dashboard-${label}.png`), fullPage: true });

  await page.locator('[data-action="back"]').click();
  await waitForTitle(page, '2학년 1반 8월 배포');
  await page.goBack();
  await waitForTitle(page, '분수 비교 활동지');
  await page.goBack();
  await waitForTitle(page, '활동지별');
  await page.waitForFunction(() => document.querySelectorAll('.oj-ai-report__table tbody tr').length === 18);
  const restored = await page.evaluate(() => ({
    scrollY: window.scrollY,
    from: document.querySelector('[data-field="period-from"]')?.value,
    through: document.querySelector('[data-field="period-through"]')?.value,
    scheduled: document.querySelector('[data-filter="assignment"][value="scheduled"]')?.checked,
    official: document.querySelector('[data-filter="submission"][value="official"]')?.checked,
    latestUrl: window.__reportRequests.at(-1)?.url || '',
  }));
  if (Math.abs(restored.scrollY - savedScroll) > 100) errors.push(`${label}: report scroll was not restored (${savedScroll} -> ${restored.scrollY})`);
  if (restored.from !== '2026-08-01' || restored.through !== '2026-08-31' || restored.scheduled !== false || restored.official !== true) errors.push(`${label}: report filters were not restored`);
  if (!restored.latestUrl.includes('period_to=2026-09-01') || !restored.latestUrl.includes('assignment_states=open%2Cclosed')) errors.push(`${label}: restored report request lost filters`);

  const exportCases = [
    ['pdf', 'PDF', 'export-pdf', 'report_print.v1', 'application/pdf'],
    ['csv', 'CSV', 'export-csv', 'report_csv.v1', 'text/csv'],
    ['zip', 'ZIP', 'export-json-package', 'report_json_package.v1', 'application/zip'],
  ];
  for (const [key, labelText] of exportCases) {
    await page.locator('[data-role="export-controls"] label').filter({ hasText: labelText }).click();
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('button', { name: '파일 만들기', exact: true }).click();
    const download = await downloadEvent;
    if (download.suggestedFilename() !== `worksheet-report.${key}`) errors.push(`${label}: ${labelText} filename drifted`);
    await download.cancel();
    await page.waitForFunction((expected) => document.querySelector('[data-role="export-status"]')?.textContent === expected, labelText + ' 다운로드됨');
  }
  const exportEvidence = await page.evaluate(() => ({
    snapshots: window.__reportSnapshotRequests,
    exports: window.__reportExportRequests,
    downloads: window.__reportDownloads,
  }));
  if (exportEvidence.snapshots.length !== 3 || exportEvidence.exports.length !== 3 || exportEvidence.downloads.length !== 3) errors.push(`${label}: report export command count drifted`);
  exportEvidence.snapshots.forEach((entry, index) => {
    const body = entry?.body || {};
    if (!sameKeys(body, ['command_id','expected','payload'])) errors.push(`${label}: C45 body ${index + 1} drifted`);
    if (!/^[0-9A-HJKMNP-TV-Z]{26}$/.test(String(body.command_id || ''))) errors.push(`${label}: C45 command id ${index + 1} drifted`);
    if (!sameKeys(body.expected, [
      'expected_source_type','expected_source_id','expected_source_revision','expected_live_source_manifest_hash',
      'expected_filter_hash','expected_request_schema','expected_request_version','expected_request_hash',
    ])) errors.push(`${label}: C45 expected binding ${index + 1} drifted`);
    if (body.expected?.expected_source_type !== 'context'
      || body.expected?.expected_source_id !== 'class-a'
      || body.expected?.expected_source_revision !== 'context-r7'
      || body.expected?.expected_request_schema !== 'oj_ai.report_snapshot_request'
      || body.expected?.expected_request_version !== '1.0.0'
      || !/^[a-f0-9]{64}$/.test(String(body.expected?.expected_request_hash || ''))) errors.push(`${label}: C45 fixed contract ${index + 1} drifted`);
    if (body.payload?.lens !== 'worksheet'
      || body.payload?.source_page_size !== 25
      || body.payload?.filter?.period?.from !== '2026-08-01'
      || body.payload?.filter?.period?.to !== '2026-09-01') errors.push(`${label}: C45 report payload ${index + 1} drifted`);
    if (entry.headers?.['Idempotency-Key'] !== 'report-snapshot-' + body.command_id) errors.push(`${label}: C45 idempotency key ${index + 1} drifted`);
  });
  exportCases.forEach(([, labelText, route, profile, mimeType], index) => {
    const entry = exportEvidence.exports[index];
    const body = entry?.body || {};
    if (entry?.route !== route || !sameKeys(body, ['command_id','expected','payload'])) errors.push(`${label}: ${labelText} command route drifted`);
    if (!sameKeys(body.expected, ['expected_snapshot_hash','expected_snapshot_id','expected_snapshot_state'])
      || body.expected?.expected_snapshot_state !== 'ready') errors.push(`${label}: ${labelText} snapshot binding drifted`);
    if (!sameKeys(body.payload, ['locale','profile']) || body.payload?.profile !== profile || body.payload?.locale !== 'ko-KR') errors.push(`${label}: ${labelText} export profile drifted`);
    if (entry?.headers?.['Idempotency-Key'] !== 'report-export-' + body.command_id) errors.push(`${label}: ${labelText} idempotency key drifted`);
    if (exportEvidence.downloads[index]?.headers?.Accept !== mimeType || exportEvidence.downloads[index]?.headers?.['X-WP-Nonce'] !== 'visual-rest-nonce') errors.push(`${label}: ${labelText} private download headers drifted`);
  });

  await page.evaluate(() => { window.__reportPermissionRevoked = true; });
  await page.getByRole('button', { name: '조회', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('#oj-ai-report-app')?.dataset.access === 'denied');
  const denied = await page.evaluate(() => ({
    text: document.querySelector('#oj-ai-report-app')?.innerText || '',
    contentHidden: document.querySelector('[data-role="content"]')?.hidden,
    listChildren: document.querySelector('[data-role="list"]')?.childElementCount,
    controlsDisabled: [...document.querySelectorAll('[data-role="filters"] input, [data-role="filters"] select, [data-role="filters"] button')].every((control) => control.disabled),
    notice: document.querySelector('[data-role="notice"]')?.textContent,
  }));
  if (denied.text.includes('김하늘') || denied.contentHidden !== true || denied.listChildren !== 0) errors.push(`${label}: revoked report data remained visible`);
  if (!denied.controlsDisabled || denied.notice !== '이 보고서를 볼 수 없습니다.') errors.push(`${label}: permission revocation did not fail closed`);
  try {
    const diagnostic = JSON.parse(reportWarnings.at(-1)?.slice('OJ AI report request failed '.length) || 'null');
    if (!sameKeys(diagnostic, ['status', 'code', 'correlation']) || diagnostic.status !== 403 || diagnostic.code !== 'oj_ai_report_forbidden') {
      errors.push(`${label}: report diagnostics must serialize only the safe status, code and correlation fields`);
    }
  } catch (_error) {
    errors.push(`${label}: report diagnostics were not readable JSON`);
  }

  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] report dashboard ${label} ${JSON.stringify({ listLayout, detailLayout, savedScroll, restoredScroll: restored.scrollY })}`);
}

async function inspectWorkflowEntry(browser, viewport, label) {
  const page = await browser.newPage({ viewport });
  await page.addInitScript(({ overview }) => {
    if (!location.search.includes('workflow-entry')) return;
    const overrides = {
      read: () => ({
        worksheetId: 'worksheet-report-10', assignmentId: 'assignment-report-1',
        context: { type: 'class', id: 'class-a', revision: 'context-r7' },
      }),
      setSelection: () => {},
      overviewUrl: () => overview,
      allPages: async (request, route) => (await request(route)).data.items,
    };
    let workflow;
    Object.defineProperty(window, 'OJAIWorkflow', {
      configurable: true,
      get: () => workflow,
      set: value => { workflow = { ...value, ...overrides }; },
    });
  }, { overview: fixtureUrl });
  await page.goto(fixtureUrl + '?workflow-entry');
  await waitForTitle(page, '2학년 1반 8월 배포');
  if (label === 'console') await page.addStyleTag({ content: 'body { margin-left: 240px !important; }' });
  await page.getByRole('button', { name: '김하늘', exact: true }).click();
  await waitForTitle(page, '김하늘');
  await page.getByRole('button', { name: '이전', exact: true }).click();
  await waitForTitle(page, '2학년 1반 8월 배포');
  await page.getByRole('navigation', { name: '보고서 위치' }).getByRole('button', { name: '분수 비교 활동지', exact: true }).click();
  await waitForTitle(page, '분수 비교 활동지');
  if (page.url() !== fixtureUrl + '?workflow-entry') errors.push(`${label}: workflow breadcrumb left its report`);
  await inspectLayout(page, label, 'workflow-report');
  await page.getByRole('link', { name: '대상 전체 집계', exact: true }).click();
  await waitForTitle(page, '활동지별');
  if (await page.getByRole('button', { name: '파일 만들기', exact: true }).isDisabled()) errors.push(`${label}: workflow report export is unreachable`);
  await page.close();
  console.log(`[OK] workflow report ${label}: direct entry, parent navigation and class-wide export entry`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await inspectWorkflowEntry(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectWorkflowEntry(browser, { width: 390, height: 844 }, 'mobile');
  await inspectWorkflowEntry(browser, { width: 1044, height: 900 }, 'console');
  await browser.close();
} catch (error) {
  errors.push(`Playwright report dashboard check failed: ${error.message}`);
}

if (errors.length > 0) {
  console.error('Report dashboard visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('Report dashboard desktop/mobile interaction checks passed.');

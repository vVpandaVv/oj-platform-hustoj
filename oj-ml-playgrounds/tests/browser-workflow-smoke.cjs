'use strict';

// Isolated UI smoke tests only: real local JS/CSS, synthetic route fixtures,
// temporary headless browser profiles, and no live WordPress/OJ or student data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.OJ_ML_PLAYWRIGHT || 'C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const plugin = path.resolve(__dirname, '..');
const workspace = path.dirname(plugin);
const shots = path.join(workspace, '_notes', 'tmp', 'oj-ml-workflow-ui');
const origin = 'https://oj-ml-smoke.test';
const assets = {
  '/addon.js': ['application/javascript', fs.readFileSync(path.join(plugin, 'resources/js/addon.js'), 'utf8')],
  '/teacher-workflow.js': ['application/javascript', fs.readFileSync(path.join(plugin, 'resources/js/teacher-workflow.js'), 'utf8')],
  '/rubric-tools.js': ['application/javascript', fs.readFileSync(path.join(plugin, 'resources/js/rubric-tools.js'), 'utf8')],
  '/portfolio-bridge.js': ['application/javascript', fs.readFileSync(path.join(plugin, 'resources/js/portfolio-bridge.js'), 'utf8')],
  '/assignment-policy-tools.js': ['application/javascript', fs.readFileSync(path.join(plugin, 'resources/js/assignment-policy-tools.js'), 'utf8')],
  '/addon.css': ['text/css', fs.readFileSync(path.join(plugin, 'resources/css/addon.css'), 'utf8')],
};
const report = { kind: 'mocked browser UI smoke; not live server acceptance', browsers: [], checks: [], console: [], pageErrors: [], requests: [] };
const template = { title: '탐구 포트폴리오', markdown: '## 문제 정의 {#topic}\n\n해결하려는 문제를 설명하세요.\n\n## 결과 해석 {#result}\n\n실험 결과와 한계를 설명하세요.\n' };
const assignment = { id: 'assignment-1', title: '우리 반 머신러닝 탐구', description: '합성 UI 테스트 과제입니다.', status: 'published', maxAttempts: 3, revision: 4, dueAt: '2099-09-30T14:59:00Z', contextType: 'class', contextId: 'class-1', taskType: 'classification', policy: { portfolioTemplate: template }, submissionPolicy: { maxArtifactBytes: 26214400, portfolioTemplate: template } };
const artifact = { originalName: '합성 실습.mlpx', bytes: 1114683, sha256: '8'.repeat(64) };
function attempt(id, status) {
  return { id, assignmentId: assignment.id, status, attemptNo: 1, receivedAt: '2026-09-07T08:40:38Z', artifact, canEvaluate: true,
    clientAppVersion: status === 'accepted' ? '0.12.1' : null, formatVersion: status === 'accepted' ? 2 : null,
    failure: status === 'needs_resubmit' ? { code: 'OJ_ML_MLPX_NOT_ZIP' } : null,
    evaluation: { status: status === 'accepted' ? 'accepted' : status === 'needs_resubmit' ? 'rejected' : 'system_pending', evaluator: 'mlpx-preflight', evaluatorVersion: '4',
      findings: status === 'needs_resubmit' ? [{ severity: 'error', code: 'OJ_ML_MLPX_NOT_ZIP', message: '제출 파일을 열지 못했습니다. 원본 확인이 필요합니다.' }] : [], facts: {} },
    review: null };
}
const rows = [
  { userId: 11, displayName: '김탐구', attemptsUsed: 1, canEvaluate: true, attempt: attempt('accepted-1', 'accepted'), allowance: { revision: 0 } },
  { userId: 12, displayName: '이도전', attemptsUsed: 3, canEvaluate: true, attempt: attempt('rejected-1', 'needs_resubmit'), allowance: { revision: 2, maxAttempts: 3 } },
  { userId: 13, displayName: '박대기', attemptsUsed: 1, canEvaluate: true, attempt: attempt('pending-1', 'system_pending'), allowance: { revision: 0 } },
  { userId: 14, displayName: '최시작', attemptsUsed: 0, canEvaluate: true, attempt: null, allowance: { revision: 0 } },
];
const preview = {
  project: { name: '붓꽃 분류 탐구', appVersion: '0.12.1', formatVersion: 2, taskType: 'classification', dataType: 'tabular', locale: 'ko' },
  portfolio: { title: '탐구 포트폴리오', sections: [{ id: 'topic', title: '문제 정의', description: '해결하려는 문제를 설명하세요.', answered: true, answer: '꽃의 측정값을 사용하여 품종을 구분하고자 했습니다.' }] },
  settings: { data: { features: ['꽃받침 길이', '꽃잎 길이'] }, selectedAlgorithms: [{ algorithm: 'decision_tree', runtime: 'browser' }] },
  experiments: [{ startedAt: '2026-09-07T08:30:00Z', taskType: 'classification', runtime: 'browser', sampleCount: 150,
    runs: [{ algorithm: 'decision_tree', status: 'done', computedBy: 'browser', metrics: [{ name: 'accuracy', value: 0.93 }] }] }],
  notices: ['합성 fixture입니다. 학습이나 제출물을 실행하지 않습니다.'],
};
function check(name, condition = true) {
  assert.ok(condition, name);
  report.checks.push(name);
}
function shell(pageType, options = {}) {
  const capabilities = options.readOnly ? '0' : '1';
  return '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    + '<link rel="stylesheet" href="/addon.css"><style>body{margin:0;font-family:Arial,"Malgun Gothic",sans-serif;background:#fff}main{max-width:1120px;margin:24px auto;padding:0 16px;min-width:0}</style>'
    + '<script defer src="/teacher-workflow.js"></script><script defer src="/rubric-tools.js"></script><script defer src="/portfolio-bridge.js"></script><script defer src="/assignment-policy-tools.js"></script><script defer src="/addon.js"></script></head><body><main>'
    + '<section class="oj-ml-app" data-oj-ml-page="' + pageType + '" data-rest-root="/wp-json/oj-ml/v1/" data-rest-nonce="synthetic-nonce" data-assignment-id="assignment-1" data-public-pc-mode="0"'
    + ' data-attempt-id="accepted-1" data-can-review="' + capabilities + '" data-can-manage="' + capabilities + '" data-can-report="1" data-can-settings="' + capabilities + '"'
    + ' data-console-assignments-url="/console-assignments" data-console-results-url="/console-results?assignment_id={assignment_id}" data-console-settings-url="/console-settings"'
    + ' data-lab-url="/lab/{assignment_id}" data-result-url="/result/{attempt_id}" data-playground-url="">'
    + '<p class="oj-ml-status" data-oj-ml-status role="status">화면을 준비합니다.</p></section></main>'
    + '<aside aria-label="테스트 환경" style="position:fixed;bottom:4px;right:4px;z-index:999;background:#fff8dd;color:#704500;border:1px solid #d6bb70;padding:4px 6px;font-size:11px;pointer-events:none;max-width:calc(100% - 24px)">합성 데이터 · 로컬 UI 검사 · 운영 서버 아님</aside></body></html>';
}
async function makePage(browser, browserName, viewport, pageType, options = {}) {
  const context = await browser.newContext({ viewport, locale: 'ko-KR', timezoneId: 'Asia/Seoul', acceptDownloads: true });
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  const label = browserName + ':' + viewport.width + ':' + pageType + (options.readOnly ? ':readonly' : '');
  const requests = [];
  const localRows = structuredClone(rows);
  const currentAssignment = structuredClone(options.assignment || assignment);
  if (currentAssignment.rubric) localRows.forEach(row => { if (row.attempt) row.attempt.rubric = currentAssignment.rubric; });
  if (options.readOnly) localRows.forEach(row => { row.canEvaluate = false; if (row.attempt) row.attempt.canEvaluate = false; });
  let draft = null;
  page.on('pageerror', error => report.pageErrors.push({ label, message: error.message }));
  page.on('console', message => { if (message.type() === 'error' || message.type() === 'warning') report.console.push({ label, type: message.type(), message: message.text() }); });
  page.on('dialog', dialog => dialog.accept());
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) { report.pageErrors.push({ label, message: 'Unexpected external request: ' + url.origin }); return route.abort(); }
    const asset = assets[url.pathname];
    if (asset) return route.fulfill({ status: 200, contentType: asset[0], body: asset[1] });
    if (url.pathname === '/favicon.ico') return route.fulfill({ status: 204, body: '' });
    if (!url.pathname.startsWith('/wp-json/oj-ml/v1/')) return route.fulfill({ status: 200, contentType: 'text/html', body: shell(pageType, options) });
    const apiPath = url.pathname.slice('/wp-json/oj-ml/v1/'.length);
    const body = request.postData() ? (/json/i.test(request.headers()['content-type'] || '') ? JSON.parse(request.postData()) : request.postData()) : null;
    const record = { label, path: apiPath, method: request.method(), body };
    requests.push(record); report.requests.push(record);
    if (options.api) {
      const override = await options.api({ path: apiPath, method: request.method(), body, url, request, rows: localRows });
      if (override !== undefined) return route.fulfill({ status: override.status || 200, contentType: override.contentType || 'application/json', body: override.raw === undefined ? JSON.stringify({ data: override.data }) : override.raw });
    }
    let data;
    if (apiPath === 'console/report-assignment-options') data = { items: [currentAssignment], total: 1 };
    else if (apiPath === 'console/contexts') data = { items: [{ id: 'class-1', type: 'class', label: '1학년 1반', lifecycleState: 'active' }], nextCursor: null };
    else if (apiPath === 'console/assignments' && request.method() === 'GET') data = { items: draft ? [draft] : [], total: draft ? 1 : 0, page: 1, perPage: 100 };
    else if (apiPath === 'console/assignments' && request.method() === 'POST') { draft = Object.assign({ id: 'draft-1', status: 'draft', revision: 1 }, body); data = { assignment: draft }; }
    else if (apiPath === 'console/assignments/draft-1' && request.method() === 'PATCH') { draft = Object.assign({}, draft, body, { revision: 2 }); data = { assignment: draft }; }
    else if (apiPath === 'console/assignments/assignment-1/results') data = { assignment: currentAssignment, items: localRows, total: 4, perPage: 100 };
    else if (/\/learners\/\d+\/allowance$/.test(apiPath)) data = { ok: true };
    else if (/\/learners\/\d+\/history$/.test(apiPath)) { const user = Number(apiPath.split('/')[4]); const row = localRows.find(item => item.userId === user); data = { items: row && row.attempt ? [row.attempt] : [], total: row && row.attempt ? 1 : 0, perPage: 20 }; }
    else if (/\/preview$/.test(apiPath)) data = { content: preview };
    else if (/\/review$/.test(apiPath)) return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ code: 'smoke_expected_failure', message: '의도적인 평가 저장 실패', data: { correlationId: 'fixture-only' } }) });
    else if (apiPath === 'assignments/assignment-1') data = { assignment: currentAssignment };
    else { report.pageErrors.push({ label, message: 'Unhandled fixture request: ' + apiPath }); return route.fulfill({ status: 404, contentType: 'application/json', body: '{"message":"Unhandled smoke fixture"}' }); }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data }) });
  });
  await page.goto(origin + '/' + pageType);
  return { page, context, requests, label };
}
async function noOverflow(page, name) {
  const bounds = await page.evaluate(() => ({ viewport: innerWidth, root: document.documentElement.scrollWidth, body: document.body.scrollWidth }));
  check(name + ' document has no horizontal overflow ' + JSON.stringify(bounds), bounds.root <= bounds.viewport + 1 && bounds.body <= bounds.viewport + 1);
}
async function shot(page, name) {
  await page.screenshot({ path: path.join(shots, name + '.png'), fullPage: true });
}
async function mobileBounds(page, locator, name) {
  if (page.viewportSize().width > 700) return;
  const bounds = await locator.evaluateAll(nodes => nodes.map(node => {
    const rect = node.getBoundingClientRect();
    return { left: rect.left, right: rect.right, width: rect.width, viewport: innerWidth };
  }));
  check(name + ' every mobile work control fits viewport ' + JSON.stringify(bounds), bounds.length > 0 && bounds.every(rect => rect.width > 0 && rect.left >= -1 && rect.right <= rect.viewport + 1));
}
async function viewportShot(page, locator, name) {
  await locator.scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(shots, name + '-viewport.png'), fullPage: false });
}
async function resultsScenario(browser, name, viewport) {
  const { page, context, requests, label } = await makePage(browser, name, viewport, 'console-results');
  try {
    await page.locator('[data-result-open="view"]').first().waitFor();
    check(label + ' four student status groups visible', await page.locator('.oj-ml-stat').count() === 4);
    check(label + ' settings/assignment navigation visible', await page.getByRole('link', { name: '과제·양식 설정' }).isVisible());
    check(label + ' result panels initially closed', await page.locator('[data-oj-ml-result-details][open]').count() === 0);
    await page.locator('[data-result-open="view"]').nth(0).click();
    await page.locator('[data-oj-ml-preview][data-preview-state="loaded"]').waitFor();
    check(label + ' opens accepted preview', await page.getByRole('heading', { name: '붓꽃 분류 탐구', exact: true }).isVisible());
    check(label + ' student text visible', await page.getByText('꽃의 측정값을 사용하여 품종을 구분하고자 했습니다.', { exact: true }).isVisible());
    check(label + ' opening result does not open allowance', await page.locator('[data-allowance-panel][open]').count() === 0);
    const review = page.locator('[data-oj-ml-review-form][data-attempt-id="accepted-1"]');
    await review.locator('[name="score"]').fill('87');
    await review.locator('[name="maxScore"]').fill('100');
    await review.locator('[name="feedback"]').fill('실험 비교와 한계 설명을 잘 작성했습니다.');
    await review.locator('[data-review-action="save"]').click();
    await page.getByText(/의도적인 평가 저장 실패.*입력한 점수와 피드백은 유지했습니다/).waitFor();
    check(label + ' failed save preserves score', await review.locator('[name="score"]').inputValue() === '87');
    check(label + ' failed save preserves feedback', await review.locator('[name="feedback"]').inputValue() === '실험 비교와 한계 설명을 잘 작성했습니다.');
    check(label + ' failed save restores action', await review.locator('[data-review-action="save"]').isEnabled());
    const reviewRequest = requests.find(item => item.path.endsWith('/review'));
    check(label + ' review payload', reviewRequest.body.score === 87 && reviewRequest.body.maxScore === 100 && reviewRequest.body.expectedVersion === 0);
    await noOverflow(page, label + ':preview');
    await mobileBounds(page, review, label + ':review-form');
    await mobileBounds(page, review.locator('input:not([type="hidden"]),textarea,button'), label + ':review-controls');
    await mobileBounds(page, page.locator('[data-oj-ml-preview][data-preview-state="loaded"]'), label + ':preview-content');
    await shot(page, name + '-' + viewport.width + '-accepted-preview');
    if (viewport.width <= 700) await viewportShot(page, review, name + '-' + viewport.width + '-accepted-review');

    await page.locator('[data-result-open="view"]').nth(1).click();
    const rejected = page.locator('[data-oj-ml-result-details]').nth(1);
    check(label + ' rejected result has actionable explanation', await rejected.getByText('다시 제출할 준비가 필요합니다', { exact: true }).isVisible());
    check(label + ' rejected technical details initially collapsed', await rejected.locator('.oj-ml-technical[open]').count() === 0);
    check(label + ' rejected result does not pretend empty algorithm data', !(await rejected.innerText()).includes('무결성 목록'));
    check(label + ' rejected cannot grade or preview', await rejected.locator('[data-oj-ml-preview], [data-oj-ml-review-form]').count() === 0);
    await mobileBounds(page, rejected.locator('.oj-ml-workflow-banner'), label + ':rejected-guidance');
    if (viewport.width <= 700) await viewportShot(page, rejected.locator('.oj-ml-workflow-banner'), name + '-' + viewport.width + '-rejected-guidance');
    await page.locator('[data-result-open="allowance"][data-result-user="12"]').click();
    const tools = page.locator('[data-learner-tools="12"]');
    check(label + ' allowance opens independently', await tools.locator('[data-allowance-panel]').getAttribute('open') !== null);
    check(label + ' history remains collapsed', await tools.locator('[data-history-panel][open]').count() === 0);
    const allowance = tools.locator('[data-allowance-form]');
    await allowance.locator('[name="maxAttempts"]').fill('5');
    await allowance.locator('[name="dueAt"]').fill('2099-10-02T18:00');
    await allowance.locator('[name="reason"]').fill('파일 확인 후 재제출 기회 제공');
    await mobileBounds(page, allowance, label + ':allowance-form');
    await mobileBounds(page, allowance.locator('input,button'), label + ':allowance-controls');
    await shot(page, name + '-' + viewport.width + '-resubmit-settings');
    if (viewport.width <= 700) await viewportShot(page, allowance, name + '-' + viewport.width + '-resubmit-settings');
    await allowance.locator('button[type="submit"]').click();
    await page.getByText('이 학생의 제출 허용 설정을 저장했습니다.', { exact: true }).waitFor();
    const allowed = requests.find(item => item.path.endsWith('/learners/12/allowance'));
    check(label + ' allowance API payload retains individual revision', allowed.body.expectedRevision === 2 && allowed.body.maxAttempts === 5 && allowed.body.dueAt === '2099-10-02T09:00:00.000Z' && allowed.body.reason === '파일 확인 후 재제출 기회 제공');
    await page.locator('[data-result-open="view"]').nth(2).click();
    const pending = page.locator('[data-oj-ml-result-details]').nth(2);
    check(label + ' pending explains waiting', await pending.getByText('서버가 제출 파일을 확인하고 있습니다', { exact: true }).isVisible());
    check(label + ' pending retry available without grading', await pending.locator('[data-reinspect-id]').count() === 1 && await pending.locator('[data-oj-ml-review-form]').count() === 0);
    await page.locator('[data-result-open="history"][data-result-user="14"]').click();
    await page.locator('[data-learner-tools="14"] [data-attempt-history]').getByText('전체 0회 · 1 페이지', { exact: true }).waitFor();
    check(label + ' non-submitted history has explicit empty result');
    await noOverflow(page, label + ':results');
  } finally { await context.close(); }
}
async function assignmentsScenario(browser, name, viewport) {
  const { page, context, requests, label } = await makePage(browser, name, viewport, 'console-assignments');
  try {
    const form = page.locator('[data-oj-ml-assignment-form]');
    await form.locator('select[name="context"] option[value="0"]').waitFor({ state: 'attached' });
    check(label + ' template optional initially', !(await form.locator('[data-template-enabled]').isChecked()));
    await form.locator('[name="context"]').selectOption('0');
    await form.locator('[name="title"]').fill('1반 탐구 과제');
    await form.locator('[data-template-enabled]').check();
    await form.locator('[data-template-preset]').click();
    check(label + ' eight preset questions', await form.locator('[data-question-title]').count() === 8);
    await form.locator('[data-template-title]').fill('1반 맞춤 포트폴리오');
    await form.locator('[data-question-title="0"]').fill('내가 정한 탐구 문제');
    await form.locator('[data-question-prompt="0"]').fill('우리 주변에서 찾은 문제와 해결 대상을 구체적으로 작성하세요.');
    await noOverflow(page, label + ':template');
    await shot(page, name + '-' + viewport.width + '-template-editor');
    await form.locator('button[type="submit"]').click();
    await page.getByText('과제 초안을 만들었습니다.', { exact: true }).waitFor();
    const created = requests.find(item => item.path === 'console/assignments' && item.method === 'POST');
    check(label + ' create contains explicit template policy', created.body.policy.portfolioTemplate.title === '1반 맞춤 포트폴리오' && created.body.policy.portfolioTemplate.markdown.includes('## 내가 정한 탐구 문제 {#topic}') && created.body.policy.portfolioTemplate.markdown.includes('우리 주변에서 찾은 문제'));
    await page.locator('[data-edit-id="draft-1"]').click();
    check(label + ' draft editing loads saved template', await form.locator('[data-template-title]').inputValue() === '1반 맞춤 포트폴리오');
    await form.locator('[data-question-prompt="0"]').fill('수정한 학생 작성 안내입니다.');
    await form.locator('button[type="submit"]').click();
    await page.getByText('과제 초안을 수정했습니다.', { exact: true }).waitFor();
    const changed = requests.find(item => item.path === 'console/assignments/draft-1' && item.method === 'PATCH');
    check(label + ' draft update contains edited template and revision', changed.body.expectedRevision === 1 && changed.body.policy.portfolioTemplate.markdown.includes('수정한 학생 작성 안내입니다.'));
  } finally { await context.close(); }
}
async function studentAndReadonlyScenario(browser, name) {
  const lab = await makePage(browser, name, { width: 390, height: 844 }, 'lab');
  try {
    const button = lab.page.locator('[data-student-template-download]');
    await button.waitFor();
    check(name + ' student sees teacher template title', await lab.page.getByText('탐구 포트폴리오', { exact: true }).isVisible());
    const waiting = lab.page.waitForEvent('download');
    await button.click();
    const download = await waiting;
    check(name + ' template download filename', download.suggestedFilename() === 'portfolio-template.md');
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream) chunks.push(chunk);
    check(name + ' template download is exact UTF-8 Markdown', Buffer.concat(chunks).toString('utf8') === template.markdown);
    await noOverflow(lab.page, name + ':student-template');
    await shot(lab.page, name + '-390-student-template');
  } finally { await lab.context.close(); }
  const readonly = await makePage(browser, name, { width: 1280, height: 900 }, 'console-results', { readOnly: true });
  try {
    await readonly.page.locator('[data-result-open="view"]').first().waitFor();
    await readonly.page.locator('[data-result-open="view"]').first().click();
    check(name + ' report-only cannot grade/preview', await readonly.page.locator('[data-oj-ml-preview], [data-oj-ml-review-form]').count() === 0);
    await readonly.page.locator('[data-result-open="allowance"][data-result-user="12"]').click();
    check(name + ' report-only has clear allowance denial', await readonly.page.locator('[data-learner-tools="12"]').getByText('학생별 제출 허용은 ML 과제 관리 권한이 필요합니다. 담당 관리자에게 권한을 확인하세요.', { exact: true }).isVisible());
    check(name + ' report-only cannot mutate allowance', await readonly.page.locator('[data-allowance-form]').count() === 0);
    check(name + ' report-only navigation hides settings', await readonly.page.getByRole('link', { name: '운영 설정', exact: true }).count() === 0);
  } finally { await readonly.context.close(); }
}
async function run() {
  fs.mkdirSync(shots, { recursive: true });
  const targets = [
    ['chrome', process.env.OJ_ML_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'],
    ['edge', process.env.OJ_ML_EDGE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'],
  ];
  for (const [name, executablePath] of targets) {
    assert.ok(fs.existsSync(executablePath), name + ' executable must exist; do not silently skip browsers');
    const browser = await chromium.launch({ executablePath, headless: true, args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
    try {
      report.browsers.push({ name, version: browser.version() });
      for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
        await resultsScenario(browser, name, viewport);
        await assignmentsScenario(browser, name, viewport);
      }
      await studentAndReadonlyScenario(browser, name);
    } finally { await browser.close(); }
  }
  check('no uncaught page JavaScript errors', report.pageErrors.length === 0);
  const unexpectedConsole = report.console.filter(item => !item.message.includes('503 (Service Unavailable)'));
  check('no unexpected console errors/warnings', unexpectedConsole.length === 0);
  const summary = process.env.OJ_ML_SMOKE_VERBOSE ? report : { kind: report.kind, browsers: report.browsers, console: report.console, pageErrors: report.pageErrors, mockRequestCount: report.requests.length };
  process.stdout.write(JSON.stringify({ ...summary, count: report.checks.length, screenshots: shots }, null, 2) + '\n');
  process.stdout.write('Browser workflow mocked UI smoke tests passed.\n');
}
module.exports = { chromium, assets, assignment, attempt, rows, template, preview, makePage, check, noOverflow, shot, mobileBounds, viewportShot, report, shots, origin };
if (require.main === module) run().catch(error => {
  process.stderr.write(error.stack + '\n' + JSON.stringify(report, null, 2) + '\n');
  process.exitCode = 1;
});

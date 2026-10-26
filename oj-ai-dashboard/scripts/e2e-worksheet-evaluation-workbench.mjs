import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/worksheet-evaluation-workbench-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
const contextualWorksheetId = '01HZY3M4N5P6Q7R8S9T0V1W2X3';

await mkdir(artifactDir, { recursive: true });

function intersects(a, b) {
  return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
}

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(message.text());
  });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForFunction(() => document.querySelector('[data-role="evaluation-detail"] h2')?.textContent === '우리 동네 환경 조사');
  assert.deepEqual(await page.locator('.oj-ai-evaluation__dimension > strong').allTextContents(), ['학습목표 정합성','명료성','답변 가능성','난이도','평가 기준','안전성']);
  const contextualListUrl = await page.evaluate(() => window.__lastEvaluationListUrl || '');
  if (new URL(contextualListUrl).searchParams.get('view') !== 'latest') errors.push(`${label}: worksheet list must use latest-only queries`);
  await page.waitForFunction(() => document.querySelectorAll('.oj-ai-evaluation__finding').length === 2);

  const layout = await page.evaluate(() => {
    const rect = (selector) => {
      const element = document.querySelector(selector);
      if (!element) return null;
      const value = element.getBoundingClientRect();
      return { left: value.left, right: value.right, top: value.top, bottom: value.bottom, width: value.width, height: value.height };
    };
    const rootElement = document.querySelector('#oj-ai-worksheet-evaluation-app');
    const list = document.querySelector('.oj-ai-evaluation__inbox');
    const detail = document.querySelector('.oj-ai-evaluation__detail');
    return {
      root: rect('#oj-ai-worksheet-evaluation-app'),
      header: rect('.oj-ai-evaluation__header'),
      headerTitle: rect('.oj-ai-evaluation__header > div:first-child'),
      headerActions: rect('.oj-ai-evaluation__header-actions'),
      list: rect('.oj-ai-evaluation__inbox'),
      detail: rect('.oj-ai-evaluation__detail'),
      findingCount: document.querySelectorAll('.oj-ai-evaluation__finding').length,
      decisionCount: document.querySelectorAll('[data-field="finding-decision"]').length,
      rootTextLength: rootElement?.textContent.trim().length || 0,
      detailMetaText: document.querySelector('.oj-ai-evaluation__detail-meta')?.textContent || '',
      runTimelineText: [...document.querySelectorAll('.oj-ai-evaluation__timeline')][0]?.textContent || '',
      internalEvidenceVisible: /private_metadata|text\.content|(?:node|response)\s*·/.test(detail?.textContent || ''),
      friendlyEvidenceVisible: [...document.querySelectorAll('.oj-ai-evaluation__evidence')]
        .some((item) => item.textContent.trim() === '응답칸을 확인하세요.'),
      refreshIconHref: document.querySelector('[data-action="detail-refresh"] use')?.getAttribute('href') || '',
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      listOverflow: list ? list.scrollWidth > list.clientWidth + 1 : true,
      detailOverflow: detail ? detail.scrollWidth > detail.clientWidth + 1 : true,
    };
  });

  if (!layout.root || layout.root.width < 200 || layout.root.height < 500 || layout.rootTextLength < 120) errors.push(`${label}: workbench root is blank`);
  if (/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(layout.detailMetaText)) errors.push(`${label}: detail header date rendered as raw UTC instead of the site timezone/locale`);
  if (!layout.runTimelineText.includes('AI Provider 인증에 실패했습니다.')) errors.push(`${label}: failed run did not surface the platform's safe failure message`);
  if (!layout.list || !layout.detail) errors.push(`${label}: list/detail layout is missing`);
  if (layout.findingCount !== 2 || layout.decisionCount !== 2) errors.push(`${label}: findings or review controls are incomplete`);
  if (layout.internalEvidenceVisible || !layout.friendlyEvidenceVisible) errors.push(`${label}: internal evidence path was exposed instead of a user-facing location`);
  if (layout.refreshIconHref !== '#refresh-cw') errors.push(`${label}: dynamic refresh icon did not use the Lucide sprite`);
  if (layout.horizontalOverflow || layout.listOverflow || layout.detailOverflow) errors.push(`${label}: horizontal overflow detected`);
  if (label === 'desktop' && layout.headerTitle && layout.headerActions && intersects(layout.headerTitle, layout.headerActions)) errors.push(`${label}: header title overlaps actions`);
  if (label === 'desktop' && layout.list && layout.detail && intersects(layout.list, layout.detail)) errors.push(`${label}: inbox overlaps detail`);

  await page.getByRole('button', { name: '활동지 점검', exact: true }).click();
  await page.waitForSelector('[data-role="new-evaluation-dialog"][open]');
  await page.selectOption('[data-field="worksheet"]', { index: 1 });
  await page.waitForFunction(() => document.querySelector('[data-role="new-evaluation-status"]')?.textContent.includes('외부 AI 처리 확인에 동의해 주세요.'));
  await page.check('[data-field="external-processing-ack"]');
  await page.waitForFunction(() => document.querySelector('[data-role="new-evaluation-status"]')?.textContent.includes('버전 4'));
  if (!(await page.getByRole('button', { name: '점검 시작', exact: true }).isEnabled())) errors.push(`${label}: C10 start command did not become available after acknowledgement`);
  await page.evaluate(() => { window.__failNextEvaluation = true; });
  await page.getByRole('button', { name: '점검 시작', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="new-evaluation-status"]')?.textContent.includes('평가 설정을 확인해 주세요.'));
  const failedRequest = await page.evaluate(() => ({
    buttonEnabled: !document.querySelector('[data-action="enqueue"]')?.disabled,
    dialogOpen: document.querySelector('[data-role="new-evaluation-dialog"]')?.hasAttribute('open'),
    statusTone: document.querySelector('[data-role="new-evaluation-status"]')?.dataset.tone || '',
    requestCount: window.__evaluationCommandCount || 0,
  }));
  if (!failedRequest.buttonEnabled || !failedRequest.dialogOpen || failedRequest.statusTone !== 'error' || failedRequest.requestCount !== 1) {
    errors.push(`${label}: failed C10 request was hidden or could not be retried`);
  }
  await page.getByRole('button', { name: '점검 시작', exact: true }).click();
  await page.waitForFunction(() => !document.querySelector('[data-role="new-evaluation-dialog"]')?.hasAttribute('open'));
  const completedRequest = await page.evaluate(() => ({
    notice: document.querySelector('[data-role="notice"]')?.textContent || '',
    requestCount: window.__evaluationCommandCount || 0,
  }));
  if (!completedRequest.notice.includes('점검을 요청했습니다.') || completedRequest.requestCount !== 2) {
    errors.push(`${label}: successful C10 request did not close the dialog and refresh the workbench`);
  }

  const secondFinding = page.locator('.oj-ai-evaluation__finding').nth(1);
  await secondFinding.locator('[data-field="finding-decision"]').selectOption('dismissed');
  await secondFinding.locator('[data-field="finding-reason"]').selectOption('not_applicable');
  await secondFinding.locator('[data-field="finding-note"]').fill('교사가 활동지 맥락을 확인했습니다.');
  if (!(await page.getByRole('button', { name: '검토 완료', exact: true }).isVisible())) errors.push(`${label}: C33 completion command is not visible`);

  await page.screenshot({
    path: path.join(artifactDir, `worksheet-evaluation-workbench-${label}.png`),
    fullPage: true,
  });
  await page.locator('[data-action="select-evaluation"]').filter({ hasText: '저작권 보호하기' }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="evaluation-detail"] h2')?.textContent === '저작권 보호하기');
  const failedDetailText = await page.locator('[data-role="evaluation-detail"]').textContent();
  if (!failedDetailText.includes('활동지 자체 평가 작업 설정과 실행 상태를 확인한 뒤 다시 점검해 주세요.')) {
    errors.push(label + ': failed AI run did not expose stable task recovery guidance');
  }

  await page.getByRole('button', { name: '다시 시도', exact: true }).click();
  await page.waitForSelector('[data-role="new-evaluation-dialog"][open]');
  const retryRecovery = await page.evaluate(() => ({
    detailReads: window.__failedDetailReads || 0,
    expectedRunId: window.__retryRequestBody?.expected?.expected_run_id || '',
    expectedRunNo: window.__retryRequestBody?.expected?.expected_run_no || 0,
    notice: document.querySelector('[data-role="notice"]')?.textContent || '',
    dialogStatus: document.querySelector('[data-role="new-evaluation-status"]')?.textContent || '',
    selectedWorksheet: document.querySelector('[data-field="worksheet"]')?.value || '',
  }));
  if (
    retryRecovery.detailReads < 3
    || retryRecovery.expectedRunId !== '01HZY3M4N5P6Q7R8S9T0V1W2XE'
    || retryRecovery.expectedRunNo !== 2
  ) {
    errors.push(label + ': C11 retry did not preflight and submit the latest run state (' + JSON.stringify(retryRecovery) + ')');
  }
  if (
    !retryRecovery.notice.includes('평가 기준이 바뀌었습니다.')
    || !retryRecovery.dialogStatus.includes('현재 기준으로 새 점검')
    || retryRecovery.selectedWorksheet !== '01HZY3M4N5P6Q7R8S9T0V1W2XF'
  ) {
    errors.push(label + ': configuration conflict did not open the current-revision recovery flow (' + JSON.stringify(retryRecovery) + ')');
  }
  await page.goto(`${fixtureUrl}?evidence_mismatch=1`, { waitUntil: 'load' });
  await page.locator('[data-action="select-evaluation"]').filter({ hasText: '저작권 보호하기' }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="evaluation-detail"] h2')?.textContent === '저작권 보호하기');
  const quoteFailureText = await page.locator('[data-role="evaluation-detail"]').innerText();
  assert.equal(quoteFailureText.split('AI가 제시한 근거 문구가 해당 원문과 일치하지 않아').length - 1, 2, 'Both progress and history need human-readable evidence failure guidance');
  assert.doesNotMatch(quoteFailureText, /\$\.findings|not a substring|anchored field/, 'Internal validation paths must not leak into teacher-facing failure text');
  assert.equal(await page.getByRole('button', { name: '다시 시도', exact: true }).isEnabled(), true);
  await page.screenshot({ path: path.join(artifactDir, `worksheet-evidence-failure-${label}.png`), fullPage: true });
  const schemaFailures = [
    ['output_schema_invalid', '$ (key set mismatch)', '필수 항목이 빠졌거나 허용하지 않는 항목'],
    ['output_schema_invalid', '$.dimensions[0].confidence', '0부터 1 사이의 숫자'],
    ['output_domain_invalid', '$.findings[0].confidence', '0부터 1 사이의 숫자'],
    ['output_schema_invalid', '$.source_hash', '원본 식별 정보'],
    ['output_schema_invalid', '$.dimensions', '평가 항목별 AI 결과'],
    ['output_schema_invalid', '$.findings[0].evidence[1].quote', '근거의 구성·원문 위치 또는 인용문 형식'],
    ['output_schema_invalid', '$.findings[0] (key set mismatch)', '지적 사항의 구성 또는 값'],
    ['output_schema_invalid', '$.unexpected_private_key.secret-value', '필수 항목 또는 자료형'],
    ['output_domain_invalid', '$.dimensions[custom_private_key].rating (consistency with findings/limitations)', '평가 항목별 AI 결과'],
  ];
  for (const [code, failurePath, expected] of schemaFailures) {
    const query = new URLSearchParams({ schema_failure: '1', failure_code: code, failure_path: failurePath });
    await page.goto(`${fixtureUrl}?${query}`, { waitUntil: 'load' });
    await page.locator('[data-action="select-evaluation"]').filter({ hasText: '저작권 보호하기' }).click();
    const detail = page.locator('[data-role="evaluation-detail"]');
    await detail.getByRole('heading', { name: '저작권 보호하기', exact: true }).waitFor();
    const failureText = await detail.innerText();
    assert.equal(failureText.split(expected).length - 1, 2, `${label}: progress and history should classify ${code}`);
    assert.doesNotMatch(failureText, /\$\.|key set mismatch|private_key|secret-value|consistency with/, 'No diagnostic paths or values may leak');
    assert.equal(await page.getByRole('button', { name: '다시 시도', exact: true }).isEnabled(), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1), false);
  }
  await page.screenshot({ path: path.join(artifactDir, `worksheet-schema-failure-${label}.png`), fullPage: true });
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] evaluation ${label} ${JSON.stringify(layout)}`);
}

async function inspectHistory(browser, viewport, label) {
  const page = await browser.newPage({ viewport });
  const failures = [];
  page.on('pageerror', error => failures.push(error.message));
  try {
    await page.goto(`${fixtureUrl}?history=1&worksheet_id=${contextualWorksheetId}`);
    const detail = page.locator('[data-role="evaluation-detail"]');
    await detail.getByRole('heading', { name: '우리 동네 환경 조사', exact: true }).waitFor();
    assert.equal(new URL(await page.evaluate(() => window.__lastEvaluationListUrl)).searchParams.get('worksheet_id'), contextualWorksheetId);
    assert.equal(await page.locator('.oj-ai-evaluation__inbox').isVisible(), true, 'Scoped workspace exposes the review history sidebar');
    assert.equal(await page.locator('[data-role="evaluation-list"]').isVisible(), false, 'Scoped workspace must not repeat the worksheet list');
    assert.equal(await page.locator('.oj-ai-evaluation__filters').isVisible(), false);
    assert.equal(await page.locator('[data-action="new-evaluation"]').isVisible(), false, 'Only one contextual start action');
    assert.match(await detail.innerText(), /현재 버전 4/);
    assert.match(await detail.innerText(), /점검 버전 3/);
    assert.equal(await page.getByRole('button', { name: '수정본 점검', exact: true }).count(), 1);
    const history = page.locator('[data-role="evaluation-history"]');
    assert.notEqual(await history.getAttribute('open'), null, 'Scoped review history is open by default');
    await page.waitForFunction(() => document.querySelectorAll('[data-action="select-history"]').length === 1);
    await page.getByRole('button', {name:'이력 더 보기',exact:true}).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-action="select-history"]').length === 2);
    assert.equal(await page.getByRole('button', {name:'이력 더 보기',exact:true}).isVisible(), false);
    assert.equal(await page.evaluate(() => window.__evaluationCommandCount), 0, 'Reading history must not call AI');
    await page.locator('[data-action="select-history"]').filter({hasText:'점검 버전 2'}).click();
    await detail.getByRole('button',{name:'최신 점검 보기',exact:true}).waitFor();
    assert.equal(await page.evaluate(() => document.activeElement?.tagName), 'H2', 'History selection must move keyboard focus to the loaded result');
    assert.match(await detail.innerText(), /점검 버전 2/);
    assert.equal(await detail.getByRole('button',{name:'수정본 점검',exact:true}).count(), 0, 'Past records must lead back to latest');
    await detail.getByRole('button',{name:'최신 점검 보기',exact:true}).click();
    await detail.getByRole('button',{name:'수정본 점검',exact:true}).waitFor();
    await history.locator('summary').click();
    await page.screenshot({path:path.join(artifactDir,`worksheet-history-${label}.png`),fullPage:true});
    await detail.getByRole('button',{name:'수정본 점검',exact:true}).click();
    const dialog=page.locator('[data-role="new-evaluation-dialog"]');
    await dialog.getByRole('heading',{name:'수정본 점검',exact:true}).waitFor();
    await dialog.getByRole('button',{name:'닫기',exact:true}).click();
    await page.evaluate(() => window.__setCurrentWorksheetVersion());
    await detail.getByRole('button',{name:'수정본 점검',exact:true}).click();
    await dialog.locator('[data-field="external-processing-ack"]').check();
    await page.waitForFunction(() => document.querySelector('[data-role="new-evaluation-status"]').textContent.includes('버전 5'));
    await dialog.getByRole('button',{name:'점검 시작',exact:true}).click();
    await page.waitForFunction(() => window.__evaluationCommandCount === 1);
    assert.match(await page.evaluate(()=>window.__evaluateRequestUrl), /01HZY3M4N5P6Q7R8S9T0V1W2XJ\/commands\/evaluate$/, 'Dialog must reload the saved version, never use its old cache');
    await page.waitForFunction(() => !document.querySelector('[data-role="new-evaluation-dialog"]').open);
    await detail.getByRole('button',{name:'수정본 점검',exact:true}).waitFor();
    const readsBeforeReopen = await page.evaluate(() => window.__historyReads.length);
    if (await history.getAttribute('open') === null) {
      await history.locator('summary').click();
      await page.waitForFunction(count => window.__historyReads.length > count, readsBeforeReopen);
    }
    await page.waitForFunction(() => document.querySelector('[data-role="history-list"]').getAttribute('aria-busy') === 'false');
    await page.evaluate(() => { window.__failHistory=true; });
    await page.locator('[data-action="refresh"]').click();
    await history.getByRole('button',{name:'이력 다시 불러오기',exact:true}).waitFor();
    await history.getByRole('button',{name:'이력 다시 불러오기',exact:true}).click();
    await page.waitForFunction(() => document.querySelectorAll('[data-action="select-history"]').length > 0);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth>innerWidth+1), false);
    assert.deepEqual(failures,[]);
    console.log(`[OK] ${label}: scoped latest/history, lazy pagination, original versions, no AI on browsing, fresh version on enqueue`);
  } finally { await page.close(); }
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1440, height: 1000 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await inspectHistory(browser, { width: 1440, height: 1000 }, 'desktop');
  await inspectHistory(browser, { width: 390, height: 844 }, 'mobile');
  await browser.close();
} catch (error) {
  errors.push(`Playwright evaluation workbench check failed: ${error.message}`);
}

if (errors.length > 0) {
  console.error('Worksheet evaluation workbench visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('Worksheet evaluation workbench desktop/mobile visual checks passed.');

import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/assignment-workbench-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
await mkdir(artifactDir, { recursive: true });

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') pageErrors.push(message.text()); });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForFunction(() => document.querySelector('[data-field="context-filter"]')?.options.length === 2);
  await page.selectOption('[data-field="context-filter"]', '0');
  await page.waitForFunction(() => !document.querySelector('[data-action="new"]')?.disabled && document.querySelectorAll('[data-role="assignment-list"] tr').length === 1);

  await page.getByRole('button', { name: '새 배포', exact: true }).click();
  const focused = await page.evaluate(() => document.activeElement?.getAttribute('data-field'));
  if (focused !== 'publication') errors.push(`${label}: the wizard did not focus the publication choice`);
  await page.getByRole('button', { name: '다음', exact: true }).click();
  if ((await page.locator('[data-role="wizard-notice"]').textContent()) !== '활동지를 선택해 주세요.') errors.push(`${label}: missing publication validation`);
  await page.selectOption('[data-field="publication"]', '0');
  await page.getByRole('button', { name: '다음', exact: true }).click();
  if (!(await page.locator('[data-step="2"]').isVisible())) errors.push(`${label}: target step did not open`);
  await page.getByRole('button', { name: '다음', exact: true }).click();
  await page.fill('[data-field="opens-at"]', '2026-08-22T10:30');
  await page.fill('[data-field="due-at"]', '2026-08-22T10:00');
  await page.fill('[data-field="closes-at"]', '2026-08-22T11:00');
  await page.getByRole('button', { name: '다음', exact: true }).click();
  if ((await page.locator('[data-role="wizard-notice"]').textContent()) !== '공개, 마감, 종료 순서를 확인해 주세요.') errors.push(`${label}: missing schedule order validation`);
  await page.fill('[data-field="opens-at"]', '2026-08-22T09:00');
  await page.getByRole('button', { name: '다음', exact: true }).click();
  if ((await page.locator('[data-review="publication"]').textContent()) !== '분수 비교 활동지') errors.push(`${label}: review publication drifted`);
  if ((await page.locator('[data-review="context"]').textContent()) !== '2학년 1반 (수업)') errors.push(`${label}: review Context drifted`);

  const layout = await page.evaluate(() => {
    const wizard = document.querySelector('[data-role="wizard"]');
    const rect = wizard?.getBoundingClientRect();
    const visibleControls = [...wizard.querySelectorAll('button, input, select')].filter((control) => control.getClientRects().length > 0);
    return {
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      wizardOutside: !rect || rect.left < -1 || rect.right > window.innerWidth + 1 || rect.top < -1 || rect.bottom > window.innerHeight + 1,
      controlOutside: visibleControls.some((control) => { const item = control.getBoundingClientRect(); return item.left < -1 || item.right > window.innerWidth + 1; }),
      undersizedButton: visibleControls.filter((control) => control.tagName === 'BUTTON').some((control) => { const item = control.getBoundingClientRect(); return item.height < 37 || item.width < 37; }),
      textLength: wizard?.textContent.trim().length || 0,
    };
  });
  if (layout.horizontalOverflow || layout.wizardOutside || layout.controlOutside || layout.undersizedButton || layout.textLength < 50) errors.push(`${label}: invalid wizard layout ${JSON.stringify(layout)}`);

  await page.getByRole('button', { name: '배포 만들기', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="wizard"]')?.hidden && document.querySelectorAll('[data-role="assignment-list"] tr').length === 2);
  const request = await page.evaluate(() => window.__assignmentRequests[0]);
  const topKeys = Object.keys(request?.body || {}).sort();
  const payloadKeys = Object.keys(request?.body?.payload || {}).sort();
  if (JSON.stringify(topKeys) !== JSON.stringify(['command_id', 'expected', 'payload'])) errors.push(`${label}: C13 top-level body drifted`);
  if (JSON.stringify(payloadKeys) !== JSON.stringify(['context', 'resubmission_policy', 'schedule', 'worksheet_publication_id'])) errors.push(`${label}: C13 payload drifted`);
  if ('learner_user_ids' in (request?.body?.payload || {}) || 'learners' in (request?.body?.payload || {})) errors.push(`${label}: browser supplied a learner roster`);
  if (request?.headers?.['Idempotency-Key'] !== request?.body?.command_id) errors.push(`${label}: command and idempotency tokens diverged`);
  if (!request?.body?.payload?.schedule?.opens_at_utc?.endsWith('Z')) errors.push(`${label}: local schedule was not normalized to UTC`);

  await page.getByRole('button', { name: '지난 배포', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-detail="title"]')?.textContent === '지난 배포');
  await page.getByRole('button', { name: '제출 현황', exact: true }).click();
  await page.waitForFunction(() => document.querySelectorAll('[data-role="submission-rows"] tr').length === 1);
  const routeConfig = await page.evaluate(() => ({
    canAssess: document.querySelector('#oj-ai-assignment-app')?.dataset.canAssessSubmissions,
    assessmentsUrl: document.querySelector('#oj-ai-assignment-app')?.dataset.assessmentsUrl || '',
    linkHref: document.querySelector('[data-action="open-assessments"]')?.getAttribute('href') || '',
  }));
  if (routeConfig.canAssess !== 'true' || !routeConfig.assessmentsUrl.includes('type=submission') || !routeConfig.linkHref.includes('type=submission')) errors.push(`${label}: assessment route or capability is missing`);

  await page.getByRole('button', { name: '보기', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-submission="student"]')?.textContent === '김하늘');
  await page.getByRole('button', { name: 'AI 평가 시작', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-submission="evaluation"]')?.textContent === '요청됨');
  const singleRequest = await page.evaluate(() => window.__assessmentRequests.find((item) => item.kind === 'single'));
  if (!singleRequest || singleRequest.body?.command_id !== singleRequest.headers?.['Idempotency-Key']) errors.push(`${label}: single evaluation idempotency contract drifted`);
  if (singleRequest?.body?.expected?.expected_official_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2X7') errors.push(`${label}: single evaluation revision precondition drifted`);
  if (JSON.stringify(Object.keys(singleRequest?.body?.payload || {})) !== '[]') errors.push(`${label}: single evaluation payload must remain empty`);
  if (!singleRequest?.url?.includes('context_revision=context-r1')) errors.push(`${label}: single evaluation context scope is missing`);

  await page.getByRole('button', { name: '제출 목록', exact: true }).click();
  await page.getByRole('button', { name: '제출 평가 시작', exact: true }).click();
  await page.waitForFunction(() => window.__assessmentRequests.filter((item) => item.kind === 'batch').length === 1);
  await page.waitForFunction(() => document.querySelector('[data-role="submission-notice"]')?.textContent.startsWith('채점 대기 등록 ')
    && document.querySelector('[data-action="evaluate-assignment-submissions"]')?.disabled === false);
  const batchRequest = await page.evaluate(() => window.__assessmentRequests.find((item) => item.kind === 'batch'));
  if (!batchRequest || batchRequest.body?.command_id !== batchRequest.headers?.['Idempotency-Key']) errors.push(`${label}: batch evaluation idempotency contract drifted`);
  const batchPayloadKeys = Object.keys(batchRequest?.body?.payload || {}).sort();
  if (JSON.stringify(batchPayloadKeys) !== JSON.stringify(['item_cursor', 'limit', 'source_cutoff_utc'])) errors.push(`${label}: batch evaluation payload drifted`);
  if (batchRequest?.body?.expected?.expected_state !== 'closed' || batchRequest?.body?.expected?.expected_schedule_version !== 1) errors.push(`${label}: batch evaluation precondition drifted`);
  if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(batchRequest?.body?.payload?.source_cutoff_utc || '')) errors.push(`${label}: batch source cutoff drifted from the UTC storage contract`);
  if ((await page.locator('[data-role="submission-notice"]').textContent()) !== '채점 대기 등록 1명 · 기존 작업 0명 · 제외 0명 · 등록 실패 0명') errors.push(`${label}: batch evaluation completion counts were not announced`);

  await page.getByRole('button', { name: '새 배포', exact: true }).click();
  await page.keyboard.press('Escape');
  if (!(await page.locator('[data-role="wizard"]').evaluate((node) => node.hidden))) errors.push(`${label}: Escape did not close the wizard`);
  const returnedFocus = await page.evaluate(() => document.activeElement?.getAttribute('data-action'));
  if (returnedFocus !== 'new') errors.push(`${label}: close did not return focus to the trigger`);
  await page.screenshot({ path: path.join(artifactDir, `assignment-workbench-${label}.png`), fullPage: true });
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] assignment workbench ${label} ${JSON.stringify(layout)}`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await browser.close();
} catch (error) { errors.push(`Playwright assignment workbench check failed: ${error.message}`); }

if (errors.length > 0) {
  console.error('Assignment workbench visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}
console.log('Assignment workbench desktop/mobile interaction checks passed.');

import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixture = pathToFileURL(path.join(root, 'tests/fixtures/student-result-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
await mkdir(artifactDir, { recursive: true });

const sameKeys = (value, keys) => JSON.stringify(Object.keys(value || {}).sort()) === JSON.stringify([...keys].sort());

async function layoutSnapshot(page) {
  return page.evaluate(() => {
    const surface = document.querySelector('.oj-ai-student-result-page');
    const surfaceRect = surface?.getBoundingClientRect();
    const controls = [...document.querySelectorAll('button, a')].filter((node) => node.getClientRects().length > 0);
    const textNodes = [...document.querySelectorAll('.oj-ai-result__title, .oj-ai-result-score-list__row dt, .oj-ai-result-feedback-item, .oj-ai-result-message__title, .oj-ai-result-pdf__status')];
    const headerChildren = [...document.querySelectorAll('.oj-ai-result__header > *')].map((node) => node.getBoundingClientRect());
    const headerOverlap = headerChildren.length === 2
      && headerChildren[0].left < headerChildren[1].right
      && headerChildren[0].right > headerChildren[1].left
      && headerChildren[0].top < headerChildren[1].bottom
      && headerChildren[0].bottom > headerChildren[1].top;
    return {
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      surfaceOutside: !surfaceRect || surfaceRect.left < -1 || surfaceRect.right > window.innerWidth + 1,
      controlOutside: controls.some((node) => { const rect = node.getBoundingClientRect(); return rect.left < -1 || rect.right > window.innerWidth + 1; }),
      undersizedControl: controls.some((node) => node.matches('button') && node.getBoundingClientRect().height < 40),
      textOverflow: textNodes.some((node) => node.scrollWidth > node.clientWidth + 1),
      headerOverlap,
    };
  });
}

async function openState(browser, viewport, state, label, pageErrors, pdf = 'ready') {
  const expectedState = ['score_only', 'feedback_only'].includes(state)
    ? 'released'
    : (state === 'invalid' ? 'error' : (state === 'stale' ? 'updating' : state));
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(`${state}: ${error.message}`));
  page.on('console', (message) => { if (message.type() === 'error') pageErrors.push(`${state}: ${message.text()}`); });
  await page.goto(`${fixture}?state=${state}&pdf=${pdf}`, { waitUntil: 'load' });
  try {
    await page.waitForFunction((expected) => document.getElementById('oj-ai-student-result-app')?.dataset.state === expected, expectedState, { timeout: 5000 });
  } catch (error) {
    const evidence = await page.evaluate(() => ({
      state: document.getElementById('oj-ai-student-result-app')?.dataset.state || '',
      text: document.getElementById('oj-ai-student-result-app')?.innerText || '',
      requests: window.__studentResultRequests || [],
    }));
    throw new Error(`${state} expected ${expectedState}: ${error.message}; ${JSON.stringify(evidence)}`);
  }
  const layout = await layoutSnapshot(page);
  if (Object.values(layout).some(Boolean)) errors.push(`${label} ${state}: invalid layout ${JSON.stringify(layout)}`);
  return { page, layout };
}

async function inspectViewport(browser, viewport, label) {
  const pageErrors = [];
  const { page: released, layout: releasedLayout } = await openState(browser, viewport, 'released', label, pageErrors);
  if ((await released.getByRole('heading', { name: '자료 해석 활동지', exact: true }).count()) !== 1) errors.push(`${label}: result title is not an accessible heading`);
  if ((await released.getByText('공개된 결과', { exact: true }).count()) !== 1) errors.push(`${label}: released state badge is missing`);
  if ((await released.getByText('8', { exact: true }).count()) !== 1) errors.push(`${label}: visible total score drifted`);
  if ((await released.locator('.oj-ai-result-score-list__row').count()) !== 2) errors.push(`${label}: criterion score rows drifted`);
  if ((await released.locator('.oj-ai-result-feedback-item').count()) !== 2) errors.push(`${label}: criterion feedback rows drifted`);
  if ((await released.locator('.oj-ai-result-next-steps li').count()) !== 2) errors.push(`${label}: next-step list drifted`);
  if ((await released.getByRole('button', { name: 'PDF 만들기', exact: true }).count()) !== 1) errors.push(`${label}: result PDF create command is missing`);
  if ((await released.getByRole('button', { name: '다시 불러오기', exact: true }).count()) !== 1) errors.push(`${label}: direct result refresh command is missing`);
  const request = await released.evaluate(() => window.__studentResultRequests.find((entry) => entry.url.endsWith('/me/results/1A040G2081040G2081040G2081')));
  if (!request?.url.endsWith('/me/results/1A040G2081040G2081040G2081')) errors.push(`${label}: Q33 request path drifted`);
  if (!sameKeys(request?.headers, ['Accept', 'X-WP-Nonce']) || request?.headers?.Accept !== 'application/json' || request?.headers?.['X-WP-Nonce'] !== 'nonce-g4-result') errors.push(`${label}: Q33 private request headers drifted`);
  const visibleText = await released.locator('#oj-ai-student-result-app').innerText();
  for (const privateText of ['teacher_memo', 'student_note', 'provider_key', 'model_key', 'answer_key']) {
    if (visibleText.includes(privateText)) errors.push(`${label}: result UI exposed ${privateText}`);
  }
  await released.screenshot({ path: path.join(artifactDir, `student-result-released-${label}.png`), fullPage: true });

  await released.getByRole('button', { name: 'PDF 만들기', exact: true }).click();
  await released.waitForFunction(() => document.querySelector('[data-role="result-pdf-button"]')?.textContent === 'PDF 다운로드', null, { timeout: 5000 });
  const pdfEvidence = await released.evaluate(() => ({
    requests: window.__studentResultRequests,
    status: document.querySelector('[data-role="result-pdf-status"]')?.textContent || '',
  }));
  const enqueue = pdfEvidence.requests.find((item) => item.method === 'POST' && item.url.includes('/commands/export-pdf'));
  if (!enqueue?.url.endsWith('/me/results/1A040G2081040G2081040G2081/commands/export-pdf')) errors.push(`${label}: C52 route drifted`);
  if (!sameKeys(enqueue?.body, ['command_id', 'expected', 'payload'])) errors.push(`${label}: C52 top-level body drifted`);
  if (!sameKeys(enqueue?.body?.expected, ['expected_release_hash', 'expected_release_state'])
    || enqueue?.body?.expected?.expected_release_hash !== 'a'.repeat(64)
    || enqueue?.body?.expected?.expected_release_state !== 'released') errors.push(`${label}: C52 current release precondition drifted`);
  if (!sameKeys(enqueue?.body?.payload, ['locale', 'profile'])
    || enqueue?.body?.payload?.locale !== 'ko-KR' || enqueue?.body?.payload?.profile !== 'student_result') errors.push(`${label}: C52 payload drifted`);
  if (enqueue?.headers?.['Idempotency-Key'] !== enqueue?.body?.command_id
    || enqueue?.headers?.Accept !== 'application/json'
    || enqueue?.headers?.['Content-Type'] !== 'application/json'
    || enqueue?.headers?.['X-WP-Nonce'] !== 'nonce-g4-result') errors.push(`${label}: C52 private command headers drifted`);
  const jobRequest = pdfEvidence.requests.find((item) => item.method === 'GET' && item.url.includes('/export-jobs/'));
  if (!jobRequest?.url.endsWith('/export-jobs/1A040G2081040G2081040G2082')
    || !sameKeys(jobRequest?.headers, ['Accept', 'X-WP-Nonce'])) errors.push(`${label}: Q23 polling request drifted`);
  if (pdfEvidence.status !== '준비됨') errors.push(`${label}: ready PDF status copy drifted`);
  const readyLayout = await layoutSnapshot(released);
  if (Object.values(readyLayout).some(Boolean)) errors.push(`${label}: ready PDF layout invalid ${JSON.stringify(readyLayout)}`);
  await released.screenshot({ path: path.join(artifactDir, `student-result-pdf-ready-${label}.png`), fullPage: true });

  await released.getByRole('button', { name: 'PDF 다운로드', exact: true }).click();
  await released.waitForFunction(() => document.querySelector('[data-role="result-pdf-status"]')?.textContent === '다운로드됨');
  const downloadRequest = await released.evaluate(() => window.__studentResultRequests.find((item) => item.url.includes('/artifacts/')));
  if (!downloadRequest?.url.endsWith('/artifacts/1A040G2081040G2081040G2083/download')
    || downloadRequest?.headers?.Accept !== 'application/pdf'
    || downloadRequest?.headers?.['X-WP-Nonce'] !== 'nonce-g4-result') errors.push(`${label}: Q24 private download request drifted`);
  await released.screenshot({ path: path.join(artifactDir, `student-result-pdf-downloaded-${label}.png`), fullPage: true });

  await released.evaluate(() => {
    window.__setStudentResultMode('forbidden');
    document.querySelector('[data-action="reload-result"]')?.click();
    window.__studentResultImmediate = {
      oldResultCount: document.querySelectorAll('.oj-ai-result').length,
      oldFeedbackCount: document.querySelectorAll('.oj-ai-result-feedback-item').length,
      oldPdfCount: document.querySelectorAll('.oj-ai-result-pdf').length,
      state: document.getElementById('oj-ai-student-result-app')?.dataset.state,
    };
  });
  const immediate = await released.evaluate(() => window.__studentResultImmediate);
  if (JSON.stringify(immediate) !== JSON.stringify({ oldResultCount: 0, oldFeedbackCount: 0, oldPdfCount: 0, state: 'loading' })) errors.push(`${label}: permission refresh did not clear the prior body immediately: ${JSON.stringify(immediate)}`);
  await released.waitForFunction(() => document.getElementById('oj-ai-student-result-app')?.dataset.state === 'unavailable');
  if ((await released.getByText('결과를 확인할 수 없습니다.', { exact: true }).count()) !== 1) errors.push(`${label}: permission-loss copy drifted`);
  if ((await released.locator('.oj-ai-result, .oj-ai-result-feedback-item, .oj-ai-result-total').count()) !== 0) errors.push(`${label}: permission loss retained released content`);
  await released.screenshot({ path: path.join(artifactDir, `student-result-permission-revoked-${label}.png`), fullPage: true });
  await released.close();

  const { page: corrected } = await openState(browser, viewport, 'corrected', label, pageErrors);
  if ((await corrected.getByText('수정된 결과', { exact: true }).count()) !== 1) errors.push(`${label}: corrected state is not visible`);
  if ((await corrected.locator('.oj-ai-result-feedback-item').count()) !== 2) errors.push(`${label}: corrected projection did not replace the full body`);
  if ((await corrected.getByRole('button', { name: 'PDF 만들기', exact: true }).count()) !== 1) errors.push(`${label}: corrected release cannot create its own PDF`);
  await corrected.screenshot({ path: path.join(artifactDir, `student-result-corrected-${label}.png`), fullPage: true });
  await corrected.close();

  const { page: stale } = await openState(browser, viewport, 'stale', label, pageErrors);
  if ((await stale.getByText('결과를 업데이트하고 있어요.', { exact: true }).count()) !== 1) errors.push(`${label}: stale safe state copy drifted`);
  if ((await stale.locator('.oj-ai-result').count()) !== 0) errors.push(`${label}: stale state exposed a result body`);
  await stale.evaluate(() => { window.__setStudentResultMode('corrected'); });
  await stale.getByRole('button', { name: '다시 불러오기', exact: true }).click();
  await stale.waitForFunction(() => document.getElementById('oj-ai-student-result-app')?.dataset.state === 'corrected');
  if ((await stale.getByText('수정된 결과', { exact: true }).count()) !== 1) errors.push(`${label}: stale retry did not load the current correction`);
  await stale.close();

  const { page: withdrawn } = await openState(browser, viewport, 'withdrawn', label, pageErrors);
  if ((await withdrawn.getByText('공개가 종료된 결과예요.', { exact: true }).count()) !== 1) errors.push(`${label}: withdrawn safe state copy drifted`);
  const withdrawnText = await withdrawn.locator('#oj-ai-student-result-app').innerText();
  if (withdrawnText.includes('자료 해석 활동지') || withdrawnText.includes('자료를 정확히')) errors.push(`${label}: withdrawn state leaked prior projection text`);
  await withdrawn.screenshot({ path: path.join(artifactDir, `student-result-withdrawn-${label}.png`), fullPage: true });
  await withdrawn.close();

  const { page: expired } = await openState(browser, viewport, 'released', label, pageErrors, 'expired');
  await expired.getByRole('button', { name: 'PDF 만들기', exact: true }).click();
  await expired.waitForFunction(() => document.querySelector('[data-role="result-pdf-button"]')?.textContent === '다시 만들기');
  if ((await expired.getByText('PDF가 만료되었습니다.', { exact: true }).count()) !== 1) errors.push(`${label}: Q23 expiry did not expose the remake state`);
  const expiredLayout = await layoutSnapshot(expired);
  if (Object.values(expiredLayout).some(Boolean)) errors.push(`${label}: expired PDF layout invalid ${JSON.stringify(expiredLayout)}`);
  await expired.screenshot({ path: path.join(artifactDir, `student-result-pdf-expired-${label}.png`), fullPage: true });
  await expired.close();

  const { page: downloadExpired } = await openState(browser, viewport, 'released', label, pageErrors, 'download_expired');
  await downloadExpired.getByRole('button', { name: 'PDF 만들기', exact: true }).click();
  await downloadExpired.waitForFunction(() => document.querySelector('[data-role="result-pdf-button"]')?.textContent === 'PDF 다운로드');
  await downloadExpired.getByRole('button', { name: 'PDF 다운로드', exact: true }).click();
  await downloadExpired.waitForFunction(() => document.querySelector('[data-role="result-pdf-button"]')?.textContent === '다시 만들기');
  if ((await downloadExpired.getByText('PDF가 만료되었습니다.', { exact: true }).count()) !== 1) errors.push(`${label}: Q24 expiry did not return to remake`);
  await downloadExpired.close();

  const { page: revokedPdf } = await openState(browser, viewport, 'released', label, pageErrors, 'job_revoked');
  await revokedPdf.getByRole('button', { name: 'PDF 만들기', exact: true }).click();
  await revokedPdf.waitForFunction(() => document.getElementById('oj-ai-student-result-app')?.dataset.state === 'withdrawn');
  if ((await revokedPdf.locator('.oj-ai-result, .oj-ai-result-pdf').count()) !== 0) errors.push(`${label}: revoked Q23 retained result or PDF controls`);
  await revokedPdf.close();

  const { page: correctedDuringPdf } = await openState(browser, viewport, 'released', label, pageErrors, 'enqueue_conflict');
  await correctedDuringPdf.getByRole('button', { name: 'PDF 만들기', exact: true }).click();
  await correctedDuringPdf.waitForFunction(() => document.getElementById('oj-ai-student-result-app')?.dataset.state === 'corrected');
  if ((await correctedDuringPdf.getByText('수정된 결과', { exact: true }).count()) !== 1
    || (await correctedDuringPdf.getByRole('button', { name: 'PDF 만들기', exact: true }).count()) !== 1) errors.push(`${label}: C52 source drift did not reload the corrected result`);
  await correctedDuringPdf.close();

  for (const state of ['score_only', 'feedback_only']) {
    const { page } = await openState(browser, viewport, state, label, pageErrors);
    if (state === 'score_only') {
      if ((await page.locator('.oj-ai-result-total').count()) !== 1 || (await page.locator('.oj-ai-result-score-list__row, .oj-ai-result-feedback').count()) !== 0) errors.push(`${label}: total-only projection visibility drifted`);
    } else if ((await page.locator('.oj-ai-result-score-section').count()) !== 0 || (await page.locator('.oj-ai-result-feedback').count()) !== 1) {
      errors.push(`${label}: feedback-only projection visibility drifted`);
    }
    await page.close();
  }

  const { page: invalid } = await openState(browser, viewport, 'invalid', label, pageErrors);
  if ((await invalid.locator('.oj-ai-result').count()) !== 0 || (await invalid.getByText('잠시 후 다시 확인해 주세요.', { exact: true }).count()) !== 1) errors.push(`${label}: invalid Q33 payload did not fail closed`);
  if ((await invalid.locator('body').innerText()).includes('must never render')) errors.push(`${label}: invalid private payload reached visible text`);
  await invalid.close();

  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  console.log(`[OK] student result ${label} ${JSON.stringify({ releasedLayout })}`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectViewport(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectViewport(browser, { width: 390, height: 844 }, 'mobile');
  await browser.close();
} catch (error) {
  errors.push(`Playwright student result check failed: ${error.message}`);
}

if (errors.length > 0) {
  console.error('Student result visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}
console.log('Student result desktop/mobile, C52/Q23/Q24 PDF states, correction, withdrawal, expiry, permission-loss, and projection-visibility checks passed.');

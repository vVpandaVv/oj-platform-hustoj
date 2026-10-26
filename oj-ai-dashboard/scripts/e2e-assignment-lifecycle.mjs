import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/assignment-lifecycle-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
await mkdir(artifactDir, { recursive: true });

const sameKeys = (value, keys) => JSON.stringify(Object.keys(value || {}).sort()) === JSON.stringify([...keys].sort());
const publicationHash = 'a'.repeat(64);
const submissionId = '11040G2081040G2081040G2081';
const officialRevisionId = '12040G2081040G2081040G2082';
const officialRevisionHash = 'd'.repeat(64);
const attachmentSetHash = 'e'.repeat(64);

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport, timezoneId: 'Asia/Seoul' });
  await page.addInitScript(() => { Date.now = () => Date.parse('2026-08-22T09:30:00Z'); });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') pageErrors.push(message.text()); });
  page.on('dialog', (dialog) => dialog.accept());
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForFunction(() => document.querySelector('[data-field="context-filter"]')?.options.length === 2);
  await page.selectOption('[data-field="context-filter"]', '0');
  await page.waitForFunction(() => document.querySelectorAll('[data-role="assignment-list"] tr').length === 1);

  await page.getByRole('button', { name: '분수 비교 활동지', exact: true }).click();
  await page.waitForFunction(() => !document.querySelector('[data-role="assignment-detail"]')?.hidden);
  if ((await page.locator('[data-detail="state"]').textContent()) !== '예약') errors.push(`${label}: Q12 detail state drifted`);
  if ((await page.locator('[data-role="target-list"] li').count()) !== 3) errors.push(`${label}: Q12 target projection drifted`);

  await page.locator('[data-role="detail-actions"] [data-command="update_schedule"]').click();
  await page.waitForFunction(() => !document.querySelector('[data-role="lifecycle-dialog"]')?.hidden);
  if ((await page.locator('[data-field="lifecycle-due"]').inputValue()) !== '2026-08-22T19:00') errors.push(`${label}: UTC schedule was not projected into local time`);
  await page.fill('[data-field="lifecycle-due"]', '2026-08-22T20:00');
  await page.fill('[data-field="lifecycle-closes"]', '2026-08-22T21:00');

  const dialogLayout = await page.evaluate(() => {
    const dialog = document.querySelector('[data-role="lifecycle-dialog"]');
    const rect = dialog?.getBoundingClientRect();
    const controls = [...dialog.querySelectorAll('button, input')].filter((control) => control.getClientRects().length > 0);
    return {
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      dialogOutside: !rect || rect.left < -1 || rect.right > window.innerWidth + 1 || rect.top < -1 || rect.bottom > window.innerHeight + 1,
      controlOutside: controls.some((control) => { const item = control.getBoundingClientRect(); return item.left < -1 || item.right > window.innerWidth + 1; }),
      undersizedButton: controls.filter((control) => control.tagName === 'BUTTON').some((control) => { const item = control.getBoundingClientRect(); return item.height < 37 || item.width < 37; }),
    };
  });
  if (Object.values(dialogLayout).some(Boolean)) errors.push(`${label}: invalid lifecycle dialog layout ${JSON.stringify(dialogLayout)}`);

  await page.locator('[data-action="save-lifecycle"]').click();
  await page.waitForFunction(() => window.__lifecycleRequests.length === 1 && document.querySelector('[data-role="lifecycle-dialog"]')?.hidden);
  const update = await page.evaluate(() => window.__lifecycleRequests[0]);
  if (update?.action !== 'update-schedule') errors.push(`${label}: C14 route drifted`);
  if (update?.url.includes('limit=')) errors.push(`${label}: lifecycle scope query leaked a list parameter`);
  if (!sameKeys(update?.body, ['command_id', 'expected', 'payload'])) errors.push(`${label}: C14 top-level body drifted`);
  if (!sameKeys(update?.body?.expected, ['expected_state', 'expected_lock_version', 'expected_schedule_version', 'expected_schedule_hash'])) errors.push(`${label}: C14 CAS body drifted`);
  if (!sameKeys(update?.body?.payload, ['schedule']) || !sameKeys(update?.body?.payload?.schedule, ['opens_at_utc', 'due_at_utc', 'closes_at_utc', 'auto_submit_enabled'])) errors.push(`${label}: C14 schedule body drifted`);
  if (update?.body?.expected?.expected_state !== 'scheduled' || update?.body?.expected?.expected_lock_version !== 1 || update?.body?.expected?.expected_schedule_version !== 1) errors.push(`${label}: C14 expected snapshot drifted`);
  if (update?.headers?.['Idempotency-Key'] !== update?.body?.command_id) errors.push(`${label}: C14 idempotency token drifted`);

  await page.locator('[data-role="detail-actions"] [data-command="open"]').click();
  const confirmation = page.getByRole('dialog', { name: '배포를 여시겠습니까?', exact: true });
  await confirmation.getByRole('button', { name: '취소', exact: true }).click();
  if ((await page.evaluate(() => window.__lifecycleRequests.length)) !== 1) errors.push(`${label}: cancelled opening sent a command`);
  await page.locator('[data-role="detail-actions"] [data-command="open"]').click();
  await confirmation.getByRole('button', { name: '배포 열기', exact: true }).click();
  await page.waitForFunction(() => window.__lifecycleRequests.length === 2 && document.querySelector('[data-detail="state"]')?.textContent === '진행');
  const open = await page.evaluate(() => window.__lifecycleRequests[1]);
  if (open?.action !== 'open' || !sameKeys(open?.body?.payload, [])) errors.push(`${label}: C15 empty payload drifted`);
  if (open?.body?.expected?.expected_lock_version !== 2 || open?.body?.expected?.expected_schedule_version !== 2 || open?.body?.expected?.expected_schedule_hash !== 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc') errors.push(`${label}: C15 did not use the refreshed CAS snapshot`);
  if ((await page.locator('[data-role="detail-actions"] [data-command="close"]').count()) !== 1) errors.push(`${label}: open state actions did not refresh`);

  await page.getByRole('button', { name: '제출 현황', exact: true }).click();
  await page.waitForFunction(() => document.querySelectorAll('[data-role="submission-rows"] tr').length === 3);
  if (!(await page.locator('[data-role="submission-rows"]').textContent()).includes('시작 전')) errors.push(`${label}: Q13 omitted a not-started target`);
  await page.locator('[data-role="submission-rows"] [data-action="view-submission"]').first().click();
  await page.waitForFunction(() => !document.querySelector('[data-role="submission-detail"]')?.hidden);
  if (!(await page.locator('[data-role="submission-answers"]').textContent()).includes('3/4가 2/3보다 큽니다.')) errors.push(`${label}: Q14 official answer was not rendered`);
  if (!(await page.locator('[data-role="submission-history"]').textContent()).includes('제출본')) errors.push(`${label}: Q15 official audit marker was not rendered`);

  const pdfButton = page.getByRole('button', { name: 'PDF', exact: true });
  if ((await pdfButton.getAttribute('title')) !== 'PDF 만들기') errors.push(`${label}: the submission PDF command is not concise or discoverable`);
  await pdfButton.click();
  await page.waitForFunction(() => document.querySelector('[data-role="submission-actions"] .oj-ai-assignment__action-status')?.textContent === 'PDF 준비 중');
  const pendingPdfLayout = await page.evaluate(() => {
    const actions = document.querySelector('[data-role="submission-actions"]');
    const rect = actions?.getBoundingClientRect();
    const button = actions?.querySelector('[data-action="export-submission-pdf"]');
    const status = actions?.querySelector('.oj-ai-assignment__action-status');
    const buttonRect = button?.getBoundingClientRect();
    const statusRect = status?.getBoundingClientRect();
    return {
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      actionsOutside: !rect || rect.left < -1 || rect.right > window.innerWidth + 1,
      controlOutside: !buttonRect || buttonRect.left < -1 || buttonRect.right > window.innerWidth + 1,
      statusOutside: !statusRect || statusRect.left < -1 || statusRect.right > window.innerWidth + 1,
      buttonEnabled: !button?.disabled,
      statusText: status?.textContent || '',
    };
  });
  if (pendingPdfLayout.horizontalOverflow || pendingPdfLayout.actionsOutside || pendingPdfLayout.controlOutside || pendingPdfLayout.statusOutside || pendingPdfLayout.buttonEnabled || pendingPdfLayout.statusText !== 'PDF 준비 중') {
    errors.push(`${label}: invalid pending submission PDF layout ${JSON.stringify(pendingPdfLayout)}`);
  }
  await page.screenshot({ path: path.join(artifactDir, `assignment-submission-pdf-pending-${label}.png`), fullPage: true });

  const pdfEnqueue = await page.evaluate(() => window.__submissionPdfRequests[0]);
  if (!pdfEnqueue || !pdfEnqueue.url.includes(`/submission-revisions/${officialRevisionId}/commands/export-pdf?`) || pdfEnqueue.url.includes('limit=')) errors.push(`${label}: C49 route drifted`);
  if (!sameKeys(pdfEnqueue?.body, ['command_id', 'expected', 'payload'])) errors.push(`${label}: C49 top-level body drifted`);
  if (!sameKeys(pdfEnqueue?.body?.expected, ['expected_revision_id', 'expected_revision_hash', 'expected_publication_hash', 'expected_attachment_set_hash', 'expected_submission_id', 'expected_submission_state', 'expected_submission_lock_version', 'expected_context_revision'])) errors.push(`${label}: C49 expected body drifted`);
  if (!sameKeys(pdfEnqueue?.body?.payload, ['profile']) || pdfEnqueue?.body?.payload?.profile !== 'teacher_submission') errors.push(`${label}: C49 profile drifted`);
  if (pdfEnqueue?.body?.expected?.expected_revision_id !== officialRevisionId || pdfEnqueue?.body?.expected?.expected_revision_hash !== officialRevisionHash) errors.push(`${label}: C49 official revision evidence drifted`);
  if (pdfEnqueue?.body?.expected?.expected_submission_id !== submissionId || pdfEnqueue?.body?.expected?.expected_submission_state !== 'submitted' || pdfEnqueue?.body?.expected?.expected_submission_lock_version !== 4) errors.push(`${label}: C49 submission CAS drifted`);
  if (pdfEnqueue?.body?.expected?.expected_context_revision !== 'context-r1' || pdfEnqueue?.body?.expected?.expected_publication_hash !== publicationHash || pdfEnqueue?.body?.expected?.expected_attachment_set_hash !== attachmentSetHash) errors.push(`${label}: C49 frozen source evidence drifted`);
  if (pdfEnqueue?.headers?.['Idempotency-Key'] !== pdfEnqueue?.body?.command_id) errors.push(`${label}: C49 idempotency token drifted`);

  await page.evaluate(() => { window.__submissionPdfReady = true; });
  await page.waitForFunction(() => document.querySelector('[data-role="submission-actions"] .oj-ai-assignment__action-status')?.textContent === 'PDF 준비됨' && window.__submissionPdfDownloads.filter((item) => item.kind === 'request').length === 1 && window.__submissionPdfDownloads.filter((item) => item.kind === 'browser').length === 1);
  const pdfEvidence = await page.evaluate(() => ({ polls: window.__submissionPdfPolls, downloads: window.__submissionPdfDownloads, requests: window.__submissionPdfRequests }));
  if (pdfEvidence.polls.length < 2 || pdfEvidence.polls[0]?.ready !== false || !pdfEvidence.polls.some((item) => item.ready === true)) errors.push(`${label}: Q23 polling states drifted`);
  const privateDownload = pdfEvidence.downloads.find((item) => item.kind === 'request');
  const browserDownload = pdfEvidence.downloads.find((item) => item.kind === 'browser');
  if (!privateDownload?.url.endsWith('/artifacts/1A040G2081040G2081040G208A/download')) errors.push(`${label}: Q24 artifact route drifted`);
  if (privateDownload?.headers?.Accept !== 'application/pdf' || privateDownload?.headers?.['X-WP-Nonce'] !== 'nonce-g3-lifecycle') errors.push(`${label}: Q24 authentication headers drifted`);
  if (browserDownload?.name !== 'kim-haneul-submission.pdf') errors.push(`${label}: Q24 filename drifted`);
  if ((await pdfButton.getAttribute('title')) !== 'PDF 내려받기' || await pdfButton.isDisabled()) errors.push(`${label}: ready submission PDF control drifted`);
  await page.screenshot({ path: path.join(artifactDir, `assignment-submission-pdf-ready-${label}.png`), fullPage: true });
  await pdfButton.click();
  await page.waitForFunction(() => window.__submissionPdfDownloads.filter((item) => item.kind === 'request').length === 2);
  if ((await page.evaluate(() => window.__submissionPdfRequests.length)) !== 1) errors.push(`${label}: ready PDF created a duplicate C49 job`);

  await page.getByRole('button', { name: '추가 제출 1회 허용', exact: true }).click();
  await page.waitForFunction(() => !document.querySelector('[data-role="submission-control-dialog"]')?.hidden);
  await page.getByRole('button', { name: '확인', exact: true }).click();
  if ((await page.locator('[data-role="submission-control-notice"]').textContent()) !== '사유를 입력해 주세요.') errors.push(`${label}: C27 accepted an empty reason`);
  await page.fill('[data-field="submission-control-reason"]', '답안을 보완해 다시 제출하세요.');
  const controlLayout = await page.evaluate(() => {
    const dialog = document.querySelector('[data-role="submission-control-dialog"]');
    const rect = dialog?.getBoundingClientRect();
    const controls = [...dialog.querySelectorAll('button, textarea')].filter((control) => control.getClientRects().length > 0);
    return {
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      dialogOutside: !rect || rect.left < -1 || rect.right > window.innerWidth + 1 || rect.top < -1 || rect.bottom > window.innerHeight + 1,
      controlOutside: controls.some((control) => { const item = control.getBoundingClientRect(); return item.left < -1 || item.right > window.innerWidth + 1; }),
      undersizedButton: controls.filter((control) => control.tagName === 'BUTTON').some((control) => { const item = control.getBoundingClientRect(); return item.height < 37 || item.width < 37; }),
    };
  });
  if (Object.values(controlLayout).some(Boolean)) errors.push(`${label}: invalid submission control layout ${JSON.stringify(controlLayout)}`);
  await page.getByRole('button', { name: '확인', exact: true }).click();
  await page.waitForFunction(() => window.__submissionRequests.length === 1 && document.querySelector('[data-submission="state"]')?.textContent === '다시 작성');
  const reopen = await page.evaluate(() => window.__submissionRequests[0]);
  if (reopen?.action !== 'reopen' || reopen?.url.includes('limit=')) errors.push(`${label}: C27 route or scope query drifted`);
  if (!sameKeys(reopen?.body, ['command_id', 'expected', 'payload'])) errors.push(`${label}: C27 top-level body drifted`);
  if (!sameKeys(reopen?.body?.expected, ['expected_state', 'expected_lock_version', 'expected_official_revision_id', 'expected_official_attempt_no', 'expected_schedule_version', 'expected_schedule_hash', 'expected_publication_hash'])) errors.push(`${label}: C27 CAS body drifted`);
  if (!sameKeys(reopen?.body?.payload, ['reason']) || reopen?.body?.payload?.reason !== '답안을 보완해 다시 제출하세요.') errors.push(`${label}: C27 reason body drifted`);
  if (reopen?.body?.expected?.expected_state !== 'submitted' || reopen?.body?.expected?.expected_lock_version !== 4 || reopen?.body?.expected?.expected_official_attempt_no !== 1) errors.push(`${label}: C27 expected snapshot drifted`);
  if (reopen?.body?.expected?.expected_schedule_version !== 2 || reopen?.body?.expected?.expected_schedule_hash !== 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc') errors.push(`${label}: C27 schedule evidence drifted`);
  if (reopen?.body?.expected?.expected_publication_hash !== 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa') errors.push(`${label}: C27 publication evidence drifted`);
  if (reopen?.headers?.['Idempotency-Key'] !== reopen?.body?.command_id) errors.push(`${label}: C27 idempotency token drifted`);
  if (!(await page.locator('[data-role="submission-history"]').textContent()).includes('답안을 보완해 다시 제출하세요.')) errors.push(`${label}: C27 reason was not added to the audit view`);

  await page.getByRole('button', { name: '잠금', exact: true }).click();
  await page.fill('[data-field="submission-control-reason"]', '제출본을 확정합니다.');
  await page.getByRole('button', { name: '확인', exact: true }).click();
  await page.waitForFunction(() => window.__submissionRequests.length === 2 && document.querySelector('[data-submission="state"]')?.textContent === '잠김');
  const lock = await page.evaluate(() => window.__submissionRequests[1]);
  if (lock?.action !== 'lock' || lock?.url.includes('limit=')) errors.push(`${label}: C28 route or scope query drifted`);
  if (lock?.body?.expected?.expected_state !== 'reopened' || lock?.body?.expected?.expected_lock_version !== 5) errors.push(`${label}: C28 did not use the refreshed CAS snapshot`);
  if (lock?.headers?.['Idempotency-Key'] !== lock?.body?.command_id) errors.push(`${label}: C28 idempotency token drifted`);
  if (!(await page.locator('[data-role="submission-history"]').textContent()).includes('제출본을 확정합니다.')) errors.push(`${label}: C28 reason was not added to the audit view`);

  const pageLayout = await page.evaluate(() => {
    const detail = document.querySelector('[data-role="assignment-detail"]');
    const rect = detail?.getBoundingClientRect();
    const submissions = document.querySelector('[data-role="submission-panel"]');
    const submissionRect = submissions?.getBoundingClientRect();
    return {
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      detailOutside: !rect || rect.left < -1 || rect.right > window.innerWidth + 1,
      submissionsOutside: !submissionRect || submissionRect.left < -1 || submissionRect.right > window.innerWidth + 1,
    };
  });
  if (Object.values(pageLayout).some(Boolean)) errors.push(`${label}: invalid detail layout ${JSON.stringify(pageLayout)}`);
  await page.screenshot({ path: path.join(artifactDir, `assignment-lifecycle-${label}.png`), fullPage: true });
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] assignment lifecycle ${label} ${JSON.stringify({ dialogLayout, controlLayout, pageLayout })}`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await browser.close();
} catch (error) { errors.push(`Playwright assignment lifecycle check failed: ${error.message}`); }

if (errors.length > 0) {
  console.error('Assignment lifecycle visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}
console.log('Assignment lifecycle desktop/mobile interaction checks passed.');

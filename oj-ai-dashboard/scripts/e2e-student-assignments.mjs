import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixture = pathToFileURL(path.join(root, 'tests/fixtures/student-assignments-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
await mkdir(artifactDir, { recursive: true });

const sameKeys = (value, keys) => JSON.stringify(Object.keys(value || {}).sort()) === JSON.stringify([...keys].sort());

async function layoutSnapshot(page, selector) {
  return page.evaluate((target) => {
    const surface = document.querySelector(target);
    const rect = surface?.getBoundingClientRect();
    const controls = [...document.querySelectorAll('button, a, input, textarea, select, canvas')].filter((node) => node.getClientRects().length > 0);
    return {
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      surfaceOutside: !rect || rect.left < -1 || rect.right > window.innerWidth + 1,
      controlOutside: controls.some((node) => { const item = node.getBoundingClientRect(); return item.left < -1 || item.right > window.innerWidth + 1; }),
      undersizedCommand: controls.filter((node) => node.matches('button, a.oj-ai-student-primary')).some((node) => { const item = node.getBoundingClientRect(); return item.height < 40; }),
    };
  }, selector);
}

async function inspectViewport(browser, viewport, label) {
  const pageErrors = [];
  const listPage = await browser.newPage({ viewport });
  listPage.on('pageerror', (error) => pageErrors.push(error.message));
  listPage.on('console', (message) => { if (message.type() === 'error') pageErrors.push(message.text()); });
  await listPage.goto(fixture, { waitUntil: 'load' });
  await listPage.waitForFunction(() => document.querySelectorAll('.oj-ai-student-card').length === 2);
  if ((await listPage.getByRole('tab').count()) !== 3) errors.push(`${label}: the three task tabs are missing`);
  if ((await listPage.getByRole('tablist', { name: '활동지 상태', exact: true }).count()) !== 1) errors.push(`${label}: status tabs do not have an accessible group name`);
  const todoTitles = await listPage.locator('.oj-ai-student-card__title').allTextContents();
  if (JSON.stringify(todoTitles) !== JSON.stringify(['분수 비교 활동지', '도형 관찰 기록'])) errors.push(`${label}: todo tab drifted: ${JSON.stringify(todoTitles)}`);
  const todoTab = listPage.getByRole('tab', { name: '할 일', exact: true });
  if ((await todoTab.getAttribute('tabindex')) !== '0') errors.push(`${label}: selected tab is not the only tab stop`);
  await todoTab.press('ArrowRight');
  const submittedTab = listPage.getByRole('tab', { name: '제출함', exact: true });
  if (!(await submittedTab.getAttribute('aria-selected') === 'true') || !(await submittedTab.evaluate((node) => node === document.activeElement))) errors.push(`${label}: arrow-key navigation did not select and focus the submitted tab`);
  if ((await listPage.locator('.oj-ai-student-card').count()) !== 1 || (await listPage.locator('.oj-ai-student-card__title').textContent()) !== '자료 해석 활동') errors.push(`${label}: closed unpublished submission must remain in the submitted tab`);
  if ((await listPage.getByRole('link', { name: '제출 보기', exact: true }).count()) !== 1) errors.push(`${label}: unpublished submission link is missing`);
  await listPage.getByRole('tab', { name: '결과', exact: true }).click();
  const resultTitles = await listPage.locator('.oj-ai-student-card__title').allTextContents();
  if (JSON.stringify(resultTitles) !== JSON.stringify(['공개된 마감 활동', '공개된 진행 활동'])) errors.push(`${label}: results must depend on release availability, not deadline`);
  if ((await listPage.locator('.oj-ai-student-status').allTextContents()).some((text) => text !== '결과 공개')) errors.push(`${label}: published results must not be labelled editable`);
  const resultLinks = await listPage.getByRole('link', { name: '결과 확인', exact: true }).evaluateAll((nodes) => nodes.map((node) => node.getAttribute('href')));
  if (JSON.stringify(resultLinks) !== JSON.stringify(['/oj-addons/oj-ai-dashboard/results/00000000000000000000000091', '/oj-addons/oj-ai-dashboard/results/00000000000000000000000092'])) errors.push(`${label}: published result links must target the exact releases`);
  const listLayout = await layoutSnapshot(listPage, '.oj-ai-student-page');
  if (Object.values(listLayout).some(Boolean)) errors.push(`${label}: invalid list layout ${JSON.stringify(listLayout)}`);
  await listPage.screenshot({ path: path.join(artifactDir, `student-assignments-list-${label}.png`), fullPage: true });
  await listPage.close();

  const detailPage = await browser.newPage({ viewport });
  detailPage.on('pageerror', (error) => pageErrors.push(error.message));
  detailPage.on('console', (message) => { if (message.type() === 'error') pageErrors.push(message.text()); });
  await detailPage.goto(`${fixture}?mode=detail`, { waitUntil: 'load' });
  await detailPage.waitForFunction(() => document.querySelector('[data-action="start"]') && document.querySelector('.oj-ai-student-response-anchor'));
  if ((await detailPage.getByRole('textbox', { name: /한 줄 답/ }).count()) !== 1) errors.push(`${label}: short text has no accessible response label`);
  if ((await detailPage.getByRole('textbox', { name: /풀이 과정/ }).count()) !== 1) errors.push(`${label}: long text has no accessible response label`);
  if ((await detailPage.getByRole('img', { name: '그림', exact: true }).count()) !== 1) errors.push(`${label}: drawing response has no accessible name`);
  const kinds = await detailPage.locator('.oj-ai-student-response-anchor').evaluateAll((nodes) => nodes.map((node) => node.dataset.responseKind));
  const expectedKinds = ['short_text','long_text','single_choice','multiple_choice','checkbox','number','table','image_upload','file_upload','drawing'];
  if (JSON.stringify(kinds) !== JSON.stringify(expectedKinds)) errors.push(`${label}: P02 ten-kind renderer drifted: ${JSON.stringify(kinds)}`);
  const questionNav = detailPage.getByRole('navigation', { name: '문항 이동', exact: true });
  if ((await questionNav.count()) !== 1 || (await questionNav.getByRole('button').count()) !== 10) errors.push(`${label}: ten-question navigation is missing`);
  if ((await questionNav.getByRole('status').textContent()) !== '0 / 10 완료') errors.push(`${label}: question progress summary drifted`);
  await questionNav.getByRole('button', { name: /4번/ }).click();
  if ((await questionNav.getByRole('button', { name: /4번/ }).getAttribute('aria-current')) !== 'true') errors.push(`${label}: question navigation did not mark the current response`);
  if ((await detailPage.locator('textarea.oj-ai-student-response-input').count()) !== 1) errors.push(`${label}: long-text control is missing`);
  if ((await detailPage.locator('.oj-ai-student-response-table').count()) !== 1) errors.push(`${label}: table control is missing`);
  if ((await detailPage.locator('input[type="file"]').count()) !== 2) errors.push(`${label}: attachment control placeholders are missing`);
  if ((await detailPage.locator('canvas.oj-ai-student-response-drawing').count()) !== 1) errors.push(`${label}: drawing control is missing`);
  const pdfButton = detailPage.getByRole('button', { name: 'PDF 만들기', exact: true });
  if ((await pdfButton.count()) !== 1) errors.push(`${label}: C51 PDF command is not directly reachable`);
  await pdfButton.click();
  await detailPage.waitForFunction(() => window.__studentPdfRequests.length === 1 && document.querySelector('[data-action="download-pdf"]'));
  const pdfRequest = await detailPage.evaluate(() => window.__studentPdfRequests[0]);
  if (!sameKeys(pdfRequest?.body, ['command_id','expected','payload'])) errors.push(`${label}: C51 top-level body drifted`);
  if (!sameKeys(pdfRequest?.body?.expected, [
    'expected_assignment_lock_version','expected_assignment_state','expected_context_revision',
    'expected_publication_id','expected_publication_hash','expected_target_state',
  ])) errors.push(`${label}: C51 expected binding drifted`);
  if (!sameKeys(pdfRequest?.body?.payload, ['profile']) || pdfRequest?.body?.payload?.profile !== 'student_blank') errors.push(`${label}: C51 print profile drifted`);
  if (pdfRequest?.body?.expected?.expected_assignment_lock_version !== 0
    || pdfRequest?.body?.expected?.expected_assignment_state !== 'open'
    || pdfRequest?.body?.expected?.expected_context_revision !== 'member-r2'
    || pdfRequest?.body?.expected?.expected_publication_id !== '16040G2081040G2081040G2081'
    || pdfRequest?.body?.expected?.expected_publication_hash !== 'a'.repeat(64)
    || pdfRequest?.body?.expected?.expected_target_state !== 'active') errors.push(`${label}: C51 exact assignment publication binding drifted`);
  if (pdfRequest?.headers?.['Idempotency-Key'] !== pdfRequest?.body?.command_id) errors.push(`${label}: C51 idempotency token drifted`);
  const downloadButton = detailPage.getByRole('button', { name: 'PDF 다운로드', exact: true });
  const downloadEvent = detailPage.waitForEvent('download');
  await downloadButton.click();
  const download = await downloadEvent;
  await download.cancel();
  await detailPage.waitForFunction(() => window.__studentPdfDownloads.length === 1);
  const pdfDownload = await detailPage.evaluate(() => window.__studentPdfDownloads[0]);
  if (pdfDownload?.headers?.['X-WP-Nonce'] !== 'nonce-g3-student') errors.push(`${label}: Q24 private download omitted the REST nonce`);
  if ((await detailPage.locator('[data-role="worksheet-pdf-status"]').textContent()) !== '다운로드됨') errors.push(`${label}: PDF download status copy drifted`);

  await detailPage.getByRole('button', { name: '시작', exact: true }).click();
  await detailPage.waitForFunction(() => window.__studentRequests.length === 1 && document.querySelector('[data-action="continue"]'));
  const request = await detailPage.evaluate(() => window.__studentRequests[0]);
  if (!sameKeys(request?.body, ['command_id','expected','payload'])) errors.push(`${label}: C22 top-level body drifted`);
  if (!sameKeys(request?.body?.expected, ['expected_state','expected_lock_version','expected_schedule_version','expected_schedule_hash','expected_publication_hash'])) errors.push(`${label}: C22 expected CAS drifted`);
  if (!sameKeys(request?.body?.payload, [])) errors.push(`${label}: C22 payload must remain exactly empty`);
  if (request?.headers?.['Idempotency-Key'] !== request?.body?.command_id) errors.push(`${label}: C22 idempotency token drifted`);
  await detailPage.getByRole('button', { name: '작성 계속', exact: true }).click();
  const focusClass = await detailPage.evaluate(() => document.activeElement?.className || '');
  if (!String(focusClass).includes('oj-ai-student-response-anchor')) errors.push(`${label}: continue did not focus the first response`);

  const shortAnswer = detailPage.locator('[data-response-id="response-short"] input[type="text"]');
  const longAnswer = detailPage.locator('[data-response-id="response-long"] textarea');
  await detailPage.waitForFunction(() => window.__studentSubmissionReads >= 1 && !document.querySelector('[data-response-id="response-short"]')?.disabled);
  await shortAnswer.fill('내 자동 저장 답');
  await detailPage.waitForFunction(() => window.__studentAutosaveRequests.length >= 1 && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
  const saveSemantics = await detailPage.locator('[data-role="save-indicator"]').evaluate((node) => ({ role: node.getAttribute('role'), live: node.getAttribute('aria-live'), atomic: node.getAttribute('aria-atomic'), busy: node.getAttribute('aria-busy') }));
  if (JSON.stringify(saveSemantics) !== JSON.stringify({ role: 'status', live: 'polite', atomic: 'true', busy: 'false' })) errors.push(`${label}: autosave live-region semantics drifted: ${JSON.stringify(saveSemantics)}`);
  const firstAutosave = await detailPage.evaluate(() => window.__studentAutosaveRequests[0]);
  if (!sameKeys(firstAutosave?.body, ['command_id','expected','payload'])) errors.push(`${label}: C23 top-level body drifted`);
  if (!sameKeys(firstAutosave?.body?.expected, ['expected_state','expected_lock_version','expected_current_revision_id','expected_schedule_version','expected_schedule_hash','expected_publication_hash','expected_save_token'])) errors.push(`${label}: C23 expected CAS drifted`);
  if (!sameKeys(firstAutosave?.body?.payload, ['answers','staged_attachment_ids'])) errors.push(`${label}: C23 payload drifted`);
  if (firstAutosave?.headers?.['Idempotency-Key'] !== firstAutosave?.body?.command_id) errors.push(`${label}: C23 idempotency token drifted`);
  if (firstAutosave?.body?.payload?.answers?.['response-short'] !== '내 자동 저장 답') errors.push(`${label}: C23 did not collect the short-text answer`);
  if (!Array.isArray(firstAutosave?.body?.payload?.staged_attachment_ids) || firstAutosave.body.payload.staged_attachment_ids.length !== 0) errors.push(`${label}: C23 staged attachment IDs drifted`);

  await detailPage.evaluate(() => { window.__studentAutosaveMode = 'offline'; });
  await longAnswer.fill('연결이 끊겨도 보존할 답');
  await detailPage.waitForFunction(() => window.__studentAutosaveRequests.length >= 2 && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'offline');
  const offlineEvidence = await detailPage.evaluate(() => ({
    request: window.__studentAutosaveRequests[1],
    recoveryKeys: Object.keys(localStorage).filter((key) => key.startsWith('oj_ai_submission_recovery_v1:')),
    label: document.querySelector('[data-role="save-label"]')?.textContent || '',
  }));
  if (offlineEvidence.recoveryKeys.length !== 1) errors.push(`${label}: offline draft was not kept in one scoped recovery record`);
  if (offlineEvidence.label !== '연결되면 저장됩니다') errors.push(`${label}: offline status copy drifted`);
  await detailPage.evaluate(() => {
    window.__studentAutosaveMode = 'success';
    window.dispatchEvent(new Event('online'));
  });
  await detailPage.waitForFunction(() => window.__studentAutosaveRequests.length >= 3 && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
  const retryEvidence = await detailPage.evaluate(() => ({ first: window.__studentAutosaveRequests[1], retry: window.__studentAutosaveRequests[2] }));
  if (retryEvidence.first?.body?.command_id !== retryEvidence.retry?.body?.command_id) errors.push(`${label}: offline retry changed the C23 operation ID`);
  if (JSON.stringify(retryEvidence.first?.body) !== JSON.stringify(retryEvidence.retry?.body)) errors.push(`${label}: offline retry changed the exact C23 request body`);

  await detailPage.evaluate(() => { window.__studentAutosaveMode = 'conflict'; });
  await shortAnswer.fill('내 충돌 답');
  await detailPage.waitForFunction(() => window.__studentAutosaveRequests.length >= 4 && document.querySelector('[data-role="save-conflict"]')?.hidden === false);
  if ((await detailPage.getByRole('region', { name: '저장 충돌 해결', exact: true }).count()) !== 1) errors.push(`${label}: conflict resolver has no accessible region name`);
  if ((await detailPage.getByRole('radio', { name: /내 내용/ }).count()) < 1) errors.push(`${label}: conflict choices have no accessible names`);
  const conflictText = await detailPage.locator('[data-role="save-conflict"]').innerText();
  if (!conflictText.includes('내 충돌 답') || !conflictText.includes('서버에서 저장한 답')) errors.push(`${label}: conflict comparison did not preserve both answer copies`);
  if (conflictText.includes('{"') || conflictText.includes('response-short')) errors.push(`${label}: conflict UI exposed raw JSON or response IDs`);
  const conflictLayout = await layoutSnapshot(detailPage, '.oj-ai-student-page');
  if (Object.values(conflictLayout).some(Boolean)) errors.push(`${label}: invalid conflict layout ${JSON.stringify(conflictLayout)}`);
  await detailPage.screenshot({ path: path.join(artifactDir, `student-assignments-conflict-${label}.png`), fullPage: true });
  await detailPage.evaluate(() => { window.__studentAutosaveMode = 'success'; });
  await detailPage.getByRole('button', { name: '내 내용 모두', exact: true }).click();
  await detailPage.getByRole('button', { name: '선택한 내용 저장', exact: true }).click();
  await detailPage.waitForFunction(() => window.__studentAutosaveRequests.length >= 5 && document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
  const resolved = await detailPage.evaluate(() => ({
    request: window.__studentAutosaveRequests.at(-1),
    recoveryKeys: Object.keys(localStorage).filter((key) => key.startsWith('oj_ai_submission_recovery_v1:')),
    conflictHidden: document.querySelector('[data-role="save-conflict"]')?.hidden,
  }));
  if (resolved.request?.body?.expected?.expected_current_revision_id !== String(203).padStart(26, '0')) errors.push(`${label}: conflict resolution did not rebase on the latest server revision`);
  if (resolved.request?.body?.payload?.answers?.['response-short'] !== '내 충돌 답') errors.push(`${label}: conflict resolution lost the selected local answer`);
  if (!resolved.conflictHidden || resolved.recoveryKeys.length !== 0) errors.push(`${label}: resolved conflict did not clear transient recovery state`);

  const detailLayout = await layoutSnapshot(detailPage, '.oj-ai-student-page');
  if (Object.values(detailLayout).some(Boolean)) errors.push(`${label}: invalid detail layout ${JSON.stringify(detailLayout)}`);
  await detailPage.screenshot({ path: path.join(artifactDir, `student-assignments-detail-${label}.png`), fullPage: true });

  await detailPage.evaluate(() => { window.__studentAutosaveMode = 'delayed'; });
  await longAnswer.fill('저장 요청에 포함된 이전 답');
  await detailPage.waitForFunction(() => window.__studentAutosaveRequests.length >= 6 && typeof window.__studentAutosaveRelease === 'function');
  await longAnswer.fill('제출 직전 최신 답');
  detailPage.on('dialog', (dialog) => { errors.push(`${label}: submission must not depend on a browser confirmation`); dialog.dismiss(); });
  await detailPage.getByRole('button', { name: '제출', exact: true }).click();
  const submitDialog = detailPage.getByRole('dialog', { name: '제출 확인', exact: true });
  await submitDialog.waitFor({ timeout: 3000 });
  if ((await detailPage.evaluate(() => window.__studentSubmitRequests.length)) !== 0) errors.push(`${label}: submission happened before explicit confirmation`);
  if (!(await submitDialog.getByRole('button', { name: '계속 작성', exact: true }).evaluate((node) => node === document.activeElement))) errors.push(`${label}: submission confirmation must default to continuing the draft`);
  const dialogLayout = await layoutSnapshot(detailPage, 'dialog');
  if (Object.values(dialogLayout).some(Boolean)) errors.push(`${label}: invalid submission confirmation layout ${JSON.stringify(dialogLayout)}`);
  await detailPage.screenshot({ path: path.join(artifactDir, `student-submit-confirm-${label}.png`) });
  await submitDialog.getByRole('button', { name: '계속 작성', exact: true }).click();
  await submitDialog.waitFor({ state: 'detached' });
  if ((await detailPage.evaluate(() => window.__studentSubmitRequests.length)) !== 0 || (await longAnswer.inputValue()) !== '제출 직전 최신 답') errors.push(`${label}: cancel must preserve the draft without submission`);
  if (!(await detailPage.getByRole('button', { name: '제출', exact: true }).evaluate((node) => node === document.activeElement))) errors.push(`${label}: closing confirmation did not restore focus`);
  await detailPage.getByRole('button', { name: '제출', exact: true }).click();
  await submitDialog.press('Escape');
  await submitDialog.waitFor({ state: 'detached' });
  if ((await detailPage.evaluate(() => window.__studentSubmitRequests.length)) !== 0) errors.push(`${label}: Escape must not submit`);
  await detailPage.getByRole('button', { name: '제출', exact: true }).click();
  await submitDialog.getByRole('button', { name: '제출하기', exact: true }).click();
  await detailPage.waitForFunction(() => document.querySelector('[data-action="submit-assignment"]')?.disabled === true);
  const pendingSubmit = await detailPage.evaluate(() => ({
    submitRequests: window.__studentSubmitRequests.length,
    buttonDisabled: document.querySelector('[data-action="submit-assignment"]')?.disabled === true,
    label: document.querySelector('[data-role="submit-label"]')?.textContent || '',
  }));
  if (pendingSubmit.submitRequests !== 0) errors.push(`${label}: submit did not wait for the active autosave`);
  if (!pendingSubmit.buttonDisabled || pendingSubmit.label !== '최신 답안을 저장한 뒤 제출합니다') errors.push(`${label}: submit preparation state is not visible`);
  await detailPage.evaluate(() => window.__studentAutosaveRelease?.());
  await detailPage.waitForFunction(() => window.__studentAutosaveRequests.length >= 7 && window.__studentSubmitRequests.length === 1 && document.querySelector('[data-role="save-label"]')?.textContent === '제출 완료');
  const submitted = await detailPage.evaluate(() => ({
    request: window.__studentSubmitRequests[0],
    autosave: window.__studentAutosaveRequests.at(-1),
    submitLabel: document.querySelector('[data-role="submit-label"]')?.textContent || '',
    submitHidden: document.querySelector('[data-action="submit-assignment"]')?.hidden === true,
    responseDisabled: document.querySelector('[data-response-id="response-short"] .oj-ai-student-response-input')?.matches(':disabled') === true,
  }));
  if (!sameKeys(submitted.request?.body, ['command_id','expected','payload'])) errors.push(`${label}: C26 top-level body drifted`);
  if (!sameKeys(submitted.request?.body?.expected, ['expected_state','expected_lock_version','expected_current_revision_id','expected_schedule_version','expected_schedule_hash','expected_publication_hash','expected_save_token'])) errors.push(`${label}: C26 expected CAS drifted`);
  if (!sameKeys(submitted.request?.body?.payload, [])) errors.push(`${label}: C26 payload must remain exactly empty`);
  if (submitted.request?.headers?.['Idempotency-Key'] !== submitted.request?.body?.command_id) errors.push(`${label}: C26 idempotency token drifted`);
  if (submitted.autosave?.body?.payload?.answers?.['response-long'] !== '제출 직전 최신 답') errors.push(`${label}: submit lost the latest answer before autosave`);
  if (submitted.request?.body?.expected?.expected_current_revision_id !== String(206).padStart(26, '0')
    || submitted.request?.body?.expected?.expected_lock_version !== 6
    || submitted.request?.body?.expected?.expected_save_token !== '3'.repeat(64)) errors.push(`${label}: C26 did not bind to the completed autosave snapshot`);
  if (submitted.submitLabel !== '제출이 완료되었습니다' || !submitted.submitHidden || !submitted.responseDisabled) errors.push(`${label}: submitted UI did not become final and read-only ${JSON.stringify(submitted)}`);
  if (await detailPage.locator('[data-action="submit-assignment"]').isVisible()) errors.push(`${label}: hidden submit command is still visually displayed`);
  if (await detailPage.locator('.oj-ai-student-primary--start').isVisible()) errors.push(`${label}: stale continue command remains after submission`);
  if (await detailPage.locator('[data-response-id="response-long"] textarea').inputValue() !== '제출 직전 최신 답') errors.push(`${label}: compact submit receipt cleared the visible answer`);
  if (await detailPage.locator('.oj-ai-student-detail__notice').textContent() !== '제출 완료') errors.push(`${label}: submitted header still shows the draft state`);
  await detailPage.screenshot({ path: path.join(artifactDir, `student-submit-final-${label}.png`), fullPage: true });
  await detailPage.close();

  for (const submissionState of ['reopened', 'locked']) {
    const statusPage = await browser.newPage({ viewport });
    statusPage.on('pageerror', (error) => pageErrors.push(`${submissionState}: ${error.message}`));
    statusPage.on('console', (message) => { if (message.type() === 'error') pageErrors.push(`${submissionState}: ${message.text()}`); });
    await statusPage.goto(`${fixture}?mode=detail&submission_state=${submissionState}`, { waitUntil: 'load' });
    await statusPage.waitForFunction(() => document.querySelector('[data-role="submission-status"]'));
    const statusText = await statusPage.locator('[data-role="submission-status"]').innerText();
    if (submissionState === 'reopened') {
      if (!statusText.includes('다시 작성할 수 있습니다') || !statusText.includes('답안을 보완해 다시 제출하세요.')) errors.push(`${label}: reopened reason is missing`);
      if (!statusText.includes('1 / 2회') || !statusText.includes('남은 제출') || !statusText.includes('1회')) errors.push(`${label}: reopened attempt summary is missing`);
      if ((await statusPage.getByRole('button', { name: '작성 계속', exact: true }).count()) !== 1) errors.push(`${label}: reopened work has no direct continue action`);
      if (await statusPage.locator('.oj-ai-student-response-anchor .oj-ai-student-response-input').first().isDisabled()) errors.push(`${label}: reopened response controls stayed locked`);
    } else {
      if (!statusText.includes('제출이 잠겼습니다') || !statusText.includes('검토를 위해 제출을 잠갔습니다.') || !statusText.includes('교사에게 문의하세요.')) errors.push(`${label}: locked state guidance is missing`);
      if ((await statusPage.locator('[data-action="continue"], [data-action="start"]').count()) !== 0) errors.push(`${label}: locked work exposes an edit command`);
      if (!(await statusPage.locator('.oj-ai-student-response-anchor .oj-ai-student-response-input').first().isDisabled())) errors.push(`${label}: locked response controls are editable`);
    }
    if (statusText.includes('control_receipt') || statusText.includes('resubmission_policy_hash') || statusText.includes('submission_revision_hash')) errors.push(`${label}: status UI exposed contract fields`);
    const submissionPdfButton = statusPage.getByRole('button', { name: '작성 결과 PDF 만들기', exact: true });
    if ((await submissionPdfButton.count()) !== 1) errors.push(`${label}: ${submissionState} work has no direct own-result PDF action`);
    await submissionPdfButton.click();
    await statusPage.waitForFunction(() => window.__studentSubmissionPdfRequests.length === 1 && document.querySelector('[data-action="download-submission-pdf"]'));
    const submissionPdfRequest = await statusPage.evaluate(() => window.__studentSubmissionPdfRequests[0]);
    if (!sameKeys(submissionPdfRequest?.body, ['command_id','expected','payload'])) errors.push(`${label}: C67 top-level body drifted`);
    if (!sameKeys(submissionPdfRequest?.body?.expected, [
      'expected_assignment_id','expected_assignment_state','expected_attachment_set_hash','expected_context_revision',
      'expected_official_revision_hash','expected_official_revision_id','expected_publication_hash','expected_publication_id',
      'expected_submission_lock_version','expected_submission_state','expected_target_state',
    ])) errors.push(`${label}: C67 expected binding drifted`);
    if (!sameKeys(submissionPdfRequest?.body?.payload, ['profile']) || submissionPdfRequest?.body?.payload?.profile !== 'teacher_submission') errors.push(`${label}: C67 print profile drifted`);
    const submissionPdfExpected = submissionPdfRequest?.body?.expected || {};
    if (submissionPdfExpected.expected_assignment_id !== '01040G2081040G2081040G2081'
      || submissionPdfExpected.expected_assignment_state !== 'open'
      || submissionPdfExpected.expected_attachment_set_hash !== '5'.repeat(64)
      || submissionPdfExpected.expected_context_revision !== 'member-r2'
      || submissionPdfExpected.expected_official_revision_hash !== '4'.repeat(64)
      || submissionPdfExpected.expected_official_revision_id !== '17040G2081040G2081040G2082'
      || submissionPdfExpected.expected_publication_hash !== 'a'.repeat(64)
      || submissionPdfExpected.expected_publication_id !== '16040G2081040G2081040G2081'
      || submissionPdfExpected.expected_submission_lock_version !== 2
      || submissionPdfExpected.expected_submission_state !== submissionState
      || submissionPdfExpected.expected_target_state !== 'active') errors.push(`${label}: C67 exact own official source binding drifted`);
    if (submissionPdfRequest?.headers?.['Idempotency-Key'] !== submissionPdfRequest?.body?.command_id) errors.push(`${label}: C67 idempotency token drifted`);
    const submissionDownloadButton = statusPage.getByRole('button', { name: '작성 결과 PDF 다운로드', exact: true });
    const submissionDownloadEvent = statusPage.waitForEvent('download');
    await submissionDownloadButton.click();
    const submissionDownload = await submissionDownloadEvent;
    if (submissionDownload.suggestedFilename() !== 'submission-15040G2081040G2081040G2081.pdf') errors.push(`${label}: Q24 own-result filename drifted`);
    await submissionDownload.cancel();
    await statusPage.waitForFunction(() => window.__studentSubmissionPdfDownloads.length === 1);
    const submissionPdfDownload = await statusPage.evaluate(() => window.__studentSubmissionPdfDownloads[0]);
    if (submissionPdfDownload?.headers?.Accept !== 'application/pdf' || submissionPdfDownload?.headers?.['X-WP-Nonce'] !== 'nonce-g3-student') errors.push(`${label}: Q24 own-result download omitted private PDF headers`);
    if ((await statusPage.locator('[data-role="submission-pdf-status"]').textContent()) !== '다운로드됨') errors.push(`${label}: own-result PDF download status copy drifted`);
    const statusLayout = await layoutSnapshot(statusPage, '.oj-ai-student-page');
    if (Object.values(statusLayout).some(Boolean)) errors.push(`${label}: invalid ${submissionState} status layout ${JSON.stringify(statusLayout)}`);
    await statusPage.screenshot({ path: path.join(artifactDir, `student-submission-${submissionState}-${label}.png`), fullPage: true });
    await statusPage.close();
  }

  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  console.log(`[OK] student assignments ${label} ${JSON.stringify({ listLayout, conflictLayout, detailLayout })}`);
}

async function inspectConfirmationBoundaries(browser) {
  const page = await browser.newPage();
  try {
    await page.goto(`${fixture}?mode=detail`);
    await page.getByRole('button', { name: '시작', exact: true }).click();
    await page.locator('[data-response-id="response-short"] input').fill('Confirmation boundary test');
    await page.waitForFunction(() => document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
    await page.evaluate(() => {
      const submit = document.querySelector('[data-action="submit-assignment"]');
      submit.click(); submit.click();
    });
    const dialog = page.getByRole('dialog', { name: '제출 확인', exact: true });
    await dialog.waitFor();
    if (await dialog.count() !== 1) errors.push('submission confirmation: double click opened multiple dialogs');
    await page.evaluate(() => {
      const root = document.getElementById('oj-ai-student-assignment-app');
      root.dispatchEvent(new CustomEvent('oj-ai:student-assignment-detail', { detail: { assignment: JSON.parse(root.dataset.assignment) } }));
    });
    await dialog.waitFor({ state: 'detached' });
    if (await page.evaluate(() => window.__studentSubmitRequests.length) !== 0) errors.push('submission confirmation: stale context submitted an answer');
    await page.waitForFunction(() => document.querySelector('[data-role="save-indicator"]')?.dataset.state === 'saved');
    await page.evaluate(() => { HTMLDialogElement.prototype.showModal = () => { throw new Error('Fixture: dialog unavailable'); }; });
    await page.getByRole('button', { name: '제출', exact: true }).click();
    await page.getByText('제출 확인 창을 열지 못했습니다. 화면을 새로고침해 주세요.', { exact: true }).waitFor();
    if (await page.evaluate(() => window.__studentSubmitRequests.length) !== 0) errors.push('submission confirmation: unavailable dialog must fail closed');
  } finally { await page.close(); }
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectViewport(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectViewport(browser, { width: 390, height: 844 }, 'mobile');
  await inspectConfirmationBoundaries(browser);
  await browser.close();
} catch (error) { errors.push(`Playwright student assignment check failed: ${error.message}`); }

if (errors.length > 0) {
  console.error('Student assignment visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}
console.log('Student assignment desktop/mobile interaction checks passed.');

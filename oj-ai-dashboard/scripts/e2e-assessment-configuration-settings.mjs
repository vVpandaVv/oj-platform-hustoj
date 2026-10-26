import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/assessment-configuration-settings-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
await mkdir(artifactDir, { recursive: true });

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(message.text());
  });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForFunction(() => document.querySelector('[data-config-role="policy-summary"]')?.textContent === '업데이트 필요');
  const policyCompatibility = await page.evaluate(() => ({
    warning: document.querySelector('[data-config-role="policy-error"]')?.textContent || '',
    tone: document.querySelector('[data-config-role="policy-error"]')?.dataset.tone || '',
  }));
  if (!policyCompatibility.warning.includes('새 초안') || policyCompatibility.tone !== 'warning') {
    errors.push(`${label}: historical worksheet policy update guidance is missing`);
  }
  await page.getByRole('button', { name: '새 초안 저장', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-config-role="policy-revisions"]')?.textContent.includes('2차'));
  const createdPolicy = await page.evaluate(() => {
    const request = [...window.__settingsRequests].reverse().find((entry) => entry.method === 'POST'
      && entry.url.endsWith('/settings/policies/revisions'));
    return request?.body?.payload?.value || null;
  });
  if (createdPolicy?.prompt?.contract_version !== 'worksheet_quality_prompt.v5'
    || createdPolicy?.input_schema?.schema_id !== 'oj_ai.worksheet_quality_request'
    || createdPolicy?.output_schema?.schema_id !== 'oj_ai.worksheet_quality_output') {
    errors.push(`${label}: new worksheet policy did not use the current v5 contract`);
  }
  await page.getByRole('button', { name: '사용하기', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-config-role="policy-summary"]')?.textContent === '사용 중 · 2차');
  const authenticatedReads = await page.evaluate(() => {
    const paths = [
      '/settings/policies',
      '/settings/rubrics',
      '/settings/review-rules',
      '/settings/external-ai-consent',
      '/settings/submission-external-ai-consent',
    ];
    return paths.map((path) => {
      const request = window.__settingsRequests.find((entry) => entry.method === 'GET' && entry.url.endsWith(path));
      return { path, nonce: request?.headers?.['X-WP-Nonce'] || '' };
    });
  });
  authenticatedReads.forEach(({ path, nonce }) => {
    if (nonce !== 'nonce-cfg') {
      errors.push(`${label}: ${path} GET did not send the wp_rest nonce`);
    }
  });

  const layout = await page.evaluate(() => {
    const rootElement = document.querySelector('#oj-ai-assessment-settings');
    const rootRect = rootElement?.getBoundingClientRect();
    const controls = [...document.querySelectorAll('button, input, select, textarea')];
    const visibleControls = controls.filter((control) => control.getClientRects().length > 0);
    return {
      rootWidth: rootRect?.width || 0,
      rootHeight: rootRect?.height || 0,
      textLength: rootElement?.textContent.trim().length || 0,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      rootOverflow: rootElement ? rootElement.scrollWidth > rootElement.clientWidth + 1 : true,
      controlOverflow: visibleControls.some((control) => control.scrollWidth > control.clientWidth + 1),
      outsideViewport: visibleControls.some((control) => {
        const rect = control.getBoundingClientRect();
        return rect.left < -1 || rect.right > window.innerWidth + 1;
      }),
    };
  });
  if (layout.rootWidth < 250 || layout.rootHeight < 250 || layout.textLength < 80) errors.push(`${label}: settings root is blank`);
  if (layout.horizontalOverflow || layout.rootOverflow || layout.controlOverflow || layout.outsideViewport) errors.push(`${label}: horizontal or control overflow detected`);

  const policyTab = page.getByRole('tab', { name: '평가 정책', exact: true });
  await policyTab.focus();
  await policyTab.press('ArrowRight');
  const keyboardRubricTab = page.getByRole('tab', { name: '평가 기준', exact: true });
  if ((await keyboardRubricTab.getAttribute('aria-selected')) !== 'true'
    || !(await keyboardRubricTab.evaluate((node) => node === document.activeElement))) {
    errors.push(`${label}: settings ArrowRight did not select and focus the next tab`);
  }
  await keyboardRubricTab.press('Home');
  if ((await policyTab.getAttribute('aria-selected')) !== 'true'
    || !(await policyTab.evaluate((node) => node === document.activeElement))) {
    errors.push(`${label}: settings Home did not return to the first tab`);
  }

  const tab = async (name) => {
    await page.getByRole('tab', { name, exact: true }).click();
    const selected = await page.getByRole('tab', { name, exact: true }).getAttribute('aria-selected');
    if (selected !== 'true') errors.push(`${label}: ${name} tab did not activate`);
  };

  await tab('성취기준');
  await page.waitForFunction(() => document.querySelector('[data-catalog-role="notice"]')?.textContent === '교육과정을 추가하세요.');
  const emptyCatalog = await page.evaluate(() => ({
    message: document.querySelector('[data-catalog-role="standards"]')?.textContent || '',
    curriculumOptions: document.querySelector('[data-catalog-field="curriculum"]')?.options.length || 0,
    subjectSwitchVisible: Boolean(document.querySelector('[data-config-role="subject-switch"]')?.getClientRects().length),
  }));
  if (!emptyCatalog.message.includes('교육과정을 먼저 추가') || emptyCatalog.curriculumOptions !== 1 || emptyCatalog.subjectSwitchVisible) {
    errors.push(`${label}: empty curriculum catalog state failed (${JSON.stringify(emptyCatalog)})`);
  }

  await page.getByRole('button', { name: '교육과정 추가', exact: true }).click();
  const curriculumDialog = page.locator('[data-catalog-dialog="curriculum"]');
  const curriculumDialogFits = await curriculumDialog.evaluate((dialog) => {
    const rect = dialog.getBoundingClientRect();
    return rect.left >= -1 && rect.right <= window.innerWidth + 1 && rect.top >= -1 && rect.bottom <= window.innerHeight + 1;
  });
  await curriculumDialog.getByLabel('이름', { exact: true }).fill('학교 교육과정');
  await curriculumDialog.getByLabel('교육과정 코드', { exact: true }).fill('school-curriculum');
  await curriculumDialog.getByLabel('버전', { exact: true }).fill('current');
  await curriculumDialog.getByLabel('언어', { exact: true }).fill('ko-KR');
  await curriculumDialog.getByRole('button', { name: '저장', exact: true }).click();
  await page.waitForFunction(() => (
    document.querySelector('[data-catalog-field="curriculum"]')?.value === '01K00000000000000000000401'
    && document.querySelector('[data-catalog-action="standard-new"]')?.disabled === false
  ));

  await page.getByRole('button', { name: '성취기준 추가', exact: true }).click();
  const standardDialog = page.locator('[data-catalog-dialog="standard"]');
  const standardDialogFits = await standardDialog.evaluate((dialog) => {
    const rect = dialog.getBoundingClientRect();
    return rect.left >= -1 && rect.right <= window.innerWidth + 1 && rect.top >= -1 && rect.bottom <= window.innerHeight + 1;
  });
  await standardDialog.getByLabel('코드', { exact: true }).fill('LOCAL-OBS-01');
  await standardDialog.getByLabel('학년·범위', { exact: true }).fill('학교 지정 범위');
  await standardDialog.getByLabel('성취기준', { exact: true }).fill('관찰 결과를 근거와 함께 설명한다.');
  await standardDialog.getByLabel('설명', { exact: true }).fill('학교가 직접 정한 관찰 활동의 확인 기준');
  await standardDialog.getByRole('button', { name: '저장', exact: true }).click();
  await page.waitForFunction(() => document.querySelectorAll('[data-catalog-role="standards"] .oj-ai-catalog__row').length === 1);
  await page.locator('[data-catalog-field="search"]').fill('LOCAL-OBS-01');
  await page.waitForFunction(() => [...window.__settingsRequests].some((entry) => (
    entry.method === 'GET' && entry.url.includes('/standards?')
    && new URL(entry.url).searchParams.get('search') === 'LOCAL-OBS-01'
  )));

  const catalogResult = await page.evaluate(() => {
    const curriculumSave = window.__settingsRequests.find((entry) => entry.method === 'POST' && entry.url.endsWith('/curriculums/commands/save'));
    const standardSave = window.__settingsRequests.find((entry) => entry.method === 'POST' && entry.url.endsWith('/standards/commands/save'));
    const panel = document.querySelector('[data-settings-panel="curriculum"]');
    const panelRect = panel?.getBoundingClientRect();
    const controls = [...(panel?.querySelectorAll('button, input, select, textarea') || [])].filter((control) => control.getClientRects().length > 0);
    return {
      curriculum: curriculumSave?.body?.payload || null,
      standard: standardSave?.body?.payload || null,
      curriculumNonce: curriculumSave?.headers?.['X-WP-Nonce'] || '',
      standardNonce: standardSave?.headers?.['X-WP-Nonce'] || '',
      curriculumIdempotency: curriculumSave?.headers?.['Idempotency-Key'] || '',
      standardIdempotency: standardSave?.headers?.['Idempotency-Key'] || '',
      rowText: panel?.querySelector('[data-catalog-role="standards"]')?.textContent || '',
      overflow: Boolean(panel && panel.scrollWidth > panel.clientWidth + 1),
      outsidePanel: controls.some((control) => {
        const rect = control.getBoundingClientRect();
        return Boolean(panelRect && (rect.left < panelRect.left - 1 || rect.right > panelRect.right + 1));
      }),
    };
  });
  if (!curriculumDialogFits || !standardDialogFits || catalogResult.overflow || catalogResult.outsidePanel) {
    errors.push(`${label}: curriculum catalog layout overflowed (${JSON.stringify(catalogResult)})`);
  }
  if (
    catalogResult.curriculum?.title !== '학교 교육과정'
    || catalogResult.curriculum?.source_key !== 'school-curriculum'
    || catalogResult.curriculum?.source_version !== 'current'
    || catalogResult.curriculum?.locale !== 'ko-KR'
    || Object.hasOwn(catalogResult.curriculum || {}, 'source_kind')
    || catalogResult.standard?.code !== 'LOCAL-OBS-01'
    || catalogResult.standard?.grade_label !== '학교 지정 범위'
    || catalogResult.standard?.title !== '관찰 결과를 근거와 함께 설명한다.'
    || catalogResult.standard?.description !== '학교가 직접 정한 관찰 활동의 확인 기준'
    || !catalogResult.rowText.includes('LOCAL-OBS-01')
  ) {
    errors.push(`${label}: operator-entered curriculum data was not preserved (${JSON.stringify(catalogResult)})`);
  }
  if (
    catalogResult.curriculumNonce !== 'nonce-cfg' || catalogResult.standardNonce !== 'nonce-cfg'
    || !catalogResult.curriculumIdempotency || !catalogResult.standardIdempotency
  ) {
    errors.push(`${label}: curriculum catalog command authentication drifted (${JSON.stringify(catalogResult)})`);
  }
  await page.screenshot({ path: path.join(artifactDir, `curriculum-catalog-settings-${label}.png`), fullPage: true });

  await tab('평가 기준');
  let dimensions = await page.locator('[data-config-item="dimension"]').count();
  if (dimensions !== 6) errors.push(`${label}: worksheet-quality rubric expected 6 dimensions, found ${dimensions}`);

  await page.getByRole('button', { name: '학생 제출 평가', exact: true }).click();
  let criteria = await page.locator('[data-config-item="criterion"]').count();
  if (criteria !== 1) errors.push(`${label}: student rubric expected 1 criterion, found ${criteria}`);
  await page.getByRole('button', { name: '평가 항목 추가', exact: true }).click();
  criteria = await page.locator('[data-config-item="criterion"]').count();
  if (criteria !== 2) errors.push(`${label}: adding a student criterion failed`);
  await page.getByRole('button', { name: '새 초안 저장', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-config-role="rubric-revisions"]')?.textContent.includes('2차'));
  await page.getByRole('button', { name: '사용하기', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-config-role="rubric-summary"]')?.textContent === '사용 중 · 2차');

  await tab('검토·승인');
  if (!(await page.getByLabel('사람 검토 필수').isChecked())) errors.push(`${label}: mandatory human review is not fixed on`);
  if (!(await page.getByLabel('명시적 공개 필수').isChecked())) errors.push(`${label}: explicit release is not fixed on`);

  await tab('평가 정책');
  if ((await page.locator('[data-config-role="subject-label"]').first().textContent()) !== '학생 제출 평가') errors.push(`${label}: subject selection did not persist across tabs`);

  await tab('AI 연결');
  await page.waitForFunction(() => document.querySelector('#oj-ai-external-consent-app [data-role="status-badge"]')?.textContent === '승인 필요');
  if (await page.locator('[data-config-role="subject-switch"]').isVisible()) errors.push(`${label}: subject switch must be hidden on connection tab`);
  const worksheetConsentTab = page.getByRole('tab', { name: '활동지 평가', exact: true });
  const submissionConsentTab = page.getByRole('tab', { name: '학생 제출 평가', exact: true });
  await worksheetConsentTab.focus();
  await worksheetConsentTab.press('ArrowRight');
  await page.waitForFunction(() => document.querySelector('#oj-ai-submission-external-consent-app [data-role="status-badge"]')?.textContent === '승인 필요');
  if ((await submissionConsentTab.getAttribute('aria-selected')) !== 'true'
    || !(await submissionConsentTab.evaluate((node) => node === document.activeElement))) {
    errors.push(`${label}: submission consent tab did not activate and focus by keyboard`);
  }
  if (!(await page.locator('#oj-ai-submission-external-consent-app').isVisible())) errors.push(`${label}: submission consent panel is not visible`);

  await tab('시스템 상태');
  await page.waitForFunction(() => document.querySelector('#oj-ai-operations-diagnostics')?.dataset.access === 'granted');
  if (await page.locator('[data-config-role="subject-switch"]').isVisible()) errors.push(`${label}: subject switch must be hidden on system tab`);
  const diagnosticRows = await page.locator('[data-diagnostics-role="list"] [data-check-key]').count();
  if (diagnosticRows !== 5) errors.push(`${label}: system status expected 5 checks, found ${diagnosticRows}`);


  await tab('평가 기준');
  await page.screenshot({ path: path.join(artifactDir, `assessment-configuration-settings-${label}.png`), fullPage: true });
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.close();
  console.log(`[OK] assessment configuration ${label} ${JSON.stringify(layout)}`);
}

async function inspectPartialFailure(browser) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(message.text());
  });
  await page.goto(`${fixtureUrl}?fail=review_rule`, { waitUntil: 'load' });
  await page.waitForFunction(() => document.querySelector('[data-config-role="policy-summary"]')?.textContent === '업데이트 필요');

  const policySaveDisabled = await page.locator('[data-config-action="save-policy"]').isDisabled();
  await page.getByRole('tab', { name: '평가 기준', exact: true }).click();
  const rubricSaveDisabled = await page.locator('[data-config-action="save-rubric"]').isDisabled();
  await page.getByRole('tab', { name: '검토·승인', exact: true }).click();
  const result = await page.evaluate(() => ({
    globalNotice: document.querySelector('[data-config-role="notice"]')?.textContent || '',
    reviewError: document.querySelector('[data-config-role="review-rule-error"]')?.textContent || '',
    reviewErrorVisible: !document.querySelector('[data-config-role="review-rule-error"]')?.hidden,
    reviewSummary: document.querySelector('[data-config-role="review-rule-summary"]')?.textContent || '',
    reviewThresholdDisabled: Boolean(document.querySelector('[data-config-field="confidence-threshold"]')?.disabled),
  }));

  if (policySaveDisabled || rubricSaveDisabled) {
    errors.push('partial-failure: a working settings panel was disabled');
  }
  if (!result.globalNotice.includes('일부 설정') || !result.reviewErrorVisible || !result.reviewError
    || result.reviewSummary !== '불러오기 실패' || !result.reviewThresholdDisabled) {
    errors.push(`partial-failure: resource isolation failed ${JSON.stringify(result)}`);
  }
  await page.screenshot({ path: path.join(artifactDir, 'assessment-configuration-settings-partial-failure.png'), fullPage: true });
  pageErrors.forEach((message) => errors.push(`partial-failure: browser error: ${message}`));
  await page.close();
  console.log(`[OK] assessment configuration partial failure ${JSON.stringify(result)}`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1280, height: 900 }, 'desktop');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await inspectPartialFailure(browser);
  await browser.close();
} catch (error) {
  errors.push(`Playwright assessment configuration check failed: ${error.message}`);
}

if (errors.length > 0) {
  console.error('Assessment configuration settings visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('Assessment configuration settings desktop/mobile interaction checks passed.');

import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { browserLaunchOptions, loadPlaywright } from './lib/playwright-runtime.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureUrl = pathToFileURL(path.join(root, 'tests/fixtures/worksheet-workbench-visual.html')).href;
const artifactDir = path.join(root, 'tests/artifacts');
const errors = [];
const responseLabels = ['단답형', '서술형', '숫자', '단일 선택', '복수 선택', '확인 체크', '표 입력', '그림 응답', '이미지 첨부', '파일 첨부'];

await mkdir(artifactDir, { recursive: true });

function verifyLayout(layout, label) {
  if (!layout.rootVisible) errors.push(`${label}: workbench root is blank`);
  if (!layout.exportsVisible) errors.push(`${label}: PDF and worksheet file exports are not visible`);
  if (!layout.exportStatusFits) errors.push(`${label}: export status text is clipped`);
  if (layout.offscreenExportControls.length > 0) errors.push(`${label}: export controls are offscreen (${layout.offscreenExportControls.join(', ')})`);
  if (!layout.editorVisible) errors.push(`${label}: Plate editor is blank`);
  if (!layout.controlsVisible) errors.push(`${label}: response menu is blank`);
  if (!layout.saveVisible) errors.push(`${label}: save command is not visible`);
  if (!layout.toolsVisible) errors.push(`${label}: file, import or duplicate commands are not visible`);
  if (!layout.releaseVisible) errors.push(`${label}: approval and publication commands are not visible`);
  if (layout.phaseCount !== 4 || layout.currentPhaseCount !== 1) errors.push(`${label}: four-step worksheet flow drifted`);
  if (!layout.canvasVisible || !layout.inspectorVisible) errors.push(`${label}: editor canvas or inspector is missing`);
  if (layout.inspectorTabCount !== 3) errors.push(`${label}: worksheet inspector tabs drifted`);
  if (label === 'desktop' && !layout.outlineVisible) errors.push(`${label}: document outline is missing`);
  if (!layout.inspectorFieldsVisible) errors.push(`${label}: selected question settings are missing`);
  if (!layout.outlineLoaded) errors.push(`${label}: document outline was not populated`);
  if (!layout.qualityLinkVisible || !layout.qualityLinkContext) errors.push(`${label}: contextual worksheet self-assessment entry is missing`);
  if (layout.workspaceOverlap) errors.push(`${label}: editor tracks overlap`);
  if (layout.horizontalOverflow) errors.push(`${label}: viewport has horizontal overflow`);
  if (layout.headerOverlap) errors.push(`${label}: title and save actions overlap`);
  if (!layout.workspaceTracksFit) errors.push(`${label}: editor track is clipped by the workspace`);
  if (!layout.inspectorTextWraps) errors.push(`${label}: inspector guidance text cannot wrap`);
  if (!layout.workflowInset) errors.push(`${label}: approval or export content touches its boundary`);
  if (layout.iconCount < 3) errors.push(`${label}: workbench command icons are missing`);
  if (layout.brokenIcons.length > 0) errors.push(`${label}: workbench icons are unstyled (${layout.brokenIcons.join(', ')})`);
  if (layout.offscreenResponseControls.length > 0) {
    errors.push(`${label}: response menu is offscreen (${layout.offscreenResponseControls.join(', ')})`);
  }
}

function firstDifference(expected, actual, currentPath = '$') {
  if (JSON.stringify(expected) === JSON.stringify(actual)) {
    return null;
  }
  if (typeof expected !== 'object' || expected === null || typeof actual !== 'object' || actual === null) {
    return `${currentPath}: expected ${JSON.stringify(expected)}, received ${JSON.stringify(actual)}`;
  }
  if (Array.isArray(expected) !== Array.isArray(actual)) {
    return `${currentPath}: container type differs`;
  }
  if (Array.isArray(expected)) {
    if (expected.length !== actual.length) {
      return `${currentPath}.length: expected ${expected.length}, received ${actual.length}`;
    }
    for (let index = 0; index < expected.length; index += 1) {
      const difference = firstDifference(expected[index], actual[index], `${currentPath}[${index}]`);
      if (difference) return difference;
    }
    return `${currentPath}: array order differs`;
  }
  for (const key of [...new Set([...Object.keys(expected), ...Object.keys(actual)])].sort()) {
    const difference = firstDifference(expected[key], actual[key], `${currentPath}.${key}`);
    if (difference) return difference;
  }
  return `${currentPath}: object differs`;
}

async function inspectPage(browser, viewport, label) {
  const pageErrors = [];
  const page = await browser.newPage({ viewport });
  page.on('dialog', (dialog) => dialog.accept());
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') {
      pageErrors.push(message.text());
    }
  });
  await page.goto(fixtureUrl, { waitUntil: 'load' });
  await page.waitForSelector('.hws-template-workspace', { timeout: 30000 });
  try {
    await page.waitForSelector('[contenteditable="true"]', { timeout: 30000 });
  } catch (error) {
    throw new Error([
      error instanceof Error ? error.message : String(error),
      ...pageErrors,
    ].filter(Boolean).join('\n'));
  }
  await page.waitForFunction(() => document.querySelector('#ws-template-title')?.value === '우리 동네 환경 조사');
  await page.waitForFunction(() => document.querySelector('#ws-plate-template-builder-root')?.getAttribute('aria-busy') === 'false');
  await page.waitForFunction(() => document.querySelector('[data-role="quality-status"]')?.textContent === '검토 필요 · 지적 2개');
  const qualitySummary = await page.evaluate(() => ({
    status: document.querySelector('[data-role="quality-status"]')?.textContent,
    requestUrl: window.__lastWorksheetQualityUrl || '',
  }));
  if (new URL(qualitySummary.requestUrl).searchParams.get('worksheet_id') !== '01HZY3M4N5P6Q7R8S9T0V1W2X3') errors.push(`${label}: Q09 worksheet quality filter drifted`);

  await page.locator('.hws-response-toolbar-button').click();
  const controls = page.locator('[data-response-kind]');
  const labels = await controls.allTextContents();
  if (JSON.stringify(labels) !== JSON.stringify(responseLabels)) {
    errors.push(`${label}: expected 10 response menu items, received ${labels.join(', ')}`);
  }

  const before = await page.locator('.hws-response-node').count();
  const questionsBefore = await page.locator('.hws-question-block').count();
  const outlineBefore = await page.locator('[data-role="document-outline"] button').count();
  await page.getByRole('menuitem', { name: '단일 선택', exact: true }).click();
  await page.waitForFunction((count) => document.querySelectorAll('.hws-response-node').length === count + 1, before);
  const activeInspector = '#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden])';
  const groupedAfterToolbarInsert = await page.evaluate(() => ({
    questions: document.querySelectorAll('.hws-question-block').length,
    firstQuestionResponses: document.querySelectorAll('.hws-question-block')[0]?.querySelectorAll('.hws-response-node').length || 0,
  }));
  if (groupedAfterToolbarInsert.questions !== questionsBefore || groupedAfterToolbarInsert.firstQuestionResponses !== 2) {
    errors.push(`${label}: response toolbar created a question instead of a second answer (${JSON.stringify(groupedAfterToolbarInsert)})`);
  }
  await page.waitForTimeout(120);
  await page.waitForFunction(() => document.querySelectorAll('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) [data-hws-response-prop="rubric_criterion_id"]').length === 2);
  const criterionSurface = page.locator(`${activeInspector}`);
  if ((await criterionSurface.locator('[data-hws-response-prop="rubric_criterion_id"]').count()) !== 2) errors.push(`${label}: active rubric response criteria are missing`);
  if (!(await criterionSurface.textContent()).includes('전체 결과 · 전체 완성도')) errors.push(`${label}: submission-scope criterion is missing`);
  await criterionSurface.locator('input[value="01K00000000000000000000211"]').check();
  await page.waitForFunction(() => document.querySelector('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) .hws-response-inspector__criteria-total')?.textContent.trim() === '배점 3점');

  const activeKind = page.locator(`${activeInspector} [data-hws-response-prop="kind"]`);
  await activeKind.selectOption('long_text');
  await page.waitForFunction(() => {
    const panel = document.querySelector('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden])');
    const labelField = panel?.querySelector('[data-hws-response-prop="label"]');
    const responseId = panel?.getAttribute('data-hws-response-id') || '';
    const previewLabel = document.querySelector(`.hws-response-node__preview[data-hws-response-id="${responseId}"] strong`);
    return labelField?.value === '학생 긴 응답칸' && previewLabel?.textContent === '학생 긴 응답칸';
  });
  await activeKind.selectOption('single_choice');
  await page.waitForFunction(() => {
    const panel = document.querySelector('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden])');
    const labelField = panel?.querySelector('[data-hws-response-prop="label"]');
    const responseId = panel?.getAttribute('data-hws-response-id') || '';
    const preview = document.querySelector(`.hws-response-node__preview[data-hws-response-id="${responseId}"]`);
    const schema = JSON.parse(document.querySelector('#ws-builder-schema')?.value || '{}');
    let response = null;
    const visit = (node) => {
      if (!node || typeof node !== 'object' || response) return;
      if (node.kind === 'single_choice' && node.label === '학생 단일 선택칸') response = node;
      if (Array.isArray(node.children)) node.children.forEach(visit);
    };
    (schema.plate_value || []).forEach(visit);
    return labelField?.value === '학생 단일 선택칸'
      && preview?.querySelector('strong')?.textContent === '학생 단일 선택칸'
      && preview?.querySelector('.hws-response-node__badge')?.textContent === '단일 선택'
      && preview?.querySelectorAll('select option').length === 3
      && Array.isArray(response?.options)
      && response.options.length === 2;
  });
  await page.locator('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) [data-hws-response-prop="label"]').fill('선호 조사');
  await activeKind.selectOption('multiple_choice');
  await page.waitForFunction(() => {
    const panel = document.querySelector('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden])');
    const responseId = panel?.getAttribute('data-hws-response-id') || '';
    const preview = document.querySelector(`.hws-response-node__preview[data-hws-response-id="${responseId}"]`);
    const schema = JSON.parse(document.querySelector('#ws-builder-schema')?.value || '{}');
    const serialized = JSON.stringify(schema.plate_value || []);
    return panel?.querySelector('[data-hws-response-prop="label"]')?.value === '선호 조사'
      && preview?.querySelector('strong')?.textContent === '선호 조사'
      && preview?.querySelector('.hws-response-node__badge')?.textContent === '복수 선택'
      && preview?.querySelectorAll('.hws-response-node__student-checklist input[type="checkbox"]').length === 2
      && serialized.includes('"kind":"multiple_choice"')
      && serialized.includes('"label":"선호 조사"');
  });

  await page.evaluate(() => {
    window.__ojaiSelectionEvents = [];
    window.addEventListener('ojai-plate-response-select', (event) => {
      window.__ojaiSelectionEvents.push({ ...event.detail });
    });
  });

  await page.locator('[data-action="add-question"]').evaluate((button) => button.click());
  try {
    await page.waitForFunction((count) => document.querySelectorAll('.hws-response-node').length === count + 2, before, { timeout: 7000 });
  } catch (error) {
    const diagnostic = await page.evaluate(() => ({
      questionCount: document.querySelectorAll('.hws-question-block').length,
      responseCount: document.querySelectorAll('.hws-response-node').length,
      activeQuestion: document.querySelector('#ws-response-inspector-root')?.getAttribute('data-active-question') || '',
      schema: JSON.parse(document.querySelector('#ws-builder-schema')?.value || '{}').plate_value,
    }));
    throw new Error([
      error instanceof Error ? error.message : String(error),
      JSON.stringify(diagnostic),
      ...pageErrors,
    ].filter(Boolean).join('\n'));
  }
  const newestQuestion = page.locator('.hws-question-block').last();
  const newestResponse = newestQuestion.locator('.hws-response-node__preview').first();
  const newestResponseId = await newestResponse.getAttribute('data-hws-response-id');
  await newestResponse.click();
  await page.waitForTimeout(250);
  const selectionDiagnostic = await page.evaluate(() => ({
    selectedResponseId: document.querySelector('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden])')?.getAttribute('data-hws-response-id') || '',
    activeResponse: document.querySelector('#ws-response-inspector-root')?.getAttribute('data-active-response') || '',
    activeInstance: document.querySelector('#ws-response-inspector-root')?.getAttribute('data-active-response-instance') || '',
    visibleEditors: Array.from(document.querySelectorAll('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden])')).map((node) => node.getAttribute('data-hws-response-id')),
    events: window.__ojaiSelectionEvents || [],
  }));
  if (selectionDiagnostic.selectedResponseId !== newestResponseId) {
    errors.push(`${label}: new question response selection drifted (expected ${newestResponseId}, diagnostic ${JSON.stringify(selectionDiagnostic)})`);
  }

  await page.locator('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) [data-hws-response-prop="label"]').fill('추가 서술 답안');
  await page.waitForTimeout(180);
  const criterionAvailability = await page.evaluate(() => {
    const content = document.querySelector('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) input[value="01K00000000000000000000211"]');
    const evidence = document.querySelector('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) input[value="01K00000000000000000000212"]');
    const schema = JSON.parse(document.querySelector('#ws-builder-schema')?.value || '{}');
    return { contentDisabled: content?.disabled, evidenceDisabled: evidence?.disabled, schema: schema.plate_value };
  });
  if (!criterionAvailability.contentDisabled) errors.push(`${label}: a response criterion could be selected twice (${JSON.stringify(criterionAvailability.schema)})`);
  const secondQuestionCriterion = page.locator('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) input[value="01K00000000000000000000212"]');
  if (criterionAvailability.evidenceDisabled) errors.push(`${label}: unused response criterion was disabled`);
  await secondQuestionCriterion.check();
  await page.waitForFunction(() => document.querySelector('#ws-builder-schema')?.value.includes('01K00000000000000000000212'));
  await page.waitForFunction((count) => (
    document.querySelectorAll('[data-role="document-outline"] button').length === count + 1
  ), outlineBefore);
  await page.locator('.hws-question-block.is-selected .hws-question-block__add-response').click();
  await page.waitForFunction((count) => document.querySelectorAll('.hws-response-node').length === count + 3, before);
  await page.locator('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) [data-hws-response-prop="label"]').fill('추가 보충 답안');
  await page.locator('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) [data-hws-response-prop="kind"]').selectOption('short_text');
  const groupedQuestionCounts = await page.locator('.hws-question-block').evaluateAll((questions) => (
    questions.map((question) => question.querySelectorAll('.hws-response-node').length)
  ));
  if (JSON.stringify(groupedQuestionCounts) !== JSON.stringify([2, 2])) {
    errors.push(`${label}: question response grouping drifted (${JSON.stringify(groupedQuestionCounts)})`);
  }

  await page.locator('[data-role="document-outline"] button')
    .filter({ hasText: '관찰 내용' })
    .evaluate((button) => button.click());
  await page.waitForTimeout(250);
  const selectedQuestion = await page.evaluate(() => ({
    currentOutlineLabel: document.querySelector('[data-role="document-outline"] button[aria-current="true"] strong')?.textContent || '',
    activeValues: Array.from(document.querySelectorAll(
      '#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) [data-hws-response-prop="label"]'
    )).map((input) => input.value),
    outlineKeys: Array.from(document.querySelectorAll('[data-role="document-outline"] button'))
      .map((button) => button.dataset.outlineNodeId || ''),
    responseKeys: Array.from(document.querySelectorAll('.hws-response-node__preview'))
      .map((response) => ({
        node: response.getAttribute('data-hws-node-id'),
        response: response.getAttribute('data-hws-response-id'),
      })),
  }));
  if (
    selectedQuestion.activeValues.length !== 1
    || selectedQuestion.activeValues[0] !== '관찰 내용'
    || selectedQuestion.currentOutlineLabel !== '관찰 내용'
  ) {
    errors.push(`${label}: outline question selection drifted (${JSON.stringify(selectedQuestion)})`);
  }
  await page.locator('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) [data-hws-response-prop="min_chars"]').fill('30');
  await page.locator('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden]) [data-hws-response-prop="max_chars"]').fill('900');
  await page.waitForFunction(() => {
    const schema = JSON.parse(document.querySelector('#ws-builder-schema')?.value || '{}');
    let response = null;
    const visit = (node) => {
      if (!node || typeof node !== 'object' || response) return;
      if (node.label === '관찰 내용') response = node;
      if (Array.isArray(node.children)) node.children.forEach(visit);
    };
    (schema.plate_value || []).forEach(visit);
    return response?.constraints?.min_chars === 30 && response?.constraints?.max_chars === 900;
  });
  const multipleQuestionState = await page.evaluate(() => ({
    outlineCount: document.querySelectorAll('[data-role="document-outline"] button').length,
    responseCount: document.querySelectorAll('.hws-response-node').length,
    questionCount: document.querySelectorAll('.hws-question-block').length,
  }));
  if (multipleQuestionState.responseCount !== before + 3 || multipleQuestionState.questionCount !== questionsBefore + 1 || multipleQuestionState.outlineCount !== outlineBefore + 1) {
    errors.push(`${label}: multiple question authoring drifted (${JSON.stringify(multipleQuestionState)})`);
  }

  await page.getByRole('button', { name: '저장', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="save-status"]')?.textContent === '저장됨');

  await page.waitForTimeout(700);
  const stableSaveStatus = await page.locator('[data-role="save-status"]').textContent();
  if (stableSaveStatus !== '저장됨') {
    const diagnostic = await page.evaluate(() => {
      const current = JSON.parse(document.querySelector('#ws-builder-schema')?.value || '{}');
      const expected = window.OJAIWorksheetAuthoringWorkbench.teacherDocumentToPlateSchema(
        window.__lastSavedTeacherDocument
      );
      return {
        current: current.plate_value,
        expected: expected.plate_value,
      };
    });
    errors.push(`${label}: save status reverted to ${stableSaveStatus}; ${firstDifference(diagnostic.expected, diagnostic.current)}`);
  }

  const savedBindings = await page.evaluate(() => {
    const result = [];
    const visit = (value) => {
      if (!Array.isArray(value)) return;
      value.forEach((node) => {
        const ids = Array.isArray(node?.grading?.rubric_criterion_ids) ? node.grading.rubric_criterion_ids : [];
        if (ids.length) result.push({ ids, label: node.label, maximum: node.grading.max_points });
        visit(node?.children);
      });
    };
    visit(window.__lastSavedTeacherDocument?.nodes || []);
    return result;
  });
  const savedCriterionIds = savedBindings.flatMap((item) => item.ids).sort();
  if (JSON.stringify(savedCriterionIds) !== JSON.stringify(['01K00000000000000000000211', '01K00000000000000000000212'])) {
    const savedAssessmentDiagnostic = await page.evaluate(() => ({
      assessmentBindings: window.__lastSavedTeacherDocument?.settings?.assessment_bindings || [],
      plateSchema: JSON.parse(document.querySelector('#ws-builder-schema')?.value || '{}').plate_value || [],
    }));
    errors.push(`${label}: saved worksheet grading bindings drifted (${JSON.stringify({ savedBindings, ...savedAssessmentDiagnostic })})`);
  }

  const assetId = '01HZY3M4N5P6Q7R8S9T0V1W2XB';
  await page.locator('[data-field="asset-file"]').setInputFiles({
    name: 'field-photo.png',
    mimeType: 'image/png',
    buffer: Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
  });
  await page.waitForFunction(() => Boolean(window.__lastStageRequest));
  await page.waitForFunction((expectedAssetId) => {
    const schema = JSON.parse(document.querySelector('#ws-builder-schema')?.value || '{}');
    const visit = (value) => {
      if (Array.isArray(value)) return value.some(visit);
      if (!value || typeof value !== 'object') return false;
      if (value.asset_id === expectedAssetId) return true;
      return Object.values(value).some(visit);
    };
    return visit(schema);
  }, assetId);
  const stageContract = await page.evaluate(() => window.__lastStageRequest);
  if (
    stageContract.expected_state !== 'draft'
    || stageContract.expected_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2X4'
    || stageContract.file_name !== 'field-photo.png'
    || stageContract.content_type !== ''
  ) {
    errors.push(`${label}: C64 multipart contract drifted (${JSON.stringify(stageContract)})`);
  }
  if (!(await page.locator('[data-role="asset-tray"]').isVisible())) {
    errors.push(`${label}: staged asset tray is not visible`);
  }

  await page.getByRole('button', { name: '저장', exact: true }).click();
  await page.waitForFunction((expectedAssetId) => (
    Array.isArray(window.__lastSavedReceipts)
    && window.__lastSavedReceipts.length === 1
    && window.__lastSavedReceipts[0] === expectedAssetId
  ), assetId);
  await page.waitForFunction(() => document.querySelector('[data-role="save-status"]')?.textContent === '저장됨');
  if (await page.locator('[data-role="asset-tray"]').isVisible()) {
    errors.push(`${label}: promoted asset remained in the staging tray`);
  }

  await page.locator('.oj-ai-workbench__more > summary').click();
  await page.getByRole('button', { name: '문서 검증', exact: true }).click();
  await page.locator('.oj-ai-workbench__more > summary').click();
  await page.waitForFunction(() => (
    document.querySelector('[data-role="validation-title"]')?.textContent === '검증 완료'
  ));
  const validationContract = await page.evaluate(() => window.__lastValidationRequest);
  if (
    typeof validationContract?.command_id !== 'string'
    || validationContract.command_id.length === 0
    || validationContract.expected?.expected_state !== 'draft'
    || validationContract.expected?.expected_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2X4'
    || JSON.stringify(validationContract.payload) !== '{}'
  ) {
    errors.push(`${label}: C05 exact JSON contract drifted (${JSON.stringify(validationContract)})`);
  }
  if (!(await page.locator('[data-role="validation-result"]').isVisible())) {
    errors.push(`${label}: validation result is not visible`);
  }

  await page.locator('.oj-ai-workbench__more > summary').click();
  await page.getByRole('button', { name: '미리보기', exact: true }).click();
  await page.locator('.oj-ai-workbench__more').evaluate((details) => { details.open = false; });
  await page.waitForFunction(() => (
    window.__lastPreviewKind === 'student'
    && document.querySelector('[data-role="preview-title"]')?.textContent === '학생 미리보기'
    && document.querySelectorAll('.oj-ai-workbench__preview-response').length > 0
  ));
  await page.waitForFunction(() => Array.from(
    document.querySelectorAll('[data-role="preview-content"] img')
  ).every((image) => image.complete && image.naturalWidth > 0));
  const studentPreview = await page.evaluate(() => {
    const shell = document.querySelector('[data-role="preview-shell"]');
    const panel = document.querySelector('.oj-ai-workbench__preview-panel');
    const content = document.querySelector('[data-role="preview-content"]');
    const rect = panel?.getBoundingClientRect();
    const text = content?.textContent || '';
    const samplePoints = rect ? [
      [rect.left + 16, rect.top + 16],
      [rect.left + (rect.width / 2), rect.top + 96],
      [rect.left + 24, Math.min(rect.bottom - 24, rect.top + 330)],
      [rect.right - 24, Math.min(rect.bottom - 24, rect.top + 330)],
      [rect.left + (rect.width / 2), rect.bottom - 24],
    ] : [];
    const topLayerAtSamples = samplePoints.every(([x, y]) => (
      document.elementFromPoint(x, y)?.closest('[data-role="preview-shell"]') === shell
    ));
    return {
      imageCount: content?.querySelectorAll('img').length || 0,
      panelInViewport: Boolean(rect && rect.left >= -1 && rect.right <= window.innerWidth + 1),
      privateTextVisible: /grading|correct_answer|teacher_note|rubric_ref/i.test(text),
      responseCount: content?.querySelectorAll('.oj-ai-workbench__preview-response').length || 0,
      responseControlCount: content?.querySelectorAll('input, textarea, table').length || 0,
      shellVisible: Boolean(shell && !shell.hidden && rect && rect.width > 0 && rect.height > 0),
      horizontalOverflow: Boolean(content && content.scrollWidth > content.clientWidth + 1),
      topLayerAtSamples,
    };
  });
  if (
    !studentPreview.shellVisible
    || !studentPreview.panelInViewport
    || studentPreview.privateTextVisible
    || studentPreview.responseCount < 1
    || studentPreview.responseControlCount < 1
    || studentPreview.imageCount < 1
    || studentPreview.horizontalOverflow
    || !studentPreview.topLayerAtSamples
  ) {
    errors.push(`${label}: student projection preview failed (${JSON.stringify(studentPreview)})`);
  }
  await page.screenshot({
    path: path.join(artifactDir, `worksheet-preview-student-${label}.png`),
    fullPage: false,
  });

  await page.getByRole('tab', { name: '인쇄', exact: true }).click();
  await page.waitForFunction(() => (
    window.__lastPreviewKind === 'print'
    && document.querySelector('[data-role="preview-title"]')?.textContent === '인쇄 미리보기'
    && document.querySelector('[data-role="preview-status"]')?.textContent === '인쇄 화면'
  ));
  const printPreview = await page.evaluate(() => {
    const shell = document.querySelector('[data-role="preview-shell"]');
    const panel = document.querySelector('.oj-ai-workbench__preview-panel');
    const printButton = document.querySelector('[data-action="preview-print-page"]');
    const closeButton = document.querySelector('.oj-ai-workbench__preview-close');
    const title = document.querySelector('[data-role="preview-title"]');
    const rect = panel?.getBoundingClientRect();
    const titleRect = title?.getBoundingClientRect();
    const actionsRect = printButton?.parentElement?.getBoundingClientRect();
    const closeRect = closeButton?.getBoundingClientRect();
    const overlaps = (a, b) => Boolean(
      a && b && a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top
    );
    return {
      closeButtonInViewport: Boolean(closeRect && closeRect.left >= -1 && closeRect.right <= window.innerWidth + 1),
      kind: shell?.dataset.kind,
      panelInViewport: Boolean(rect && rect.left >= -1 && rect.right <= window.innerWidth + 1),
      printButtonVisible: Boolean(printButton && !printButton.hidden),
      shellVisible: Boolean(shell && !shell.hidden),
      titleFits: Boolean(title && title.scrollWidth <= title.clientWidth + 1),
      titleOverlap: overlaps(titleRect, actionsRect),
      titleText: title?.textContent,
    };
  });
  if (
    !printPreview.closeButtonInViewport
    || printPreview.kind !== 'print'
    || !printPreview.panelInViewport
    || !printPreview.printButtonVisible
    || !printPreview.shellVisible
    || !printPreview.titleFits
    || printPreview.titleOverlap
    || printPreview.titleText !== '인쇄 미리보기'
  ) {
    errors.push(`${label}: print projection preview failed (${JSON.stringify(printPreview)})`);
  }
  await page.screenshot({
    path: path.join(artifactDir, `worksheet-preview-print-${label}.png`),
    fullPage: false,
  });
  await page.getByRole('button', { name: '닫기', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="preview-shell"]')?.hidden === true);

  await page.getByRole('button', { name: 'PDF', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="export-status"]')?.textContent === 'PDF 다운로드됨');
  const pdfExport = await page.evaluate(() => ({
    body: window.__lastWorksheetPdfExportRequest,
    download: window.__lastExportDownloadRequest,
  }));
  if (
    typeof pdfExport.body?.command_id !== 'string'
    || pdfExport.body.expected?.expected_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2X4'
    || pdfExport.body.expected?.expected_source_hash !== 'a'.repeat(64)
    || pdfExport.body.expected?.expected_state !== 'draft'
    || JSON.stringify(pdfExport.body.payload) !== JSON.stringify({ profile: 'student_blank' })
    || pdfExport.download?.kind !== 'worksheet_pdf'
  ) {
    errors.push(`${label}: C48/Q23/Q24 PDF export drifted (${JSON.stringify(pdfExport)})`);
  }

  await page.getByRole('button', { name: '활동지 파일', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="export-status"]')?.textContent === '활동지 파일 다운로드됨');
  const packageExport = await page.evaluate(() => ({
    body: window.__lastWorksheetPackageExportRequest,
    download: window.__lastExportDownloadRequest,
  }));
  if (
    typeof packageExport.body?.command_id !== 'string'
    || packageExport.body.expected?.expected_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2X4'
    || packageExport.body.expected?.expected_source_hash !== 'a'.repeat(64)
    || packageExport.body.expected?.expected_state !== 'draft'
    || JSON.stringify(packageExport.body.payload) !== JSON.stringify({ format_version: 1 })
    || packageExport.download?.kind !== 'worksheet_package'
  ) {
    errors.push(`${label}: C66/Q23/Q24 worksheet file export drifted (${JSON.stringify(packageExport)})`);
  }

  const exportSurface = await page.evaluate(() => ({
    status: document.querySelector('[data-role="export-status"]')?.textContent,
    pdfEnabled: !document.querySelector('[data-action="export-pdf"]')?.disabled,
    packageEnabled: !document.querySelector('[data-action="export-package"]')?.disabled,
  }));
  if (exportSurface.status !== '활동지 파일 다운로드됨' || !exportSurface.pdfEnabled || !exportSurface.packageEnabled) {
    errors.push(`${label}: export controls did not recover after download (${JSON.stringify(exportSurface)})`);
  }

  await page.locator('[data-field="template-package"]').setInputFiles({
    name: 'ecosystem.ojws',
    mimeType: 'application/vnd.oj.ai-worksheet+zip',
    buffer: Buffer.from('fixture-package'),
  });
  await page.waitForFunction(() => Boolean(window.__lastImportRequest));
  await page.waitForFunction(() => document.querySelector('#ws-template-title')?.value === '가져온 생태 활동지');
  const importContract = await page.evaluate(() => window.__lastImportRequest);
  if (
    importContract.expected_state !== 'draft'
    || importContract.expected_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2X4'
    || importContract.package_name !== 'ecosystem.ojws'
    || importContract.content_type !== ''
  ) {
    errors.push(`${label}: C04 multipart contract drifted (${JSON.stringify(importContract)})`);
  }

  const duplicateBody = await page.evaluate(() => (
    window.OJAIWorksheetAuthoringWorkbench.duplicateCommandBody(
      'duplicate-command',
      'draft',
      '01HZY3M4N5P6Q7R8S9T0V1W2XC'
    )
  ));
  if (
    duplicateBody.command_id !== 'duplicate-command'
    || duplicateBody.expected?.expected_state !== 'draft'
    || duplicateBody.expected?.expected_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2XC'
    || JSON.stringify(duplicateBody.payload) !== '{}'
  ) {
    errors.push(`${label}: C03 exact JSON contract drifted (${JSON.stringify(duplicateBody)})`);
  }

  await page.locator('.oj-ai-workbench__more > summary').click();
  await page.getByRole('button', { name: '문서 검증', exact: true }).click();
  await page.locator('.oj-ai-workbench__more > summary').click();
  await page.waitForFunction(() => !document.querySelector('[data-action="approve"]')?.disabled);
  await page.getByRole('button', { name: '교사 검토 승인', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="save-status"]')?.textContent === '승인됨');
  const approvalBody = await page.evaluate(() => window.__lastApprovalRequest);
  if (
    approvalBody.expected?.expected_state !== 'draft'
    || approvalBody.expected?.expected_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2XC'
    || approvalBody.expected?.expected_document_hash !== 'a'.repeat(64)
    || approvalBody.expected?.expected_validation_projection_hash !== 'c'.repeat(64)
    || approvalBody.expected?.expected_approval_decision_hash !== 'd'.repeat(64)
  ) {
    errors.push(`${label}: C06 workbench contract drifted (${JSON.stringify(approvalBody)})`);
  }

  await page.waitForFunction(() => !document.querySelector('[data-action="publish"]')?.disabled);
  await page.getByRole('button', { name: '발행', exact: true }).click();
  const publishDialog = page.getByRole('dialog', { name: '배포용 버전 발행', exact: true });
  await publishDialog.waitFor({ state: 'visible' });
  if (await page.evaluate(() => window.__publicationRequestCount || 0)) errors.push(`${label}: opening publication confirmation sent a command`);
  await publishDialog.getByRole('button', { name: '취소', exact: true }).click();
  await publishDialog.waitFor({ state: 'detached' });
  if (!(await page.locator('[data-action="publish"]').evaluate(button => button === document.activeElement))) errors.push(`${label}: publication cancel did not restore focus`);
  await page.locator('[data-action="publish"]').click();
  await publishDialog.waitFor({ state: 'visible' });
  await page.keyboard.press('Escape');
  await publishDialog.waitFor({ state: 'detached' });
  if (await page.evaluate(() => window.__publicationRequestCount || 0)) errors.push(`${label}: cancelling publication sent a command`);
  await page.locator('[data-action="publish"]').click();
  await publishDialog.waitFor({ state: 'visible' });
  // A background readiness change must invalidate the pending confirmation.
  await page.locator('[data-action="publish"]').evaluate(button => { button.disabled = true; });
  await publishDialog.getByRole('button', { name: '발행하기', exact: true }).click();
  await publishDialog.waitFor({ state: 'detached' });
  await page.waitForFunction(() => document.querySelector('[data-role="notice"]')?.textContent === '내용이 바뀌었습니다. 문서를 다시 확인해 주세요.');
  if (await page.evaluate(() => window.__publicationRequestCount || 0)) errors.push(`${label}: stale publication readiness sent a command`);
  await page.locator('[data-action="publish"]').evaluate(button => { button.disabled = false; });
  try {
    await page.locator('[data-action="publish"]').click();
  } catch (error) {
    const publicationState = await page.evaluate(() => ({
      notice: document.querySelector('[data-role="notice"]')?.textContent,
      save: document.querySelector('[data-role="save-status"]')?.textContent,
      release: document.querySelector('[data-role="release-title"]')?.textContent,
      actions: Array.from(document.querySelectorAll('[data-action]')).map(button => ({
        action: button.dataset.action, disabled: button.disabled, text: button.textContent,
      })),
      requests: window.__publicationRequestCount || 0,
    }));
    await page.screenshot({ path: path.join(artifactDir, `worksheet-publication-failure-${label}.png`), fullPage: true });
    throw new Error(`${label}: publication recovery ${JSON.stringify(publicationState)}`, { cause: error });
  }
  await publishDialog.waitFor({ state: 'visible' });
  await page.screenshot({ path: path.join(artifactDir, `worksheet-publication-dialog-${label}.png`), fullPage: false });
  const publicationDialogFit = await publishDialog.evaluate(dialog => {
    const bounds = dialog.getBoundingClientRect();
    return bounds.left >= 0 && bounds.right <= innerWidth && dialog.scrollWidth <= dialog.clientWidth + 1;
  });
  if (!publicationDialogFit) errors.push(`${label}: publication confirmation overflows the viewport`);
  await publishDialog.getByRole('button', { name: '발행하기', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="release-title"]')?.textContent === '발행됨');
  if (await page.evaluate(() => window.__publicationRequestCount) !== 1) errors.push(`${label}: publication confirmation did not send exactly one command`);
  const publicationBody = await page.evaluate(() => window.__lastPublicationRequest);
  if (
    publicationBody.expected?.expected_state !== 'approved'
    || publicationBody.expected?.expected_revision_id !== '01HZY3M4N5P6Q7R8S9T0V1W2XC'
    || publicationBody.expected?.expected_document_hash !== 'a'.repeat(64)
    || publicationBody.expected?.expected_approval_decision_hash !== 'd'.repeat(64)
  ) {
    errors.push(`${label}: C07 workbench contract drifted (${JSON.stringify(publicationBody)})`);
  }

  await page.getByRole('button', { name: '보관', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="worksheet-state"]')?.textContent === '보관');
  const archived = await page.evaluate(() => ({
    body: window.__lastLifecycleRequest,
    editorReadOnly: document.querySelector('#ws-plate-template-builder-root')?.dataset.readonly,
    restoreVisible: !document.querySelector('[data-action="restore-worksheet"]')?.hidden,
  }));
  if (
    archived.body.expected?.expected_state !== 'active'
    || archived.body.expected?.expected_lock_version !== 2
    || archived.editorReadOnly !== 'true'
    || !archived.restoreVisible
  ) {
    errors.push(`${label}: C08 workbench contract drifted (${JSON.stringify(archived)})`);
  }

  await page.getByRole('button', { name: '복원', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="worksheet-state"]')?.textContent === '사용 중');
  const restored = await page.evaluate(() => ({
    body: window.__lastRestoreRequest,
    editorReadOnly: document.querySelector('#ws-plate-template-builder-root')?.dataset.readonly,
  }));
  if (
    restored.body.expected?.expected_state !== 'archived'
    || restored.body.expected?.expected_lock_version !== 3
    || restored.editorReadOnly !== 'false'
  ) {
    errors.push(`${label}: C09 workbench contract drifted (${JSON.stringify(restored)})`);
  }
  await page.getByRole('tab', { name: '학습 목표', exact: true }).click();
  await page.waitForFunction(() => (
    document.querySelector('[data-role="achievement-status"]')?.textContent === '1개 연결'
    && document.querySelectorAll('[data-role="achievement-standards"] .oj-ai-workbench__standard-row').length === 2
    && document.querySelectorAll('[data-role="learning-goals"] .oj-ai-workbench__learning-goal').length === 1
  ));
  await page.locator('[data-field="learning-goal-text"]').fill('환경 보호 방법을 제안할 수 있다.');
  await page.locator('[data-field="learning-goal-add-target"]').selectOption('worksheet');
  await page.getByRole('button', { name: '목표 추가', exact: true }).click();
  await page.waitForFunction(() => (
    document.querySelectorAll('[data-role="learning-goals"] .oj-ai-workbench__learning-goal').length === 2
  ));
  const goalAddTarget = page.locator('[data-field="achievement-add-target"]');
  if (await goalAddTarget.locator('option').count() < 2) {
    errors.push(`${label}: learning goal add target does not expose worksheet and response targets`);
  }
  await goalAddTarget.selectOption('worksheet');
  await page.locator('[data-role="achievement-standards"] .oj-ai-workbench__standard-row').nth(1).getByRole('button', { name: '연결', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="achievement-status"]')?.textContent === '2개 연결');
  const goalPanel = await page.evaluate(() => {
    const panel = document.querySelector('[data-inspector-panel="goals"]');
    const inspector = document.querySelector('.oj-ai-workbench__inspector');
    const targets = Array.from(panel?.querySelectorAll('[data-field="achievement-target"]') || []).map((control) => control.value);
    const learningGoalTargets = Array.from(panel?.querySelectorAll('[data-field="learning-goal-target"]') || []).map((control) => control.value);
    const learningGoalTexts = Array.from(panel?.querySelectorAll('[data-field="learning-goal-edit"]') || []).map((control) => control.value);
    const addTarget = panel?.querySelector('[data-field="achievement-add-target"]');
    return {
      visible: Boolean(panel && !panel.hidden && panel.getClientRects().length),
      learningGoalCount: learningGoalTexts.length,
      learningGoalTargets,
      learningGoalTexts,
      linkCount: panel?.querySelectorAll('.oj-ai-workbench__achievement-link').length || 0,
      standardCount: panel?.querySelectorAll('.oj-ai-workbench__standard-row').length || 0,
      targetCount: addTarget?.options.length || 0,
      targets,
      overflow: Boolean(panel && panel.scrollWidth > panel.clientWidth + 1),
      insideInspector: Boolean(panel && inspector && panel.getBoundingClientRect().right <= inspector.getBoundingClientRect().right + 1),
    };
  });
  if (
    !goalPanel.visible || goalPanel.learningGoalCount !== 2
    || !goalPanel.learningGoalTargets.includes('worksheet')
    || !goalPanel.learningGoalTargets.some((target) => target.startsWith('response:'))
    || !goalPanel.learningGoalTexts.includes('환경 보호 방법을 제안할 수 있다.')
    || goalPanel.linkCount !== 2 || goalPanel.standardCount !== 2 || goalPanel.targetCount < 2
    || !goalPanel.targets.includes('worksheet') || !goalPanel.targets.some((target) => target.startsWith('response:'))
    || goalPanel.overflow || !goalPanel.insideInspector
  ) {
    errors.push(`${label}: worksheet-scoped learning goal inspector failed (${JSON.stringify(goalPanel)})`);
  }
  await page.screenshot({
    path: path.join(artifactDir, `worksheet-goals-${label}.png`),
    fullPage: true,
  });

  await page.getByRole('tab', { name: '평가 연결', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-role="assessment-map-summary"]')?.textContent === '3/3개 사용');
  const assessmentRows = page.locator('[data-inspector-panel="assessment"] .oj-ai-workbench__assessment-row');
  await assessmentRows.nth(0).locator('[data-field="assessment-target"]').selectOption('worksheet');
  await assessmentRows.nth(2).locator('[data-field="assessment-target"]').selectOption({ index: 1 });
  await assessmentRows.nth(1).locator('[data-field="assessment-enabled"]').uncheck();
  await page.waitForFunction(() => document.querySelector('[data-role="assessment-map-summary"]')?.textContent === '2/3개 사용');
  const assessmentPanel = await page.evaluate(() => {
    const panel = document.querySelector('[data-inspector-panel="assessment"]');
    const rows = Array.from(panel?.querySelectorAll('.oj-ai-workbench__assessment-row') || []);
    const enabledRows = rows.filter((row) => row.querySelector('[data-field="assessment-enabled"]')?.checked);
    return {
      visible: Boolean(panel && !panel.hidden && panel.getClientRects().length),
      rowCount: rows.length,
      describedCount: rows.filter((row) => Boolean(row.querySelector('p')?.textContent?.trim())).length,
      enabledCount: enabledRows.length,
      targets: enabledRows.map((row) => row.querySelector('[data-field="assessment-target"]')?.value || ''),
      disabledTargetCount: rows.filter((row) => row.querySelector('[data-field="assessment-target"]')?.disabled).length,
      overflow: Boolean(panel && panel.scrollWidth > panel.clientWidth + 1),
    };
  });
  if (
    !assessmentPanel.visible || assessmentPanel.rowCount !== 3 || assessmentPanel.describedCount !== 3
    || assessmentPanel.enabledCount !== 2 || assessmentPanel.disabledTargetCount !== 1
    || !assessmentPanel.targets.includes('worksheet') || !assessmentPanel.targets.some((target) => target.startsWith('response:'))
    || assessmentPanel.overflow
  ) {
    errors.push(`${label}: worksheet criterion selection failed (${JSON.stringify(assessmentPanel)})`);
  }
  await page.evaluate(() => {
    window.__lastSavedTeacherDocument = null;
    window.__lastSavedAchievementLinks = null;
  });
  await page.getByRole('button', { name: '저장', exact: true }).click();
  await page.waitForFunction(() => Boolean(window.__lastSavedTeacherDocument && window.__lastSavedAchievementLinks));
  const scopedSave = await page.evaluate(() => ({
    assessmentBindings: window.__lastSavedTeacherDocument?.settings?.assessment_bindings || [],
    learningGoals: window.__lastSavedTeacherDocument?.settings?.learning_goals || [],
    achievementLinks: window.__lastSavedAchievementLinks || [],
  }));
  const expectedAssessmentBindings = [
    { criterion_id: '01K00000000000000000000211', scope: 'worksheet', response_id: null },
    { criterion_id: '01K00000000000000000000213', scope: 'response', response_id: '01HZY3M4N5P6Q7R8S9T0V1W2XQ' },
  ];
  if (JSON.stringify(scopedSave.assessmentBindings) !== JSON.stringify(expectedAssessmentBindings)) {
    errors.push(`${label}: worksheet assessment bindings were not saved (${JSON.stringify(scopedSave.assessmentBindings)})`);
  }
  if (
    scopedSave.learningGoals.length !== 2
    || !scopedSave.learningGoals.some((goal) => goal.text === '환경 보호 방법을 제안할 수 있다.' && goal.scope === 'worksheet' && goal.response_id === null)
    || !scopedSave.learningGoals.some((goal) => goal.scope === 'response' && goal.response_id === '01HZY3M4N5P6Q7R8S9T0V1W2XQ')
  ) {
    errors.push(`${label}: worksheet learning goals were not saved (${JSON.stringify(scopedSave.learningGoals)})`);
  }
  if (
    scopedSave.achievementLinks.length !== 2
    || !scopedSave.achievementLinks.some((link) => link.scope === 'worksheet' && link.standard_public_id === '01K00000000000000000000302')
    || !scopedSave.achievementLinks.some((link) => link.scope === 'response' && link.standard_public_id === '01K00000000000000000000301')
  ) {
    errors.push(`${label}: worksheet learning goal targets were not saved (${JSON.stringify(scopedSave.achievementLinks)})`);
  }
  await page.screenshot({
    path: path.join(artifactDir, `worksheet-assessment-map-${label}.png`),
    fullPage: true,
  });
  await page.getByRole('tab', { name: '문항', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-inspector-panel="question"]')?.hidden === false);
  const layout = await page.evaluate(() => {
    const rect = (selector) => document.querySelector(selector)?.getBoundingClientRect();
    const rootRect = rect('#oj-ai-worksheet-authoring-app');
    const editorRect = rect('[contenteditable="true"]');
    const controlsRect = rect('.hws-response-toolbar-button');
    const saveRect = rect('[data-action="save"]');
    const titleRect = rect('.oj-ai-workbench__heading');
    const actionsRect = rect('.oj-ai-workbench__actions');
    const moreRect = rect('.oj-ai-workbench__more > summary');
    const overlaps = (a, b) => Boolean(
      a && b && a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top
    );
    const releaseRect = rect('[data-role="release-controls"]');
    const exportRect = rect('[data-role="export-controls"]');
    const exportStatus = document.querySelector('[data-role="export-status"]');
    const phaseItems = Array.from(document.querySelectorAll('.oj-ai-workbench__phases li'));
    const outline = document.querySelector('.oj-ai-workbench__outline');
    const canvas = document.querySelector('.oj-ai-workbench__canvas');
    const inspector = document.querySelector('.oj-ai-workbench__inspector');
    const workspace = document.querySelector('.oj-ai-workbench__workspace');
    const inspectorFields = document.querySelector('#ws-response-inspector-root [data-hws-response-editor="1"]:not([hidden])');
    const qualityLink = document.querySelector('[data-role="quality-link"]');
    const trackRects = [outline, canvas, inspector]
      .filter((element) => element && element.getClientRects().length > 0)
      .map((element) => element.getBoundingClientRect());
    const workspaceRect = workspace?.getBoundingClientRect();
    const offscreenResponseControls = Array.from(document.querySelectorAll('.hws-response-toolbar-button'))
      .filter((button) => {
        const buttonRect = button.getBoundingClientRect();
        return buttonRect.left < -1 || buttonRect.right > window.innerWidth + 1;
      })
      .map(() => '응답');
    const offscreenExportControls = Array.from(document.querySelectorAll('[data-action="export-pdf"], [data-action="export-package"]'))
      .filter((button) => {
        const buttonRect = button.getBoundingClientRect();
        return buttonRect.left < -1 || buttonRect.right > window.innerWidth + 1;
      })
      .map((button) => button.textContent?.trim() || '?');
    const icons = Array.from(document.querySelectorAll('#oj-ai-worksheet-authoring-app .oj-ai-icon'))
      .filter((icon) => icon.getClientRects().length > 0);
    const brokenIcons = icons.filter((icon) => {
      const iconRect = icon.getBoundingClientRect();
      const style = getComputedStyle(icon);
      return iconRect.width > 24 || iconRect.height > 24 || style.fill !== 'none';
    }).map((icon) => icon.querySelector('use')?.getAttribute('href') || 'icon');

    return {
      controlsVisible: Boolean(controlsRect && controlsRect.width > 0 && controlsRect.height > 0),
      editorVisible: Boolean(editorRect && editorRect.width > 0 && editorRect.height > 0),
      phaseCount: phaseItems.length,
      currentPhaseCount: phaseItems.filter((item) => item.querySelector('[aria-current="step"]')).length,
      outlineVisible: Boolean(outline && outline.getClientRects().length > 0),
      outlineLoaded: document.querySelectorAll('[data-role="document-outline"] li:not(.oj-ai-workbench__outline-empty)').length > 0,
      canvasVisible: Boolean(canvas && canvas.getClientRects().length > 0),
      inspectorVisible: Boolean(inspector && inspector.getClientRects().length > 0),
      inspectorTabCount: document.querySelectorAll('[data-inspector-tab]').length,
      inspectorFieldsVisible: Boolean(inspectorFields && inspectorFields.getClientRects().length > 0),
      qualityLinkVisible: Boolean(qualityLink && qualityLink.getClientRects().length > 0),
      qualityLinkContext: String(qualityLink?.href || '').includes('worksheet_id=01HZY3M4N5P6Q7R8S9T0V1W2X3'),
      workspaceOverlap: trackRects.some((item, index) => trackRects.slice(index + 1).some((candidate) => overlaps(item, candidate))),
      headerOverlap: window.innerWidth > 760 && overlaps(titleRect, actionsRect),
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
      workspaceTracksFit: Boolean(workspaceRect && trackRects.every((item) => (
        item.left >= workspaceRect.left - 1 && item.right <= workspaceRect.right + 1
      ))),
      inspectorTextWraps: getComputedStyle(
        document.querySelector('.oj-ai-workbench__inspector-empty')
      ).whiteSpace === 'normal',
      workflowInset: ['release-controls', 'export-controls'].every((role) => {
        const section = document.querySelector(`[data-role="${role}"]`);
        if (!section) return false;
        const style = getComputedStyle(section);
        return parseFloat(style.paddingLeft) >= 12 && parseFloat(style.paddingRight) >= 12;
      }),
      offscreenResponseControls,
      offscreenExportControls,
      rootVisible: Boolean(rootRect && rootRect.width > 0 && rootRect.height > 0),
      releaseVisible: Boolean(releaseRect && releaseRect.width > 0 && releaseRect.height > 0),
      exportsVisible: Boolean(exportRect && exportRect.width > 0 && exportRect.height > 0),
      exportStatusFits: Boolean(exportStatus && exportStatus.scrollWidth <= exportStatus.clientWidth + 1),
      saveVisible: Boolean(saveRect && saveRect.width > 0 && saveRect.height > 0),
      toolsVisible: Boolean(moreRect && moreRect.width > 0 && moreRect.height > 0),
      iconCount: icons.length,
      brokenIcons,
    };
  });
  verifyLayout(layout, label);
  pageErrors.forEach((message) => errors.push(`${label}: browser error: ${message}`));
  await page.screenshot({
    path: path.join(artifactDir, `worksheet-workbench-${label}.png`),
    fullPage: true,
  });
  await page.close();
  console.log(`[OK] ${label} ${JSON.stringify(layout)}`);
}

try {
  const playwright = await loadPlaywright();
  const browser = await playwright.chromium.launch(browserLaunchOptions());
  await inspectPage(browser, { width: 1440, height: 1000 }, 'desktop');
  await inspectPage(browser, { width: 1116, height: 820 }, 'compact-desktop');
  await inspectPage(browser, { width: 1024, height: 768 }, 'tablet');
  await inspectPage(browser, { width: 390, height: 844 }, 'mobile');
  await browser.close();
} catch (error) {
  errors.push(`Playwright workbench check failed: ${error.stack || error.message}`);
}

if (errors.length > 0) {
  console.error('Worksheet workbench visual checks failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('Worksheet workbench desktop/tablet/mobile visual checks passed.');

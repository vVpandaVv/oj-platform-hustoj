(function (window, document) {
  'use strict';

  const RESOURCE_PATHS = Object.freeze({
    policy: Object.freeze({ list: '/settings/policies', create: '/settings/policies/revisions', key: 'evaluation_policies' }),
    rubric: Object.freeze({ list: '/settings/rubrics', create: '/settings/rubrics/revisions', key: 'rubrics' }),
    review_rule: Object.freeze({ list: '/settings/review-rules', create: '/settings/review-rules/revisions', key: 'review_rules' }),
  });
  const PUBLIC_ID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

  function commandEnvelope(commandId, expected, payload) {
    return { command_id: commandId, expected: { ...expected }, payload: { ...payload } };
  }

  function createRevisionCommand(commandId, latestRevisionNo, payload) {
    return commandEnvelope(commandId, { expected_latest_revision_no: latestRevisionNo }, payload);
  }

  function activateRevisionCommand(commandId, revision, activeRevisionId) {
    return commandEnvelope(commandId, {
      expected_active_revision_id: activeRevisionId || null,
      expected_lock_version: revision.lock_version,
      expected_state: 'draft',
    }, {});
  }

  window.OJAIAssessmentConfigurationSettings = Object.freeze({
    commandEnvelope,
    createRevisionCommand,
    activateRevisionCommand,
  });

  const root = document.querySelector('#oj-ai-assessment-settings');
  const app = document.querySelector('#oj-ai-assessment-configuration-app');
  if (!root || !app) return;

  function worksheetPolicyContract() {
    const values = {
      promptContract: root.dataset.worksheetPromptContract || '',
      promptHash: root.dataset.worksheetPromptHash || '',
      inputSchema: root.dataset.worksheetInputSchema || '',
      inputVersion: root.dataset.worksheetInputVersion || '',
      inputHash: root.dataset.worksheetInputHash || '',
      outputSchema: root.dataset.worksheetOutputSchema || '',
      outputVersion: root.dataset.worksheetOutputVersion || '',
      outputHash: root.dataset.worksheetOutputHash || '',
    };
    if (Object.values(values).some((value) => !value)) return null;
    return {
      prompt: { content_hash: values.promptHash, contract_version: values.promptContract },
      input_schema: { content_hash: values.inputHash, schema_id: values.inputSchema, schema_version: values.inputVersion },
      output_schema: { content_hash: values.outputHash, schema_id: values.outputSchema, schema_version: values.outputVersion },
    };
  }

  const currentWorksheetPolicy = worksheetPolicyContract();

  const elements = {
    notice: app.querySelector('[data-config-role="notice"]'),
    subjectSwitch: root.querySelector('[data-config-role="subject-switch"]'),
    refresh: root.querySelector('[data-config-action="refresh"]'),
    subjectButtons: [...root.querySelectorAll('[data-config-subject]')],
    tabButtons: [...root.querySelectorAll('[data-settings-tab]')],
    panels: [...root.querySelectorAll('[data-settings-panel]')],
    policyEnabled: app.querySelector('[data-config-field="policy-enabled"]'),
    policySummary: app.querySelector('[data-config-role="policy-summary"]'),
    policyError: app.querySelector('[data-config-role="policy-error"]'),
    policyPanel: app.querySelector('[data-settings-panel="policy"]'),
    policyRevisions: app.querySelector('[data-config-role="policy-revisions"]'),
    rubricSummary: app.querySelector('[data-config-role="rubric-summary"]'),
    rubricError: app.querySelector('[data-config-role="rubric-error"]'),
    rubricPanel: app.querySelector('[data-settings-panel="rubric"]'),
    rubricEditor: app.querySelector('[data-config-role="rubric-editor"]'),
    rubricRevisions: app.querySelector('[data-config-role="rubric-revisions"]'),
    reviewSummary: app.querySelector('[data-config-role="review-rule-summary"]'),
    reviewError: app.querySelector('[data-config-role="review-rule-error"]'),
    reviewPanel: app.querySelector('[data-settings-panel="review-rule"]'),
    reviewThreshold: app.querySelector('[data-config-field="confidence-threshold"]'),
    reviewRevisions: app.querySelector('[data-config-role="review-rule-revisions"]'),
  };

  const state = {
    busy: false,
    ready: false,
    subject: 'worksheet_quality',
    resources: { policy: null, rubric: null, review_rule: null },
    errors: { policy: null, rubric: null, review_rule: null },
  };

  function clone(value) {
    return window.structuredClone ? window.structuredClone(value) : JSON.parse(JSON.stringify(value));
  }

  function token(prefix) {
    const random = window.crypto?.randomUUID
      ? window.crypto.randomUUID().replaceAll('-', '')
      : `${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
    return `${prefix}:${Date.now().toString(36)}:${random}`;
  }

  function publicId() {
    const bytes = new Uint8Array(26);
    window.crypto.getRandomValues(bytes);
    return [...bytes].map((value) => PUBLIC_ID_ALPHABET[value % PUBLIC_ID_ALPHABET.length]).join('');
  }

  function textNode(tag, className, value) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    node.textContent = value;
    return node;
  }

  function button(label, action, className = 'oj-ai-config__button') {
    const control = document.createElement('button');
    control.type = 'button';
    control.className = className;
    control.dataset.configAction = action;
    control.textContent = label;
    return control;
  }

  function input(type, value, label) {
    const control = document.createElement('input');
    control.type = type;
    control.value = value;
    control.setAttribute('aria-label', label);
    return control;
  }

  function showNotice(message, tone = 'neutral') {
    elements.notice.textContent = message;
    elements.notice.dataset.tone = tone;
    elements.notice.hidden = !message;
  }

  function resourceUi(resourceType) {
    return {
      policy: { panel: elements.policyPanel, error: elements.policyError, summary: elements.policySummary },
      rubric: { panel: elements.rubricPanel, error: elements.rubricError, summary: elements.rubricSummary },
      review_rule: { panel: elements.reviewPanel, error: elements.reviewError, summary: elements.reviewSummary },
    }[resourceType];
  }

  function resourceLabel(resourceType) {
    return {
      policy: '평가 정책',
      rubric: '평가 기준',
      review_rule: '검토·승인 기준',
    }[resourceType] || '평가 설정';
  }

  function resourceErrorMessage(resourceType, error) {
    if (Number(error?.status) === 401) return '로그인 상태를 확인한 뒤 다시 시도해 주세요.';
    if (Number(error?.status) === 403) return `${resourceLabel(resourceType)}을 관리할 권한이 없습니다.`;
    return error?.message || `${resourceLabel(resourceType)}을 불러오지 못했습니다.`;
  }

  function policyUsesCurrentContract(revision) {
    if (!currentWorksheetPolicy || !revision?.value) return false;
    const value = revision.value;
    return value.prompt?.contract_version === currentWorksheetPolicy.prompt.contract_version
      && value.prompt?.content_hash === currentWorksheetPolicy.prompt.content_hash
      && value.input_schema?.schema_id === currentWorksheetPolicy.input_schema.schema_id
      && value.input_schema?.schema_version === currentWorksheetPolicy.input_schema.schema_version
      && value.input_schema?.content_hash === currentWorksheetPolicy.input_schema.content_hash
      && value.output_schema?.schema_id === currentWorksheetPolicy.output_schema.schema_id
      && value.output_schema?.schema_version === currentWorksheetPolicy.output_schema.schema_version
      && value.output_schema?.content_hash === currentWorksheetPolicy.output_schema.content_hash;
  }

  function policyUpdateRequired() {
    const revision = activeRevision('policy');
    if (!revision) return false;
    return !policyUsesActiveReferences(revision)
      || (state.subject === 'worksheet_quality' && !policyUsesCurrentContract(revision));
  }

  function policyUsesActiveReferences(revision) {
    const rubric = activeRevision('rubric');
    const review = activeRevision('review_rule');
    return Boolean(revision?.value && rubric && review)
      && revision.value.rubric_revision_id === rubric.revision_id
      && revision.value.review_rule_revision_id === review.revision_id;
  }

  function policyUpdateMessage(revision) {
    if (!policyUsesActiveReferences(revision)) {
      return '평가 기준 연결을 반영하려면 새 초안을 저장하고 사용해 주세요.';
    }
    return '현재 평가 방식으로 새 초안을 저장하고 사용해 주세요.';
  }

  function renderResourceState(resourceType) {
    const ui = resourceUi(resourceType);
    if (!ui) return;
    const error = state.errors[resourceType];
    const available = Boolean(state.resources[resourceType]) && !error;
    const updateRequired = resourceType === 'policy' && !error && policyUpdateRequired();
    if (ui.error) {
      ui.error.textContent = error
        ? resourceErrorMessage(resourceType, error)
        : (updateRequired ? policyUpdateMessage(activeRevision('policy')) : '');
      ui.error.dataset.tone = updateRequired ? 'warning' : 'danger';
      ui.error.hidden = !error && !updateRequired;
    }
    if (ui.summary) {
      ui.summary.dataset.status = error ? 'error' : (updateRequired ? 'warning' : (available ? 'ready' : 'loading'));
      if (error) ui.summary.textContent = '불러오기 실패';
      if (updateRequired) ui.summary.textContent = '업데이트 필요';
    }
  }

  function applyResourceAvailability() {
    Object.keys(RESOURCE_PATHS).forEach((resourceType) => {
      const ui = resourceUi(resourceType);
      if (!ui?.panel) return;
      const available = Boolean(state.resources[resourceType]) && !state.errors[resourceType];
      ui.panel.querySelectorAll('button, input, select, textarea').forEach((control) => {
        if (control.hasAttribute('data-config-fixed')) return;
        control.disabled = state.busy || !available;
      });
    });
    if (!state.busy && state.resources.policy && !state.errors.policy && !editableRevision('policy')) {
      elements.policyEnabled.disabled = true;
    }
    if (!state.busy && state.resources.rubric && !state.errors.rubric && state.subject === 'worksheet_quality') {
      const add = elements.rubricEditor.querySelector('[data-config-action="add-dimension"]');
      if (add) add.disabled = elements.rubricEditor.querySelectorAll('[data-config-item="dimension"]').length >= 12;
    }
  }

  function setBusy(value) {
    state.busy = value;
    root.dataset.busy = value ? '1' : '0';
    elements.subjectButtons.forEach((control) => { control.disabled = value; });
    if (elements.refresh) elements.refresh.disabled = value;
    applyResourceAvailability();
  }

  async function request(path, options = {}) {
    const method = options.method || 'GET';
    const headers = { Accept: 'application/json' };
    headers['X-WP-Nonce'] = root.dataset.restNonce || '';
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      headers['Idempotency-Key'] = options.idempotencyKey || token('idem');
    }
    const response = await window.fetch(`${root.dataset.restRoot}${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: method === 'GET' ? undefined : JSON.stringify(options.body || {}),
    });
    let payload = null;
    try {
      payload = await response.json();
    } catch (_error) {
      payload = null;
    }
    if (!response.ok) {
      const error = new Error(payload?.message || '요청을 처리하지 못했습니다.');
      error.status = Number(payload?.data?.status || response.status || 500);
      error.code = String(payload?.code || payload?.data?.code || '');
      error.correlationId = String(payload?.data?.correlation_id || '');
      throw error;
    }
    if (!payload || typeof payload !== 'object' || !payload.data) {
      const error = new Error('응답을 확인할 수 없습니다.');
      error.status = 503;
      throw error;
    }
    return payload.data;
  }

  function subjectData(resourceType) {
    const resource = state.resources[resourceType];
    if (!resource || !Array.isArray(resource.subjects)) return null;
    return resource.subjects.find((subject) => subject.subject_type === state.subject) || null;
  }

  function activeRevision(resourceType) {
    const subject = subjectData(resourceType);
    if (!subject || !Array.isArray(subject.revisions)) return null;
    return subject.revisions.find((revision) => revision.revision_id === subject.active_revision_id) || null;
  }

  function editableRevision(resourceType) {
    const subject = subjectData(resourceType);
    return activeRevision(resourceType) || subject?.revisions?.[0] || null;
  }

  function subjectLabel() {
    return state.subject === 'worksheet_quality' ? '활동지 자체 평가' : '학생 제출 평가';
  }

  function summary(resourceType) {
    const active = activeRevision(resourceType);
    return active ? `사용 중 · ${active.revision_no}차` : '설정 필요';
  }

  function revisionStateLabel(value) {
    return { active: '사용 중', draft: '초안', retired: '이전' }[value] || '확인 필요';
  }

  function renderRevisionList(resourceType, container) {
    const subject = subjectData(resourceType);
    const rows = Array.isArray(subject?.revisions) ? subject.revisions : [];
    const fragment = document.createDocumentFragment();
    if (rows.length === 0) {
      fragment.append(textNode('p', 'oj-ai-config__empty', '저장된 설정이 없습니다.'));
    }
    rows.forEach((revision) => {
      const row = document.createElement('div');
      row.className = 'oj-ai-config__history-row';
      const description = document.createElement('div');
      description.append(textNode('strong', '', `${revision.revision_no}차`));
      const badge = textNode('span', 'oj-ai-config__badge', revisionStateLabel(revision.state));
      badge.dataset.state = revision.state;
      description.append(badge);
      description.append(textNode('small', '', revision.activated_at_utc || revision.updated_at_utc || ''));
      row.append(description);
      if (revision.state === 'draft') {
        const activate = button('사용하기', 'activate', 'oj-ai-config__button oj-ai-config__button--primary');
        activate.dataset.resourceType = resourceType;
        activate.dataset.revisionId = revision.revision_id;
        row.append(activate);
      }
      fragment.append(row);
    });
    container.replaceChildren(fragment);
  }

  function renderPolicy() {
    const revision = editableRevision('policy');
    elements.policySummary.textContent = summary('policy');
    const key = state.subject === 'worksheet_quality' ? 'evaluation_enabled' : 'assessment_enabled';
    elements.policyEnabled.checked = revision?.value?.[key] !== false;
    elements.policyEnabled.disabled = state.busy || !revision;
    renderRevisionList('policy', elements.policyRevisions);
  }

  function field(label, control) {
    const wrapper = document.createElement('label');
    wrapper.className = 'oj-ai-config__field';
    wrapper.append(textNode('span', '', label), control);
    return wrapper;
  }

  function dimensionRow(dimension, index) {
    const row = document.createElement('div');
    row.className = 'oj-ai-config__editor-row';
    row.dataset.configItem = 'dimension';
    row.dataset.key = dimension.dimension_key;
    row.append(textNode('span', 'oj-ai-config__position', String(index + 1)));
    const label = input('text', dimension.label || '', '평가 항목');
    label.dataset.field = 'label';
    const description = document.createElement('textarea');
    description.rows = 2;
    description.value = dimension.description || '';
    description.dataset.field = 'description';
    description.setAttribute('aria-label', '확인 기준');
    row.append(field('평가 항목', label), field('확인 기준', description));
    if (index >= 6) {
      const remove = button('삭제', 'remove-item', 'oj-ai-config__icon-button');
      remove.setAttribute('aria-label', '평가 항목 삭제');
      row.append(remove);
    }
    return row;
  }

  function levelRow(level, index) {
    const row = document.createElement('div');
    row.className = 'oj-ai-config__level-row';
    row.dataset.levelItem = 'level';
    row.dataset.id = level.level_key || publicId();
    row.append(textNode('span', 'oj-ai-config__position', String(index + 1)));
    const label = input('text', level.label || '', '등급 설명');
    label.dataset.levelField = 'label';
    const points = input('number', level.points ?? '0', '점수');
    points.min = '0';
    points.max = '99999999';
    points.step = '0.5';
    points.dataset.levelField = 'points';
    row.append(field('등급 설명', label), field('점수', points));
    const remove = button('삭제', 'remove-level', 'oj-ai-config__icon-button');
    remove.setAttribute('aria-label', '등급 삭제');
    row.append(remove);
    return row;
  }

  function updateCriterionMaxPoints(criterionRowEl) {
    const display = criterionRowEl.querySelector('[data-role="max-points-display"]');
    if (!display) return;
    const points = [...criterionRowEl.querySelectorAll('[data-level-field="points"]')]
      .map((control) => Number(control.value))
      .filter((value) => Number.isFinite(value));
    display.textContent = points.length ? `배점(자동 계산): ${Math.max(...points)}점` : '등급을 2개 이상 추가해 주세요.';
  }

  function criterionLevels(criterion) {
    if (Array.isArray(criterion.levels) && criterion.levels.length >= 2) {
      return criterion.levels;
    }
    const rawMaximum = String(criterion.maximum_points ?? '10');
    const maximum = Number(rawMaximum);
    const maximumPoints = Number.isFinite(maximum) && maximum > 0 ? rawMaximum : '10';
    return [
      { level_key: publicId(), label: '근거를 사용해 요구한 내용을 충분히 설명함', points: maximumPoints },
      { level_key: publicId(), label: '근거가 부족하거나 요구한 내용을 설명하지 못함', points: '0' },
    ];
  }

  function criterionRow(criterion, index) {
    const row = document.createElement('div');
    row.className = 'oj-ai-config__editor-row oj-ai-config__editor-row--criterion';
    row.dataset.configItem = 'criterion';
    row.dataset.id = criterion.criterion_id || publicId();
    row.append(textNode('span', 'oj-ai-config__position', String(index + 1)));
    const label = input('text', criterion.label || '', '평가 항목');
    label.dataset.field = 'label';
    const scope = document.createElement('select');
    scope.dataset.field = 'scope';
    [['submission', '활동지 전체'], ['response', '문항 응답']].forEach(([value, text]) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = text;
      option.selected = value === criterion.scope;
      scope.append(option);
    });
    row.append(field('평가 항목', label), field('기본 대상', scope));
    const description = document.createElement('textarea');
    description.rows = 2;
    description.value = criterion.description
      || '학생 응답에서 이 항목을 판단할 수 있는 구체적인 근거를 확인합니다.';
    description.dataset.field = 'description';
    description.setAttribute('aria-label', '평가 방법');
    const descriptionField = field('평가 방법', description);
    descriptionField.classList.add('oj-ai-config__field--description');
    const remove = button('삭제', 'remove-item', 'oj-ai-config__icon-button');
    remove.setAttribute('aria-label', '평가 항목 삭제');
    row.append(remove);
    row.append(descriptionField);

    const levelsSection = document.createElement('div');
    levelsSection.className = 'oj-ai-config__levels';
    levelsSection.append(textNode('span', 'oj-ai-config__levels-label', '등급별 배점 (필수, 최소 2개, 점수 중복 불가)'));
    const levelsList = document.createElement('div');
    levelsList.className = 'oj-ai-config__levels-list';
    const levels = criterionLevels(criterion);
    levels.forEach((level, levelIndex) => levelsList.append(levelRow(level, levelIndex)));
    levelsSection.append(levelsList);
    levelsSection.append(button('등급 추가', 'add-level'));
    const maxDisplay = document.createElement('p');
    maxDisplay.className = 'oj-ai-config__max-points';
    maxDisplay.dataset.role = 'max-points-display';
    levelsSection.append(maxDisplay);
    row.append(levelsSection);
    updateCriterionMaxPoints(row);
    return row;
  }

  function renderRubric() {
    const guidance = root.querySelector('[data-config-role="rubric-guidance"]');
    if (guidance) guidance.textContent = state.subject === 'worksheet_quality'
      ? '각 항목이 활동지에서 확인할 구체적인 근거를 정합니다.'
      : '공통 평가 요소와 기본 대상을 정합니다. 사용할 요소와 실제 대상은 각 활동지에서 선택합니다.';
    elements.rubricSummary.textContent = summary('rubric');
    const revision = editableRevision('rubric');
    const value = revision?.value || {};
    const fragment = document.createDocumentFragment();
    if (state.subject === 'worksheet_quality') {
      const dimensions = Array.isArray(value.dimensions) ? value.dimensions : [];
      dimensions.forEach((dimension, index) => fragment.append(dimensionRow(dimension, index)));
      const add = button('평가 항목 추가', 'add-dimension');
      add.disabled = state.busy || dimensions.length >= 12;
      fragment.append(add);
    } else {
      const criteria = Array.isArray(value.criteria) ? value.criteria : [];
      criteria.forEach((criterion, index) => fragment.append(criterionRow(criterion, index)));
      fragment.append(button('평가 항목 추가', 'add-criterion'));
    }
    elements.rubricEditor.replaceChildren(fragment);
    renderRevisionList('rubric', elements.rubricRevisions);
  }

  function renderReviewRule() {
    const revision = editableRevision('review_rule');
    elements.reviewSummary.textContent = summary('review_rule');
    elements.reviewThreshold.value = revision?.confidence_threshold || '0.8';
    renderRevisionList('review_rule', elements.reviewRevisions);
  }

  function render() {
    elements.subjectButtons.forEach((control) => {
      const selected = control.dataset.configSubject === state.subject;
      control.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    root.querySelectorAll('[data-config-role="subject-label"]').forEach((node) => {
      node.textContent = subjectLabel();
    });
    renderPolicy();
    renderRubric();
    renderReviewRule();
    Object.keys(RESOURCE_PATHS).forEach(renderResourceState);
    applyResourceAvailability();
  }

  async function load() {
    let loadedCount = 0;
    state.ready = false;
    setBusy(true);
    showNotice('평가 설정을 불러오는 중입니다.');
    try {
      const entries = Object.entries(RESOURCE_PATHS);
      const results = await Promise.allSettled(entries.map(async ([resourceType, route]) => {
        const data = await request(route.list);
        return [resourceType, data[route.key]];
      }));
      results.forEach((result, index) => {
        const resourceType = entries[index][0];
        if (result.status === 'fulfilled') {
          state.resources[resourceType] = result.value[1];
          state.errors[resourceType] = null;
          loadedCount += 1;
          return;
        }
        state.resources[resourceType] = null;
        state.errors[resourceType] = result.reason instanceof Error
          ? result.reason
          : new Error(`${resourceLabel(resourceType)}을 불러오지 못했습니다.`);
      });
      state.ready = loadedCount > 0;
      render();
      const failedCount = entries.length - loadedCount;
      if (failedCount === 0) {
        showNotice('');
      } else if (loadedCount === 0) {
        showNotice('평가 설정을 불러오지 못했습니다. 각 탭의 상태를 확인해 주세요.', 'danger');
      } else {
        showNotice('일부 설정을 불러오지 못했습니다. 나머지 설정은 계속 사용할 수 있습니다.', 'danger');
      }
    } catch (error) {
      showNotice(error?.message || '평가 설정을 불러오지 못했습니다.', 'danger');
    } finally {
      setBusy(false);
    }
    return loadedCount > 0;
  }

  function decimalUnits(value, allowZero = false) {
    const normalized = String(value).trim();
    const match = normalized.match(/^(?:0|[1-9][0-9]{0,7})(?:\.([0-9]{1,4}))?$/);
    if (!match) throw new Error('배점을 확인해 주세요.');
    const [whole, fraction = ''] = normalized.split('.');
    const units = Number(whole) * 10000 + Number(fraction.padEnd(4, '0'));
    if (units < 1 && !allowZero) throw new Error('배점은 0보다 커야 합니다.');
    return units;
  }

  function decimalString(units) {
    const whole = Math.floor(units / 10000);
    const fraction = String(units % 10000).padStart(4, '0').replace(/0+$/, '');
    return fraction ? `${whole}.${fraction}` : String(whole);
  }

  function rubricPayload() {
    if (state.subject === 'worksheet_quality') {
      const dimensions = [...elements.rubricEditor.querySelectorAll('[data-config-item="dimension"]')].map((row) => ({
        dimension_key: row.dataset.key,
        label: row.querySelector('[data-field="label"]').value.trim(),
        description: row.querySelector('[data-field="description"]').value.trim(),
        evidence_required: true,
      }));
      return { subject_type: state.subject, total_points: null, value: { dimensions } };
    }
    let totalUnits = 0;
    const criteria = [...elements.rubricEditor.querySelectorAll('[data-config-item="criterion"]')].map((row, position) => {
      const label = row.querySelector('[data-field="label"]').value.trim();
      const description = row.querySelector('[data-field="description"]').value.trim();
      if (!description) throw new Error(`"${label || position + 1 + '번'}" 평가 항목의 평가 방법을 입력해 주세요.`);
      const levelRows = [...row.querySelectorAll('[data-level-item="level"]')];
      if (levelRows.length < 2) throw new Error(`"${label || position + 1 + '번'}" 평가 항목의 등급을 2개 이상 입력해 주세요.`);
      const seenUnits = new Set();
      const levels = levelRows.map((levelRowEl, levelIndex) => {
        const levelLabel = levelRowEl.querySelector('[data-level-field="label"]').value.trim();
        if (!levelLabel) throw new Error('등급명을 입력해 주세요.');
        const units = decimalUnits(levelRowEl.querySelector('[data-level-field="points"]').value, true);
        if (seenUnits.has(units)) throw new Error('등급별 점수는 서로 달라야 합니다.');
        seenUnits.add(units);
        return { label: levelLabel, level_key: levelRowEl.dataset.id, points: decimalString(units), position: levelIndex };
      });
      const maximumUnits = Math.max(...[...seenUnits]);
      totalUnits += maximumUnits;
      return {
        criterion_id: row.dataset.id,
        label,
        description,
        levels,
        maximum_points: decimalString(maximumUnits),
        position,
        scope: row.querySelector('[data-field="scope"]').value,
      };
    });
    if (criteria.length === 0) throw new Error('평가 항목을 하나 이상 추가해 주세요.');
    return { subject_type: state.subject, total_points: decimalString(totalUnits), value: { criteria } };
  }

  function policyPayload() {
    const revision = editableRevision('policy');
    const rubric = activeRevision('rubric');
    const review = activeRevision('review_rule');
    if (!revision || !rubric || !review) throw new Error('평가 기준과 검토 기준을 먼저 사용 상태로 바꿔 주세요.');
    const value = clone(revision.value);
    value.rubric_revision_id = rubric.revision_id;
    value.review_rule_revision_id = review.revision_id;
    const enabledKey = state.subject === 'worksheet_quality' ? 'evaluation_enabled' : 'assessment_enabled';
    value[enabledKey] = elements.policyEnabled.checked;
    if (state.subject === 'worksheet_quality') {
      if (!currentWorksheetPolicy) throw new Error('현재 평가 방식을 확인할 수 없습니다.');
      value.prompt = clone(currentWorksheetPolicy.prompt);
      value.input_schema = clone(currentWorksheetPolicy.input_schema);
      value.output_schema = clone(currentWorksheetPolicy.output_schema);
    }
    return { subject_type: state.subject, value };
  }

  function reviewRulePayload() {
    const threshold = String(elements.reviewThreshold.value || '').trim();
    if (!/^(?:0(?:\.[0-9]{1,4})?|1(?:\.0{1,4})?)$/.test(threshold)) {
      throw new Error('우선 검토 기준은 0에서 1 사이여야 합니다.');
    }
    return {
      subject_type: state.subject,
      value: { abstain_handling: 'require_review', safety_handling: 'require_explicit_review' },
      review_required: true,
      explicit_release_required: true,
      confidence_threshold: threshold,
    };
  }

  async function createRevision(resourceType) {
    const subject = subjectData(resourceType);
    if (!subject) throw new Error('설정을 먼저 새로고침해 주세요.');
    const payload = resourceType === 'policy'
      ? policyPayload()
      : resourceType === 'rubric' ? rubricPayload() : reviewRulePayload();
    await request(RESOURCE_PATHS[resourceType].create, {
      method: 'POST',
      idempotencyKey: token('idem'),
      body: createRevisionCommand(token('command'), subject.latest_revision_no, payload),
    });
  }

  async function activateRevision(resourceType, revisionId) {
    const subject = subjectData(resourceType);
    const revision = subject?.revisions?.find((item) => item.revision_id === revisionId);
    if (!revision || revision.state !== 'draft') throw new Error('사용할 초안을 찾을 수 없습니다.');
    await request(`${RESOURCE_PATHS[resourceType].create}/${revisionId}/commands/activate`, {
      method: 'POST',
      idempotencyKey: token('idem'),
      body: activateRevisionCommand(token('command'), revision, subject.active_revision_id),
    });
  }

  async function mutate(resourceType, work, successMessage) {
    if (state.busy) return;
    setBusy(true);
    showNotice('저장하는 중입니다.');
    try {
      await work();
      await load();
      if (state.resources[resourceType] && !state.errors[resourceType]) showNotice(successMessage, 'success');
    } catch (error) {
      const message = error?.message || '설정을 저장하지 못했습니다.';
      if (error?.status === 409) await load();
      showNotice(message, 'danger');
    } finally {
      setBusy(false);
    }
  }

  function activateTab(name) {
    const configurationVisible = ['policy', 'rubric', 'review-rule'].includes(name);
    elements.tabButtons.forEach((control) => {
      const selected = control.dataset.settingsTab === name;
      control.setAttribute('aria-selected', selected ? 'true' : 'false');
      control.tabIndex = selected ? 0 : -1;
    });
    elements.panels.forEach((panel) => { panel.hidden = panel.dataset.settingsPanel !== name; });
    app.hidden = !configurationVisible;
    elements.subjectSwitch.hidden = !configurationVisible;
    if (elements.refresh) elements.refresh.hidden = !configurationVisible;
  }

  function moveTabFocus(current, key) {
    const currentIndex = elements.tabButtons.indexOf(current);
    if (currentIndex < 0) return;
    let nextIndex = currentIndex;
    if (key === 'ArrowLeft') nextIndex = (currentIndex - 1 + elements.tabButtons.length) % elements.tabButtons.length;
    if (key === 'ArrowRight') nextIndex = (currentIndex + 1) % elements.tabButtons.length;
    if (key === 'Home') nextIndex = 0;
    if (key === 'End') nextIndex = elements.tabButtons.length - 1;
    const next = elements.tabButtons[nextIndex];
    activateTab(next.dataset.settingsTab);
    next.focus();
  }

  root.addEventListener('keydown', (event) => {
    const tab = event.target.closest('[role="tab"][data-settings-tab]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    moveTabFocus(tab, event.key);
  });

  root.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-settings-tab]');
    if (tab) {
      activateTab(tab.dataset.settingsTab);
      return;
    }
    const subject = event.target.closest('[data-config-subject]');
    if (subject) {
      state.subject = subject.dataset.configSubject;
      render();
      return;
    }
    const control = event.target.closest('[data-config-action]');
    if (!control) return;
    const action = control.dataset.configAction;
    if (action === 'refresh') load();
    if (action === 'save-policy') mutate('policy', () => createRevision('policy'), '정책 초안을 저장했습니다.');
    if (action === 'save-rubric') mutate('rubric', () => createRevision('rubric'), '평가 기준 초안을 저장했습니다.');
    if (action === 'save-review-rule') mutate('review_rule', () => createRevision('review_rule'), '검토 기준 초안을 저장했습니다.');
    if (action === 'activate') mutate(
      control.dataset.resourceType,
      () => activateRevision(control.dataset.resourceType, control.dataset.revisionId),
      '선택한 설정을 사용합니다.'
    );
    if (action === 'remove-item') {
      const row = control.closest('[data-config-item]');
      if (row) row.remove();
      [...elements.rubricEditor.querySelectorAll('[data-config-item]')].forEach((item, index) => {
        const position = item.querySelector('.oj-ai-config__position');
        if (position) position.textContent = String(index + 1);
      });
    }
    if (action === 'add-dimension') {
      const count = elements.rubricEditor.querySelectorAll('[data-config-item="dimension"]').length;
      if (count < 12) elements.rubricEditor.insertBefore(dimensionRow({
        dimension_key: `custom_dimension_${Date.now().toString(36)}`,
        label: '추가 평가 항목',
        description: '활동지에서 확인할 근거를 입력하세요.',
      }, count), control);
    }
    if (action === 'add-criterion') {
      const count = elements.rubricEditor.querySelectorAll('[data-config-item="criterion"]').length;
      elements.rubricEditor.insertBefore(criterionRow({
        criterion_id: publicId(), label: '추가 평가 항목',
        description: '학생 응답에서 확인할 내용과 근거를 입력하세요.', scope: 'submission',
        levels: [
          { level_key: publicId(), label: '근거를 사용해 요구한 내용을 충분히 설명함', points: '10' },
          { level_key: publicId(), label: '근거가 부족하거나 요구한 내용을 설명하지 못함', points: '0' },
        ],
      }, count), control);
    }
    if (action === 'add-level') {
      const criterionRowEl = control.closest('[data-config-item="criterion"]');
      const levelsList = criterionRowEl?.querySelector('.oj-ai-config__levels-list');
      if (!levelsList) return;
      const count = levelsList.querySelectorAll('[data-level-item="level"]').length;
      levelsList.append(levelRow({ level_key: publicId(), label: '새 등급', points: '0' }, count));
      updateCriterionMaxPoints(criterionRowEl);
    }
    if (action === 'remove-level') {
      const criterionRowEl = control.closest('[data-config-item="criterion"]');
      const levelsList = criterionRowEl?.querySelector('.oj-ai-config__levels-list');
      const levelRowEl = control.closest('[data-level-item="level"]');
      if (!levelsList || !levelRowEl) return;
      if (levelsList.querySelectorAll('[data-level-item="level"]').length <= 2) {
        showNotice('등급은 최소 2개 이상 있어야 합니다.', 'danger');
        return;
      }
      levelRowEl.remove();
      [...levelsList.querySelectorAll('[data-level-item="level"]')].forEach((item, levelIndex) => {
        const position = item.querySelector('.oj-ai-config__position');
        if (position) position.textContent = String(levelIndex + 1);
      });
      updateCriterionMaxPoints(criterionRowEl);
    }
  });

  root.addEventListener('input', (event) => {
    if (!event.target.closest('[data-level-field="points"]')) return;
    const criterionRowEl = event.target.closest('[data-config-item="criterion"]');
    if (criterionRowEl) updateCriterionMaxPoints(criterionRowEl);
  });

  const entry = new URLSearchParams(window.location?.search || '');
  if (['worksheet_quality', 'student_submission'].includes(entry.get('subject'))) state.subject = entry.get('subject');
  const requestedTab = entry.get('tab');
  activateTab(elements.tabButtons.some((button) => button.dataset.settingsTab === requestedTab) ? requestedTab : elements.tabButtons[0]?.dataset.settingsTab);
  load();
})(window, document);

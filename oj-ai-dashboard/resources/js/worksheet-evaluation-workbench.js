(function (window, document) {
  'use strict';

  const root = document.querySelector('#oj-ai-worksheet-evaluation-app');
  const TIME_ZONE = resolveTimeZone(root?.dataset.timezone);
  const LOCALE = String(root?.dataset.locale || 'ko-KR').replace(/_/g, '-');

  function resolveTimeZone(raw) {
    const candidate = raw || 'Asia/Seoul';
    try {
      new Intl.DateTimeFormat('en-US', { timeZone: candidate });
      return candidate;
    } catch (_error) {
      const match = candidate.match(/^([+-])(\d{2}):(\d{2})$/);
      if (match && match[3] === '00') {
        const etc = `Etc/GMT${match[1] === '-' ? '+' : '-'}${Number(match[2])}`;
        try {
          new Intl.DateTimeFormat('en-US', { timeZone: etc });
          return etc;
        } catch (_fallbackError) { /* fall through to default */ }
      }
      return 'Asia/Seoul';
    }
  }
  const STATE_LABELS = Object.freeze({
    requested: '요청됨',
    queued: '대기',
    running: '진행 중',
    review_required: '교사 검토 대기',
    reviewed: '교사 검토 완료',
    failed: '실패',
    cancelled: '취소',
  });
  const RUN_LABELS = Object.freeze({
    queued: '대기',
    leased: '준비 중',
    running: '진행 중',
    succeeded: '완료',
    retry_wait: '재시도 대기',
    failed: '실패',
    cancelled: '취소',
  });
  const ERROR_LABELS = Object.freeze({
    worker_lost: '이전 처리 시도가 응답하지 않아 중단되었습니다.',
    queue_deadline_exceeded: '대기 시간이 너무 길어 처리가 취소되었습니다.',
    context_denied: '현재 권한으로는 이 활동지를 처리할 수 없습니다.',
    source_stale: '접수 이후 활동지 내용이 변경되어 다시 점검이 필요합니다.',
    external_processing_not_approved: '외부 AI 처리 동의가 필요합니다. 설정에서 승인해 주세요.',
    routing_stale: 'AI 연결 설정이 변경되었습니다. 설정에서 연결 상태를 다시 확인해 주세요.',
    routing_changed_during_execute: '처리 중 AI 연결 설정이 변경되었습니다. 다시 시도해 주세요.',
    invalid_gateway_response: 'AI 연결에 실패했습니다. 설정에서 연결 상태를 확인해 주세요.',
    addon_ai_task_failed: 'AI 작업이 완료되지 않았습니다. OJ Platform의 활동지 자체 평가 작업 설정과 실행 상태를 확인한 뒤 다시 점검해 주세요.',
    addon_ai_task_unroutable: 'AI 작업을 연결할 수 없습니다. 설정에서 연결 상태를 확인해 주세요.',
    output_too_large: 'AI 응답이 너무 큽니다.',
    output_schema_invalid: 'AI 응답 형식이 올바르지 않습니다.',
    output_domain_invalid: 'AI 응답 내용이 올바르지 않습니다.',
    output_privacy_invalid: 'AI 응답에 허용되지 않은 정보가 포함되었습니다.',
    worksheet_evaluation_worker_storage_unavailable: '저장소에 접근할 수 없습니다.',
    worksheet_evaluation_worker_item_invalid: '처리 항목이 올바르지 않습니다.',
  });
  const OVERALL_LABELS = Object.freeze({
    ready: '양호',
    needs_attention: '확인 필요',
    action_required: '수정 필요',
    safety_review: '안전 검토',
    not_assessable: '평가 어려움',
  });
  const RATING_LABELS = Object.freeze({
    meets: '충족',
    minor_issue: '일부 보완',
    major_issue: '보완 필요',
    not_assessable: '판단 어려움',
  });
  const DIMENSION_LABELS = Object.freeze({
    learning_goal_alignment: '학습목표 정합성',
    instruction_clarity: '명료성',
    difficulty_fit: '난이도',
    learning_alignment: '학습목표 정합성',
    clarity: '명료성',
    answerability: '답변 가능성',
    difficulty: '난이도',
    rubric_quality: '평가 기준',
    safety: '안전성',
  });
  const SEVERITY_LABELS = Object.freeze({
    info: '안내',
    minor: '경미',
    major: '중요',
    critical: '긴급',
  });
  const DECISION_LABELS = Object.freeze({
    resolved: '해결',
    deferred: '보류',
    dismissed: '기각',
  });
  const REASONS = Object.freeze([
    ['needs_revision', '수정 필요'],
    ['needs_information', '확인 필요'],
    ['follow_up', '추후 확인'],
    ['not_applicable', '해당 없음'],
    ['accepted_risk', '현재 상태 수용'],
    ['false_positive', '잘못된 지적'],
  ]);

  function commandEnvelope(commandId, expected, payload) {
    if (!commandId || typeof commandId !== 'string') {
      throw new TypeError('commandId is required.');
    }
    return { command_id: commandId, expected: { ...expected }, payload: { ...payload } };
  }

  function enqueueCommand(commandId, revision) {
    return commandEnvelope(commandId, {
      expected_revision_id: revision.id,
      expected_source_hash: revision.document_hash,
    }, {});
  }

  function controlCommand(commandId, detail) {
    const run = detail?.evaluation?.latest_run;
    if (!run) throw new TypeError('latest run is required.');
    return commandEnvelope(commandId, {
      expected_state: detail.evaluation.state,
      expected_run_id: run.id,
      expected_run_no: run.run_no,
    }, {});
  }

  function reviewCommand(commandId, detail, itemDecisions, completeReview) {
    return commandEnvelope(commandId, {
      expected_state: detail.evaluation.state,
      expected_result_id: detail.result.id,
      expected_review_no: detail.evaluation.status.latest_review_no || 0,
    }, {
      complete_review: Boolean(completeReview),
      item_decisions: itemDecisions,
    });
  }

  const adapter = Object.freeze({
    commandEnvelope,
    enqueueCommand,
    controlCommand,
    reviewCommand,
  });
  window.OJAIWorksheetEvaluationWorkbench = adapter;

  if (!root) return;

  const fields = {
    search: root.querySelector('[data-field="search"]'),
    state: root.querySelector('[data-field="state"]'),
    worksheet: root.querySelector('[data-field="worksheet"]'),
    acknowledgement: root.querySelector('[data-field="external-processing-ack"]'),
  };
  const elements = {
    summary: root.querySelector('[data-role="summary"]'),
    consent: root.querySelector('[data-role="consent-status"]'),
    consentLabel: root.querySelector('[data-role="consent-label"]'),
    notice: root.querySelector('[data-role="notice"]'),
    content: root.querySelector('[data-role="content"]'),
    list: root.querySelector('[data-role="evaluation-list"]'),
    detail: root.querySelector('[data-role="evaluation-detail"]'),
    loadMore: root.querySelector('[data-action="load-more"]'),
    dialog: root.querySelector('[data-role="new-evaluation-dialog"]'),
    dialogStatus: root.querySelector('[data-role="new-evaluation-status"]'),
    enqueue: root.querySelector('[data-action="enqueue"]'),
  };
  const state = {
    items: [],
    nextCursor: '',
    selectedId: '',
    detail: null,
    currentRevision: null,
    worksheets: [],
    newRevision: null,
    enqueuePending: false,
    listRequest: 0,
    detailRequest: 0,
    historyWorksheetId: '',
    historyItems: [],
    historyCursor: '',
    historyRequest: 0,
    historyLoaded: false,
    historyPending: false,
    revisionRequest: 0,
    selectedReviewId: '',
    reviewEditing: false,
    reviewPending: false,
  };
  let consentRecorded = root.dataset.consentRecorded === '1';
  let consentState = consentRecorded ? 'approved' : 'missing';
  const canReview = root.dataset.canReview === '1';
  const requestedWorksheetValue = new URLSearchParams(window.location.search).get('worksheet_id') || '';
  const embedded = Boolean(root.closest('[data-role="worksheet-review-stage"]'));
  const requestedWorksheetId = (embedded ? root.closest('[data-worksheet-id]')?.dataset.worksheetId : '') || (/^[0-9A-HJKMNP-TV-Z]{26}$/.test(requestedWorksheetValue)
    ? requestedWorksheetValue
    : '');
  let requestedWorksheetHandled = false;
  root.dataset.scopedWorksheet = requestedWorksheetId ? '1' : '0';
  const startButton = root.querySelector('[data-action="new-evaluation"]');
  startButton.textContent = requestedWorksheetId ? '점검 시작' : '활동지 점검';
  const history = node('details', 'oj-ai-evaluation__history');
  history.dataset.role = 'evaluation-history';
  history.hidden = true;
  history.appendChild(node('summary', '', 'AI 점검 이력'));
  const historyList = node('div', 'oj-ai-evaluation__history-list');
  historyList.dataset.role = 'history-list';
  const historyMore = button('이력 더 보기', 'history-more', 'oj-ai-evaluation__load-more');
  historyMore.hidden = true;
  append(history, historyList, historyMore);
  if (requestedWorksheetId) {
    elements.list.hidden = true;
    elements.loadMore.hidden = true;
    elements.list.parentElement.appendChild(history);
  } else {
    elements.content.after(history);
  }
  history.addEventListener('toggle', () => {
    if (history.open && !state.historyLoaded) loadHistory();
  });

  function node(tag, className, text) {
    const value = document.createElement(tag);
    if (className) value.className = className;
    if (text !== undefined && text !== null) value.textContent = String(text);
    return value;
  }

  function append(parent, ...children) {
    children.filter(Boolean).forEach((child) => parent.appendChild(child));
    return parent;
  }

  function button(label, action, className = '') {
    const value = node('button', className, label);
    value.type = 'button';
    value.dataset.action = action;
    return value;
  }

  function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'oj-ai-icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `${root.dataset.iconSprite}#${name}`);
    svg.appendChild(use);
    return svg;
  }

  function iconButton(name, action, className, label) {
    const control = button('', action, className);
    control.appendChild(icon(name));
    control.setAttribute('aria-label', label);
    control.title = label;
    return control;
  }

  function labelFor(map, value, fallback = '확인 중') {
    return map[value] || fallback;
  }

  function errorLabel(code) {
    if (!code) return '';
    return ERROR_LABELS[code] || `원인 확인이 필요합니다. (오류 코드: ${code})`;
  }

  function failureText(run) {
    const message = run.last_error_message || '';
    if (run.last_error_code === 'output_domain_invalid' && message.includes('(not a substring of the anchored field)')) {
      return 'AI가 제시한 근거 문구가 해당 원문과 일치하지 않아 결과를 저장하지 않았습니다. 다시 점검해 주세요.';
    }
    if (['output_schema_invalid', 'output_domain_invalid'].includes(run.last_error_code)) {
      let reason = 'AI 응답의 필수 항목 또는 자료형이 평가 형식과 맞지 않습니다.';
      // Classify known validation locations; never echo model-supplied keys or values.
      if (/^\$\.source_hash(?:$|\s)|^\$\.schema_id\|schema_version\|source_hash$/.test(message)) {
        reason = 'AI 응답의 평가 형식 또는 원본 식별 정보가 요청과 맞지 않습니다.';
      } else if (/^\$\.(?:dimensions|findings)\[\d+\]\.confidence$/.test(message)) {
        reason = 'AI가 반환한 신뢰도는 0부터 1 사이의 숫자여야 합니다.';
      } else if (/^\$ \(key set mismatch\)$/.test(message)) {
        reason = 'AI 응답에 필수 항목이 빠졌거나 허용하지 않는 항목이 포함되었습니다.';
      } else if (/^\$\.findings\[\d+\]\.evidence(?:$|\[|\s)/.test(message)) {
        reason = 'AI가 반환한 근거의 구성·원문 위치 또는 인용문 형식이 올바르지 않습니다.';
      } else if (/^\$\.dimensions(?:$|\[|\s)/.test(message)) {
        reason = '평가 항목별 AI 결과의 구성 또는 판정이 평가 형식과 맞지 않습니다.';
      } else if (/^\$\.findings(?:$|\[|\s)/.test(message)) {
        reason = 'AI가 반환한 지적 사항의 구성 또는 값이 평가 형식과 맞지 않습니다.';
      }
      return reason + ' 결과를 저장하지 않았습니다. 재점검 후에도 반복되면 관리자에게 실행 이력을 전달해 주세요.';
    }
    if (['output_privacy_invalid', 'output_too_large'].includes(run.last_error_code)) {
      return errorLabel(run.last_error_code) + ' 다시 점검해 주세요.';
    }
    if (run.last_error_code === 'addon_ai_task_failed') {
      const guidance = errorLabel(run.last_error_code);
      return message ? message + ' ' + guidance : guidance;
    }
    return message || errorLabel(run.last_error_code);
  }

  function toneForState(value) {
    if (value === 'failed') return 'danger';
    if (value === 'review_required' || value === 'running' || value === 'queued') return 'attention';
    if (value === 'reviewed') return 'success';
    return 'neutral';
  }

  function badge(text, tone = 'neutral') {
    const value = node('span', 'oj-ai-evaluation__badge', text);
    value.dataset.tone = tone;
    return value;
  }

  function dateLabel(value) {
    if (typeof value !== 'string' || !value) return '';
    const parsed = new Date(`${value.replace(' ', 'T')}Z`);
    if (Number.isNaN(parsed.getTime())) return value;
    try {
      return new Intl.DateTimeFormat(LOCALE, {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: TIME_ZONE,
      }).format(parsed);
    } catch (_error) {
      return value;
    }
  }

  function token(prefix) {
    const random = window.crypto?.randomUUID
      ? window.crypto.randomUUID().replaceAll('-', '')
      : `${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
    return `${prefix}:${Date.now().toString(36)}:${random}`;
  }

  function showNotice(message, tone = 'neutral') {
    elements.notice.textContent = message;
    elements.notice.dataset.tone = tone;
    elements.notice.hidden = !message;
  }

  function problemMessage(error, fallback) {
    const conflicts = {
      worksheet_evaluation_state_conflict: '평가 상태가 바뀌었습니다. 최신 상태를 불러왔습니다.',
      worksheet_evaluation_source_stale: '활동지가 수정되었습니다. 현재 버전으로 새 점검을 시작해 주세요.',
      worksheet_evaluation_configuration_changed: '평가 기준이 바뀌었습니다. 현재 기준으로 새 점검을 시작해 주세요.',
      worksheet_evaluation_idempotency_conflict: '같은 요청이 다른 내용으로 처리되었습니다. 최신 상태를 불러왔습니다.',
    };
    if (error?.status === 409) return conflicts[error.code] || error.message || '평가 상태가 바뀌었습니다. 최신 상태를 불러왔습니다.';
    if (error?.status === 403) return '이 작업을 수행할 권한이 없습니다.';
    if (error?.status === 404) return '대상을 찾을 수 없습니다.';
    if (error?.status === 422) return error.message || '입력 내용을 확인해 주세요.';
    return error?.message || fallback;
  }

  async function request(path, options = {}) {
    const method = options.method || 'GET';
    const headers = {
      Accept: 'application/json',
      'X-WP-Nonce': root.dataset.restNonce || '',
    };
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
      error.code = payload?.code || '';
      error.safeData = payload?.data || {};
      throw error;
    }
    if (!payload || typeof payload !== 'object' || !Object.hasOwn(payload, 'data')) {
      const error = new Error('응답을 확인할 수 없습니다.');
      error.status = 503;
      throw error;
    }
    return payload;
  }

  function updateConsent() {
    const labels = {
      approved: '외부 AI 처리가 승인되었습니다.',
      stale: 'AI 연결이 바뀌어 다시 승인이 필요합니다.',
      missing: '점검을 시작하려면 외부 AI 처리 승인이 필요합니다.',
      unavailable: '외부 AI 처리 승인 상태를 확인하지 못했습니다.',
    };
    elements.consent.dataset.state = consentRecorded ? 'recorded' : consentState;
    elements.consentLabel.textContent = labels[consentState] || labels.unavailable;
  }

  async function loadConsentStatus() {
    try {
      const payload = await request('/settings/external-ai-consent');
      const consent = payload?.data?.external_ai_consent?.consent;
      consentState = ['approved', 'stale', 'missing'].includes(consent?.state)
        ? consent.state
        : 'unavailable';
      consentRecorded = consentState === 'approved' && consent?.effective === true;
    } catch (_error) {
      consentRecorded = false;
      consentState = 'unavailable';
    }
    updateConsent();
    updateEnqueueState();
  }

  function visibleItems() {
    const term = String(fields.search.value || '').trim().toLocaleLowerCase();
    if (!term) return state.items;
    return state.items.filter((item) => String(item?.worksheet?.title || '').toLocaleLowerCase().includes(term));
  }

  function renderList() {
    elements.list.replaceChildren();
    const items = visibleItems();
    if (!items.length) {
      elements.list.appendChild(node('div', 'oj-ai-evaluation__empty', state.items.length ? '검색 결과가 없습니다.' : '평가가 없습니다.'));
      return;
    }
    items.forEach((item) => {
      const entry = button('', 'select-evaluation', 'oj-ai-evaluation__list-item');
      entry.dataset.evaluationId = item.id;
      entry.setAttribute('aria-current', item.id === state.selectedId || item.worksheet.id === state.detail?.evaluation?.worksheet?.id ? 'true' : 'false');
      const title = node('div', 'oj-ai-evaluation__list-title');
      append(title, node('strong', '', item.worksheet.title), badge(
        item.is_stale ? '수정본 미점검' : labelFor(STATE_LABELS, item.state),
        item.is_stale ? 'attention' : toneForState(item.state)
      ));
      const meta = node('div', 'oj-ai-evaluation__list-meta');
      meta.textContent = `버전 ${item.revision.revision_no} · ${dateLabel(item.updated_at_utc)}`;
      const counts = node('div', 'oj-ai-evaluation__list-counts');
      append(
        counts,
        node('span', '', `지적 ${item.status.finding_count}`),
        node('span', '', item.status.needs_attention_count ? `확인 ${item.status.needs_attention_count}` : '확인 항목 없음')
      );
      append(entry, title, meta, counts);
      elements.list.appendChild(entry);
    });
  }

  function prepareHistory(worksheetId) {
    if (state.historyWorksheetId !== worksheetId) {
      ++state.historyRequest;
      state.historyWorksheetId = worksheetId;
      state.historyItems = [];
      state.historyCursor = '';
      state.historyLoaded = false;
      state.historyPending = false;
      history.open = Boolean(requestedWorksheetId);
    }
    history.hidden = !worksheetId;
    renderHistory();
    if (history.open && !state.historyLoaded) loadHistory();
  }

  function renderHistory() {
    historyList.replaceChildren();
    state.historyItems.forEach(item => {
      const entry = button('', 'select-history', 'oj-ai-evaluation__history-item');
      entry.dataset.evaluationId = item.id;
      entry.setAttribute('aria-current', item.id === state.selectedId ? 'true' : 'false');
      const latest = state.items.find(value => value.worksheet.id === item.worksheet.id);
      append(entry,
        node('strong', '', `점검 버전 ${item.revision.revision_no}`),
        badge(labelFor(STATE_LABELS, item.state), toneForState(item.state)),
        node('span', '', dateLabel(item.requested_at_utc)),
        node('span', '', item.id === latest?.id ? '최신 점검' : '이전 점검')
      );
      entry.appendChild(node('span', '', `지적 ${item.status.finding_count} · 교사 검토 ${item.status.latest_review_no || 0}회`));
      historyList.appendChild(entry);
    });
    if (state.historyLoaded && !state.historyItems.length) {
      historyList.appendChild(node('p', '', '점검 이력이 없습니다.'));
    }
    historyMore.hidden = !state.historyCursor;
    historyMore.disabled = state.historyPending;
  }

  async function loadHistory(appendPage = false) {
    if (!state.historyWorksheetId || state.historyPending) return;
    const requestNumber = ++state.historyRequest;
    const worksheetId = state.historyWorksheetId;
    state.historyPending = true;
    historyList.setAttribute('aria-busy', 'true');
    historyMore.disabled = true;
    if (!appendPage) historyList.replaceChildren(node('p', '', '점검 이력을 불러오는 중입니다.'));
    const query = new URLSearchParams({ worksheet_id: worksheetId, view: 'history', limit: '30' });
    if (appendPage && state.historyCursor) query.set('cursor', state.historyCursor);
    try {
      const envelope = await request(`/worksheet-evaluations?${query}`);
      if (requestNumber !== state.historyRequest || worksheetId !== state.historyWorksheetId) return;
      const items = Array.isArray(envelope.data?.items) ? envelope.data.items : [];
      state.historyItems = [...new Map((appendPage ? [...state.historyItems, ...items] : items)
        .filter(item => item.worksheet?.id === worksheetId).map(item => [item.id, item])).values()];
      state.historyCursor = envelope.meta?.next_cursor || '';
      state.historyLoaded = true;
      renderHistory();
    } catch (error) {
      if (requestNumber !== state.historyRequest) return;
      const message = node('p', '', problemMessage(error, '점검 이력을 불러오지 못했습니다.'));
      message.setAttribute('role', 'status');
      historyList.append(message, button('이력 다시 불러오기', 'history-reload'));
    } finally {
      if (requestNumber === state.historyRequest) {
        state.historyPending = false;
        historyList.setAttribute('aria-busy', 'false');
        historyMore.disabled = false;
      }
    }
  }

  async function loadEvaluations(appendPage = false, preferredId = '') {
    if (!isWorkbenchVisible()) return;
    const requestNumber = ++state.listRequest;
    ++state.detailRequest;
    if (!appendPage) {
      state.nextCursor = '';
      ++state.historyRequest;
      state.historyPending = false;
      state.historyLoaded = false;
      showNotice('평가를 불러오는 중입니다.');
    }
    const query = new URLSearchParams({ limit: '30', view: 'latest' });
    if (fields.state.value) query.set('state', fields.state.value);
    if (requestedWorksheetId) query.set('worksheet_id', requestedWorksheetId);
    if (appendPage && state.nextCursor) query.set('cursor', state.nextCursor);
    try {
      const envelope = await request(`/worksheet-evaluations?${query.toString()}`);
      if (requestNumber !== state.listRequest) return;
      const incoming = Array.isArray(envelope.data?.items) ? envelope.data.items : [];
      state.items = [...new Map((appendPage ? [...state.items, ...incoming] : incoming).map(item => [item.id, item])).values()];
      state.nextCursor = envelope.meta?.next_cursor || '';
      elements.loadMore.hidden = !state.nextCursor;
      elements.summary.textContent = requestedWorksheetId ? '현재 활동지' : `활동지 ${state.items.length}개${state.nextCursor ? '+' : ''}`;
      startButton.hidden = Boolean(requestedWorksheetId && state.items.length);
      elements.content.hidden = false;
      showNotice('');
      if (preferredId) state.selectedId = preferredId;
      if (!state.items.some((item) => item.id === state.selectedId)) {
        state.selectedId = state.items[0]?.id || '';
      }
      renderList();
      if (state.selectedId) {
        await loadDetail(state.selectedId);
      } else {
        state.detail = null;
        prepareHistory('');
        renderEmptyDetail('점검 결과가 없습니다.', '현재 조건에 맞는 점검 결과가 없습니다.');
        if (requestedWorksheetId && !embedded && !requestedWorksheetHandled && isWorkbenchVisible()) {
          requestedWorksheetHandled = true;
          await openNewEvaluation(requestedWorksheetId);
        }
      }
    } catch (error) {
      if (requestNumber !== state.listRequest) return;
      elements.content.hidden = true;
      history.hidden = true;
      showNotice(problemMessage(error, '평가를 불러오지 못했습니다.'), 'error');
    }
  }

  function renderEmptyDetail(title, message) {
    const empty = node('div', 'oj-ai-evaluation__empty');
    append(empty, node('strong', '', title), node('span', '', message));
    elements.detail.replaceChildren(empty);
  }

  async function readCurrentRevision(detail) {
    try {
      const worksheet = await request(`/worksheets/${encodeURIComponent(detail.evaluation.worksheet.id)}`);
      const revisionId = worksheet.data?.worksheet?.current_revision_id;
      if (!revisionId) return null;
      const revision = await request(`/worksheet-revisions/${encodeURIComponent(revisionId)}`);
      const value = revision.data?.revision;
      if (!value?.id || !value?.document_hash) return null;
      return { id: value.id, document_hash: value.document_hash, revision_no: value.revision_no };
    } catch (_error) {
      return null;
    }
  }

  async function loadDetail(evaluationId) {
    const requestNumber = ++state.detailRequest;
    state.selectedId = evaluationId;
    state.detail = null;
    state.selectedReviewId = '';
    state.reviewEditing = false;
    renderList();
    renderEmptyDetail('불러오는 중', '평가 결과를 확인하고 있습니다.');
    try {
      const envelope = await request(`/worksheet-evaluations/${encodeURIComponent(evaluationId)}`);
      if (requestNumber !== state.detailRequest) return;
      const detail = envelope.data;
      const currentRevision = await readCurrentRevision(detail);
      if (requestNumber !== state.detailRequest) return;
      state.detail = detail;
      state.currentRevision = currentRevision;
      prepareHistory(detail.evaluation.worksheet.id);
      state.items = state.items.map((item) => item.id === detail.evaluation.id
        ? { ...item, ...detail.evaluation }
        : item);
      renderList();
      renderDetail();
    } catch (error) {
      if (requestNumber !== state.detailRequest) return;
      renderEmptyDetail('불러오지 못했습니다.', problemMessage(error, '잠시 뒤 다시 시도해 주세요.'));
    }
  }

  async function selectEvaluation(evaluationId) {
    await loadDetail(evaluationId);
    if (state.detail?.evaluation?.id !== evaluationId) return;
    const heading = elements.detail.querySelector('h2');
    if (heading) {
      heading.tabIndex = -1;
      heading.focus({ preventScroll: true });
      heading.scrollIntoView({ block: 'start' });
    }
  }

  function detailHeader(detail) {
    const evaluation = detail.evaluation;
    const header = node('header', 'oj-ai-evaluation__detail-header');
    const identity = node('div');
    append(identity, node('h2', '', evaluation.worksheet.title));
    const meta = node('div', 'oj-ai-evaluation__detail-meta');
    append(
      meta,
      badge(labelFor(STATE_LABELS, evaluation.state), toneForState(evaluation.state)),
      node('span', '', state.currentRevision ? `현재 버전 ${state.currentRevision.revision_no}` : '현재 버전 확인 불가'),
      node('span', '', `점검 버전 ${evaluation.revision.revision_no}`),
      node('span', '', dateLabel(evaluation.updated_at_utc))
    );
    if (evaluation.is_stale) meta.appendChild(badge('수정본 미점검', 'attention'));
    identity.appendChild(meta);

    const actions = node('div', 'oj-ai-evaluation__detail-actions');
    const edit = node('a', '', '활동지 열기');
    const workflow = window.OJAIWorkflow;
    const selection = workflow?.read();
    edit.href = selection?.projectId && selection.worksheetId === evaluation.worksheet.id
      ? workflow.url('author')
      : String(root.dataset.editUrlTemplate || '').replace('{worksheet_id}', evaluation.worksheet.id);
    actions.appendChild(edit);
    const latest = state.items.find(item => item.worksheet.id === evaluation.worksheet.id);
    const historical = latest && latest.id !== evaluation.id;
    if (historical) {
      const current = button('최신 점검 보기', 'select-evaluation');
      current.dataset.evaluationId = latest.id;
      actions.appendChild(current);
    } else if (evaluation.is_stale) {
      const current = button('수정본 점검', 'evaluate-current', 'oj-ai-evaluation__primary');
      current.dataset.worksheetId = evaluation.worksheet.id;
      actions.appendChild(current);
    } else if (evaluation.status.next_action === 'retry') {
      actions.appendChild(button('다시 시도', 'retry-evaluation', 'oj-ai-evaluation__primary'));
    } else if (evaluation.status.next_action === 'cancel') {
      actions.appendChild(button('취소', 'cancel-evaluation', 'oj-ai-evaluation__danger'));
    }
    const refresh = iconButton('refresh-cw', 'detail-refresh', 'oj-ai-evaluation__icon-button', '평가 새로고침');
    actions.appendChild(refresh);
    append(header, identity, actions);
    return header;
  }

  function metrics(detail) {
    const evaluation = detail.evaluation;
    const result = detail.result;
    const values = [
      [result ? 'AI 판정' : '진행 상태', result ? labelFor(OVERALL_LABELS, result.overall_status) : labelFor(STATE_LABELS, evaluation.state)],
      ['신뢰도', result ? `${Math.round(Number(result.overall_confidence) * 100)}%` : '-'],
      ['지적', evaluation.status.finding_count],
      ['확인 필요', evaluation.status.needs_attention_count],
    ];
    const container = node('div', 'oj-ai-evaluation__metrics');
    values.forEach(([label, value]) => {
      const metric = node('div', 'oj-ai-evaluation__metric');
      append(metric, node('span', '', label), node('strong', '', value));
      container.appendChild(metric);
    });
    return container;
  }

  function section(title) {
    const value = node('section', 'oj-ai-evaluation__section');
    value.appendChild(node('h3', '', title));
    return value;
  }

  function renderDimensions(result) {
    const container = node('div', 'oj-ai-evaluation__dimensions');
    result.dimensions.forEach((dimension) => {
      const row = node('div', 'oj-ai-evaluation__dimension');
      append(
        row,
        node('strong', '', labelFor(DIMENSION_LABELS, dimension.dimension_key, '기타 평가 항목')),
        badge(labelFor(RATING_LABELS, dimension.rating), dimension.rating === 'meets' ? 'success' : 'attention'),
        node('p', '', dimension.summary)
      );
      container.appendChild(row);
    });
    return container;
  }

  function latestDecisions(detail) {
    const latest = selectedReview(detail) || detail.reviews?.[detail.reviews.length - 1];
    const result = new Map();
    (latest?.item_decisions || []).forEach((item) => result.set(item.finding_key, item));
    return result;
  }

  function selectedReview(detail) {
    return detail.reviews?.find(review => review.id === state.selectedReviewId);
  }

  function reviewEditable(detail) {
    return canReview && !state.selectedReviewId && !state.reviewPending
      && (detail.evaluation.state === 'review_required' || (detail.evaluation.state === 'reviewed' && state.reviewEditing));
  }

  function reviewDecisionText(decision) {
    return { resolved: '해결', deferred: '보류', dismissed: '기각' }[decision] || '미처리';
  }

  function recordedDecision(previous) {
    const value = node('div', 'oj-ai-evaluation__recorded-decision');
    value.appendChild(node('strong', '', `당시 처리: ${reviewDecisionText(previous?.decision)}`));
    if (previous?.reason_code) value.appendChild(node('p', '', `사유: ${REASONS.find(([key]) => key === previous.reason_code)?.[1] || '기타 사유'}`));
    if (previous?.note) value.appendChild(node('p', '', previous.note));
    if (previous?.resolution_revision_id) value.appendChild(node('p', '', '해결 시점의 수정본 연결 기록 있음'));
    return value;
  }

  function option(value, text, selected = false) {
    const item = node('option', '', text);
    item.value = value;
    item.selected = selected;
    return item;
  }

  function reviewControls(finding, previous) {
    const controls = node('div', 'oj-ai-evaluation__finding-review');
    const decisionLabel = node('label', '', '처리');
    const decision = node('select');
    decision.dataset.field = 'finding-decision';
    append(
      decision,
      option('', '선택 안 함', !previous?.decision),
      option('resolved', '해결', previous?.decision === 'resolved'),
      option('deferred', '보류', previous?.decision === 'deferred'),
      option('dismissed', '기각', previous?.decision === 'dismissed')
    );
    decisionLabel.appendChild(decision);

    const reasonLabel = node('label', '', '사유');
    reasonLabel.dataset.role = 'finding-reason-label';
    const reason = node('select');
    reason.dataset.field = 'finding-reason';
    reason.appendChild(option('', '사유 선택', !previous?.reason_code));
    let previousReasonFound = false;
    REASONS.forEach(([value, text]) => {
      const selected = previous?.reason_code === value;
      previousReasonFound ||= selected;
      reason.appendChild(option(value, text, selected));
    });
    if (previous?.reason_code && !previousReasonFound) {
      reason.appendChild(option(previous.reason_code, '기타 사유', true));
    }
    reasonLabel.appendChild(reason);

    const noteLabel = node('label', '', '메모');
    noteLabel.dataset.role = 'finding-note-label';
    const note = node('textarea');
    note.dataset.field = 'finding-note';
    note.maxLength = 1000;
    note.placeholder = '판단 근거를 입력하세요';
    note.value = previous?.note || '';
    noteLabel.appendChild(note);
    append(controls, decisionLabel, reasonLabel, noteLabel);
    return controls;
  }

  function updateFindingControls(article) {
    const decision = article.querySelector('[data-field="finding-decision"]')?.value || '';
    const reasonLabel = article.querySelector('[data-role="finding-reason-label"]');
    const noteLabel = article.querySelector('[data-role="finding-note-label"]');
    const needsReason = decision === 'deferred' || decision === 'dismissed';
    if (reasonLabel) reasonLabel.hidden = !needsReason;
    if (noteLabel) noteLabel.hidden = !needsReason;
  }

  function renderFinding(finding, previous) {
    const article = node('article', 'oj-ai-evaluation__finding');
    article.dataset.findingKey = finding.finding_key;
    article.dataset.previousDecision = previous?.decision || '';
    article.dataset.resolutionRevisionId = previous?.resolution_revision_id || '';
    article.dataset.resolutionSourceHash = previous?.resolution_source_hash || '';
    const header = node('header', 'oj-ai-evaluation__finding-header');
    const title = node('div');
    append(
      title,
      node('h4', '', finding.title),
      node('div', 'oj-ai-evaluation__detail-meta', labelFor(DIMENSION_LABELS, finding.dimension_key, '기타 평가 항목'))
    );
    append(header, title, badge(labelFor(SEVERITY_LABELS, finding.severity), finding.severity === 'critical' ? 'danger' : finding.severity === 'major' ? 'attention' : 'neutral'));
    article.appendChild(header);
    article.appendChild(node('p', '', finding.rationale));
    finding.evidence.forEach((evidence) => {
      const evidenceMessages = {
        worksheet: '활동지를 확인하세요.',
        node: '활동지 내용을 확인하세요.',
        response: '응답칸을 확인하세요.',
        achievement: '학습목표를 확인하세요.',
      };
      const quote = typeof evidence.quote === 'string' && evidence.quote.trim()
        ? evidence.quote
        : evidenceMessages[evidence.anchor_type] || '관련 내용을 확인하세요.';
      article.appendChild(node('div', 'oj-ai-evaluation__evidence', quote));
    });
    article.appendChild(node('p', 'oj-ai-evaluation__recommendation', finding.recommendation));
    if (reviewEditable(state.detail)) {
      article.appendChild(reviewControls(finding, previous));
      updateFindingControls(article);
    } else if (state.selectedReviewId || state.detail.reviews?.length) {
      article.appendChild(recordedDecision(previous));
    }
    return article;
  }

  function renderFindings(detail) {
    const value = node('div');
    const previous = latestDecisions(detail);
    detail.result.findings.forEach((finding) => value.appendChild(renderFinding(finding, previous.get(finding.finding_key))));
    return value;
  }

  function reviewActions(detail) {
    if (!canReview || !Array.isArray(detail.result?.findings) || !['review_required', 'reviewed'].includes(detail.evaluation.state)) return null;
    const value = node('div', 'oj-ai-evaluation__review-actions');
    const latest = detail.reviews?.[detail.reviews.length - 1];
    if (state.selectedReviewId) {
      value.appendChild(button('현재 검토로 돌아가기', 'current-review'));
      return value;
    }
    if (detail.evaluation.state === 'reviewed' && !state.reviewEditing) {
      append(value, node('span', 'oj-ai-evaluation__review-status', `교사 검토 ${latest?.review_no || 0}회 · 완료`), button('재검토', 'reopen-review', 'oj-ai-evaluation__primary'));
      return value;
    }
    const status = latest
      ? `교사 검토 ${latest.review_no}회 · ${latest.decision === 'completed' ? '완료' : '진행 중'}`
      : '교사 검토 대기';
    append(
      value,
      node('span', 'oj-ai-evaluation__review-status', state.reviewEditing ? `재검토 작성 · 다음 ${Number(latest?.review_no || 0) + 1}차` : status),
      detail.result.findings.length ? button('검토 저장', 'save-review') : null,
      button('검토 완료', 'complete-review', 'oj-ai-evaluation__primary')
    );
    if (state.reviewEditing) value.appendChild(button('재검토 취소', 'current-review'));
    return value;
  }

  function reviewHistory(detail) {
    if (!detail.reviews?.length) return null;
    const wrapper = section('교사 검토 이력');
    wrapper.dataset.role = 'review-history';
    const list = node('div', 'oj-ai-evaluation__review-history-list');
    detail.reviews.slice().reverse().forEach(review => {
      const entry = button('', 'select-review', 'oj-ai-evaluation__history-item');
      entry.dataset.reviewId = review.id;
      entry.setAttribute('aria-current', String(review.id === state.selectedReviewId));
      append(entry, node('strong', '', `${review.review_no}차 검토`),
        node('span', '', review.decision === 'completed' ? '완료' : '진행 중'),
        node('span', '', dateLabel(review.created_at_utc)),
        node('span', '', `처리 ${review.item_decisions.length}건`));
      list.appendChild(entry);
    });
    wrapper.appendChild(list);
    return wrapper;
  }

  function timeline(title, rows, mapper) {
    if (!rows?.length) return null;
    const wrapper = section(title);
    const list = node('ul', 'oj-ai-evaluation__timeline');
    rows.forEach((row) => {
      const values = mapper(row);
      const item = node('li');
      values.forEach((value) => item.appendChild(node('span', '', value)));
      list.appendChild(item);
    });
    wrapper.appendChild(list);
    return wrapper;
  }

  function renderDetail() {
    const detail = state.detail;
    if (!detail?.evaluation) {
      renderEmptyDetail('결과가 없습니다.', '평가를 다시 선택해 주세요.');
      return;
    }
    const fragment = document.createDocumentFragment();
    fragment.appendChild(detailHeader(detail));
    fragment.appendChild(metrics(detail));
    const recorded = selectedReview(detail);
    if (recorded) {
      const banner = node('div', 'oj-ai-evaluation__recorded-review');
      banner.dataset.role = 'recorded-review';
      append(banner, node('strong', '', `${recorded.review_no}차 교사 검토 기록`),
        node('span', '', `${recorded.decision === 'completed' ? '완료' : '진행 중'} · ${dateLabel(recorded.created_at_utc)}`),
        button('현재 검토로 돌아가기', 'current-review'));
      if (recorded.note) banner.appendChild(node('p', '', recorded.note));
      fragment.appendChild(banner);
    }
    if (detail.result) {
      const summary = section('요약');
      summary.appendChild(node('p', 'oj-ai-evaluation__summary-text', detail.result.summary));
      fragment.appendChild(summary);
      const dimensions = section('점검 항목');
      dimensions.appendChild(renderDimensions(detail.result));
      fragment.appendChild(dimensions);
      const findings = section(`지적 ${detail.result.findings.length}건`);
      findings.appendChild(renderFindings(detail));
      const actions = recorded ? null : reviewActions(detail);
      if (actions) findings.appendChild(actions);
      fragment.appendChild(findings);
      if (detail.result.limitations?.length) {
        const limitations = section('확인 사항');
        const list = node('ul');
        detail.result.limitations.forEach((item) => list.appendChild(node('li', '', item)));
        limitations.appendChild(list);
        fragment.appendChild(limitations);
      }
    } else {
      const waiting = section('진행 상태');
      const latestRun = detail.runs?.[0];
      const message = latestRun?.state === 'failed' && latestRun.last_error_code
        ? `AI 점검이 실패했습니다. ${failureText(latestRun)}`
        : 'AI 점검 결과를 기다리고 있습니다.';
      waiting.appendChild(node('p', 'oj-ai-evaluation__summary-text', message));
      fragment.appendChild(waiting);
    }
    const runTimeline = timeline('실행 이력', detail.runs, (run) => {
      const stateLabel = 'failed' === run.state && run.last_error_code
        ? `${labelFor(RUN_LABELS, run.state)} · ${failureText(run)}`
        : labelFor(RUN_LABELS, run.state);
      return [
        `#${run.run_no}`,
        stateLabel,
        dateLabel(run.finished_at_utc || run.queued_at_utc),
      ];
    });
    if (runTimeline) fragment.appendChild(runTimeline);
    const reviewTimeline = reviewHistory(detail);
    if (reviewTimeline) fragment.appendChild(reviewTimeline);
    elements.detail.replaceChildren(fragment);
  }

  function collectReviewDecisions(complete) {
    const items = [];
    const articles = [...elements.detail.querySelectorAll('[data-finding-key]')];
    articles.forEach((article) => {
      const decision = article.querySelector('[data-field="finding-decision"]')?.value || '';
      if (!decision) return;
      if (decision === 'resolved') {
        const previousResolution = article.dataset.previousDecision === 'resolved'
          && article.dataset.resolutionRevisionId
          && article.dataset.resolutionSourceHash;
        const revision = previousResolution
          ? { id: article.dataset.resolutionRevisionId, document_hash: article.dataset.resolutionSourceHash }
          : state.currentRevision;
        if (!revision || revision.id === state.detail.evaluation.revision.id) {
          throw new Error('수정한 새 활동지 버전을 저장한 뒤 해결로 표시해 주세요.');
        }
        items.push({
          finding_key: article.dataset.findingKey,
          decision,
          reason_code: null,
          note: null,
          resolution_revision_id: revision.id,
          resolution_source_hash: revision.document_hash,
        });
        return;
      }
      const reason = article.querySelector('[data-field="finding-reason"]')?.value || '';
      const note = article.querySelector('[data-field="finding-note"]')?.value.trim() || '';
      if (!reason || !note) throw new Error('보류 또는 기각 항목의 사유와 메모를 입력해 주세요.');
      items.push({
        finding_key: article.dataset.findingKey,
        decision,
        reason_code: reason,
        note,
        resolution_revision_id: null,
        resolution_source_hash: null,
      });
    });
    if (!complete && !items.length) throw new Error('저장할 검토 항목을 선택해 주세요.');
    if (complete && items.length !== articles.length) throw new Error('모든 지적 항목을 확인해 주세요.');
    if (complete && items.some((item) => item.decision === 'deferred')) throw new Error('보류 항목을 해결하거나 기각한 뒤 검토를 완료해 주세요.');
    return items;
  }

  async function submitReview(complete) {
    if (!state.detail || !reviewEditable(state.detail)) return;
    let saved = false;
    try {
      const decisions = collectReviewDecisions(complete);
      const detail = state.detail;
      state.reviewPending = true;
      elements.detail.querySelectorAll('button, select, textarea').forEach(control => { control.disabled = true; });
      showNotice(complete ? '검토를 완료하는 중입니다.' : '검토를 저장하는 중입니다.');
      await request(`/worksheet-evaluations/${encodeURIComponent(detail.evaluation.id)}/commands/review`, {
        method: 'POST',
        idempotencyKey: token('review'),
        body: reviewCommand(token('command'), detail, decisions, complete),
      });
      if (complete) {
        document.dispatchEvent(new CustomEvent('ojai:worksheet-review-completed', {
          detail: { worksheet_id: detail.evaluation.worksheet.id, revision_id: detail.evaluation.revision.id },
        }));
      }
      saved = true;
      showNotice(complete ? '검토를 완료했습니다.' : '검토를 저장했습니다.', 'success');
      await loadDetail(detail.evaluation.id);
      state.historyLoaded = false;
      if (history.open) await loadHistory();
    } catch (error) {
      showNotice(problemMessage(error, '검토를 저장하지 못했습니다.'), 'error');
      if (error?.status === 409) await loadDetail(state.selectedId);
    } finally {
      state.reviewPending = false;
      if (saved && state.detail) renderDetail();
      else elements.detail.querySelectorAll('button, select, textarea').forEach(control => { control.disabled = false; });
    }
  }

  async function executeControl(operation) {
    if (!state.detail?.evaluation?.latest_run) return;
    if (operation === 'cancel' && !(await (window.OJP && typeof window.OJP.confirm === 'function' ? window.OJP.confirm({title:'이 평가를 취소할까요?',message:'진행 중인 점검이 멈춥니다. 나중에 다시 요청할 수 있습니다.',confirmLabel:'평가 취소',destructive:true}) : Promise.resolve(window.confirm('이 평가를 취소할까요?'))))) return;
    const action = operation === 'retry' ? 'retry' : 'cancel';
    showNotice(operation === 'retry' ? '다시 점검을 요청하는 중입니다.' : '평가를 취소하는 중입니다.');
    try {
      const evaluationId = state.detail.evaluation.id;
      let commandDetail = state.detail;
      if (operation === 'retry') {
        const fresh = await request(`/worksheet-evaluations/${encodeURIComponent(evaluationId)}`);
        if (!fresh.data?.evaluation?.latest_run) throw new Error('최신 평가 상태를 확인하지 못했습니다.');
        commandDetail = fresh.data;
        state.detail = commandDetail;
        state.currentRevision = await readCurrentRevision(commandDetail);
        renderDetail();
        if (commandDetail.evaluation.status?.next_action !== 'retry') {
          showNotice('평가 상태가 이미 바뀌었습니다. 최신 상태를 확인해 주세요.');
          return;
        }
      }
      await request(`/worksheet-evaluations/${encodeURIComponent(evaluationId)}/commands/${action}`, {
        method: 'POST',
        idempotencyKey: token(action),
        body: controlCommand(token('command'), commandDetail),
      });
      showNotice(operation === 'retry' ? '다시 점검을 요청했습니다.' : '평가를 취소했습니다.', 'success');
      await loadEvaluations(false, evaluationId);
    } catch (error) {
      showNotice(problemMessage(error, '작업을 완료하지 못했습니다.'), 'error');
      if (error?.status === 409) {
        const selectedId = state.selectedId;
        await loadDetail(selectedId);
        if ([
          'worksheet_evaluation_source_stale',
          'worksheet_evaluation_configuration_changed',
        ].includes(error.code)) {
          const worksheetId = state.detail?.evaluation?.worksheet?.id || '';
          await openNewEvaluation(worksheetId, problemMessage(error, '현재 기준으로 새 점검을 시작해 주세요.'));
        }
      }
    }
  }

  async function loadWorksheets() {
    const items = requestedWorksheetId
      ? [(await request('/worksheets/' + encodeURIComponent(requestedWorksheetId))).data?.worksheet]
      : await window.OJAIWorkflow.allPages(request, '/worksheets?limit=100');
    state.worksheets = items.filter(item => item?.current_revision_id);
    fields.worksheet.replaceChildren(option('', '활동지를 선택하세요'));
    state.worksheets.forEach((item) => fields.worksheet.appendChild(option(item.id, item.title)));
  }

  function updateEnqueueState(statusOverride = null, tone = 'neutral') {
    const acknowledged = fields.acknowledgement.checked;
    elements.enqueue.disabled = state.enqueuePending || !consentRecorded || !state.newRevision || !acknowledged;
    elements.enqueue.textContent = state.enqueuePending ? '요청 중' : '점검 시작';
    elements.enqueue.setAttribute('aria-busy', state.enqueuePending ? 'true' : 'false');
    elements.dialogStatus.dataset.tone = tone;
    if (statusOverride !== null) {
      elements.dialogStatus.textContent = statusOverride;
    } else if (!consentRecorded) {
      elements.dialogStatus.textContent = '설정에서 외부 AI 처리 동의를 먼저 승인해 주세요.';
    } else if (!fields.worksheet.value) {
      elements.dialogStatus.textContent = '점검할 활동지를 선택하세요.';
    } else if (!state.newRevision) {
      elements.dialogStatus.textContent = '현재 버전을 확인하는 중입니다.';
    } else if (!acknowledged) {
      elements.dialogStatus.textContent = '외부 AI 처리 확인에 동의해 주세요.';
    } else {
      elements.dialogStatus.textContent = `버전 ${state.newRevision.revision_no}을 점검합니다.`;
    }
  }

  async function prepareNewRevision() {
    const requestNumber = ++state.revisionRequest;
    state.newRevision = null;
    updateEnqueueState();
    const worksheet = state.worksheets.find((item) => item.id === fields.worksheet.value);
    if (!worksheet?.current_revision_id) {
      updateEnqueueState();
      return;
    }
    try {
      const envelope = await request(`/worksheet-revisions/${encodeURIComponent(worksheet.current_revision_id)}`);
      if (requestNumber !== state.revisionRequest) return;
      const revision = envelope.data?.revision;
      if (revision?.id && revision?.document_hash) {
        state.newRevision = {
          id: revision.id,
          document_hash: revision.document_hash,
          revision_no: revision.revision_no,
          worksheet_id: worksheet.id,
        };
      }
    } catch (error) {
      if (requestNumber !== state.revisionRequest) return;
      updateEnqueueState(problemMessage(error, '현재 버전을 확인하지 못했습니다.'), 'error');
      return;
    }
    const previous = state.items.find(item => item.worksheet.id === worksheet.id);
    const revised = previous && previous.revision.id !== state.newRevision?.id;
    elements.dialog.querySelector('h2').textContent = revised ? '수정본 점검' : '활동지 점검';
    updateEnqueueState();
  }

  async function openNewEvaluation(preselectWorksheetId = '', recoveryMessage = '') {
    if (!isWorkbenchVisible()) return;
    fields.acknowledgement.checked = false;
    state.newRevision = null;
    if (typeof elements.dialog.showModal === 'function') elements.dialog.showModal();
    else elements.dialog.setAttribute('open', '');
    elements.dialogStatus.textContent = '활동지를 불러오는 중입니다.';
    try {
      await loadWorksheets();
      if (!elements.dialog.open || !isWorkbenchVisible()) return;
      if (preselectWorksheetId && state.worksheets.some((item) => item.id === preselectWorksheetId)) {
        fields.worksheet.value = preselectWorksheetId;
      }
      await prepareNewRevision();
      if (recoveryMessage) updateEnqueueState(recoveryMessage, 'error');
    } catch (error) {
      updateEnqueueState(problemMessage(error, '활동지를 불러오지 못했습니다.'), 'error');
    }
  }

  async function enqueueEvaluation() {
    if (!isWorkbenchVisible() || !state.newRevision || state.enqueuePending) return;
    state.enqueuePending = true;
    updateEnqueueState('점검을 요청하는 중입니다.');
    try {
      const envelope = await request(`/worksheet-revisions/${encodeURIComponent(state.newRevision.id)}/commands/evaluate`, {
        method: 'POST',
        idempotencyKey: token('evaluate'),
        body: enqueueCommand(token('command'), state.newRevision),
      });
      const evaluationId = envelope.data?.evaluation?.id || '';
      state.enqueuePending = false;
      if (typeof elements.dialog.close === 'function') elements.dialog.close();
      else elements.dialog.removeAttribute('open');
      await loadEvaluations(false, evaluationId);
      showNotice('점검을 요청했습니다.', 'success');
    } catch (error) {
      state.enqueuePending = false;
      updateEnqueueState(problemMessage(error, '점검을 요청하지 못했습니다.'), 'error');
    }
  }

  root.addEventListener('click', (event) => {
    const target = event.target.closest('[data-action]');
    if (!target || !root.contains(target)) return;
    const action = target.dataset.action;
    if (state.reviewPending) return;
    if (action === 'refresh') {
      loadConsentStatus();
      loadEvaluations(false, state.selectedId);
    }
    if (action === 'load-more') loadEvaluations(true);
    if (action === 'select-evaluation' || action === 'select-history') selectEvaluation(target.dataset.evaluationId);
    if (action === 'history-more') loadHistory(true);
    if (action === 'history-reload') loadHistory(Boolean(state.historyItems.length));
    if (action === 'detail-refresh') loadDetail(state.selectedId);
    if (action === 'new-evaluation') openNewEvaluation(requestedWorksheetId);
    if (action === 'evaluate-current') openNewEvaluation(target.dataset.worksheetId);
    if (action === 'enqueue') enqueueEvaluation();
    if (action === 'retry-evaluation') executeControl('retry');
    if (action === 'cancel-evaluation') executeControl('cancel');
    if (action === 'save-review') submitReview(false);
    if (action === 'complete-review') submitReview(true);
    if (action === 'reopen-review' && canReview && state.detail?.evaluation.state === 'reviewed') {
      state.selectedReviewId = '';
      state.reviewEditing = true;
      renderDetail();
    }
    if (action === 'select-review' && !state.reviewPending && state.detail?.reviews?.some(review => review.id === target.dataset.reviewId)) {
      state.selectedReviewId = target.dataset.reviewId;
      state.reviewEditing = false;
      renderDetail();
      const heading = elements.detail.querySelector('[data-role="recorded-review"] strong');
      heading.tabIndex = -1;
      heading.focus();
    }
    if (action === 'current-review' && !state.reviewPending) {
      state.selectedReviewId = '';
      state.reviewEditing = false;
      renderDetail();
    }
  });

  root.addEventListener('change', (event) => {
    if (event.target === fields.state) loadEvaluations();
    if (event.target === fields.worksheet) prepareNewRevision();
    if (event.target === fields.acknowledgement) updateEnqueueState();
    if (event.target.matches('[data-field="finding-decision"]')) {
      const article = event.target.closest('[data-finding-key]');
      if (article) updateFindingControls(article);
    }
  });

  fields.search.addEventListener('input', renderList);
  elements.dialog.addEventListener('close', () => {
    ++state.revisionRequest;
    state.enqueuePending = false;
    state.newRevision = null;
    fields.acknowledgement.checked = false;
    updateEnqueueState();
  });

  updateConsent();
  updateEnqueueState();
  function isWorkbenchVisible() {
    return !root.closest('[hidden]') && (!embedded || root.closest('[data-workflow-stage]')?.dataset.workflowStage === 'review');
  }

  function syncWorkbenchVisibility() {
    if (isWorkbenchVisible()) {
      loadConsentStatus();
      loadEvaluations();
      return;
    }
    ++state.listRequest; ++state.detailRequest; ++state.revisionRequest; ++state.historyRequest;
    if (elements.dialog.open) {
      if (typeof elements.dialog.close === 'function') elements.dialog.close();
      else elements.dialog.removeAttribute('open');
    }
  }

  document.addEventListener('ojai:workflow-stage', syncWorkbenchVisibility);
  document.addEventListener('ojai:assessment-tab-change', syncWorkbenchVisibility);
  syncWorkbenchVisibility();
})(window, document);

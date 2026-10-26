((window, document) => {
  'use strict';

  const root = document.getElementById('oj-ai-student-assignment-app');
  if (!root || root.dataset.mode !== 'detail') return;

  const resolveTimeZone = (raw) => {
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
  };
  const TIME_ZONE = resolveTimeZone(root.dataset.timezone);

  const DETAIL_EVENT = 'oj-ai:student-assignment-detail';
  const STATUS_EVENT = 'oj-ai:student-submission-status';
  const RECOVERY_PREFIX = 'oj_ai_submission_recovery_v1:';
  const RECOVERY_SESSION_KEY = 'oj_ai_submission_recovery_session_v1';
  const RECOVERY_TTL_MS = 12 * 60 * 60 * 1000;
  const DEFAULT_AUTOSAVE_DELAY_MS = 800;
  const apiBase = root.dataset.restRoot
    || `${String(window.wpApiSettings?.root || '/wp-json/').replace(/\/$/, '')}/oj-ai/v1`;
  let nonce = root.dataset.restNonce || window.wpApiSettings?.nonce || '';
  const autosaveDelay = Math.max(10, Number.parseInt(root.dataset.autosaveDelay || '', 10) || DEFAULT_AUTOSAVE_DELAY_MS);

  class ApiError extends Error {
    constructor(status, payload, network = false) {
      super(network ? '네트워크에 연결할 수 없습니다.' : String(payload?.message || '요청을 처리하지 못했습니다.'));
      this.name = 'ApiError';
      this.status = status;
      this.code = String(payload?.code || '');
      this.data = payload?.data && typeof payload.data === 'object' ? payload.data : {};
      this.network = network;
    }
  }

  const state = {
    assignment: null,
    submission: null,
    items: [],
    serverAnswers: {},
    workingAnswers: {},
    pendingOperation: null,
    conflict: null,
    saveTimer: null,
    savePromise: null,
    saving: false,
    submitting: false,
    confirmingSubmit: false,
    attachmentBusy: false,
    pendingUpload: null,
    serverReady: false,
    editable: false,
    suppressInput: false,
    mountSequence: 0,
    recoveryKey: '',
  };
  let submissionDialog = null;

  const isObject = (value) => Boolean(value) && typeof value === 'object' && !Array.isArray(value);
  const clone = (value) => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
  const own = (value, key) => Object.prototype.hasOwnProperty.call(value || {}, key);
  const create = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  };
  const dispatchSubmissionStatus = (submission) => {
    root.dispatchEvent(new CustomEvent(STATUS_EVENT, { detail: { submission } }));
  };
  const canonicalize = (value) => {
    if (Array.isArray(value)) return value.map(canonicalize);
    if (!isObject(value)) return value;
    return Object.keys(value).sort().reduce((result, key) => {
      result[key] = canonicalize(value[key]);
      return result;
    }, {});
  };
  const fingerprint = (value) => JSON.stringify(canonicalize(value));
  const commandToken = () => {
    const value = window.crypto?.randomUUID?.() || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
    return `student-${value}`;
  };
  const shortHash = (value) => {
    let hash = 2166136261;
    for (const character of String(value || '')) {
      hash ^= character.codePointAt(0);
      hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0).toString(16).padStart(8, '0');
  };
  const sessionScope = (() => {
    try {
      let value = window.sessionStorage.getItem(RECOVERY_SESSION_KEY);
      if (!value) {
        value = commandToken();
        window.sessionStorage.setItem(RECOVERY_SESSION_KEY, value);
      }
      return value;
    } catch (error) {
      return commandToken();
    }
  })();
  const nonceScope = shortHash(nonce);

  // A host that caches this page's HTML can serve one visitor's frozen
  // nonce to everyone else. Fetch a live one and retry once before
  // giving up, instead of trusting only the value baked into the page.
  const refreshNonce = async () => {
    try {
      const response = await window.fetch(`${apiBase}/session-nonce`, {
        credentials: 'same-origin',
        // WordPress treats a request with NO nonce at all as anonymous
        // (logging the visitor out for that one request), which is worse
        // than the stale-nonce rejection this call exists to recover
        // from. Send the stale value anyway so the server sees an
        // invalid-nonce request from a still-logged-in visitor instead.
        headers: { Accept: 'application/json', 'X-WP-Nonce': nonce },
      });
      if (!response.ok) return false;
      const data = await response.json().catch(() => null);
      if (data && typeof data.nonce === 'string' && data.nonce) {
        nonce = data.nonce;
        return true;
      }
    } catch (_error) {
      // Fall through: the caller surfaces the original request's error.
    }
    return false;
  };

  // Do this once, eagerly, instead of waiting for the first protected
  // request to be rejected. A host that caches this page's HTML always
  // serves a frozen nonce on the very first load of a cached copy, so
  // waiting for that rejection would show a failed request on every
  // such visit; refreshing up front avoids it in the common case while
  // the reactive retry below still covers a nonce going stale mid-session.
  const readyNonce = refreshNonce();

  const fetchOnce = (path, options) => window.fetch(`${apiBase}${path}`, {
    credentials: 'same-origin',
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      'X-WP-Nonce': nonce,
      ...(options.headers || {}),
    },
  });

  const request = async (path, options = {}) => {
    await readyNonce;
    let response;
    try {
      response = await fetchOnce(path, options);
      if ((response.status === 401 || response.status === 403) && (await refreshNonce())) {
        response = await fetchOnce(path, options);
      }
    } catch (error) {
      throw new ApiError(0, {}, true);
    }
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new ApiError(response.status, body);
    return body;
  };

  const responseItems = (assignment) => {
    const payload = assignment?.response_schema?.payload || {};
    const items = Array.isArray(payload.items) ? payload.items : Array.isArray(payload.responses) ? payload.responses : [];
    return items.map((item, index) => ({
      response_id: String(item.response_id || item.id || ''),
      kind: String(item.kind || item.type || 'long_text'),
      label: String(item.label || item.title || `응답 ${index + 1}`),
      constraints: isObject(item.constraints) ? item.constraints : {},
    })).filter((item) => item.response_id);
  };

  const currentRevisionId = (submission) => String(submission?.current_revision?.id || submission?.current_revision_id || '') || null;
  const currentAnswers = (submission) => isObject(submission?.current_revision?.response?.answers)
    ? clone(submission.current_revision.response.answers)
    : {};
  const expectedSnapshot = (submission = state.submission) => ({
    expected_state: String(submission?.state || ''),
    expected_lock_version: Number(submission?.lock_version ?? -1),
    expected_current_revision_id: currentRevisionId(submission),
    expected_schedule_version: Number(submission?.schedule?.version ?? state.assignment?.schedule_version ?? -1),
    expected_schedule_hash: String(submission?.schedule?.hash || state.assignment?.schedule_hash || ''),
    expected_publication_hash: String(submission?.source?.publication_hash || state.assignment?.publication_hash || ''),
    expected_save_token: String(submission?.save_token || ''),
  });
  const snapshotMatches = (left, right) => fingerprint(left || {}) === fingerprint(right || {});

  const formatSavedTime = (value) => {
    if (!value) return '';
    const date = new Date(`${String(value).replace(' ', 'T')}Z`);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('ko-KR', { hour: '2-digit', minute: '2-digit', timeZone: TIME_ZONE }).format(date);
  };

  const ensureStatusUi = () => {
    if (root.querySelector('[data-role="submission-save"]')) return;
    const host = create('section', 'oj-ai-student-save');
    host.dataset.role = 'submission-save';
    const indicator = create('div', 'oj-ai-student-save__indicator');
    indicator.dataset.role = 'save-indicator';
    indicator.dataset.state = 'idle';
    indicator.setAttribute('role', 'status');
    indicator.setAttribute('aria-live', 'polite');
    indicator.setAttribute('aria-atomic', 'true');
    const label = create('span', '', '저장 준비 중');
    label.dataset.role = 'save-label';
    const retry = create('button', 'oj-ai-student-secondary', '다시 시도');
    retry.type = 'button';
    retry.dataset.action = 'retry-save';
    retry.hidden = true;
    indicator.append(label, retry);

    const recovery = create('section', 'oj-ai-student-recovery');
    recovery.dataset.role = 'local-recovery';
    recovery.setAttribute('role', 'region');
    recovery.setAttribute('aria-label', '임시 저장 복구');
    recovery.hidden = true;
    const recoveryCopy = create('div');
    recoveryCopy.append(create('strong', '', '기기에 보관된 내용이 있습니다'), create('span', '', '이 기기에서 이어서 작성할 수 있습니다.'));
    const recoveryActions = create('div', 'oj-ai-student-recovery__actions');
    const restore = create('button', 'oj-ai-student-secondary', '복구');
    restore.type = 'button';
    restore.dataset.action = 'restore-recovery';
    const discard = create('button', 'oj-ai-student-secondary', '삭제');
    discard.type = 'button';
    discard.dataset.action = 'discard-recovery';
    recoveryActions.append(restore, discard);
    recovery.append(recoveryCopy, recoveryActions);

    const conflict = create('section', 'oj-ai-student-conflict');
    conflict.dataset.role = 'save-conflict';
    conflict.setAttribute('role', 'region');
    conflict.setAttribute('aria-label', '저장 충돌 해결');
    conflict.hidden = true;
    host.append(indicator, recovery, conflict);

    const submitHost = create('section', 'oj-ai-student-submit');
    submitHost.dataset.role = 'submission-submit';
    const submitButton = create('button', 'oj-ai-student-primary', '제출');
    submitButton.type = 'button';
    submitButton.dataset.action = 'submit-assignment';
    submitButton.hidden = true;
    const submitLabel = create('span', 'oj-ai-student-submit__label');
    submitLabel.dataset.role = 'submit-label';
    submitLabel.setAttribute('role', 'status');
    submitLabel.setAttribute('aria-live', 'polite');
    submitHost.append(submitButton, submitLabel);

    // The save indicator is a persistent status line, so it belongs near
    // the top where it stays visible while writing. The submit button is
    // an action the learner reaches for after finishing the last question,
    // so it belongs at the bottom of the writing area (next to the
    // start/continue button) instead of forcing a scroll back up.
    const notice = root.querySelector('.oj-ai-student-detail__notice');
    (notice || root.querySelector('.oj-ai-student-detail__header'))?.after(host);
    const bottomAnchor = root.querySelector('.oj-ai-student-primary--start') || root.querySelector('.oj-ai-student-detail__workspace');
    if (bottomAnchor) {
      bottomAnchor.after(submitHost);
    } else {
      root.append(submitHost);
    }
  };

  const updateSubmitUi = () => {
    ensureStatusUi();
    const button = root.querySelector('[data-action="submit-assignment"]');
    const label = root.querySelector('[data-role="submit-label"]');
    const submission = state.submission;
    const canSubmit = isObject(submission) && Array.isArray(submission.actions) && submission.actions.includes('submission.submit');
    if (button) {
      button.hidden = !canSubmit;
      button.disabled = state.submitting === true;
      button.textContent = state.submitting ? '제출 중...' : '제출';
    }
    if (label) {
      const finalStates = { submitted: '제출이 완료되었습니다', auto_submitted: '자동 제출되었습니다', proxy_submitted: '교사가 제출했습니다', locked: '제출이 잠겼습니다', reopened: '교사가 추가 제출 1회를 허용했습니다' };
      label.textContent = state.submitting
        ? '최신 답안을 저장한 뒤 제출합니다'
        : isObject(submission) && finalStates[submission.state] ? finalStates[submission.state] : '';
    }
  };

  const setSaveState = (name, label, retry = false) => {
    ensureStatusUi();
    const indicator = root.querySelector('[data-role="save-indicator"]');
    const text = root.querySelector('[data-role="save-label"]');
    const button = root.querySelector('[data-action="retry-save"]');
    if (indicator) indicator.dataset.state = name;
    if (indicator) indicator.setAttribute('aria-busy', String(name === 'saving'));
    if (text) text.textContent = label;
    if (button) button.hidden = !retry;
  };

  const setResponseEnabled = (enabled) => {
    root.querySelectorAll('.oj-ai-student-response-anchor').forEach((fieldset) => {
      fieldset.disabled = !enabled;
    });
    root.querySelectorAll('input[type="file"].oj-ai-student-response-input').forEach((input) => {
      input.disabled = !enabled || state.attachmentBusy;
    });
  };

  const fieldsetFor = (id) => [...root.querySelectorAll('.oj-ai-student-response-anchor')]
    .find((node) => node.dataset.responseId === id) || null;

  const drawingBoards = new Map();
  const paintDrawing = (board) => {
    const ctx = board.canvas.getContext('2d');
    ctx.clearRect(0, 0, board.canvas.width, board.canvas.height);
    if (board.background) ctx.drawImage(board.background, 0, 0, board.canvas.width, board.canvas.height);
    ctx.strokeStyle = '#202c38'; ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    board.strokes.forEach((stroke) => {
      ctx.beginPath(); stroke.points.forEach(([x, y], index) => index ? ctx.lineTo(x, y) : ctx.moveTo(x, y));
      if (stroke.points.length === 1) ctx.lineTo(stroke.points[0][0] + 0.1, stroke.points[0][1]);
      ctx.stroke();
    });
  };
  const queueDrawing = (board) => {
    board.dirty = true;
    board.fieldset.querySelector('[data-role="drawing-status"]').textContent = '그림 저장 대기';
    window.clearTimeout(board.timer);
    board.timer = window.setTimeout(() => {
      if (state.attachmentBusy || state.saving) { if (state.editable) queueDrawing(board); return; }
      saveDrawing(board);
    }, 700);
  };
  const mountDrawings = () => {
    drawingBoards.forEach((board) => window.clearTimeout(board.timer)); drawingBoards.clear();
    state.items.filter((item) => item.kind === 'drawing').forEach((item) => {
      const fieldset = fieldsetFor(item.response_id); const canvas = fieldset?.querySelector('canvas');
      if (!canvas) return;
      const board = {item, fieldset, canvas, strokes: [], baseElements: [], background: null, dirty: false, loadedId: null, timer: null, active: null};
      drawingBoards.set(item.response_id, board);
      const point = (event) => { const rect = canvas.getBoundingClientRect(); return [Math.max(0, Math.min(canvas.width, (event.clientX - rect.left) * canvas.width / rect.width)), Math.max(0, Math.min(canvas.height, (event.clientY - rect.top) * canvas.height / rect.height))]; };
      canvas.addEventListener('pointerdown', (event) => {
        if (fieldset.disabled || !state.editable || state.attachmentBusy || board.loading || board.loadFailed || event.button !== 0) return;
        if (board.strokes.length + board.baseElements.length >= (item.constraints.max_strokes || 500)) { fieldset.querySelector('[data-role="drawing-status"]').textContent = '그림 획 수 제한에 도달했습니다.'; return; }
        event.preventDefault(); canvas.setPointerCapture(event.pointerId);
        board.active = {type: 'freedraw', points: [point(event)]}; board.strokes.push(board.active); paintDrawing(board);
      });
      canvas.addEventListener('pointermove', (event) => {
        if (!board.active || fieldset.disabled || !state.editable || board.active.points.length >= 2000) return;
        board.active.points.push(point(event)); paintDrawing(board);
      });
      const stop = () => { if (board.active) { board.active = null; queueDrawing(board); } };
      canvas.addEventListener('pointerup', stop); canvas.addEventListener('pointercancel', stop);
    });
  };
  const restoreDrawing = async (board, value) => {
    const id = value?.document_attachment_id || null;
    if (board.dirty || board.loading || board.loadedId === id) return;
    board.loadedId = id; board.strokes = []; board.baseElements = []; board.background = null;
    paintDrawing(board);
    if (!id || !state.submission?.id) return;
    const sequence = state.mountSequence; board.loading = true;
    try {
      const document = await request(`/me/submissions/${encodeURIComponent(state.submission.id)}/attachments/${encodeURIComponent(id)}/content`, {cache: 'no-store'});
      if (sequence !== state.mountSequence || board.loadedId !== id) return;
      if (document.type !== 'excalidraw_response' || !Array.isArray(document.elements) || !/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(document.image?.data_url || '')) throw new Error('그림 형식을 확인할 수 없습니다.');
      const image = new Image(); image.src = document.image.data_url; await image.decode();
      if (sequence !== state.mountSequence || board.loadedId !== id) return;
      board.background = image; board.baseElements = document.elements; paintDrawing(board);
      board.fieldset.dataset.answered = 'true';
      board.fieldset.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (error) {
      board.fieldset.querySelector('[data-role="drawing-status"]').textContent = '저장된 그림을 불러오지 못했습니다. 새로고침해 주세요.';
      board.loadFailed = true;
    } finally { board.loading = false; }
  };
  const saveDrawing = async (board) => {
    if (!board?.dirty || !state.editable || state.conflict || state.attachmentBusy || state.pendingUpload || board.loading || board.loadFailed) return;
    window.clearTimeout(board.timer);
    if (!board.strokes.length && !board.baseElements.length) {
      delete state.workingAnswers[board.item.response_id]; board.dirty = false; board.fieldset.dataset.answered = 'false'; await saveNow(); return;
    }
    const dataUrl = board.canvas.toDataURL('image/png');
    const document = {type: 'excalidraw_response', source: 'oj-ai-dashboard', version: 1, elements: [...board.baseElements, ...board.strokes], appState: {}, files: {}, image: {data_url: dataUrl, width: board.canvas.width, height: board.canvas.height, mime: 'image/png', updated_at: new Date().toISOString()}};
    const bytes = Uint8Array.from(atob(dataUrl.split(',')[1]), (char) => char.charCodeAt(0));
    const files = [{file: new File([JSON.stringify(document)], 'drawing.json', {type: 'application/json'}), role: 'document'}, {file: new File([bytes], 'drawing.png', {type: 'image/png'}), role: 'preview'}];
    await uploadFiles(null, {responseId: board.item.response_id, files, drawing: true, attachmentIds: {}});
  };

  const writeAnswers = (answers) => {
    state.suppressInput = true;
    state.items.forEach((item) => {
      const fieldset = fieldsetFor(item.response_id);
      if (!fieldset) return;
      const value = own(answers, item.response_id) ? answers[item.response_id] : undefined;
      if (item.kind === 'short_text' || item.kind === 'long_text' || item.kind === 'number') {
        const input = fieldset.querySelector('input[type="text"], textarea');
        if (input) input.value = typeof value === 'string' ? value : '';
      } else if (item.kind === 'single_choice' || item.kind === 'multiple_choice') {
        const selected = new Set(Array.isArray(value) ? value.map(String) : (typeof value === 'string' ? [value] : []));
        fieldset.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach((input) => {
          input.checked = selected.has(input.value);
        });
      } else if (item.kind === 'checkbox') {
        const input = fieldset.querySelector('input[type="checkbox"]');
        if (input) input.checked = value === true;
      } else if (item.kind === 'table') {
        const table = isObject(value) ? value : {};
        fieldset.querySelectorAll('[data-row-id][data-column-id]').forEach((input) => {
          const cell = table?.[input.dataset.rowId]?.[input.dataset.columnId];
          if (input.type === 'checkbox') input.checked = cell === true;
          else input.value = typeof cell === 'string' ? cell : '';
        });
      } else if (item.kind === 'drawing') {
        const board = drawingBoards.get(item.response_id);
        if (board) restoreDrawing(board, value);
      }
    });
    state.suppressInput = false;
    renderAttachments();
  };

  const collectAnswers = () => {
    const result = {};
    state.items.forEach((item) => {
      const id = item.response_id;
      const fieldset = fieldsetFor(id);
      if (!fieldset) return;
      if (item.kind === 'short_text' || item.kind === 'long_text' || item.kind === 'number') {
        const input = fieldset.querySelector('input[type="text"], textarea');
        const value = String(input?.value || '');
        if (value !== '') result[id] = value;
      } else if (item.kind === 'single_choice') {
        const selected = fieldset.querySelector('input[type="radio"]:checked');
        if (selected) result[id] = selected.value;
      } else if (item.kind === 'multiple_choice') {
        const selected = [...fieldset.querySelectorAll('input[type="checkbox"]:checked')].map((input) => input.value);
        if (selected.length) result[id] = selected;
      } else if (item.kind === 'checkbox') {
        result[id] = fieldset.querySelector('input[type="checkbox"]')?.checked === true;
      } else if (item.kind === 'table') {
        const table = {};
        fieldset.querySelectorAll('[data-row-id][data-column-id]').forEach((input) => {
          const rowId = String(input.dataset.rowId || '');
          const columnId = String(input.dataset.columnId || '');
          if (!rowId || !columnId) return;
          const value = input.type === 'checkbox' ? input.checked : String(input.value || '');
          if (input.type !== 'checkbox' && value === '') return;
          if (!table[rowId]) table[rowId] = {};
          table[rowId][columnId] = value;
        });
        const renderedRows = new Set([...fieldset.querySelectorAll('[data-row-id]')].map((input) => input.dataset.rowId));
        const previous = isObject(state.workingAnswers[id]) ? state.workingAnswers[id] : {};
        Object.keys(previous).filter((rowId) => !renderedRows.has(rowId)).forEach((rowId) => { table[rowId] = clone(previous[rowId]); });
        if (Object.keys(table).length) result[id] = table;
      } else if (own(state.workingAnswers, id)) {
        result[id] = clone(state.workingAnswers[id]);
      }
    });
    return result;
  };

  const recoverySafeKinds = new Set(['short_text', 'long_text', 'single_choice', 'multiple_choice', 'checkbox', 'number', 'table']);
  const recoveryPayload = (answers) => {
    const safe = {};
    const cleared = [];
    state.items.filter((item) => recoverySafeKinds.has(item.kind)).forEach((item) => {
      if (own(answers, item.response_id)) safe[item.response_id] = clone(answers[item.response_id]);
      else cleared.push(item.response_id);
    });
    return { answers: safe, cleared_response_ids: cleared };
  };

  const buildRecoveryKey = (assignment, submission) => {
    const publicationHash = String(submission?.source?.publication_hash || assignment?.publication_hash || 'unknown');
    return `${RECOVERY_PREFIX}${assignment?.id || 'unknown'}:${submission?.id || 'unknown'}:${publicationHash}`;
  };

  const removeRecovery = () => {
    if (state.recoveryKey) {
      try { window.localStorage.removeItem(state.recoveryKey); } catch (error) { /* Storage is optional. */ }
    }
    const banner = root.querySelector('[data-role="local-recovery"]');
    if (banner) banner.hidden = true;
  };

  const readRecovery = () => {
    if (!state.recoveryKey) return null;
    try {
      const value = JSON.parse(window.localStorage.getItem(state.recoveryKey) || 'null');
      if (
        !isObject(value) || value.version !== 1 || value.expires_at_ms <= Date.now()
        || value.session_scope !== sessionScope || value.nonce_scope !== nonceScope
        || value.assignment_id !== state.assignment?.id || value.submission_id !== state.submission?.id
        || value.publication_hash !== expectedSnapshot().expected_publication_hash
        || !isObject(value.answers) || !Array.isArray(value.cleared_response_ids)
      ) {
        removeRecovery();
        return null;
      }
      return value;
    } catch (error) {
      removeRecovery();
      return null;
    }
  };

  const persistRecovery = (answers = state.workingAnswers) => {
    if (!state.recoveryKey || !state.submission) return;
    const payload = recoveryPayload(answers);
    if (fingerprint(answers) === fingerprint(state.serverAnswers) && !state.pendingOperation) {
      removeRecovery();
      return;
    }
    try {
      window.localStorage.setItem(state.recoveryKey, JSON.stringify({
        version: 1,
        assignment_id: state.assignment.id,
        submission_id: state.submission.id,
        publication_hash: expectedSnapshot().expected_publication_hash,
        saved_at_utc: new Date().toISOString(),
        expires_at_ms: Date.now() + RECOVERY_TTL_MS,
        session_scope: sessionScope,
        nonce_scope: nonceScope,
        expected: expectedSnapshot(),
        answers: payload.answers,
        cleared_response_ids: payload.cleared_response_ids,
        pending_operation: state.pendingOperation ? clone(state.pendingOperation) : null,
      }));
    } catch (error) {
      setSaveState('failed', '기기에 보관하지 못했습니다', true);
    }
  };

  const showRecoveryIfNeeded = () => {
    const recovery = readRecovery();
    const banner = root.querySelector('[data-role="local-recovery"]');
    if (!banner) return;
    banner.hidden = !recovery;
    if (recovery) setSaveState('local_only', '기기에 보관됨');
  };

  const mergeRecovery = (recovery, base) => {
    const result = clone(base || {});
    recovery.cleared_response_ids.forEach((id) => { delete result[id]; });
    Object.entries(recovery.answers).forEach(([id, value]) => { result[id] = clone(value); });
    return result;
  };

  const attachmentAnswerIds = (answers) => new Set(state.items.flatMap((item) => {
    const answer = answers[item.response_id];
    if (['file_upload', 'image_upload'].includes(item.kind)) return Array.isArray(answer) ? answer : [];
    if (item.kind === 'drawing' && isObject(answer)) return [answer.document_attachment_id, answer.preview_attachment_id].filter(Boolean);
    return [];
  }));
  const stagedAttachmentIds = (answers) => (Array.isArray(state.submission?.attachments) ? state.submission.attachments : [])
    .filter((attachment) => attachmentAnswerIds(answers).has(attachment.id) && attachment.state === 'ready'
      && Array.isArray(attachment.revision_scope) && attachment.revision_scope.includes('staged'))
    .map((attachment) => String(attachment.id || ''))
    .filter(Boolean);

  const buildOperation = (answers) => {
    const token = commandToken();
    return {
      command_id: token,
      body: {
        command_id: token,
        expected: expectedSnapshot(),
        payload: { answers: clone(answers), staged_attachment_ids: stagedAttachmentIds(answers) },
      },
    };
  };

  const savedSubmission = (payload, sentAnswers) => {
    const result = payload?.data || {};
    const submission = result.submission || {};
    const revision = result.revision || {};
    return {
      ...state.submission,
      ...submission,
      // The autosave command's own result always reports actions as just
      // ['submission.autosave'] (a frozen contract on the PHP side). Keep
      // whatever the last full submission fetch already established; the
      // one case where autosaving does change eligibility (creating the
      // first revision unlocks submit) is re-fetched separately right
      // after this call — see the `hadRevision` check in performSave().
      actions: Array.isArray(state.submission?.actions) ? state.submission.actions : submission.actions,
      source: state.submission?.source,
      schedule: state.submission?.schedule,
      attachments: (state.submission?.attachments || []).map((attachment) => attachmentAnswerIds(sentAnswers).has(attachment.id)
        ? { ...attachment, state: 'bound', revision_scope: [...new Set([...(attachment.revision_scope || []).filter((scope) => scope !== 'staged'), 'current'])] }
        : { ...attachment, revision_scope: (attachment.revision_scope || []).filter((scope) => scope !== 'current') }),
      current_revision: {
        ...revision,
        response: {
          answers: clone(sentAnswers),
          publication_hash: expectedSnapshot().expected_publication_hash,
          publication_id: state.submission?.source?.worksheet_publication_id,
          response_schema_version: '1.0.0',
        },
      },
    };
  };

  const latestSubmission = async () => {
    const id = String(state.submission?.id || state.assignment?.submission?.id || '');
    if (!id) throw new ApiError(404, { message: '제출 내용을 찾을 수 없습니다.' });
    const body = await request(`/me/submissions/${encodeURIComponent(id)}`);
    if (!isObject(body?.data?.submission)) throw new ApiError(503, { message: '저장 내용을 확인하지 못했습니다.' });
    if (body.data.submission.id !== id || id !== state.submission?.id) throw new ApiError(409, { message: '현재 제출이 변경되었습니다. 다시 확인해 주세요.' });
    return body.data.submission;
  };

  const renderAttachments = () => {
    state.items.filter((item) => ['file_upload', 'image_upload', 'drawing'].includes(item.kind)).forEach((item) => {
      const fieldset = fieldsetFor(item.response_id);
      if (!fieldset) return;
      fieldset.querySelector('[data-role="answer-attachments"]')?.remove();
      const list = create('div', 'oj-ai-student-attachments');
      list.dataset.role = 'answer-attachments';
      const drawing = item.kind === 'drawing';
      const answer = state.workingAnswers[item.response_id];
      const selected = new Set(drawing ? [answer?.document_attachment_id, answer?.preview_attachment_id].filter(Boolean) : answer || []);
      if (!drawing) fieldset.dataset.answered = selected.size ? 'true' : 'false';
      (state.submission?.attachments || []).filter((file) => file.response_id === item.response_id
        && (drawing ? file.revision_scope?.includes('staged') && !selected.has(file.id)
          : selected.has(file.id) || file.revision_scope?.includes('staged'))).forEach((file) => {
        const row = create('div', 'oj-ai-student-attachment');
        const labels = { staged: '검사 대기', scanning: '검사 중', ready: '첨부 가능', bound: '답안에 저장됨', rejected: '검사 실패' };
        row.append(create('span', '', `${file.name || file.original_name || '첨부파일'} · ${selected.has(file.id) ? '답안에 연결됨' : labels[file.state] || '상태 확인 필요'}`));
        const button = (action, label) => {
          const node = create('button', 'oj-ai-student-secondary', label);
          node.type = 'button'; node.dataset.action = action; node.dataset.attachmentId = file.id;
          node.dataset.responseId = item.response_id; node.disabled = state.attachmentBusy || !state.editable;
          row.append(node);
        };
        if (!drawing && !selected.has(file.id) && file.state === 'ready') button('attach-ready', '답안에 추가');
        if (['staged', 'scanning'].includes(file.state)) button('check-attachment', '상태 확인');
        if (selected.has(file.id) || ['staged', 'scanning', 'ready'].includes(file.state)) button('remove-answer-attachment', drawing ? '미완료 파일 삭제' : '삭제');
        list.append(row);
      });
      if (state.pendingUpload?.responseId === item.response_id) {
        const retry = create('button', 'oj-ai-student-secondary', '업로드 다시 시도');
        retry.type = 'button'; retry.dataset.action = 'retry-upload'; retry.disabled = state.attachmentBusy;
        list.append(retry);
      }
      fieldset.append(list);
    });
    root.dispatchEvent(new Event('change'));
  };

  const refreshAttachmentState = async () => {
    const sequence = state.mountSequence;
    const fresh = await latestSubmission();
    if (sequence !== state.mountSequence) return [];
    if (!snapshotMatches(expectedSnapshot(fresh), expectedSnapshot())) {
      openConflictWith(collectAnswers(), fresh);
      throw new ApiError(409, { message: '답안이 변경되었습니다. 저장 내용을 먼저 확인하세요.' });
    }
    state.submission = fresh;
    renderAttachments();
    return fresh.attachments || [];
  };

  const connectAttachment = async (responseId, attachmentId) => {
    const item = state.items.find((candidate) => candidate.response_id === responseId);
    const file = (state.submission?.attachments || []).find((candidate) => candidate.id === attachmentId && candidate.response_id === responseId);
    if (!item || !file || file.state !== 'ready') throw new ApiError(409, { message: '파일 검사가 완료되지 않았습니다.' });
    const ids = new Set(state.workingAnswers[responseId] || []);
    ids.add(attachmentId);
    if (ids.size > (item.constraints.max_files || 5)) throw new ApiError(422, { message: '첨부 가능한 파일 수를 초과했습니다.' });
    state.workingAnswers[responseId] = [...ids];
    renderAttachments();
    await saveNow();
  };

  const uploadFiles = async (input = null, drawingUpload = null) => {
    if (state.attachmentBusy || !state.editable || state.conflict) return;
    const sequence = state.mountSequence;
    const fieldset = input?.closest('[data-response-id]');
    const responseId = fieldset?.dataset.responseId || state.pendingUpload?.responseId || drawingUpload?.responseId;
    const item = state.items.find((candidate) => candidate.response_id === responseId);
    if (!item || !['file_upload', 'image_upload', 'drawing'].includes(item.kind)) return;
    const selectedFiles = input ? [...(input.files || [])].map((file) => ({file, role: 'file'})) : drawingUpload?.files || [];
    if (!state.pendingUpload && !selectedFiles.length) return;
    window.clearTimeout(state.saveTimer);
    await saveNow();
    if (sequence !== state.mountSequence) return;
    if (state.pendingOperation || state.conflict || fingerprint(collectAnswers()) !== fingerprint(state.serverAnswers)) {
      setSaveState('failed', '답안을 먼저 저장한 뒤 첨부해 주세요', true); return;
    }
    state.attachmentBusy = true;
    setResponseEnabled(false);
    try {
      if (!state.pendingUpload) {
        if (drawingUpload && (state.submission?.attachments || []).some((file) => file.response_id === responseId
          && file.revision_scope?.includes('staged') && ['staged', 'scanning', 'ready'].includes(file.state))) {
          throw new ApiError(409, {message: '저장되지 않은 그림 파일이 남아 있습니다. 미완료 파일을 확인해 주세요.'});
        }
        if (!drawingUpload && (state.workingAnswers[responseId] || []).length + selectedFiles.length > (item.constraints.max_files || 5)) {
          throw new ApiError(422, { message: '첨부 가능한 파일 수를 초과했습니다.' });
        }
        state.pendingUpload = {...drawingUpload, responseId, files: selectedFiles, operation: null, currentAttachmentId: null};
      }
      const pending = state.pendingUpload;
      while (pending.files.length) {
        const {file, role} = pending.files[0];
        if (file.size > (item.constraints.max_bytes || 25 * 1024 * 1024)) {
          state.pendingUpload = null;
          throw new ApiError(422, { message: '파일 크기 제한을 초과했습니다.' });
        }
        if (!pending.operation) {
          const token = commandToken();
          const body = new FormData();
          Object.entries({ command_id: token, ...expectedSnapshot(), response_id: responseId, role })
            .forEach(([key, value]) => body.append(key, value === null ? '' : String(value)));
          body.append('file', file, file.name);
          pending.operation = { token, body };
        }
        setSaveState('saving', `${file.name} 업로드 중`);
        const result = pending.currentAttachmentId ? null : await request(`/me/submissions/${encodeURIComponent(state.submission.id)}/attachments`, {
          method: 'POST', headers: { 'Idempotency-Key': pending.operation.token }, body: pending.operation.body,
        });
        if (sequence !== state.mountSequence) return;
        const id = pending.currentAttachmentId || result?.data?.attachment?.id;
        if (!id) throw new ApiError(503, { message: '첨부 결과를 확인하지 못했습니다.' });
        pending.currentAttachmentId = id;
        setSaveState('saving', `${file.name} 검사 중`);
        let ready = false;
        for (let attempt = 0; attempt < 30; attempt += 1) {
          if (sequence !== state.mountSequence) return;
          const rows = await refreshAttachmentState();
          if (sequence !== state.mountSequence) return;
          const stored = rows.find((row) => row.id === id);
          if (stored?.state === 'ready') { ready = true; break; }
          if (!stored || stored.state === 'rejected') throw new ApiError(422, { message: '파일 검사를 통과하지 못했습니다. 다른 파일을 선택해 주세요.' });
          await new Promise((resolve) => window.setTimeout(resolve, 1000));
        }
        if (!ready) throw new ApiError(409, { message: '파일 검사가 지연되고 있습니다. 상태 확인 후 답안에 추가해 주세요.' });
        pending.files.shift(); pending.operation = null; pending.currentAttachmentId = null;
        state.attachmentBusy = false;
        if (pending.drawing) {
          pending.attachmentIds[`${role}_attachment_id`] = id;
          if (!pending.files.length) {
            state.workingAnswers[responseId] = {...pending.attachmentIds};
            const board = drawingBoards.get(responseId);
            if (board) { board.dirty = false; board.loadedId = pending.attachmentIds.document_attachment_id; board.fieldset.dataset.answered = 'true'; }
            await saveNow();
            if (board) board.fieldset.querySelector('[data-role="drawing-status"]').textContent = !state.pendingOperation && fingerprint(state.workingAnswers) === fingerprint(state.serverAnswers) ? '그림 저장됨' : '그림 연결 저장을 다시 시도해 주세요.';
          }
        } else await connectAttachment(responseId, id);
        if (sequence !== state.mountSequence) return;
        state.attachmentBusy = true;
        if (state.pendingOperation || state.conflict || fingerprint(collectAnswers()) !== fingerprint(state.serverAnswers)) {
          throw new ApiError(409, { message: '파일 연결을 저장하지 못했습니다. 답안 저장을 다시 시도해 주세요.' });
        }
      }
      state.pendingUpload = null;
      setSaveState('saved', '첨부파일 저장됨');
    } catch (error) {
      if (sequence !== state.mountSequence) return;
      if (!error.network && error.status < 500 && state.pendingUpload?.operation && !state.pendingUpload.currentAttachmentId) {
        if (Object.keys(state.pendingUpload.attachmentIds || {}).length) state.pendingUpload.operation = null;
        else state.pendingUpload = null;
      }
      setSaveState('failed', error.message || '파일을 첨부하지 못했습니다', true);
    } finally {
      if (input) input.value = '';
      if (sequence === state.mountSequence) {
        if (state.pendingUpload && !state.pendingUpload.files.length) state.pendingUpload = null;
        state.attachmentBusy = false;
        setResponseEnabled(state.editable && !state.conflict);
        renderAttachments();
      }
    }
  };

  const manageAttachment = async (button) => {
    if (state.attachmentBusy || !state.editable || state.conflict) return;
    const sequence = state.mountSequence;
    try {
      await saveNow();
      if (sequence !== state.mountSequence) return;
      if (state.pendingOperation || state.conflict || fingerprint(collectAnswers()) !== fingerprint(state.serverAnswers)) return;
      await refreshAttachmentState();
      if (sequence !== state.mountSequence) return;
      const id = button.dataset.attachmentId;
      const responseId = button.dataset.responseId;
      const file = (state.submission.attachments || []).find((row) => row.id === id);
      if (button.dataset.action === 'attach-ready') { await connectAttachment(responseId, id); return; }
      if (button.dataset.action === 'check-attachment') return;
      if (!file) throw new ApiError(404, { message: '첨부파일을 찾을 수 없습니다.' });
      if (file.revision_scope?.includes('staged')) {
        const removeMessage = `아직 답안에 저장되지 않은 ${file.name || file.original_name || '첨부파일'}을 삭제할까요? 저장된 답안은 그대로 남습니다.`;
        if (!(await (window.OJP && typeof window.OJP.confirm === 'function' ? window.OJP.confirm({ title: '첨부파일을 삭제할까요?', message: removeMessage, confirmLabel: '삭제', destructive: true }) : Promise.resolve(window.confirm(removeMessage))))) return;
        const token = commandToken();
        await request(`/me/submissions/${encodeURIComponent(state.submission.id)}/attachments/${encodeURIComponent(id)}/commands/remove`, {
          method: 'POST', headers: { 'Idempotency-Key': token },
          body: JSON.stringify({ command_id: token, expected: expectedSnapshot(), payload: {} }),
        });
        if (sequence !== state.mountSequence) return;
        state.submission.attachments = state.submission.attachments.filter((row) => row.id !== id);
        if (state.pendingUpload?.responseId === responseId && (state.pendingUpload.currentAttachmentId === id
          || Object.values(state.pendingUpload.attachmentIds || {}).includes(id))) state.pendingUpload = null;
      }
      if (state.items.find((item) => item.response_id === responseId)?.kind !== 'drawing') {
        const remaining = (state.workingAnswers[responseId] || []).filter((candidate) => candidate !== id);
        if (remaining.length) state.workingAnswers[responseId] = remaining;
        else delete state.workingAnswers[responseId];
      }
      renderAttachments();
      await saveNow();
    } catch (error) { if (sequence === state.mountSequence) setSaveState('failed', error.message, true); }
  };

  const answerPreview = (item, value) => {
    if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) return '답변 없음';
    if (typeof value === 'boolean') return value ? '선택함' : '선택하지 않음';
    if (typeof value === 'string') {
      const option = Array.isArray(item.constraints.options)
        ? item.constraints.options.find((candidate) => String(candidate.option_id || candidate.value || '') === value)
        : null;
      const text = String(option?.label || value).replace(/\s+/g, ' ').trim();
      return text.length > 100 ? `${text.slice(0, 100)}…` : text;
    }
    if (Array.isArray(value)) {
      const options = Array.isArray(item.constraints.options) ? item.constraints.options : [];
      const labels = value.map((id) => String(options.find((option) => String(option.option_id || option.value || '') === id)?.label || id));
      return labels.length ? labels.join(', ') : '답변 없음';
    }
    if (item.kind === 'drawing') return '그림 답변';
    if (item.kind === 'table') return `표 ${Object.keys(value || {}).length}행`;
    return '첨부 답변';
  };

  const conflictDifferences = (local, server) => state.items.filter((item) => fingerprint(local[item.response_id]) !== fingerprint(server[item.response_id]));

  const renderConflict = () => {
    const panel = root.querySelector('[data-role="save-conflict"]');
    if (!panel || !state.conflict) return;
    panel.replaceChildren();
    panel.hidden = false;
    const header = create('div', 'oj-ai-student-conflict__header');
    header.append(create('strong', '', '다른 저장 내용이 있습니다'), create('span', '', '문항마다 사용할 내용을 선택하세요.'));
    panel.append(header);
    if (!state.conflict.server) {
      const retry = create('button', 'oj-ai-student-secondary', '다시 확인');
      retry.type = 'button';
      retry.dataset.action = 'refresh-conflict';
      panel.append(retry);
      return;
    }
    const differences = conflictDifferences(state.conflict.local, state.conflict.server);
    const list = create('div', 'oj-ai-student-conflict__list');
    differences.forEach((item) => {
      const row = create('fieldset', 'oj-ai-student-conflict__item');
      row.dataset.responseId = item.response_id;
      row.append(create('legend', '', item.label));
      [['mine', '내 내용', state.conflict.local[item.response_id]], ['saved', '저장된 내용', state.conflict.server[item.response_id]]].forEach(([choice, label, value]) => {
        const option = create('label', 'oj-ai-student-conflict__choice');
        const input = create('input');
        input.type = 'radio';
        input.name = `conflict-${item.response_id}`;
        input.value = choice;
        input.checked = choice === 'mine';
        option.append(input, create('strong', '', label), create('span', '', answerPreview(item, value)));
        row.append(option);
      });
      list.append(row);
    });
    panel.append(list);
    const actions = create('div', 'oj-ai-student-conflict__actions');
    const mine = create('button', 'oj-ai-student-secondary', '내 내용 모두');
    mine.type = 'button';
    mine.dataset.conflictChoice = 'mine';
    const saved = create('button', 'oj-ai-student-secondary', '저장된 내용 모두');
    saved.type = 'button';
    saved.dataset.conflictChoice = 'saved';
    const apply = create('button', 'oj-ai-student-primary', '선택한 내용 저장');
    apply.type = 'button';
    apply.dataset.action = 'apply-conflict';
    actions.append(mine, saved, apply);
    panel.append(actions);
  };

  const acceptLatest = (submission) => {
    state.submission = submission;
    state.serverAnswers = currentAnswers(submission);
    state.workingAnswers = clone(state.serverAnswers);
    state.serverReady = true;
    state.editable = Array.isArray(submission.actions) && submission.actions.includes('submission.autosave');
    state.recoveryKey = buildRecoveryKey(state.assignment, submission);
    writeAnswers(state.workingAnswers);
    setResponseEnabled(state.editable);
    updateSubmitUi();
    dispatchSubmissionStatus(submission);
  };

  const openConflictWith = (local, submission) => {
    acceptLatest(submission);
    if (fingerprint(local) === fingerprint(state.serverAnswers)) {
      state.workingAnswers = clone(state.serverAnswers);
      removeRecovery();
      setSaveState('saved', `저장됨${formatSavedTime(submission.current_revision?.created_at_utc) ? ` · ${formatSavedTime(submission.current_revision.created_at_utc)}` : ''}`);
      return;
    }
    if (!state.editable) {
      state.workingAnswers = clone(local);
      writeAnswers(local);
      setResponseEnabled(false);
      persistRecovery(local);
      setSaveState('failed', '작성 시간이 종료되었습니다');
      return;
    }
    state.workingAnswers = clone(local);
    writeAnswers(local);
    state.conflict = { local: clone(local), server: clone(state.serverAnswers) };
    setResponseEnabled(false);
    persistRecovery(local);
    setSaveState('conflict', '다른 저장 내용이 있습니다');
    renderConflict();
  };

  const refreshConflict = async (local) => {
    const sequence = state.mountSequence;
    setSaveState('saving', '저장된 내용 확인 중');
    try {
      const submission = await latestSubmission();
      if (sequence !== state.mountSequence) return;
      openConflictWith(local, submission);
    } catch (error) {
      if (sequence !== state.mountSequence) return;
      state.conflict = { local: clone(local), server: null };
      setResponseEnabled(false);
      persistRecovery(local);
      setSaveState(error.network ? 'offline' : 'failed', error.network ? '연결되면 확인합니다' : '저장된 내용을 확인하지 못했습니다', true);
      renderConflict();
    }
  };

  const scheduleSave = (delay = autosaveDelay) => {
    window.clearTimeout(state.saveTimer);
    state.saveTimer = window.setTimeout(() => saveNow(), delay);
  };

  async function saveNow() {
    if (state.savePromise) return state.savePromise;
    if (state.conflict || state.attachmentBusy || !state.serverReady || !state.editable) return;
    const promise = performSave().finally(() => {
      if (state.savePromise === promise) state.savePromise = null;
    });
    state.savePromise = promise;
    return promise;
  }

  async function performSave() {
    const sequence = state.mountSequence;
    const visibleAnswers = collectAnswers();
    state.workingAnswers = clone(visibleAnswers);
    if (fingerprint(visibleAnswers) === fingerprint(state.serverAnswers) && !state.pendingOperation) {
      removeRecovery();
      setSaveState('saved', '저장됨');
      return;
    }
    if (!state.pendingOperation) state.pendingOperation = buildOperation(visibleAnswers);
    persistRecovery(visibleAnswers);
    if (window.navigator.onLine === false) {
      setSaveState('offline', '연결되면 저장됩니다', true);
      return;
    }
    const operation = state.pendingOperation;
    const sentAnswers = clone(operation.body.payload.answers);
    state.saving = true;
    setSaveState('saving', '저장 중');
    try {
      const hadRevision = Boolean(currentRevisionId(state.submission));
      const body = await request(`/me/submissions/${encodeURIComponent(state.submission.id)}/commands/autosave`, {
        method: 'POST',
        headers: { 'Idempotency-Key': operation.command_id },
        body: JSON.stringify(operation.body),
      });
      if (sequence !== state.mountSequence) return;
      state.submission = savedSubmission(body, sentAnswers);
      if (!hadRevision) {
        // This autosave just created the submission's first revision, which is
        // exactly the moment submit-eligibility can newly turn on server-side.
        // The autosave response's own actions are a frozen ['submission.autosave']
        // (see savedSubmission), so re-fetch the real action list instead of
        // guessing it client-side.
        try {
          const fresh = await latestSubmission();
          if (sequence !== state.mountSequence) return;
          if (Array.isArray(fresh.actions)) state.submission = { ...state.submission, actions: fresh.actions };
        } catch (_) { /* best effort; a later status refresh will pick this up */ }
      }
      if (sequence !== state.mountSequence) return;
      dispatchSubmissionStatus(state.submission);
      state.serverAnswers = clone(sentAnswers);
      renderAttachments();
      state.pendingOperation = null;
      state.serverReady = true;
      updateSubmitUi();
      const latestVisible = collectAnswers();
      state.workingAnswers = clone(latestVisible);
      if (fingerprint(latestVisible) !== fingerprint(state.serverAnswers)) {
        persistRecovery(latestVisible);
        setSaveState('local_only', '기기에 보관됨');
        scheduleSave(50);
      } else {
        removeRecovery();
        setSaveState('saved', `저장됨${formatSavedTime(body?.data?.revision?.created_at_utc) ? ` · ${formatSavedTime(body.data.revision.created_at_utc)}` : ''}`);
      }
    } catch (error) {
      if (sequence !== state.mountSequence) return;
      const local = collectAnswers();
      state.workingAnswers = clone(local);
      if (error.status === 409 && !['attachment_not_ready', 'submission_already_official'].includes(error.code)) {
        state.pendingOperation = null;
        await refreshConflict(local);
      } else if (error.status === 422 || error.code === 'attachment_not_ready' || error.code === 'submission_already_official') {
        state.pendingOperation = null;
        persistRecovery(local);
        setSaveState('failed', error.message, true);
      } else {
        persistRecovery(local);
        setSaveState(error.network ? 'offline' : 'failed', error.network ? '연결되면 저장됩니다' : '저장하지 못했습니다', true);
      }
    } finally {
      if (sequence === state.mountSequence) state.saving = false;
    }
  }

  const confirmSubmission = () => {
    const trigger = root.querySelector('[data-action="submit-assignment"]');
    const dialog = create('dialog', 'oj-ai-student-submit-dialog');
    dialog.setAttribute('aria-labelledby', 'oj-ai-submit-title');
    dialog.setAttribute('aria-describedby', 'oj-ai-submit-description');
    const form = create('form');
    form.method = 'dialog';
    const title = create('h2', '', '제출 확인');
    title.id = 'oj-ai-submit-title';
    const description = create('p', '', '제출하면 답안을 수정할 수 없습니다. 현재 답안을 제출하시겠습니까?');
    description.id = 'oj-ai-submit-description';
    const footer = create('footer');
    const cancel = create('button', '', '계속 작성');
    cancel.type = 'submit';
    cancel.value = 'cancel';
    cancel.autofocus = true;
    const confirm = create('button', 'oj-ai-student-primary', '제출하기');
    confirm.type = 'submit';
    confirm.value = 'submit';
    footer.append(cancel, confirm);
    form.append(title, description, footer);
    dialog.append(form);
    root.append(dialog);
    submissionDialog = dialog;
    return new Promise((resolve) => {
      dialog.addEventListener('close', () => {
        const accepted = dialog.returnValue === 'submit';
        dialog.remove();
        if (submissionDialog === dialog) submissionDialog = null;
        if (trigger?.isConnected) trigger.focus();
        resolve(accepted);
      }, { once: true });
      try {
        dialog.showModal();
      } catch (_error) {
        dialog.remove();
        submissionDialog = null;
        setSaveState('failed', '제출 확인 창을 열지 못했습니다. 화면을 새로고침해 주세요.');
        resolve(false);
      }
    });
  };

  async function submitAssignment() {
    if (state.submitting || state.confirmingSubmit || !state.submission?.id) return;
    const sequence = state.mountSequence;
    state.confirmingSubmit = true;
    let accepted = false;
    try {
      for (const board of drawingBoards.values()) { if (board.dirty) await saveDrawing(board); }
      if (sequence !== state.mountSequence) return;
      if ([...drawingBoards.values()].some((board) => board.dirty || board.loading || board.loadFailed)) { setSaveState('failed', '그림 저장을 먼저 완료해 주세요', true); return; }
      if (state.attachmentBusy || state.pendingUpload) { setSaveState('failed', '파일 업로드와 저장을 먼저 완료해 주세요'); return; }
      if (!state.submission?.actions?.includes('submission.submit')) return;
      accepted = await confirmSubmission();
    } finally {
      if (sequence === state.mountSequence) state.confirmingSubmit = false;
    }
    if (!accepted || sequence !== state.mountSequence || state.submitting || !state.submission?.id) return;
    if (state.attachmentBusy || state.pendingUpload) { setSaveState('failed', '파일 업로드와 저장을 먼저 완료해 주세요'); return; }
    if (!Array.isArray(state.submission.actions) || !state.submission.actions.includes('submission.submit')) return;
    state.submitting = true;
    setResponseEnabled(false);
    updateSubmitUi();
    try {
      for (let attempt = 0; attempt < 2; attempt += 1) {
        window.clearTimeout(state.saveTimer);
        state.saveTimer = null;
        await saveNow();
        if (sequence !== state.mountSequence) return;
        if (state.conflict || !state.serverReady || !state.editable) break;
        const answers = collectAnswers();
        if (!state.pendingOperation && fingerprint(answers) === fingerprint(state.serverAnswers)) break;
      }
      const visibleAnswers = collectAnswers();
      const hasUnsavedAnswers = state.pendingOperation
        || fingerprint(visibleAnswers) !== fingerprint(state.serverAnswers);
      if (state.conflict) {
        setSaveState('conflict', '저장 충돌을 먼저 해결해 주세요', true);
        return;
      }
      if (!state.serverReady || !state.editable || hasUnsavedAnswers) {
        const offline = window.navigator.onLine === false;
        setSaveState(
          offline ? 'offline' : 'failed',
          offline ? '인터넷에 연결한 뒤 제출해 주세요' : '답안을 저장한 뒤 다시 제출해 주세요',
          true,
        );
        return;
      }
      if (!Array.isArray(state.submission.actions) || !state.submission.actions.includes('submission.submit')) {
        setSaveState('failed', '현재 제출할 수 없습니다. 활동지를 새로고침해 주세요', true);
        return;
      }
      const token = commandToken();
      const body = await request(`/me/submissions/${encodeURIComponent(state.submission.id)}/commands/submit`, {
        method: 'POST',
        headers: { 'Idempotency-Key': token },
        body: JSON.stringify({ command_id: token, expected: expectedSnapshot(), payload: {} }),
      });
      if (sequence !== state.mountSequence) return;
      const submission = body?.data?.submission;
      if (!isObject(submission)) throw new ApiError(503, { message: '제출 결과를 확인하지 못했습니다.' });
      // C26 returns a receipt, not the full Q15 answer snapshot.
      const previous = state.submission;
      if (submission.id !== previous.id || currentRevisionId(submission) !== currentRevisionId(previous)) {
        throw new ApiError(503, { message: '제출 결과의 답안 버전을 다시 확인해 주세요.' });
      }
      acceptLatest({ ...previous, ...submission, current_revision: previous.current_revision, resubmission: null });
      try {
        const latest = await latestSubmission();
        if (sequence !== state.mountSequence) return;
        acceptLatest(latest);
      } catch (_refreshError) {
        if (sequence !== state.mountSequence) return;
        if ([401, 403].includes(_refreshError.status)) {
          removeRecovery();
          state.submission = null;
          state.serverAnswers = {};
          state.workingAnswers = {};
          state.editable = false;
          state.serverReady = false;
          root.replaceChildren(create('p', 'oj-ai-student-error', '제출은 완료되었지만 현재 답안을 볼 권한을 확인할 수 없습니다. 다시 로그인해 주세요.'));
          return;
        }
        // The committed receipt still proves submission; never offer a duplicate submit.
      }
      setSaveState('saved', '제출 완료');
    } catch (error) {
      if (sequence !== state.mountSequence) return;
      if (error.status === 409) {
        await refreshConflict(state.workingAnswers);
      } else {
        setSaveState(error.network ? 'offline' : 'failed', error.message || '제출하지 못했습니다', true);
      }
    } finally {
      if (sequence === state.mountSequence) {
        state.submitting = false;
        setResponseEnabled(state.editable);
        updateSubmitUi();
      }
    }
  }

  const onResponseChanged = (event) => {
    if (state.suppressInput || state.conflict || !state.editable) return;
    const input = event.target instanceof Element ? event.target.closest('.oj-ai-student-response-input') : null;
    if (!input || !root.contains(input)) return;
    if (input.type === 'file') { if (event.type === 'change') uploadFiles(input); return; }
    state.workingAnswers = collectAnswers();
    if (fingerprint(state.workingAnswers) === fingerprint(state.serverAnswers) && !state.pendingOperation) {
      window.clearTimeout(state.saveTimer);
      removeRecovery();
      setSaveState('saved', '저장됨');
      return;
    }
    persistRecovery();
    if (window.navigator.onLine === false) setSaveState('offline', '연결되면 저장됩니다', true);
    else {
      setSaveState('local_only', '기기에 보관됨');
      scheduleSave();
    }
  };

  const restoreRecovery = async () => {
    const recovery = readRecovery();
    if (!recovery) return;
    const local = mergeRecovery(recovery, state.serverAnswers);
    root.querySelector('[data-role="local-recovery"]').hidden = true;
    if (!state.serverReady) {
      state.workingAnswers = clone(local);
      writeAnswers(local);
      setResponseEnabled(true);
      setSaveState('offline', '연결되면 저장됩니다', true);
      return;
    }
    if (!snapshotMatches(recovery.expected, expectedSnapshot())) {
      openConflictWith(local, state.submission);
      return;
    }
    state.workingAnswers = clone(local);
    const pending = recovery.pending_operation;
    if (
      isObject(pending) && typeof pending.command_id === 'string' && isObject(pending.body)
      && pending.body.command_id === pending.command_id && isObject(pending.body.expected) && isObject(pending.body.payload)
      && snapshotMatches(pending.body.expected, expectedSnapshot())
      && fingerprint(pending.body.payload.answers) === fingerprint(local)
    ) state.pendingOperation = clone(pending);
    writeAnswers(local);
    setResponseEnabled(state.editable);
    persistRecovery(local);
    setSaveState('local_only', '기기에 보관됨');
    scheduleSave(50);
  };

  const applyConflict = () => {
    if (!state.conflict?.server) return;
    const merged = clone(state.conflict.server);
    conflictDifferences(state.conflict.local, state.conflict.server).forEach((item) => {
      const selected = root.querySelector(`input[name="conflict-${CSS.escape(item.response_id)}"]:checked`)?.value || 'mine';
      if (selected === 'mine') {
        if (own(state.conflict.local, item.response_id)) merged[item.response_id] = clone(state.conflict.local[item.response_id]);
        else delete merged[item.response_id];
      }
    });
    state.conflict = null;
    const panel = root.querySelector('[data-role="save-conflict"]');
    if (panel) panel.hidden = true;
    state.workingAnswers = clone(merged);
    writeAnswers(merged);
    setResponseEnabled(state.editable);
    if (fingerprint(merged) === fingerprint(state.serverAnswers)) {
      removeRecovery();
      setSaveState('saved', '저장됨');
    } else {
      persistRecovery(merged);
      setSaveState('local_only', '기기에 보관됨');
      scheduleSave(50);
    }
  };

  const mount = async (assignment) => {
    const sequence = ++state.mountSequence;
    submissionDialog?.close('cancel');
    state.confirmingSubmit = false;
    window.clearTimeout(state.saveTimer);
    state.assignment = assignment;
    state.items = responseItems(assignment);
    mountDrawings();
    state.submission = isObject(assignment.submission) ? assignment.submission : null;
    state.serverAnswers = {};
    state.workingAnswers = {};
    state.pendingOperation = null;
    state.pendingUpload = null;
    state.attachmentBusy = false;
    state.saving = false;
    state.savePromise = null;
    state.submitting = false;
    state.conflict = null;
    state.serverReady = false;
    state.editable = false;
    ensureStatusUi();
    setResponseEnabled(false);
    updateSubmitUi();
    if (!state.submission?.id) {
      setSaveState('idle', '시작하면 자동 저장됩니다');
      return;
    }
    state.recoveryKey = buildRecoveryKey(assignment, state.submission);
    setSaveState('saving', '저장 내용 확인 중');
    try {
      const submission = await latestSubmission();
      if (sequence !== state.mountSequence) return;
      acceptLatest(submission);
      const savedAt = formatSavedTime(submission.current_revision?.created_at_utc);
      setSaveState('saved', submission.current_revision ? `저장됨${savedAt ? ` · ${savedAt}` : ''}` : '작성 중');
      showRecoveryIfNeeded();
    } catch (error) {
      if (sequence !== state.mountSequence) return;
      const recovery = readRecovery();
      if (recovery) {
        state.workingAnswers = mergeRecovery(recovery, {});
        writeAnswers(state.workingAnswers);
        showRecoveryIfNeeded();
        setSaveState(error.network ? 'offline' : 'failed', error.network ? '연결되면 확인합니다' : '저장 내용을 확인하지 못했습니다', true);
      } else {
        setSaveState(error.network ? 'offline' : 'failed', error.network ? '인터넷 연결을 확인하세요' : error.message, true);
      }
    }
  };

  const purgeExpiredRecovery = () => {
    try {
      for (let index = window.localStorage.length - 1; index >= 0; index -= 1) {
        const key = window.localStorage.key(index);
        if (!key?.startsWith(RECOVERY_PREFIX)) continue;
        const value = JSON.parse(window.localStorage.getItem(key) || 'null');
        if (!isObject(value) || value.expires_at_ms <= Date.now() || value.session_scope !== sessionScope || value.nonce_scope !== nonceScope) {
          window.localStorage.removeItem(key);
        }
      }
    } catch (error) {
      // Storage is optional; the server remains authoritative.
    }
  };

  root.addEventListener(DETAIL_EVENT, (event) => {
    if (isObject(event.detail?.assignment)) mount(event.detail.assignment);
  });
  root.addEventListener('input', onResponseChanged);
  root.addEventListener('change', onResponseChanged);
  root.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('button') : null;
    if (!button) return;
    if (['undo-drawing', 'clear-drawing', 'save-drawing'].includes(button.dataset.action)) {
      const board = drawingBoards.get(button.closest('[data-response-id]')?.dataset.responseId);
      if (!board || !state.editable || state.attachmentBusy || board.loading || board.loadFailed) return;
      if (button.dataset.action === 'save-drawing') saveDrawing(board);
      else {
        if (button.dataset.action === 'undo-drawing') board.strokes.pop();
        else { board.strokes = []; board.baseElements = []; board.background = null; }
        paintDrawing(board); queueDrawing(board);
      }
    } else if (button.dataset.action === 'retry-upload') {
      uploadFiles();
    } else if (['attach-ready', 'check-attachment', 'remove-answer-attachment'].includes(button.dataset.action)) {
      manageAttachment(button);
    } else if (button.dataset.action === 'retry-save') {
      if (state.conflict && !state.conflict.server) refreshConflict(state.conflict.local);
      else if (!state.serverReady && state.workingAnswers && state.submission) {
        latestSubmission().then((submission) => openConflictWith(state.workingAnswers, submission)).catch(() => setSaveState('failed', '다시 확인하지 못했습니다', true));
      } else if (state.pendingUpload) uploadFiles();
      else if ([...drawingBoards.values()].some((board) => board.dirty)) { for (const board of drawingBoards.values()) if (board.dirty) saveDrawing(board); }
      else saveNow();
    } else if (button.dataset.action === 'restore-recovery') {
      restoreRecovery();
    } else if (button.dataset.action === 'discard-recovery') {
      removeRecovery();
      setSaveState(state.serverReady ? 'saved' : 'failed', state.serverReady ? '저장됨' : '저장 내용을 확인하지 못했습니다', !state.serverReady);
    } else if (button.dataset.action === 'refresh-conflict') {
      refreshConflict(state.conflict?.local || state.workingAnswers);
    } else if (button.dataset.conflictChoice) {
      root.querySelectorAll('.oj-ai-student-conflict__item').forEach((item) => {
        const input = item.querySelector(`input[value="${button.dataset.conflictChoice}"]`);
        if (input) input.checked = true;
      });
    } else if (button.dataset.action === 'apply-conflict') {
      applyConflict();
    } else if (button.dataset.action === 'submit-assignment') {
      submitAssignment();
    }
  });

  window.addEventListener('online', () => {
    if (state.conflict && !state.conflict.server) refreshConflict(state.conflict.local);
    else if (!state.serverReady && state.submission) {
      const local = clone(state.workingAnswers);
      latestSubmission().then((submission) => openConflictWith(local, submission)).catch(() => setSaveState('failed', '저장 내용을 확인하지 못했습니다', true));
    } else if (state.pendingOperation || fingerprint(state.workingAnswers) !== fingerprint(state.serverAnswers)) saveNow();
  });
  window.addEventListener('offline', () => {
    if (state.pendingOperation || fingerprint(state.workingAnswers) !== fingerprint(state.serverAnswers)) {
      persistRecovery();
      setSaveState('offline', '연결되면 저장됩니다', true);
    }
  });
  window.addEventListener('storage', (event) => {
    if (event.key === state.recoveryKey) showRecoveryIfNeeded();
  });
  window.addEventListener('beforeunload', (event) => {
    if (state.attachmentBusy || state.pendingUpload || state.pendingOperation || state.conflict || [...drawingBoards.values()].some((board) => board.dirty) || fingerprint(state.workingAnswers) !== fingerprint(state.serverAnswers)) {
      persistRecovery();
      event.preventDefault();
      event.returnValue = '';
    }
  });
  document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[href*="action=logout"]') : null;
    if (!link) return;
    try {
      for (let index = window.localStorage.length - 1; index >= 0; index -= 1) {
        const key = window.localStorage.key(index);
        if (key?.startsWith(RECOVERY_PREFIX)) window.localStorage.removeItem(key);
      }
    } catch (error) { /* Logout must continue even when storage is unavailable. */ }
  });

  purgeExpiredRecovery();
  let detailEventSeen = false;
  root.addEventListener(DETAIL_EVENT, () => { detailEventSeen = true; }, { once: true });
  window.queueMicrotask(() => {
    if (detailEventSeen || !root.dataset.assignment) return;
    try {
      const assignment = JSON.parse(root.dataset.assignment);
      if (isObject(assignment)) mount(assignment);
    } catch (error) { /* The primary renderer owns invalid detail handling. */ }
  });
})(window, document);

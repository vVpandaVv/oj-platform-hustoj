(function (window, document) {
  'use strict';

  const root = document.querySelector('#oj-ai-operations-diagnostics');
  if (!root) return;

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
  const TIME_ZONE = resolveTimeZone(root.dataset.timezone);
  const LOCALE = String(root.dataset.locale || 'ko-KR').replace(/_/g, '-');

  const elements = {
    checkedAt: root.querySelector('[data-diagnostics-role="checked-at"]'),
    list: root.querySelector('[data-diagnostics-role="list"]'),
    notice: root.querySelector('[data-diagnostics-role="notice"]'),
    refresh: root.querySelector('[data-diagnostics-action="refresh"]'),
    summary: root.querySelector('[data-diagnostics-role="summary"]'),
  };
  if (Object.values(elements).some((element) => !element)) return;
  const repairElements = {
    panel: root.querySelector('[data-diagnostics-role="repair"]'),
    status: root.querySelector('[data-diagnostics-role="repair-status"]'),
    reference: root.querySelector('[data-diagnostics-field="backup-reference"]'),
    verifiedAt: root.querySelector('[data-diagnostics-field="backup-verified-at"]'),
    forwardOnly: root.querySelector('[data-diagnostics-field="forward-only"]'),
    submit: root.querySelector('[data-diagnostics-action="repair"]'),
  };
  const hasRepairUi = Object.values(repairElements).every(Boolean);

  const profile = 'oj_ai.diagnostics@1.0.0';
  const checkOrder = ['bridge', 'schema', 'queue', 'storage', 'ai_routing'];
  const statusOrder = ['ready', 'attention', 'unavailable'];
  const aiTaskOrder = ['worksheet_quality', 'student_submission'];
  const aiTaskStatuses = ['ready', 'unroutable', 'unroutable_transient', 'routing_stale', 'runtime_unavailable'];
  const aiTaskLabels = {
    worksheet_quality: '활동지 자체 평가',
    student_submission: '학생 제출 평가',
  };
  const aiTaskStatusLabels = {
    ready: '연결됨',
    unroutable: '설정 필요',
    unroutable_transient: '잠시 확인 불가',
    routing_stale: '다시 확인 필요',
    runtime_unavailable: '실행 연결 확인 필요',
  };
  const actionNames = new Set([
    'none',
    'open_platform_operations',
    'check_data_structure',
    'check_jobs',
    'resume_jobs',
    'resume_storage_jobs',
    'check_file_scanner',
    'check_storage',
    'check_private_storage',
    'retry_storage_check',
    'open_platform_ai_assignments',
    'refresh_ai_routing',
    'retry_ai_routing',
  ]);
  const retryActions = new Set(['retry_storage_check', 'check_storage', 'refresh_ai_routing', 'retry_ai_routing']);
  const labels = {
    bridge: '애드온 실행',
    schema: '데이터 구조',
    queue: '작업 처리',
    storage: '파일 보관',
    ai_routing: 'AI 작업 배정',
  };
  const statusLabels = {
    ready: '사용 준비됨',
    attention: '확인 필요',
    unavailable: '사용할 수 없음',
  };
  const messages = {
    bridge: {
      ready: '실행 준비가 되었습니다.',
      attention: '실행 상태를 확인해 주세요.',
      unavailable: '현재 실행할 수 없습니다.',
    },
    schema: {
      ready: '최신 상태입니다.',
      attention: '데이터 구조를 확인해 주세요.',
      unavailable: '데이터 구조를 확인할 수 없습니다.',
    },
    queue: {
      ready: '작업 처리가 정상입니다.',
      attention: '대기 중인 작업을 확인해 주세요.',
      unavailable: '작업을 처리할 수 없습니다.',
    },
    storage: {
      ready: '안전하게 보관되고 있습니다.',
      attention: '파일 보관 상태를 확인해 주세요.',
      unavailable: '파일을 보관할 수 없습니다.',
    },
    ai_routing: {
      ready: '두 평가 작업이 연결되었습니다.',
      attention: 'AI 작업 배정을 확인해 주세요.',
      unavailable: '두 평가 작업의 연결 상태를 확인해 주세요.',
    },
  };

  let activeRequest = 0;
  let controller = null;
  let access = 'loading';
  let repairState = null;
  let repairBusy = false;
  let resumeStorageBusy = false;

  function create(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (typeof text === 'string') element.textContent = text;
    return element;
  }

  function exactKeys(value, expected) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
    const actual = Object.keys(value).sort();
    return actual.length === expected.length && expected.slice().sort().every((key, index) => actual[index] === key);
  }

  function normalizeRepair(value) {
    if (!exactKeys(value, ['available', 'code', 'expected'])) {
      throw new Error('diagnostics_response_invalid');
    }
    if (typeof value.available !== 'boolean' || typeof value.code !== 'string' || !/^[a-z][a-z0-9_]{2,99}$/.test(value.code)) {
      throw new Error('diagnostics_response_invalid');
    }
    if (!value.available) {
      if (value.expected !== null) throw new Error('diagnostics_response_invalid');
      return { available: false, code: value.code, expected: null };
    }
    if (!exactKeys(value.expected, ['installation_state', 'catalog_fingerprint', 'repair_state_hash'])) {
      throw new Error('diagnostics_response_invalid');
    }
    if (!['installing', 'maintenance_required'].includes(value.expected.installation_state)) {
      throw new Error('diagnostics_response_invalid');
    }
    if (!/^[a-f0-9]{64}$/.test(value.expected.catalog_fingerprint) || !/^[a-f0-9]{64}$/.test(value.expected.repair_state_hash)) {
      throw new Error('diagnostics_response_invalid');
    }
    return {
      available: true,
      code: value.code,
      expected: {
        installation_state: value.expected.installation_state,
        catalog_fingerprint: value.expected.catalog_fingerprint,
        repair_state_hash: value.expected.repair_state_hash,
      },
    };
  }

  const storageWorkerKeys = ['attachment_scan', 'attachment_cleanup', 'zip_scan', 'zip_cleanup', 'export_cleanup'];

  function normalizeStorageJobs(value) {
    if (!exactKeys(value, ['storage_jobs'])) throw new Error('storage_jobs_response_invalid');
    const storageJobs = value.storage_jobs;
    if (!exactKeys(storageJobs, ['workers']) || !exactKeys(storageJobs.workers, storageWorkerKeys)) {
      throw new Error('storage_jobs_response_invalid');
    }
    const workers = {};
    storageWorkerKeys.forEach((key) => {
      const worker = storageJobs.workers[key];
      if (
        !exactKeys(worker, ['iterations', 'succeeded'])
        || typeof worker.iterations !== 'number'
        || !Number.isInteger(worker.iterations)
        || worker.iterations < 0
        || typeof worker.succeeded !== 'boolean'
      ) {
        throw new Error('storage_jobs_response_invalid');
      }
      workers[key] = { iterations: worker.iterations, succeeded: worker.succeeded };
    });
    return workers;
  }

  function embeddedRepair() {
    if (root.dataset.recoveryMode !== 'true') return null;
    const available = root.dataset.repairAvailable === 'true';
    return normalizeRepair({
      available,
      code: root.dataset.repairCode || 'schema_repair_state_unavailable',
      expected: available ? {
        installation_state: root.dataset.repairInstallationState || '',
        catalog_fingerprint: root.dataset.repairCatalogFingerprint || '',
        repair_state_hash: root.dataset.repairStateHash || '',
      } : null,
    });
  }

  function aggregateStatus(checks) {
    const statuses = checkOrder.map((key) => checks[key].status);
    if (statuses.includes('unavailable')) return 'unavailable';
    if (statuses.includes('attention')) return 'attention';
    return 'ready';
  }

  function normalizeAiTasks(value) {
    if (!exactKeys(value, aiTaskOrder)) throw new Error('diagnostics_response_invalid');
    const tasks = {};
    aiTaskOrder.forEach((key) => {
      const task = value[key];
      if (!task || typeof task !== 'object' || !aiTaskStatuses.includes(task.status)) {
        throw new Error('diagnostics_response_invalid');
      }
      tasks[key] = { status: task.status };
    });
    return tasks;
  }

  function normalize(value) {
    if (!value || typeof value !== 'object' || value.profile !== profile) {
      throw new Error('diagnostics_response_invalid');
    }
    if (!statusOrder.includes(value.status) || typeof value.checked_at_utc !== 'string') {
      throw new Error('diagnostics_response_invalid');
    }
    if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value.checked_at_utc)) {
      throw new Error('diagnostics_response_invalid');
    }
    if (!value.checks || typeof value.checks !== 'object' || Array.isArray(value.checks)) {
      throw new Error('diagnostics_response_invalid');
    }
    const keys = Object.keys(value.checks);
    if (keys.length !== checkOrder.length || checkOrder.some((key, index) => keys[index] !== key)) {
      throw new Error('diagnostics_response_invalid');
    }

    const checks = {};
    checkOrder.forEach((key) => {
      const item = value.checks[key];
      if (!item || typeof item !== 'object' || !statusOrder.includes(item.status)) {
        throw new Error('diagnostics_response_invalid');
      }
      if (typeof item.next_action !== 'string' || !actionNames.has(item.next_action)) {
        throw new Error('diagnostics_response_invalid');
      }
      checks[key] = { status: item.status, action: item.next_action };
      if (key === 'ai_routing') checks[key].tasks = normalizeAiTasks(item.tasks);
    });
    if (aggregateStatus(checks) !== value.status) {
      throw new Error('diagnostics_response_invalid');
    }

    const repair = normalizeRepair(value.repair);
    return { status: value.status, checkedAt: value.checked_at_utc, checks, repair };
  }

  function checkedAtLabel(value) {
    const parsed = new Date(value.replace(' ', 'T') + 'Z');
    if (Number.isNaN(parsed.getTime())) return '방금 확인';
    try {
      const formatted = new Intl.DateTimeFormat(LOCALE, {
        month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: TIME_ZONE,
      }).format(parsed);
      return `마지막 확인 ${formatted}`;
    } catch (_error) {
      return '방금 확인';
    }
  }

  function setBusy(value) {
    root.dataset.busy = value ? '1' : '0';
    elements.list.setAttribute('aria-busy', value ? 'true' : 'false');
    elements.refresh.disabled = value || access === 'denied';
    updateRepairButton();
  }

  function setNotice(message, tone, role) {
    elements.notice.textContent = message;
    elements.notice.dataset.tone = tone;
    elements.notice.setAttribute('role', role || 'status');
    elements.notice.hidden = !message;
  }

  function clearRows() {
    elements.list.replaceChildren();
  }

  function actionControl(action) {
    if (action === 'none') return null;
    if (action === 'check_data_structure' && repairState?.available) {
      const button = create('button', 'oj-ai-diagnostics__action', '복구');
      button.type = 'button';
      button.dataset.diagnosticsAction = 'open-repair';
      return button;
    }
    if (action === 'resume_storage_jobs') {
      const button = create('button', 'oj-ai-diagnostics__action', '지금 정리 실행');
      button.type = 'button';
      button.dataset.diagnosticsAction = 'resume-storage-jobs';
      button.disabled = resumeStorageBusy;
      return button;
    }
    if (action === 'open_platform_operations' || action === 'open_platform_ai_assignments') {
      const link = create(
        'a',
        'oj-ai-diagnostics__action',
        action === 'open_platform_operations' ? '플랫폼 상태 열기' : 'AI 작업 배정 열기'
      );
      link.href = action === 'open_platform_operations'
        ? root.dataset.platformOperationsUrl
        : root.dataset.platformAiUrl;
      return link;
    }
    if (retryActions.has(action)) {
      const button = create('button', 'oj-ai-diagnostics__action', '다시 확인');
      button.type = 'button';
      button.dataset.diagnosticsAction = 'refresh';
      return button;
    }
    return create('span', 'oj-ai-diagnostics__guidance', '관리자 확인 필요');
  }

  function storageDetailLink() {
    const link = create('a', 'oj-ai-diagnostics__action', '파일 보관 상세 보기');
    link.href = root.dataset.storageDetailUrl;
    return link;
  }

  function aiTaskList(tasks) {
    const list = create('ul', 'oj-ai-diagnostics__tasks');
    list.setAttribute('aria-label', 'AI 평가 작업 상태');
    aiTaskOrder.forEach((key) => {
      const entry = create('li', 'oj-ai-diagnostics__task');
      entry.dataset.taskStatus = tasks[key].status;
      entry.append(
        create('span', 'oj-ai-diagnostics__task-name', aiTaskLabels[key]),
        create('span', 'oj-ai-diagnostics__task-status', aiTaskStatusLabels[tasks[key].status])
      );
      list.append(entry);
    });
    return list;
  }

  function row(key, item) {
    const entry = create('div', 'oj-ai-diagnostics__row');
    entry.dataset.checkKey = key;
    entry.dataset.status = item.status;
    entry.setAttribute('role', 'listitem');

    const icon = create('span', 'oj-ai-diagnostics__icon');
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = item.status === 'ready' ? '\u2713' : item.status === 'attention' ? '!' : '\u00d7';

    const copy = create('div', 'oj-ai-diagnostics__copy');
    const message = key === 'storage' && item.action === 'check_file_scanner'
      ? '파일 검사 서비스가 응답하지 않습니다. 관리자에게 연결 상태 확인을 요청해 주세요.'
      : messages[key][item.status];
    copy.append(create('strong', '', labels[key]), create('p', '', message));
    if (key === 'ai_routing') copy.append(aiTaskList(item.tasks));

    const result = create('div', 'oj-ai-diagnostics__result');
    const badge = create('span', 'oj-ai-diagnostics__badge', statusLabels[item.status]);
    badge.dataset.status = item.status;
    result.append(badge);
    const control = actionControl(item.action);
    if (control) result.append(control);
    if (key === 'storage') result.append(storageDetailLink());

    entry.append(icon, copy, result);
    return entry;
  }

  function repairFormValid() {
    return hasRepairUi && repairState?.available === true &&
      repairElements.reference.value.trim().length >= 3 &&
      repairElements.verifiedAt.value !== '' && repairElements.forwardOnly.checked;
  }

  function updateRepairButton() {
    if (!hasRepairUi) return;
    repairElements.submit.disabled = repairBusy || root.dataset.busy === '1' || !repairFormValid();
  }

  function renderRepair(value) {
    repairState = value;
    if (!hasRepairUi) return;
    repairElements.panel.hidden = !value?.available;
    repairElements.status.textContent = '';
    repairElements.status.dataset.tone = 'neutral';
    updateRepairButton();
  }

  function clearRepair() {
    repairState = null;
    if (!hasRepairUi) return;
    repairElements.panel.hidden = true;
    repairElements.status.textContent = '';
    repairElements.status.dataset.tone = 'neutral';
    repairElements.reference.value = '';
    repairElements.verifiedAt.value = '';
    repairElements.forwardOnly.checked = false;
    updateRepairButton();
  }

  function renderRecovery() {
    clearRows();
    const available = repairState?.available === true;
    elements.list.append(row('schema', {
      status: available ? 'attention' : 'unavailable',
      action: available ? 'check_data_structure' : 'none',
    }));
    elements.list.hidden = false;
    elements.summary.textContent = available ? '복구 필요' : '확인 필요';
    elements.summary.dataset.status = available ? 'attention' : 'unavailable';
    elements.checkedAt.textContent = '';
    access = available ? 'granted' : 'failed';
    root.dataset.access = access;
    setNotice(
      available ? '최근 백업을 확인한 뒤 복구해 주세요.' : '복구 상태를 확인할 수 없습니다.',
      available ? 'warning' : 'danger',
      available ? 'status' : 'alert'
    );
    renderRepair(repairState);
  }

  function render(value) {
    renderRepair(value.repair);
    clearRows();
    checkOrder.forEach((key) => elements.list.append(row(key, value.checks[key])));
    elements.list.hidden = false;
    elements.summary.textContent = statusLabels[value.status];
    elements.summary.dataset.status = value.status;
    elements.checkedAt.textContent = checkedAtLabel(value.checkedAt);
    setNotice('', 'neutral');
    access = 'granted';
    root.dataset.access = access;
  }

  function fail(message, denied) {
    clearRows();
    elements.list.hidden = true;
    elements.summary.textContent = denied ? '볼 수 없음' : '확인 필요';
    elements.summary.dataset.status = denied ? 'unavailable' : 'attention';
    elements.checkedAt.textContent = '';
    access = denied ? 'denied' : 'failed';
    root.dataset.access = access;
    clearRepair();
    setNotice(message, denied ? 'danger' : 'warning', denied ? 'alert' : 'status');
  }

  async function load() {
    if (root.dataset.recoveryMode === 'true') {
      repairState = embeddedRepair();
      renderRecovery();
      setBusy(false);
      return;
    }
    const requestId = ++activeRequest;
    if (controller) controller.abort();
    controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
    access = 'loading';
    root.dataset.access = access;
    clearRepair();
    clearRows();
    elements.list.hidden = true;
    setNotice('상태를 확인하는 중입니다.', 'neutral');
    setBusy(true);

    try {
      const headers = { Accept: 'application/json' };
      if (root.dataset.restNonce) headers['X-WP-Nonce'] = root.dataset.restNonce;
      const response = await window.fetch(`${root.dataset.restRoot}/diagnostics`, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
        signal: controller ? controller.signal : undefined,
      });
      const body = await response.json().catch(() => null);
      if (requestId !== activeRequest) return;
      if (!response.ok) {
        const error = new Error('diagnostics_request_failed');
        error.status = Number(response.status || body?.data?.status || 500);
        throw error;
      }
      render(normalize(body?.data));
    } catch (error) {
      if (requestId !== activeRequest || error?.name === 'AbortError') return;
      const denied = Number(error?.status || 0) === 401 || Number(error?.status || 0) === 403;
      fail(denied ? '시스템 상태를 볼 수 없습니다.' : '상태를 확인할 수 없습니다. 다시 시도해 주세요.', denied);
    } finally {
      if (requestId === activeRequest) setBusy(false);
    }
  }

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return `schema-repair-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }

  async function digest(value) {
    if (!window.crypto?.subtle || typeof window.TextEncoder !== 'function') {
      throw new Error('schema_repair_digest_unavailable');
    }
    const bytes = new window.TextEncoder().encode(value);
    const output = await window.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(output), (byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  function verifiedAtUtc() {
    const date = new Date(repairElements.verifiedAt.value);
    if (Number.isNaN(date.getTime())) throw new Error('schema_repair_time_invalid');
    return date.toISOString().slice(0, 19).replace('T', ' ');
  }

  async function repair() {
    if (!repairFormValid() || repairBusy) return;
    repairBusy = true;
    updateRepairButton();
    repairElements.status.textContent = '복구하는 중입니다.';
    repairElements.status.dataset.tone = 'neutral';
    try {
      const commandId = uuid();
      const evidenceDigest = await digest(repairElements.reference.value.trim());
      const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'Idempotency-Key': `schema-repair-${commandId}`,
      };
      if (root.dataset.restNonce) headers['X-WP-Nonce'] = root.dataset.restNonce;
      const response = await window.fetch(`${root.dataset.restRoot}/operations/schema/commands/repair`, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
        body: JSON.stringify({
          command_id: commandId,
          expected: repairState.expected,
          backup: { evidence_digest: evidenceDigest, verified_at_utc: verifiedAtUtc() },
          payload: { forward_only: true },
        }),
      });
      const body = await response.json().catch(() => null);
      if (!response.ok) {
        const error = new Error('schema_repair_request_failed');
        error.status = Number(response.status || body?.data?.status || 500);
        throw error;
      }
      repairElements.status.textContent = '복구를 완료했습니다. 상태를 다시 확인합니다.';
      repairElements.status.dataset.tone = 'success';
      repairElements.reference.value = '';
      repairElements.forwardOnly.checked = false;
      window.setTimeout(() => window.location.reload(), 700);
    } catch (error) {
      const status = Number(error?.status || 0);
      repairElements.status.textContent = status === 409
        ? '상태가 변경되었습니다. 새로고침해 주세요.'
        : status === 422
          ? '백업 확인 정보를 확인해 주세요.'
          : '지금은 복구할 수 없습니다. 다시 시도해 주세요.';
      repairElements.status.dataset.tone = 'danger';
    } finally {
      repairBusy = false;
      updateRepairButton();
    }
  }

  async function resumeStorageJobs() {
    if (resumeStorageBusy) return;
    resumeStorageBusy = true;
    const button = root.querySelector('[data-diagnostics-action="resume-storage-jobs"]');
    if (button) button.disabled = true;
    setNotice('지금 정리를 실행하는 중입니다.', 'neutral');
    let outcome = null;
    try {
      const commandId = uuid();
      const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'Idempotency-Key': `storage-resume-${commandId}`,
      };
      if (root.dataset.restNonce) headers['X-WP-Nonce'] = root.dataset.restNonce;
      const response = await window.fetch(`${root.dataset.restRoot}/operations/storage/commands/resume`, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
        body: JSON.stringify({ command_id: commandId }),
      });
      const body = await response.json().catch(() => null);
      if (!response.ok) {
        const error = new Error('storage_jobs_request_failed');
        error.status = Number(response.status || body?.data?.status || 500);
        throw error;
      }
      const workers = normalizeStorageJobs(body?.data);
      outcome = {
        succeeded: body?.meta?.all_succeeded === true && storageWorkerKeys.every((key) => workers[key].succeeded),
        scanned: workers.attachment_scan.iterations + workers.zip_scan.iterations,
        purged: workers.attachment_cleanup.iterations + workers.zip_cleanup.iterations + workers.export_cleanup.iterations,
      };
    } catch (error) {
      resumeStorageBusy = false;
      const status = Number(error?.status || 0);
      setNotice(
        status === 401 || status === 403
          ? '지금 정리를 실행할 수 없습니다.'
          : '지금 정리를 실행하지 못했습니다. 다시 시도해 주세요.',
        'danger',
        'alert'
      );
      if (button) button.disabled = false;
      return;
    }
    resumeStorageBusy = false;
    await load();
    const summary = `검사 처리 ${outcome.scanned}건 · 파일 정리 ${outcome.purged}건.`;
    setNotice(!outcome.succeeded
      ? `${summary} 일부 작업이 완료되지 않았습니다. 파일 보관 상세를 확인해 주세요.`
      : outcome.scanned + outcome.purged === 0
        ? '이번 실행에서 처리할 학생 첨부·ZIP 파일이 없습니다. 남은 만료 파일은 파일 보관 상세에서 확인해 주세요.'
        : `${summary} 남은 만료 파일은 파일 보관 상세에서 확인해 주세요.`,
    !outcome.succeeded ? 'danger' : outcome.scanned + outcome.purged === 0 ? 'warning' : 'success');
  }

  root.addEventListener('click', (event) => {
    const refresh = event.target.closest('[data-diagnostics-action="refresh"]');
    if (refresh && !refresh.disabled) load();
    const openRepair = event.target.closest('[data-diagnostics-action="open-repair"]');
    if (openRepair && hasRepairUi) repairElements.reference.focus();
    const submit = event.target.closest('[data-diagnostics-action="repair"]');
    if (submit && !submit.disabled) repair();
    const resumeStorage = event.target.closest('[data-diagnostics-action="resume-storage-jobs"]');
    if (resumeStorage && !resumeStorage.disabled) resumeStorageJobs();
  });
  if (hasRepairUi) {
    repairElements.panel.addEventListener('input', updateRepairButton);
    repairElements.panel.addEventListener('change', updateRepairButton);
  }

  load();
})(window, document);

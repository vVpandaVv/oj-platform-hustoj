(function (window, document) {
  'use strict';

  const root = document.querySelector('#oj-ai-storage-operations-detail');
  if (!root) return;

  const elements = {
    checkedAt: root.querySelector('[data-storage-role="checked-at"]'),
    metrics: root.querySelector('[data-storage-role="metrics"]'),
    workers: root.querySelector('[data-storage-role="workers"]'),
    notice: root.querySelector('[data-storage-role="notice"]'),
    refresh: root.querySelector('[data-storage-action="refresh"]'),
    resumeButton: root.querySelector('[data-storage-action="resume"]'),
    resumeStatus: root.querySelector('[data-storage-role="resume-status"]'),
  };
  if (Object.values(elements).some((element) => !element)) return;
  const backlogElement = root.querySelector('[data-storage-role="backlog"]');

  const profile = 'oj_ai.storage_operations_detail@1.0.0';
  const metricOrder = [
    'expected_workers', 'scheduled_workers', 'pending_scans', 'delayed_scans',
    'cleanup_due', 'delayed_cleanup', 'metadata_issues',
  ];
  const metricLabels = {
    expected_workers: '예상 작업자 수',
    scheduled_workers: '예약된 작업자 수',
    pending_scans: '검사 대기',
    delayed_scans: '검사 지연',
    cleanup_due: '정리 대상',
    delayed_cleanup: '정리 지연',
    metadata_issues: '메타데이터 불일치',
  };
  const workerOrder = ['attachment_scan', 'attachment_cleanup', 'zip_scan', 'zip_cleanup', 'export_cleanup'];
  const workerLabels = {
    attachment_scan: '학생 제출 파일 검사',
    attachment_cleanup: '학생 제출 파일 정리',
    zip_scan: 'ZIP 제출 검사',
    zip_cleanup: 'ZIP 제출 정리',
    export_cleanup: '내보내기 파일 정리',
  };
  const workerStatuses = ['running', 'stale', 'never'];
  const backlogLabels = {
    attachment: '학생 첨부', zip: 'ZIP 임시 파일', export: '내보내기 파일',
    worksheet: '활동지 자료', other: '기타 파일',
  };

  function normalizeBacklog(value) {
    if (value == null) return null;
    if (!exactKeys(value, Object.keys(backlogLabels))) throw new Error('storage_backlog_response_invalid');
    Object.entries(value).forEach(([key, counts]) => {
      const fields = key === 'export' ? ['expired', 'held', 'bound', 'eligible', 'preserved', 'in_use', 'blocked'] : ['expired', 'held', 'bound'];
      if (!exactKeys(counts, fields) || !Object.values(counts).every(nonNegativeInteger)
        || counts.held > counts.expired || counts.bound > counts.expired) throw new Error('storage_backlog_response_invalid');
    });
    return value;
  }

  let activeRequest = 0;
  let controller = null;
  let busy = false;
  let resumeBusy = false;

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

  function nonNegativeInteger(value) {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0;
  }

  function normalizeMetrics(value) {
    if (!exactKeys(value, metricOrder)) throw new Error('storage_detail_response_invalid');
    const metrics = {};
    metricOrder.forEach((key) => {
      if (!nonNegativeInteger(value[key])) throw new Error('storage_detail_response_invalid');
      metrics[key] = value[key];
    });
    return metrics;
  }

  function normalizeWorker(value) {
    if (!exactKeys(value, ['status', 'last_heartbeat_utc', 'minutes_since'])) {
      throw new Error('storage_detail_response_invalid');
    }
    if (!workerStatuses.includes(value.status)) throw new Error('storage_detail_response_invalid');
    if (value.last_heartbeat_utc !== null) {
      if (typeof value.last_heartbeat_utc !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value.last_heartbeat_utc)) {
        throw new Error('storage_detail_response_invalid');
      }
    }
    if (value.minutes_since !== null && !nonNegativeInteger(value.minutes_since)) {
      throw new Error('storage_detail_response_invalid');
    }
    if ('never' === value.status && (value.last_heartbeat_utc !== null || value.minutes_since !== null)) {
      throw new Error('storage_detail_response_invalid');
    }
    return { status: value.status, lastHeartbeatUtc: value.last_heartbeat_utc, minutesSince: value.minutes_since };
  }

  function normalizeWorkers(value) {
    if (!exactKeys(value, workerOrder)) throw new Error('storage_detail_response_invalid');
    const workers = {};
    workerOrder.forEach((key) => { workers[key] = normalizeWorker(value[key]); });
    return workers;
  }

  function normalize(value) {
    if (!value || typeof value !== 'object' || value.profile !== profile) {
      throw new Error('storage_detail_response_invalid');
    }
    if (!['ready', 'attention', 'unavailable'].includes(value.status)) throw new Error('storage_detail_response_invalid');
    if (typeof value.checked_at_utc !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value.checked_at_utc)) {
      throw new Error('storage_detail_response_invalid');
    }
    return {
      checkedAt: value.checked_at_utc,
      status: value.status,
      scannerStatus: ['responding', 'unavailable', 'unknown'].includes(value.scanner_status) ? value.scanner_status : 'unknown',
      metrics: value.status === 'unavailable' ? null : normalizeMetrics(value.metrics),
      workers: normalizeWorkers(value.workers),
      backlog: normalizeBacklog(value.backlog),
    };
  }

  function checkedAtLabel(value) {
    const parsed = new Date(value.replace(' ', 'T') + 'Z');
    if (Number.isNaN(parsed.getTime())) return '방금 확인';
    try {
      const timeZone = root.dataset.timezone || 'Asia/Seoul';
      const locale = String(root.dataset.locale || 'ko-KR').replace(/_/g, '-');
      const formatted = new Intl.DateTimeFormat(locale, {
        month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone,
      }).format(parsed);
      return `마지막 확인 ${formatted}`;
    } catch (_error) {
      return '방금 확인';
    }
  }

  function setNotice(message, tone, role) {
    elements.notice.textContent = message;
    elements.notice.dataset.tone = tone;
    elements.notice.setAttribute('role', role || 'status');
    elements.notice.hidden = !message;
  }

  function setBusy(value) {
    busy = value;
    root.dataset.busy = value ? '1' : '0';
    elements.metrics.setAttribute('aria-busy', value ? 'true' : 'false');
    elements.workers.setAttribute('aria-busy', value ? 'true' : 'false');
    elements.refresh.disabled = value;
    updateResumeButton();
  }

  function updateResumeButton() {
    elements.resumeButton.disabled = busy || resumeBusy;
  }

  function metricRow(key, value, metrics) {
    const entry = create('div', 'oj-ai-diagnostics__row');
    entry.dataset.metricKey = key;
    entry.setAttribute('role', 'listitem');
    const copy = create('div', 'oj-ai-diagnostics__copy');
    copy.append(create('strong', '', metricLabels[key]));
    const result = create('div', 'oj-ai-diagnostics__result');
    const badge = create('span', 'oj-ai-diagnostics__badge', String(value));
    if (typeof value !== 'number') badge.dataset.status = 'unavailable';
    else if (key === 'scheduled_workers' && value < metrics.expected_workers) badge.dataset.status = 'unavailable';
    else if (!['expected_workers', 'scheduled_workers'].includes(key) && value > 0) badge.dataset.status = 'attention';
    result.append(badge);
    entry.append(copy, result);
    return entry;
  }

  function workerStatusLabel(worker) {
    if ('running' === worker.status) return '최근 호출 기록 있음';
    if ('never' === worker.status) return '호출 기록 없음';
    return `마지막 호출: ${worker.minutesSince}분 전`;
  }

  function workerRow(key, worker) {
    const entry = create('div', 'oj-ai-diagnostics__row');
    entry.dataset.workerKey = key;
    entry.dataset.status = worker.status;
    entry.setAttribute('role', 'listitem');
    const copy = create('div', 'oj-ai-diagnostics__copy');
    copy.append(create('strong', '', workerLabels[key]));
    const result = create('div', 'oj-ai-diagnostics__result');
    const badge = create('span', 'oj-ai-diagnostics__badge', workerStatusLabel(worker));
    badge.dataset.status = worker.status;
    result.append(badge);
    entry.append(copy, result);
    return entry;
  }

  function render(value) {
    elements.metrics.replaceChildren();
    metricOrder.forEach((key) => elements.metrics.append(metricRow(key, value.metrics ? value.metrics[key] : '확인 불가', value.metrics)));
    elements.metrics.hidden = false;

    elements.workers.replaceChildren();
    workerOrder.forEach((key) => elements.workers.append(workerRow(key, value.workers[key])));
    elements.workers.hidden = false;

    if (backlogElement) {
      backlogElement.replaceChildren();
      Object.entries(backlogLabels).forEach(([key, label]) => {
        const entry = create('div', 'oj-ai-diagnostics__row');
        entry.dataset.backlogKey = key;
        entry.setAttribute('role', 'listitem');
        const copy = create('div', 'oj-ai-diagnostics__copy');
        const counts = value.backlog?.[key];
        copy.append(create('strong', '', label));
        if (key === 'export' && counts) {
          const breakdown = create('p', 'oj-ai-storage-detail__counts');
          [`정리 가능 ${counts.eligible}`, `보존 중 ${counts.preserved}`, `작업 중 ${counts.in_use}`, `확인 필요 ${counts.blocked}`]
            .forEach(text => breakdown.append(create('span', '', text)));
          copy.append(breakdown);
        } else {
          copy.append(create('p', '', counts ? `만료 ${counts.expired} · 보존 지정 ${counts.held} · 연결됨 ${counts.bound}` : '확인 불가'));
        }
        const result = create('div', 'oj-ai-diagnostics__result');
        const dispositionLabel = !counts ? '확인 불가' : key === 'export'
          ? counts.blocked > 0 ? '참조·저장 기록 확인 필요' : counts.eligible > 0 ? '정리 대기' : counts.preserved + counts.in_use > 0 ? '보관 유지' : '정리 대상 없음'
          : counts.expired === 0 ? '정리 대상 없음' : ['attachment', 'zip'].includes(key) ? '정리 조건 확인 대상' : '별도 보관 처리 필요';
        result.append(create('span', '', dispositionLabel));
        entry.append(copy, result);
        backlogElement.append(entry);
      });
      backlogElement.hidden = false;
    }

    elements.checkedAt.textContent = checkedAtLabel(value.checkedAt);
    const scannerMessage = value.scannerStatus === 'unavailable'
      ? '파일 검사 서비스가 응답하지 않습니다. 서버 관리자는 ClamAV 서비스와 소켓 연결을 확인해 주세요. 정리 실행만으로 연결 문제를 해결할 수 없습니다.'
      : value.scannerStatus === 'unknown' ? '파일 검사 서비스의 연결 상태를 확인하지 못했습니다.' : '';
    setNotice(value.metrics ? scannerMessage : '파일 보관 상태를 조회하지 못했습니다. 수치를 확인할 수 없습니다.', !value.metrics || scannerMessage ? 'warning' : 'neutral');
  }

  function fail(message, denied) {
    if (backlogElement) { backlogElement.replaceChildren(); backlogElement.hidden = true; }
    elements.metrics.replaceChildren();
    elements.metrics.hidden = true;
    elements.workers.replaceChildren();
    elements.workers.hidden = true;
    elements.checkedAt.textContent = '';
    setNotice(message, denied ? 'danger' : 'warning', denied ? 'alert' : 'status');
  }

  async function load() {
    const requestId = ++activeRequest;
    if (controller) controller.abort();
    controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
    setNotice('상태를 확인하는 중입니다.', 'neutral');
    setBusy(true);

    try {
      const headers = { Accept: 'application/json' };
      if (root.dataset.restNonce) headers['X-WP-Nonce'] = root.dataset.restNonce;
      const response = await window.fetch(`${root.dataset.restRoot}/operations/storage/detail`, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
        signal: controller ? controller.signal : undefined,
      });
      const body = await response.json().catch(() => null);
      if (requestId !== activeRequest) return;
      if (!response.ok) {
        const error = new Error('storage_detail_request_failed');
        error.status = Number(response.status || body?.data?.status || 500);
        throw error;
      }
      render(normalize(body?.data));
    } catch (error) {
      if (requestId !== activeRequest || error?.name === 'AbortError') return;
      const denied = Number(error?.status || 0) === 401 || Number(error?.status || 0) === 403;
      fail(denied ? '파일 보관 상세를 볼 수 없습니다.' : '상태를 확인할 수 없습니다. 다시 시도해 주세요.', denied);
    } finally {
      if (requestId === activeRequest) setBusy(false);
    }
  }

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return `storage-detail-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }

  async function resume() {
    if (resumeBusy || busy) return;
    resumeBusy = true;
    updateResumeButton();
    elements.resumeStatus.textContent = '지금 정리를 실행하는 중입니다.';
    elements.resumeStatus.dataset.tone = 'neutral';
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
        const error = new Error('storage_resume_request_failed');
        error.status = Number(response.status || body?.data?.status || 500);
        throw error;
      }
      const workers = body?.data?.storage_jobs?.workers;
      if (!exactKeys(workers, workerOrder) || workerOrder.some((key) =>
        !exactKeys(workers[key], ['iterations', 'succeeded']) || !nonNegativeInteger(workers[key].iterations)
        || typeof workers[key].succeeded !== 'boolean')) throw new Error('storage_resume_response_invalid');
      const succeeded = body?.meta?.all_succeeded === true && workerOrder.every((key) => workers[key].succeeded);
      const scanned = workers.attachment_scan.iterations + workers.zip_scan.iterations;
      const purged = workers.attachment_cleanup.iterations + workers.zip_cleanup.iterations + workers.export_cleanup.iterations;
      const summary = `검사 처리 ${scanned}건 · 파일 정리 ${purged}건 (내보내기 ${workers.export_cleanup.iterations}건).`;
      const failed = workerOrder.filter((key) => !workers[key].succeeded).map((key) => workerLabels[key]).join(', ');
      elements.resumeStatus.textContent = !succeeded
        ? `${summary} 정리·검사 미완료: ${failed}. 잠시 후 다시 시도해 주세요.`
        : scanned + purged === 0
          ? '이번 실행에서 처리할 파일이 없습니다. 보존 중·작업 중인 파일은 유지되며, 확인 필요 항목은 별도 확인이 필요합니다.'
          : `${summary} 남은 만료 파일을 다시 확인합니다.`;
      elements.resumeStatus.dataset.tone = !succeeded ? 'danger' : scanned + purged === 0 ? 'warning' : 'success';
    } catch (error) {
      const status = Number(error?.status || 0);
      elements.resumeStatus.textContent = status === 401 || status === 403
        ? '지금 정리를 실행할 수 없습니다.'
        : '지금 정리를 실행하지 못했습니다. 다시 시도해 주세요.';
      elements.resumeStatus.dataset.tone = 'danger';
    } finally {
      resumeBusy = false;
      updateResumeButton();
    }
    await load();
  }

  root.addEventListener('click', (event) => {
    const refresh = event.target.closest('[data-storage-action="refresh"]');
    if (refresh && !refresh.disabled) load();
    const resumeControl = event.target.closest('[data-storage-action="resume"]');
    if (resumeControl && !resumeControl.disabled) resume();
  });

  load();
})(window, document);

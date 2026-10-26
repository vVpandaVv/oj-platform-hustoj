(function (window, document) {
  'use strict';

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

  function commandEnvelope(commandId, expected, payload) {
    if (!commandId || typeof commandId !== 'string') {
      throw new TypeError('commandId is required.');
    }
    return { command_id: commandId, expected: { ...expected }, payload: { ...payload } };
  }

  function approveCommand(commandId, status) {
    return commandEnvelope(commandId, {
      expected_state: status.consent.state,
      expected_consent_hash: status.consent.consent_hash,
      expected_routing_revision: status.routing.routing_revision,
      expected_profile_fingerprint: status.routing.profile_fingerprint,
    }, { acknowledged_external_processing: true });
  }

  function revokeCommand(commandId, status) {
    return commandEnvelope(commandId, {
      expected_state: status.consent.state,
      expected_consent_hash: status.consent.consent_hash,
    }, {});
  }

  window.OJAIExternalAiConsentSettings = Object.freeze({
    commandEnvelope,
    approveCommand,
    revokeCommand,
  });

  const roots = typeof document.querySelectorAll === 'function'
    ? [...document.querySelectorAll('[data-consent-root]')]
    : [];
  if (roots.length === 0) return;

  function token(prefix) {
    const random = window.crypto?.randomUUID
      ? window.crypto.randomUUID().replaceAll('-', '')
      : Math.random().toString(36).slice(2) + Date.now().toString(36);
    return prefix + ':' + Date.now().toString(36) + ':' + random;
  }

  function controller(root) {
    const elements = {
      notice: root.querySelector('[data-role="notice"]'),
      content: root.querySelector('[data-role="content"]'),
      badge: root.querySelector('[data-role="status-badge"]'),
      title: root.querySelector('[data-role="status-title"]'),
      message: root.querySelector('[data-role="status-message"]'),
      approvedAt: root.querySelector('[data-role="approved-at"]'),
      approvedBy: root.querySelector('[data-role="approved-by"]'),
      provider: root.querySelector('[data-role="provider"]'),
      model: root.querySelector('[data-role="model"]'),
      routingState: root.querySelector('[data-role="routing-state"]'),
      acknowledgement: root.querySelector('[data-field="acknowledgement"]'),
      approve: root.querySelector('[data-action="approve"]'),
      revoke: root.querySelector('[data-action="revoke"]'),
    };
    const statusPath = root.dataset.statusPath || '/settings/external-ai-consent';
    const responseKey = root.dataset.responseKey || 'external_ai_consent';
    const TIME_ZONE = resolveTimeZone(root.dataset.timezone);
    const LOCALE = String(root.dataset.locale || 'ko-KR').replace(/_/g, '-');
    let status = null;
    let busy = false;

    function showNotice(message, tone = 'neutral') {
      elements.notice.textContent = message;
      elements.notice.dataset.tone = tone;
      elements.notice.hidden = !message;
    }

    function dateLabel(value) {
      if (typeof value !== 'string' || !value) return '-';
      const parsed = new Date(value.replace(' ', 'T') + 'Z');
      if (Number.isNaN(parsed.getTime())) return value;
      try {
        return new Intl.DateTimeFormat(LOCALE, {
          dateStyle: 'medium', timeStyle: 'short', timeZone: TIME_ZONE,
        }).format(parsed);
      } catch (_error) {
        return value;
      }
    }

    async function request(path, options = {}) {
      const method = options.method || 'GET';
      const headers = { Accept: 'application/json' };
      headers['X-WP-Nonce'] = root.dataset.restNonce || '';
      if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['Idempotency-Key'] = options.idempotencyKey || token('idem');
      }
      const response = await window.fetch((root.dataset.restRoot || '') + path, {
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
        throw error;
      }
      const value = payload?.data?.[responseKey];
      if (!value || typeof value !== 'object') {
        const error = new Error('응답을 확인할 수 없습니다.');
        error.status = 503;
        throw error;
      }
      return value;
    }

    function renderActions() {
      const consent = status?.consent || {};
      const routing = status?.routing || {};
      const canApprove = routing.available && ['missing', 'stale'].includes(consent.state);
      const canRevoke = ['approved', 'stale'].includes(consent.state) && Boolean(consent.consent_hash);
      elements.acknowledgement.disabled = busy || !canApprove;
      elements.approve.disabled = busy || !canApprove || !elements.acknowledgement.checked;
      elements.revoke.disabled = busy || !canRevoke;
    }

    function setBusy(value) {
      busy = value;
      root.dataset.busy = value ? '1' : '0';
      renderActions();
    }

    function render() {
      const consent = status?.consent || {};
      const routing = status?.routing || {};
      const storedDestination = consent.destination || {};
      const destination = routing.available ? routing : storedDestination;
      const labels = {
        approved: ['사용 가능', 'AI 처리가 승인되었습니다.', 'approved'],
        stale: ['다시 승인 필요', 'AI 연결이 바뀌었습니다.', 'attention'],
        missing: ['승인 필요', 'AI 처리를 시작하려면 승인해 주세요.', 'missing'],
      };
      const label = labels[consent.state] || ['확인 필요', '상태를 다시 확인해 주세요.', 'attention'];
      elements.badge.textContent = label[0];
      elements.badge.dataset.tone = label[2];
      elements.title.textContent = '외부 AI 처리';
      elements.message.textContent = label[1];
      elements.approvedAt.textContent = dateLabel(consent.approved_at_utc);
      elements.approvedBy.textContent = consent.approved_by_user_id ? '사용자 #' + consent.approved_by_user_id : '-';
      elements.provider.textContent = destination.provider_key || '-';
      elements.model.textContent = destination.model_key || '-';
      elements.routingState.textContent = routing.available ? '연결됨' : '연결 확인 필요';
      elements.routingState.dataset.tone = routing.available ? 'approved' : 'attention';
      elements.content.hidden = false;
      elements.acknowledgement.checked = false;
      renderActions();
    }

    async function load() {
      setBusy(true);
      showNotice('상태를 불러오는 중입니다.');
      try {
        status = await request(statusPath);
        render();
        showNotice('');
      } catch (error) {
        showNotice(error?.message || '동의 상태를 불러오지 못했습니다.', 'danger');
      } finally {
        setBusy(false);
      }
    }

    function mutationPath(operation) {
      return statusPath === '/settings/submission-external-ai-consent'
        ? '/settings/submission-external-ai-consent/commands/' + operation
        : '/settings/external-ai-consent/commands/' + operation;
    }

    async function mutate(operation) {
      if (!status || busy) return;
      if (operation === 'revoke' && !(await (window.OJP && typeof window.OJP.confirm === 'function' ? window.OJP.confirm({title:'외부 AI 처리를 중단할까요?',message:'승인을 철회하면 외부 AI로 보내는 평가가 멈춥니다.',confirmLabel:'중단',destructive:true}) : Promise.resolve(window.confirm('외부 AI 처리를 중단할까요?'))))) return;
      const commandId = token('command');
      const body = operation === 'approve'
        ? approveCommand(commandId, status)
        : revokeCommand(commandId, status);
      setBusy(true);
      showNotice(operation === 'approve' ? '승인하는 중입니다.' : '철회하는 중입니다.');
      try {
        status = await request(mutationPath(operation), {
          method: 'POST',
          idempotencyKey: token('idem'),
          body,
        });
        render();
        showNotice(operation === 'approve' ? 'AI 처리를 승인했습니다.' : '승인을 철회했습니다.', 'success');
      } catch (error) {
        showNotice(error?.message || '설정을 변경하지 못했습니다.', 'danger');
        if (error?.status === 409) await load();
      } finally {
        setBusy(false);
      }
    }

    root.addEventListener('click', (event) => {
      const control = event.target.closest('[data-action]');
      if (!control) return;
      const action = control.dataset.action;
      if (action === 'refresh') load();
      if (action === 'approve') mutate('approve');
      if (action === 'revoke') mutate('revoke');
    });
    elements.acknowledgement.addEventListener('change', renderActions);
    return Object.freeze({ load });
  }

  const controllers = roots.map(controller);
  const tabs = typeof document.querySelectorAll === 'function'
    ? [...document.querySelectorAll('[data-consent-tab]')]
    : [];
  function selectScope(scope) {
    tabs.forEach((tab) => {
      const selected = tab.dataset.consentTab === scope;
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
      tab.tabIndex = selected ? 0 : -1;
    });
    roots.forEach((root) => { root.hidden = root.dataset.consentPanel !== scope; });
  }
  tabs.forEach((tab) => tab.addEventListener('click', () => selectScope(tab.dataset.consentTab)));
  tabs.forEach((tab) => tab.addEventListener('keydown', (event) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const current = tabs.indexOf(tab);
    let next = current;
    if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
    if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    selectScope(tabs[next].dataset.consentTab);
    tabs[next].focus();
  }));
  selectScope('worksheet');
  controllers.forEach((instance) => instance.load());
})(window, document);

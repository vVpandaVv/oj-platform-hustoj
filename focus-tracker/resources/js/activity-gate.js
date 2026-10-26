/**
 * A short server lease authorizes scheduled collection. The gate never reads
 * draft contents and never authorizes a future window from cached state.
 */
(function () {
    'use strict';

    var element = document.getElementById('focus-tracker-config');
    var config = window.FocusTrackerConfig;
    if (element) {
        try { config = JSON.parse(element.textContent); } catch (error) { return; }
    }
    if (!config || !config.activityGate || !config.activityGate.url || window.FocusTrackerActivityGate) { return; }
    var settings = config.activityGate;
    var pollMs = Math.max(500, Math.min(10000, Number(settings.pollMs) || 5000));
    var timeoutMs = Math.max(1000, Math.min(10000, Number(settings.timeoutMs) || 5000));
    var listeners = [];
    var timer = null;
    var requestTimer = null;
    var busy = false;
    var refreshPending = false;
    var suspended = false;
    var stopped = false;
    var generation = 0;
    var revision = 0;
    var run = 0;
    var hasRecorded = false;
    var lastStoppedAtMs = null;
    var statusRoot = null;
    var statusLabel = null;
    var statusDetail = null;
    var statusTime = null;
    var lastStatusText = '';
    var wallStart = Date.now();
    var monoStart = monotonic();
    var epochStart = Number(config.serverNowMs) || wallStart;
    var current = { active: false, reason: 'unconfirmed', windows: [], activityIds: [],
        activeUntilMs: null, windowStartsAtMs: null, windowEndsAtMs: null, validUntilMs: 0,
        nextStartsAtMs: null, contextType: 'general', contextId: null, problemId: Number(config.problemId) || null,
        runKey: '', revision: 0, nowMs: epochStart };

    function monotonic() {
        return window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now();
    }
    function now() { return Math.round(epochStart + monotonic() - monoStart); }
    function available() { return !suspended; }
    function copy() {
        return Object.assign({}, current, { nowMs: now(), windows: current.windows.slice(), activityIds: current.activityIds.slice() });
    }
    function publish(next) {
        var wasActive = current.active;
        current = Object.assign({}, current, next);
        current.active = !!current.active;
        if (current.active) { hasRecorded = true; }
        if (wasActive && !current.active && ['refreshing', 'pagehide', 'unavailable', 'account_changed'].indexOf(current.reason) === -1) {
            lastStoppedAtMs = Math.min(now(), Number(current.activeUntilMs) || now());
        }
        if (current.active && !wasActive) { run += 1; current.runKey = 'ftk-gate-' + run; }
        current.revision = ++revision;
        current.nowMs = now();
        renderStatus();
        listeners.slice().forEach(function (listener) { listener(copy()); });
        if (typeof document.dispatchEvent === 'function' && typeof window.CustomEvent === 'function') {
            document.dispatchEvent(new window.CustomEvent('ftk:activity-gate', { detail: copy() }));
        }
    }
    function expire() {
        if (current.active && (!available() || now() >= current.validUntilMs || now() >= current.activeUntilMs)) {
            publish({ active: false, reason: !available() ? 'pagehide' : (current.deadlineReason || 'expired') });
        }
    }
    function state() { expire(); return copy(); }
    function context() {
        var forms = document.querySelectorAll('form[data-oj-submission-form]');
        for (var index = 0; index < forms.length; index += 1) {
            var problem = forms[index].querySelector('input[name="problem_id"]');
            if (!problem || Number(problem.value) !== Number(config.problemId)
                || (typeof forms[index].closest === 'function' && forms[index].closest('[hidden]'))) { continue; }
            var kinds = ['contest', 'class', 'group'];
            for (var kind = 0; kind < kinds.length; kind += 1) {
                var field = forms[index].querySelector('input[name="' + kinds[kind] + '_id"]');
                if (field && Number(field.value) > 0) {
                    return { context_type: kinds[kind], context_id: String(field.value) };
                }
            }
        }
        return { context_type: 'general', context_id: null };
    }
    function schedule(delay) {
        clearTimeout(timer);
        if (!available() || stopped) { return; }
        var at = Math.max(50, delay);
        if (current.active) { at = Math.min(at, Math.max(0, Math.min(current.validUntilMs, current.activeUntilMs) - now())); }
        if (current.nextStartsAtMs !== null && current.nextStartsAtMs > now()) { at = Math.min(at, current.nextStartsAtMs - now()); }
        timer = setTimeout(function () {
            expire();
            if (!busy) { probe(false); }
            else { schedule(100); }
        }, Math.max(0, at));
    }
    function validWindow(value) {
        return value && Number.isFinite(Number(value.startMs)) && Number.isFinite(Number(value.endMs))
            && Number(value.startMs) < Number(value.endMs);
    }

    function formatTime(value) {
        if (!Number.isFinite(Number(value)) || Number(value) <= 0) { return ''; }
        var zone = typeof settings.timeZone === 'string' ? settings.timeZone : undefined;
        try {
            var formatter = new Intl.DateTimeFormat('ko-KR', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit',
                hour12: false, timeZone: zone });
            return formatter.format(new Date(Number(value))) + ' (' + (zone || formatter.resolvedOptions().timeZone) + ')';
        } catch (error) { return new Date(Number(value)).toISOString().replace('T', ' ').slice(0, 16) + ' UTC'; }
    }

    function renderStatus() {
        if (!statusRoot) { return; }
        var label;
        var detail;
        var time = '';
        var tone = 'idle';
        if (current.active) {
            label = '기록 중'; tone = 'active';
            detail = '지정된 활동 시간에 화면 상태와 학습 활동을 기록하고 있습니다.';
            time = '예정 종료: ' + formatTime(current.activeUntilMs);
        } else if (current.reason === 'account_changed') {
            label = '로그인 확인 필요'; tone = 'warning';
            detail = '로그인 상태를 확인할 수 없어 기록과 문항을 중단했습니다. 페이지를 새로고침해 주세요.';
        } else if (['unavailable', 'expired'].indexOf(current.reason) !== -1) {
            label = '연결 오류로 중단'; tone = 'warning';
            detail = '활동 시간을 확인하지 못해 기록과 문항을 중단했습니다. 연결되면 자동으로 다시 확인합니다.';
        } else if (current.reason === 'scheduled' || (current.nextStartsAtMs && current.nextStartsAtMs > now())) {
            label = '시작 대기';
            detail = '활동이 시작되기 전에는 학습 기록을 수집하거나 초안을 분석하지 않습니다.';
            time = '다음 시작: ' + formatTime(current.nextStartsAtMs);
        } else if (['unconfirmed', 'refreshing', 'pagehide'].indexOf(current.reason) !== -1) {
            label = '활동 시간 확인 중';
            detail = '활동 시간이 확인될 때까지 새 학습 기록과 문항을 중단합니다.';
        } else if (hasRecorded) {
            label = '활동 종료';
            detail = '현재 활동의 기록 수집과 확인 문항을 종료했습니다. 다음 활동이 시작되면 자동으로 다시 확인합니다.';
            if (lastStoppedAtMs) { time = '종료 확인: ' + formatTime(lastStoppedAtMs); }
        } else {
            label = '활동 없음';
            detail = '현재 이 문제에서 참여할 지정 활동이 없습니다. 학습 기록과 초안을 수집하지 않습니다.';
        }
        var signature = [label, detail, time].join('\n');
        if (signature === lastStatusText) { return; }
        lastStatusText = signature;
        statusRoot.className = 'ftk-activity-status ftk-activity-status--' + tone;
        statusLabel.textContent = label;
        statusDetail.textContent = detail;
        statusTime.textContent = time;
        statusTime.hidden = !time;
    }

    function installStatus() {
        if (typeof document.createElement !== 'function' || !document.body || statusRoot) { return; }
        statusRoot = document.createElement('section');
        statusRoot.setAttribute('data-ftk-activity-status', '');
        statusRoot.setAttribute('role', 'status');
        statusRoot.setAttribute('aria-live', 'polite');
        statusRoot.setAttribute('aria-atomic', 'true');
        statusRoot.setAttribute('aria-label', '활동 기록 상태');
        statusLabel = document.createElement('strong'); statusLabel.setAttribute('data-ftk-gate-label', '');
        statusDetail = document.createElement('p'); statusDetail.setAttribute('data-ftk-gate-detail', '');
        statusTime = document.createElement('p'); statusTime.setAttribute('data-ftk-gate-time', '');
        statusRoot.appendChild(statusLabel); statusRoot.appendChild(statusDetail); statusRoot.appendChild(statusTime);
        var forms = document.querySelectorAll('form[data-oj-submission-form]');
        var matched = null;
        for (var index = 0; index < forms.length; index += 1) {
            var problem = forms[index].querySelector('input[name="problem_id"]');
            if (problem && Number(problem.value) === Number(config.problemId) && forms[index].parentNode) { matched = forms[index]; break; }
        }
        if (matched) { matched.parentNode.insertBefore(statusRoot, matched); }
        else { document.body.appendChild(statusRoot); }
        renderStatus();
    }
    function accept(data, pageContext, roundTripMs) {
        if (!data || !Number.isFinite(Number(data.serverNowMs)) || typeof data.active !== 'boolean'
            || !Number.isFinite(Number(data.validUntilMs)) || !Array.isArray(data.windows)) { throw new Error('Invalid activity lease'); }
        var clock = Number(data.serverNowMs);
        // Each fresh response recalibrates monotonic time; browser wall-clock
        // adjustments cannot lengthen the server's authorization lease.
        epochStart = clock; monoStart = monotonic();
        expire();
        // The response's timestamp was produced somewhere during this request.
        // Subtract the whole round trip so a slow response cannot revive a
        // lease whose real deadline has already passed while in transit.
        var lease = Math.floor(Math.min(Number(data.validUntilMs), clock + 10000) - Math.max(0, roundTripMs));
        var windows = data.windows.filter(validWindow).map(function (window) {
            return Object.assign({}, window, { startMs: Number(window.startMs), endMs: Number(window.endMs) });
        });
        var activeWindows = windows.filter(function (window) { return window.startMs <= clock && clock < window.endMs; });
        var unionEnd = null;
        if (activeWindows.length) {
            unionEnd = Math.max.apply(null, activeWindows.map(function (window) { return window.endMs; }));
            var extended;
            do {
                extended = false;
                windows.forEach(function (window) {
                    if (window.startMs <= unionEnd && window.endMs > unionEnd) { unionEnd = window.endMs; extended = true; }
                });
            } while (extended);
            var declaredEnd = Number(data.activeUntilMs || data.windowEndsAtMs);
            if (Number.isFinite(declaredEnd) && declaredEnd > clock) { unionEnd = Math.min(unionEnd, declaredEnd); }
        }
        var allowed = data.active && activeWindows.length > 0 && unionEnd > clock && lease > clock && available();
        publish({ active: allowed, reason: allowed ? 'active' : String(data.reason || 'outside_schedule'), windows: windows,
            activityIds: allowed ? activeWindows.map(function (window) { return window.activityId; }) : [],
            activeUntilMs: unionEnd, windowEndsAtMs: unionEnd,
            windowStartsAtMs: Number(data.windowStartsAtMs) || (activeWindows.length ? Math.min.apply(null, activeWindows.map(function (window) { return window.startMs; })) : null),
            validUntilMs: lease, nextStartsAtMs: Number(data.nextStartsAtMs) > clock ? Number(data.nextStartsAtMs) : null,
            deadlineReason: unionEnd && Math.min(Number(data.validUntilMs), clock + 10000) >= unionEnd ? 'ended' : 'unavailable',
            contextType: typeof data.contextType === 'string' ? data.contextType : pageContext.context_type,
            contextId: data.contextId !== undefined ? data.contextId : pageContext.context_id });
    }
    function probe(force) {
        if (force) {
            generation += 1;
            publish({ active: false, reason: 'refreshing' });
            if (busy) { refreshPending = true; }
        }
        if (!available() || stopped || busy || typeof window.fetch !== 'function') { schedule(pollMs); return; }
        expire();
        busy = true;
        var requestGeneration = generation;
        var requestStartedAt = monotonic();
        var pageContext = context();
        var payload = { user_id: Number(config.userId), problem_id: Number(config.problemId) || null,
            context_type: pageContext.context_type, context_id: pageContext.context_id };
        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var deadline = new Promise(function (resolve, reject) {
            requestTimer = setTimeout(function () { if (controller) { controller.abort(); } reject(new Error('Activity lease timed out')); }, timeoutMs);
        });
        var fetchRequest;
        try {
            fetchRequest = window.fetch(settings.url, { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' }, body: JSON.stringify(payload),
                signal: controller ? controller.signal : undefined }).then(function (response) {
                    if (!response.ok) {
                        if (response.status === 401 || response.status === 403) { stopped = true; }
                        throw new Error('Activity lease rejected');
                    }
                    return response.json();
                });
        } catch (error) { fetchRequest = Promise.reject(error); }
        Promise.race([fetchRequest, deadline]).then(function (data) {
            if (requestGeneration === generation && available()) { accept(data, pageContext, monotonic() - requestStartedAt); }
        }).catch(function () {
            if (requestGeneration === generation) { publish({ active: false, reason: stopped ? 'account_changed' : 'unavailable' }); }
        }).then(function () {
            clearTimeout(requestTimer); busy = false;
            if (refreshPending) { refreshPending = false; probe(false); }
            else { schedule(pollMs); }
        });
        schedule(pollMs);
    }
    window.FocusTrackerActivityGate = {
        state: state, now: now,
        subscribe: function (listener) {
            if (typeof listener !== 'function') { return function () {}; }
            listeners.push(listener); listener(state());
            return function () { listeners = listeners.filter(function (candidate) { return candidate !== listener; }); };
        },
        refresh: function () { probe(true); },
    };
    document.addEventListener('visibilitychange', function () {
        // Hidden observations still describe an authorized activity interval.
        // Keep their lease current so tab-away time is not silently erased.
        if (document.visibilityState === 'hidden') { expire(); schedule(pollMs); }
        else { probe(true); }
    });
    window.addEventListener('pagehide', function () { suspended = true; generation += 1; clearTimeout(timer); publish({ active: false, reason: 'pagehide' }); });
    window.addEventListener('pageshow', function (event) { if (event.persisted) { suspended = false; probe(true); } });
    window.addEventListener('online', function () { probe(true); });
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { installStatus(); probe(true); }, { once: true }); }
    else { installStatus(); probe(true); }
})();

/**
 * Optional, server-verified learning checks. Drafts are read only from the
 * current problem's submission editor and sent transiently, never persisted.
 * Check outcomes do not produce tracking events or change focus scores.
 */
(function () {
    'use strict';

    var element = document.getElementById('focus-tracker-config');
    var config = window.FocusTrackerConfig;
    if (element) {
        try { config = JSON.parse(element.textContent); } catch (error) { return; }
    }
    var settings = config && config.checks;
    var activityGate = window.FocusTrackerActivityGate;
    if (!settings || !settings.enabled || !settings.nextUrl || !settings.responseUrl
        || !(Number(config.userId) > 0) || !(Number(config.problemId) > 0)
        || typeof window.fetch !== 'function' || !activityGate || typeof activityGate.state !== 'function'
        || typeof activityGate.subscribe !== 'function' || window.__focusTrackerChecksStarted) {
        return;
    }
    window.__focusTrackerChecksStarted = true;

    var initialDelay = Number.isFinite(Number(settings.initialDelayMs))
        ? Math.max(0, Number(settings.initialDelayMs)) : 45000;
    var pollDelay = Math.max(1000, Number(settings.pollMs) || 60000);
    var maxDraftBytes = Math.min(65536, Math.max(1, Number(settings.maxDraftBytes) || 65536));
    var wallStart = Date.now();
    var epochStart = Number(config.serverNowMs) || wallStart;
    var monoStart = window.performance && typeof window.performance.now === 'function'
        ? window.performance.now() : null;
    var nextAt;
    var timer = null;
    var expiryTimer = null;
    var current = null;
    var panel = null;
    var launcher = null;
    var message = null;
    var controls = null;
    var pendingResponse = null;
    var busy = false;
    var suspended = false;
    var stopped = false;
    var generation = 0;
    var gateActive = false;
    var gateRun = null;
    var gateTimer = null;
    var activeController = null;
    var announcer = null;
    var availability = null;
    var returnFocus = null;

    function announce(text) {
        if (announcer && announcer.textContent !== text) { announcer.textContent = text; }
    }

    function inside(container, target) {
        return !!container && !!target && typeof container.contains === 'function' && container.contains(target);
    }

    function focusSafely(target) {
        if (!target || typeof target.focus !== 'function') { return; }
        try { target.focus({ preventScroll: true }); } catch (error) { target.focus(); }
    }

    function restoreFocus() {
        var target = returnFocus;
        if (!target || target.isConnected === false || hiddenAncestor(target) || target.disabled) {
            var form = currentForm();
            target = form && (form.querySelector('.ace_text-input') || form.querySelector('textarea[name="source"][data-oj-source-editor]'));
        }
        if (!target || target.isConnected === false || hiddenAncestor(target) || target.disabled
            || (typeof target.getClientRects === 'function' && target.getClientRects().length === 0)) {
            target = document.querySelector('[data-ftk-activity-status]') || document.querySelector('main');
            if (target) { target.setAttribute('tabindex', '-1'); }
        }
        focusSafely(target);
    }

    function availabilityMessage(reason) {
        var messages = {
            no_candidate: '현재 자료에서 정답을 검증할 수 있는 확인 문항이 없습니다. 학습 참여에 대한 평가가 아닙니다.',
            unsupported: '현재 문제 유형에서는 자동 학습 확인을 지원하지 않습니다.',
            disabled: '자동 학습 확인이 꺼져 있습니다.',
            access: '문제·풀이 자료를 확인하지 못해 문항 제시를 보류했습니다.',
            cooldown: '다음 학습 확인을 기다리는 중입니다. 편집을 계속할 수 있습니다.',
            connection: '연결 문제로 학습 확인을 잠시 중단했습니다. 자동으로 다시 시도합니다.',
            account: '로그인 상태를 확인하려면 페이지를 새로고침해 주세요.',
        };
        var text = messages[reason] || messages.no_candidate;
        if (availability) {
            availability.hidden = false;
            availability.textContent = text + ' 정답 여부와 응답 유무는 집중 점수에 반영하지 않습니다.';
        }
        announce(text);
    }

    function now() {
        if (typeof activityGate.now === 'function') { return activityGate.now(); }
        return Math.round(epochStart + (monoStart === null ? Date.now() - wallStart
            : window.performance.now() - monoStart));
    }

    function allowed() {
        var state = activityGate.state();
        return gateActive && visible() && state && state.active === true
            && Number(state.activeUntilMs || state.windowEndsAtMs) > now()
            && Number.isFinite(Number(state.validUntilMs)) && Number(state.validUntilMs) > now();
    }

    function clearQuestion() {
        var wasQuestion = !!current;
        var hadFocus = inside(panel, document.activeElement) || inside(launcher, document.activeElement);
        clearTimeout(timer);
        clearTimeout(expiryTimer);
        clearTimeout(gateTimer);
        if (activeController) { activeController.abort(); activeController = null; }
        generation += 1;
        clearLauncher();
        if (panel) { panel.remove(); panel = null; }
        current = null;
        pendingResponse = null;
        controls = null;
        message = null;
        busy = false;
        if (availability) { availability.hidden = true; }
        if (hadFocus) { restoreFocus(); }
        if (wasQuestion) { announce('활동 시간 또는 연결 상태가 변경되어 문항을 닫았습니다. 응답 유무는 집중 점수에 반영하지 않습니다.'); }
    }

    function gateChanged(state) {
        var end = state && Number(state.activeUntilMs || state.windowEndsAtMs);
        var lease = state && Number(state.validUntilMs);
        var active = state && state.active === true && Number.isFinite(end) && end > now()
            && Number.isFinite(lease) && lease > now();
        if (!active) {
            gateActive = false;
            clearQuestion();
            return;
        }
        var run = state.runKey || JSON.stringify([state.windowStartsAtMs, state.windowEndsAtMs || end, state.activityIds || []]);
        if (!gateActive || gateRun !== run) {
            clearQuestion();
            gateActive = true;
            gateRun = run;
            nextAt = now() + initialDelay;
            schedule(initialDelay);
        }
        clearTimeout(gateTimer);
        gateTimer = setTimeout(function () { gateChanged(activityGate.state()); }, Math.max(0, Math.min(end, Number.isFinite(lease) ? lease : end) - now()));
    }

    function visible() { return document.visibilityState !== 'hidden'; }
    function hiddenAncestor(node) {
        return !node || (typeof node.closest === 'function' && node.closest('[hidden]'));
    }

    function currentForm() {
        var forms = document.querySelectorAll('form[data-oj-submission-form]');
        for (var index = 0; index < forms.length; index += 1) {
            var problem = forms[index].querySelector('input[name="problem_id"]');
            if (problem && Number(problem.value) === Number(config.problemId) && !hiddenAncestor(forms[index])) {
                return forms[index];
            }
        }
        return null;
    }

    function byteLength(value) {
        // TextEncoder is not available in some embedded browsers. Count UTF-8
        // bytes without truncating a code point or changing program semantics.
        var bytes = 0;
        for (var index = 0; index < value.length; index += 1) {
            var point = value.charCodeAt(index);
            if (point < 128) { bytes += 1; }
            else if (point < 2048) { bytes += 2; }
            else if (point >= 55296 && point <= 56319 && index + 1 < value.length
                && value.charCodeAt(index + 1) >= 56320 && value.charCodeAt(index + 1) <= 57343) {
                bytes += 4;
                index += 1;
            } else { bytes += 3; }
        }
        return bytes;
    }

    function context() {
        var form = currentForm();
        var kinds = ['contest', 'class', 'group'];
        for (var index = 0; form && index < kinds.length; index += 1) {
            var field = form.querySelector('input[name="' + kinds[index] + '_id"]');
            if (field && Number(field.value) > 0) {
                return { context_type: kinds[index], context_id: String(field.value) };
            }
        }
        return { context_type: 'general', context_id: null };
    }

    function draft() {
        if (!allowed()) { return null; }
        var form = currentForm();
        if (!form) { return null; }
        var selected = form.querySelector('input[name="submission_mode"]:checked')
            || form.querySelector('input[type="hidden"][name="submission_mode"]');
        var mode = selected ? selected.value : 'code';
        var code = '';
        var language;
        if (mode === 'blockly') {
            var root = form.querySelector('[data-oj-blockly-editor][data-mounted="true"]');
            var generated = root && root.querySelector('[data-oj-blockly-code]');
            var status = root && root.querySelector('[data-oj-blockly-status]');
            if (hiddenAncestor(root) || !generated
                || (status && status.classList.contains('is-error'))) { return null; }
            code = generated.textContent || '';
            language = 6;
        } else if (mode === 'code_completion') {
            var completion = form.querySelector('[data-oj-completion-editor]');
            if (hiddenAncestor(completion)) { return null; }
            var parts = completion.querySelectorAll('.ojp-completion-code, .ojp-completion-blank');
            if (!parts.length) { return null; }
            for (var index = 0; index < parts.length; index += 1) {
                if (parts[index].classList.contains('ojp-completion-code')) {
                    code += parts[index].textContent || '';
                } else {
                    var answer = parts[index].querySelector('input[name^="completion_answers["], textarea[name^="completion_answers["]');
                    if (!answer || answer.disabled || !answer.value.trim()) { return null; }
                    code += answer.value;
                }
            }
            var completionLanguage = form.querySelector('input[name="language_id"]');
            language = completionLanguage ? Number(completionLanguage.value) : NaN;
        } else if (mode === 'code') {
            var source = form.querySelector('textarea[name="source"][data-oj-source-editor]');
            var languageSelect = form.querySelector('select[name="language_id"]');
            // Ace synchronizes its contents to this textarea on every change.
            // Its own CSS may hide the textarea; only a hidden mode panel is excluded.
            if (hiddenAncestor(source) || source.disabled || !languageSelect || languageSelect.disabled) { return null; }
            code = source.value;
            language = Number(languageSelect.value);
        } else { return null; }
        if (!code.trim() || !Number.isInteger(language) || language < 0 || byteLength(code) > maxDraftBytes) {
            return null;
        }
        return { source: code, language_id: language };
    }

    function request(url, payload) {
        var requestGeneration = generation;
        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var timeout;
        activeController = controller;
        var deadline = new Promise(function (resolve, reject) {
            timeout = setTimeout(function () {
                if (controller) { controller.abort(); }
                reject(new Error('Learning check request timed out'));
            }, 15000);
        });
        var fetchRequest;
        try {
            fetchRequest = window.fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' },
                body: JSON.stringify(payload),
                signal: controller ? controller.signal : undefined,
            }).then(function (response) {
                if (!response.ok) {
                    if ((response.status === 401 || response.status === 403) && requestGeneration === generation && allowed()) { stopped = true; }
                    return response.json().catch(function () { return {}; }).then(function (data) {
                        var error = new Error('Learning check request rejected');
                        error.status = response.status;
                        error.retryAfterMs = Math.max(0, Number(data && data.data && data.data.retryAfterSeconds) || 0) * 1000;
                        throw error;
                    });
                }
                return response.json();
            });
        } catch (error) { fetchRequest = Promise.reject(error); }
        return Promise.race([fetchRequest, deadline]).then(function (result) {
            clearTimeout(timeout);
            if (activeController === controller) { activeController = null; }
            return result;
        }, function (error) {
            clearTimeout(timeout);
            if (activeController === controller) { activeController = null; }
            throw error;
        });
    }

    function schedule(delay) {
        clearTimeout(timer);
        if (!suspended && !stopped && allowed()) { timer = setTimeout(tick, Math.max(0, delay)); }
    }

    function node(tag, className, text) {
        var item = document.createElement(tag);
        if (className) { item.className = className; }
        if (text !== undefined) { item.textContent = text; }
        return item;
    }

    function button(text, handler) {
        var item = node('button', 'ojp-button ojp-button--small', text);
        item.type = 'button';
        item.addEventListener('click', handler);
        return item;
    }

    function responseUrl(id) {
        if (settings.responseUrl.indexOf('{id}') !== -1) {
            return settings.responseUrl.replace('{id}', encodeURIComponent(id));
        }
        return settings.responseUrl.replace(/\/$/, '') + '/' + encodeURIComponent(id) + '/response';
    }

    function setDisabled(disabled) {
        if (controls) {
            var hadFocus = inside(controls, document.activeElement);
            controls.querySelectorAll('button').forEach(function (item) { item.disabled = disabled; });
            // Native disabled buttons drop keyboard focus in Chromium. Keep
            // it inside the question while its acknowledgement is pending.
            if (disabled && hadFocus) { focusSafely(message); }
        }
    }

    function clearLauncher() {
        if (launcher) { launcher.remove(); launcher = null; }
    }

    function showLauncher() {
        clearLauncher();
        launcher = button('학습 확인 1문항', function () {
            // Scrolling happens only after the student's explicit request.
            if (panel && current && typeof panel.scrollIntoView === 'function') {
                panel.scrollIntoView({ block: 'start', behavior: 'auto' });
                focusSafely(panel.querySelector('.ftk-check-title'));
            }
        });
        launcher.className += ' ftk-check-launcher';
        launcher.setAttribute('data-ftk-check-launcher', '');
        launcher.setAttribute('aria-controls', 'ftk-check-panel');
        launcher.setAttribute('aria-label', '학습 확인 1문항 보기');
        launcher.setAttribute('title', '편집을 계속할 수 있습니다. 원하는 때 문항을 확인하세요.');
        document.body.appendChild(launcher);
    }

    function validCheck(value) {
        if (!value || !/^[a-zA-Z0-9_-]{1,128}$/.test(String(value.id))
            || typeof value.prompt !== 'string' || !value.prompt.trim() || value.prompt.length > 8000
            || !Array.isArray(value.choices) || value.choices.length < 2 || value.choices.length > 8
            || !Number.isFinite(Number(value.expiresAtMs)) || Number(value.expiresAtMs) <= now()) { return false; }
        var ids = Object.create(null);
        return value.choices.every(function (choice) {
            if (!choice || typeof choice.id !== 'string' || !choice.id || choice.id.length > 128
                || typeof choice.label !== 'string' || choice.label.length > 8000 || ids[choice.id]) { return false; }
            ids[choice.id] = true;
            return true;
        });
    }

    function assignmentsText(value) {
        if (typeof value === 'string') { return value; }
        if (!value || typeof value !== 'object') { return ''; }
        if (Array.isArray(value)) {
            return value.map(function (item) {
                return typeof item === 'string' ? item : JSON.stringify(item);
            }).join('\n');
        }
        return Object.keys(value).map(function (key) {
            return key + ' = ' + JSON.stringify(value[key]);
        }).join('\n');
    }

    function completed(status, serverMessage) {
        clearTimeout(expiryTimer);
        var messages = {
            correct: '이 문항에 응답하고 정답을 확인한 기록을 남겼습니다.',
            incorrect: '이번 문항의 이해는 확인되지 않았습니다. 오답을 미집중으로 판단하지 않습니다.',
            unknown: '모르겠음으로 기록했습니다. 미집중으로 판단하지 않습니다.',
            skipped: '나중에 답하기로 기록했습니다. 미집중으로 판단하지 않습니다.',
            unanswered: '응답이 없어 학습 참여 여부를 확인하지 못했습니다. 미집중으로 판단하지 않습니다.',
            expired: '이 문항의 응답 기간이 지났습니다. 학습 참여 미확인이며 미집중으로 판단하지 않습니다.',
            invalidated: '활동 시간이 끝났거나 자료·권한이 변경되어 이 문항을 종료했습니다. 미집중으로 판단하지 않습니다.',
        };
        if (!Object.prototype.hasOwnProperty.call(messages, status)) { return false; }
        var hadFocus = inside(panel, document.activeElement) || inside(launcher, document.activeElement);
        clearLauncher();
        message.textContent = messages[status];
        if (typeof serverMessage === 'string' && serverMessage.trim()) {
            message.appendChild(node('span', 'ftk-check-server-message', serverMessage));
        }
        var close = button('닫기', function () {
            var shouldRestore = inside(panel, document.activeElement);
            if (panel) { panel.remove(); panel = null; }
            if (shouldRestore) { restoreFocus(); }
        });
        controls.replaceChildren(close);
        announce(messages[status]);
        if (hadFocus) { focusSafely(close); }
        current = null;
        pendingResponse = null;
        nextAt = now() + Math.max(300000, pollDelay);
        schedule(nextAt - now());
        return true;
    }

    function expire() {
        if (!allowed()) { gateChanged(activityGate.state()); return; }
        if (current && !pendingResponse && now() >= Number(current.expiresAtMs)) {
            completed('unanswered');
        }
    }

    function sendResponse() {
        if (!pendingResponse || busy || suspended || stopped || !visible() || !allowed()) { return; }
        busy = true;
        setDisabled(true);
        var queued = pendingResponse;
        var requestedGeneration = generation;
        request(responseUrl(queued.checkId), queued.payload).then(function (result) {
            if (requestedGeneration !== generation || !allowed()) { return; }
            if (!result || !completed(result.status, result.message)) { throw new Error('Invalid response acknowledgement'); }
        }).catch(function () {
            if (requestedGeneration !== generation || !allowed()) { return; }
            if (stopped) { clearLauncher(); }
            if (message) {
                message.textContent = stopped
                    ? '로그인 상태를 확인하려면 페이지를 새로고침해 주세요. 이 응답은 집중 점수에 반영되지 않습니다.'
                    : '응답을 저장하지 못해 다시 전송합니다. 이 상태를 미집중으로 판단하지 않습니다.';
                announce(message.textContent);
            }
            nextAt = now() + pollDelay;
        }).then(function () {
            if (requestedGeneration !== generation || !allowed()) { return; }
            busy = false;
            schedule(Math.max(0, nextAt - now()));
        });
    }

    function answer(answerId, action) {
        if (!current || pendingResponse || busy || !allowed()) { return; }
        expire();
        if (!current) { return; }
        var payload = {
            user_id: Number(config.userId),
            response_uid: window.crypto && typeof window.crypto.randomUUID === 'function'
                ? window.crypto.randomUUID() : 'ftk-check-' + now() + '-' + Math.random().toString(16).slice(2),
        };
        if (action) { payload.action = action; } else { payload.answer_id = answerId; }
        pendingResponse = { checkId: current.id, payload: payload };
        clearTimeout(expiryTimer);
        sendResponse();
    }

    function render(check) {
        if (!allowed()) { return; }
        if (!inside(panel, document.activeElement) && !inside(launcher, document.activeElement)) {
            returnFocus = document.activeElement === document.body ? null : document.activeElement;
        }
        if (panel) { panel.remove(); }
        if (availability) { availability.hidden = true; }
        current = check;
        panel = node('section', 'ftk-check ojp-card');
        panel.id = 'ftk-check-panel';
        panel.setAttribute('data-ftk-check', String(check.id));
        panel.setAttribute('role', 'region');
        panel.setAttribute('aria-labelledby', 'ftk-check-title');
        var title = node('h2', 'ftk-check-title', '학습 확인');
        title.id = 'ftk-check-title';
        title.setAttribute('tabindex', '-1');
        panel.appendChild(title);
        panel.appendChild(node('p', 'ftk-check-help', '정답 여부는 집중 점수에 반영하지 않습니다.'));
        panel.appendChild(node('p', 'ftk-check-source', check.sourceLabel || '현재 문제에 대한 확인'));
        panel.appendChild(node('p', 'ftk-check-prompt', check.prompt));
        if (typeof check.input === 'string' && check.input) {
            panel.appendChild(node('p', 'ftk-check-label', '예제 입력'));
            panel.appendChild(node('pre', 'ftk-check-code', check.input));
        }
        var assignments = assignmentsText(check.assignments);
        if (assignments) {
            panel.appendChild(node('p', 'ftk-check-label', '주어진 값'));
            panel.appendChild(node('pre', 'ftk-check-code', assignments));
        }
        if (typeof check.codeSnippet === 'string' && check.codeSnippet) {
            panel.appendChild(node('pre', 'ftk-check-code', check.codeSnippet));
        }
        controls = node('div', 'ftk-check-controls');
        var choices = node('div', 'ftk-check-choices');
        check.choices.forEach(function (choice) {
            var item = button(choice.label, function () { answer(choice.id); });
            item.setAttribute('data-ftk-answer', choice.id);
            choices.appendChild(item);
        });
        controls.appendChild(choices);
        var actions = node('div', 'ftk-check-actions');
        actions.appendChild(button('모르겠음', function () { answer(null, 'unknown'); }));
        actions.appendChild(button('나중에', function () { answer(null, 'skip'); }));
        controls.appendChild(actions);
        panel.appendChild(controls);
        message = node('p', 'ftk-check-message', '지금 답하기 어려우면 모르겠음 또는 나중에를 선택할 수 있습니다.');
        message.setAttribute('tabindex', '-1');
        panel.appendChild(message);
        var form = currentForm();
        if (form && form.parentNode) {
            form.parentNode.insertBefore(panel, form.nextSibling);
        } else {
            (document.querySelector('main') || document.body).appendChild(panel);
        }
        showLauncher();
        announce('학습 확인 1문항이 준비되었습니다. 원하는 때 학습 확인 버튼으로 이동할 수 있습니다. 정답 여부는 집중 점수에 반영하지 않습니다.');
        // Automatic display never moves focus or scrolls the editor.
        clearTimeout(expiryTimer);
        expiryTimer = setTimeout(expire, Math.max(0, Number(check.expiresAtMs) - now()));
    }

    function next() {
        if (!allowed()) { return; }
        busy = true;
        var requestedGeneration = generation;
        var pageContext = context();
        var payload = {
            user_id: Number(config.userId), problem_id: Number(config.problemId),
            context_type: pageContext.context_type, context_id: pageContext.context_id,
        };
        var currentDraft = draft();
        if (currentDraft) { payload.draft = currentDraft; }
        request(settings.nextUrl, payload).then(function (result) {
            if (suspended || requestedGeneration !== generation || !allowed()) { return; }
            if (result && result.status === 'inactive') {
                if (typeof activityGate.refresh === 'function') { activityGate.refresh('check-inactive'); }
                gateChanged(activityGate.state());
                return;
            }
            if (result && result.status === 'ready' && validCheck(result.check)) {
                render(result.check);
            } else {
                availabilityMessage(result && (result.reason || (result.status === 'cooldown' ? 'cooldown' : 'no_candidate')));
            }
            var retry = result && Number(result.retryAfterMs);
            nextAt = now() + (Number.isFinite(retry) && retry > 0 ? Math.max(pollDelay, retry) : pollDelay);
        }).catch(function (error) {
            if (requestedGeneration !== generation || !allowed()) { return; }
            availabilityMessage(stopped ? 'account' : error.status === 429 ? 'cooldown' : 'connection');
            nextAt = now() + Math.max(pollDelay, Number(error.retryAfterMs) || 0);
        }).then(function () {
            if (requestedGeneration !== generation || !allowed()) { return; }
            busy = false;
            schedule(Math.max(0, nextAt - now()));
        });
    }

    function tick() {
        if (suspended || stopped || !allowed()) { return; }
        expire();
        if (!visible() || busy) { schedule(pollDelay); return; }
        if (now() < nextAt) { schedule(nextAt - now()); return; }
        if (pendingResponse) { sendResponse(); return; }
        if (current) { schedule(pollDelay); return; }
        next();
    }

    function bind() {
        announcer = node('p', 'ftk-check-announcer');
        announcer.setAttribute('data-ftk-check-announcer', '');
        announcer.setAttribute('role', 'status');
        announcer.setAttribute('aria-live', 'polite');
        announcer.setAttribute('aria-atomic', 'true');
        document.body.appendChild(announcer);
        availability = node('p', 'ftk-check-availability');
        availability.setAttribute('data-ftk-check-availability', '');
        availability.hidden = true;
        var form = currentForm();
        if (form && form.parentNode) { form.parentNode.insertBefore(availability, form); }
        else { (document.querySelector('main') || document.body).appendChild(availability); }
        activityGate.subscribe(gateChanged);
    }
    document.addEventListener('visibilitychange', function () {
        if (visible()) { tick(); }
    });
    window.addEventListener('online', function () {
        if (pendingResponse) { nextAt = now(); }
        tick();
    });
    window.addEventListener('pagehide', function () {
        suspended = true;
        gateActive = false;
        clearQuestion();
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted || !suspended) { return; }
        suspended = false;
        gateChanged(activityGate.state());
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind, { once: true });
    } else { bind(); }
})();

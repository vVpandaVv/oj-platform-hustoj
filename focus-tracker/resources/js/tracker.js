/**
 * Records page state and learning-action signals, never source text or URLs.
 * Acknowledged requests remove events from the bounded, user-scoped outbox.
 * A closing page also sends a beacon; its events remain queued until acknowledged.
 */
(function () {
    'use strict';

    var configElement = document.getElementById('focus-tracker-config');
    var config = window.FocusTrackerConfig;
    if (configElement) {
        try {
            config = JSON.parse(configElement.textContent);
        } catch (error) {
            return;
        }
    }
    if (!config || !config.restUrl || window.__focusTrackerStarted) {
        return;
    }
    window.__focusTrackerStarted = true;

    var wallStartedAt = Date.now();
    var epochStartedAt = Number(config.serverNowMs) || wallStartedAt;
    var monotonicStartedAt = window.performance && typeof window.performance.now === 'function'
        ? window.performance.now() : null;
    function now() {
        if (config.activityGate && window.FocusTrackerActivityGate && typeof window.FocusTrackerActivityGate.now === 'function') {
            return window.FocusTrackerActivityGate.now();
        }
        return Math.round(epochStartedAt + (monotonicStartedAt === null
            ? Date.now() - wallStartedAt : window.performance.now() - monotonicStartedAt));
    }

    var MAX_PENDING = 1000;
    var MAX_AGE_MS = 24 * 60 * 60 * 1000;
    var BATCH_SIZE = 100;
    var EDIT_INTERVAL_MS = 800;
    var outbox = Object.create(null);
    var storagePrefix = Number(config.userId) > 0
        ? 'focus-tracker-outbox-v2:' + encodeURIComponent(config.restUrl) + ':' + config.userId + ':' : null;
    var sessionUid;
    var sessionStartedAt;
    var sequence;
    var active = false;
    var pagePresent = true;
    var sessionAllowedUntil = Infinity;
    var sessionContext = { context_type: 'general', context_id: null };
    var state;
    var flushTimer = null;
    var heartbeatTimer = null;
    var blocklyTimer = null;
    var retryTimer = null;
    var inFlight = false;
    var nextAttemptAt = 0;
    var failures = 0;
    var droppedEvents = false;
    var edits = Object.create(null);
    var blocklyWorkspaces = [];
    var inputSelector = 'textarea[name="source"], textarea#code, [data-oj-source-editor], '
        + 'input[name^="completion_answers["], textarea[name^="completion_answers["]';
    var formSelector = 'form[data-oj-submission-form], form#submission-form, form[method="post"][action*="submit"]';
    var runSelector = '[data-action="run"], #run-button, .btn-run';

    function gateState() {
        if (!config.activityGate) { return { active: true, activeUntilMs: Infinity, validUntilMs: Infinity }; }
        var gate = window.FocusTrackerActivityGate;
        return gate && typeof gate.state === 'function' ? gate.state() : { active: false };
    }

    function updateGate(next) {
        if (next.active && pagePresent) {
            sessionAllowedUntil = Math.min(Number(next.activeUntilMs) || Infinity, Number(next.validUntilMs) || Infinity);
            sessionContext = { context_type: next.contextType || 'general', context_id: next.contextId === undefined ? null : next.contextId };
            if (!active) { startSession(); }
        } else if (active) {
            endSession(Math.min(now(), sessionAllowedUntil));
        }
    }

    function canCapture() {
        if (config.activityGate) { updateGate(gateState()); }
        return pagePresent && active;
    }

    function stateMeta() {
        var meta = { visible: state.visible, focused: state.focused };
        if (droppedEvents) {
            meta.queue_dropped = true;
        }
        return meta;
    }

    function readState() {
        var visible = document.visibilityState !== 'hidden';
        return { visible: visible, focused: visible && (typeof document.hasFocus !== 'function' || document.hasFocus()) };
    }

    function removeEntry(key) {
        delete outbox[key];
        if (storagePrefix) {
            try {
                window.localStorage.removeItem(key);
            } catch (error) {
                // Memory remains usable when browser storage is unavailable.
            }
        }
    }

    function refreshOutbox() {
        if (!storagePrefix) {
            return;
        }
        try {
            var found = Object.create(null);
            var storage = window.localStorage;
            for (var index = 0; index < storage.length; index += 1) {
                var key = storage.key(index);
                if (!key || key.indexOf(storagePrefix) !== 0) {
                    continue;
                }
                try {
                    var entry = JSON.parse(storage.getItem(key));
                    if (!entry || typeof entry.session_uid !== 'string' || !entry.event
                        || !Number.isInteger(entry.event.seq) || entry.event.seq < 1
                        || !Number.isFinite(entry.queued_at) || !Number.isFinite(entry.session_started_at)) {
                        continue;
                    }
                    entry.persisted = true;
                    found[key] = entry;
                } catch (error) {
                    // Ignore unrelated or malformed local storage values.
                }
            }
            Object.keys(outbox).forEach(function (key) {
                if (!outbox[key].persisted && !found[key]) {
                    found[key] = outbox[key];
                }
            });
            outbox = found;
        } catch (error) {
            // Keep the memory copy if reading storage becomes unavailable.
        }
    }

    function pendingKeys() {
        var cutoff = now() - MAX_AGE_MS;
        var keys = Object.keys(outbox).sort(function (left, right) {
            return outbox[left].queued_at - outbox[right].queued_at
                || outbox[left].event.seq - outbox[right].event.seq;
        });
        while (keys.length && (outbox[keys[0]].queued_at < cutoff || keys.length > MAX_PENDING)) {
            removeEntry(keys.shift());
            droppedEvents = true;
        }
        return keys;
    }

    function pushEvent(type, meta, occurredAt) {
        if (!active && type !== 'session_end') {
            return;
        }
        var timestamp = occurredAt === undefined ? now() : occurredAt;
        if (timestamp < sessionStartedAt || (type === 'session_end' ? timestamp > sessionAllowedUntil : timestamp >= sessionAllowedUntil)) { return; }
        sequence += 1;
        var key = (storagePrefix || 'memory:') + sessionUid + ':' + sequence;
        var entry = {
            session_uid: sessionUid,
            problem_id: config.problemId || null,
            context_type: sessionContext.context_type,
            context_id: sessionContext.context_id,
            session_started_at: sessionStartedAt,
            queued_at: now(),
            event: { type: type, occurred_at: timestamp, seq: sequence, meta: meta || null },
        };
        outbox[key] = entry;
        if (storagePrefix) {
            try {
                window.localStorage.setItem(key, JSON.stringify(entry));
                entry.persisted = true;
            } catch (error) {
                // Retain an in-memory copy even when storage is disabled/full.
            }
        }
        pendingKeys();
    }

    function batch(final) {
        refreshOutbox();
        var keys = pendingKeys();
        if (final) {
            keys = keys.filter(function (key) { return outbox[key].session_uid === sessionUid; }).slice(-BATCH_SIZE);
        }
        if (!keys.length) {
            return null;
        }
        var first = outbox[keys[0]];
        keys = keys.filter(function (key) { return outbox[key].session_uid === first.session_uid; }).slice(0, BATCH_SIZE);
        var events = keys.map(function (key) { return outbox[key].event; });
        return {
            keys: keys,
            payload: {
                user_id: Number(config.userId) || null,
                session_uid: first.session_uid,
                problem_id: first.problem_id,
                context_type: first.context_type,
                context_id: first.context_id,
                session_started_at: first.session_started_at,
                is_final: events.some(function (event) { return event.type === 'session_end'; }),
                events: events,
            },
        };
    }

    function requestUrl() {
        return config.restUrl + (config.restUrl.indexOf('?') === -1 ? '?' : '&')
            + '_wpnonce=' + encodeURIComponent(config.nonce || '');
    }

    function flush(final) {
        if (!final) {
            if (!pagePresent || inFlight) { return; }
            if (now() < nextAttemptAt) {
                clearTimeout(retryTimer);
                retryTimer = setTimeout(function () { flush(false); }, nextAttemptAt - now());
                return;
            }
        }
        var queued = batch(final);
        if (!queued) {
            return;
        }
        var body = JSON.stringify(queued.payload);
        if (final && navigator.sendBeacon) {
            try {
                if (navigator.sendBeacon(requestUrl(), new Blob([body], { type: 'application/json' }))) {
                    return;
                }
            } catch (error) {
                // Fall back to fetch; a queued beacon is never an acknowledgement.
            }
        }
        if (typeof window.fetch !== 'function' || inFlight) {
            return;
        }
        inFlight = true;
        var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        var timeout;
        var deadline = new Promise(function (resolve, reject) {
            timeout = setTimeout(function () {
                if (controller) {
                    controller.abort();
                }
                reject(new Error('Focus Tracker request timed out'));
            }, 15000);
        });
        var request;
        try {
            request = window.fetch(requestUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                keepalive: true,
                body: body,
                signal: controller ? controller.signal : undefined,
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Focus Tracker request was rejected');
                }
                return response.json();
            });
        } catch (error) {
            request = Promise.reject(error);
        }
        Promise.race([request, deadline]).then(function (response) {
            if (!response || response.acknowledged !== true) {
                throw new Error('Focus Tracker batch was not acknowledged');
            }
            queued.keys.forEach(removeEntry);
            failures = 0;
            nextAttemptAt = 0;
        }).catch(function () {
            failures += 1;
            nextAttemptAt = now() + Math.min(60000, 1000 * Math.pow(2, Math.min(failures, 6)));
        }).then(function () {
            clearTimeout(timeout);
            inFlight = false;
            if (pagePresent && pendingKeys().length) {
                clearTimeout(retryTimer);
                retryTimer = setTimeout(function () { flush(false); }, Math.max(0, nextAttemptAt - now()));
            }
        });
    }

    // A leading event plus a fixed trailing timer records continuous editing.
    // The trailing event keeps the last input's timestamp, including at page exit.
    function commitEdit(subtype) {
        var edit = edits[subtype];
        if (!edit) {
            return;
        }
        clearTimeout(edit.timer);
        edit.timer = null;
        if (edit.pendingAt !== null) {
            pushEvent('activity', { subtype: subtype }, edit.pendingAt);
            edit.lastSentAt = edit.pendingAt;
            edit.pendingAt = null;
        }
    }

    function commitEdits() {
        Object.keys(edits).forEach(commitEdit);
    }

    function onEdit(subtype) {
        if (!canCapture() || !state.visible || !state.focused) {
            return;
        }
        var timestamp = now();
        var edit = edits[subtype];
        if (!edit) {
            edits[subtype] = { lastSentAt: timestamp, pendingAt: null, timer: null };
            pushEvent('activity', { subtype: subtype }, timestamp);
            return;
        }
        edit.pendingAt = timestamp;
        if (timestamp - edit.lastSentAt >= EDIT_INTERVAL_MS) {
            commitEdit(subtype);
        } else if (!edit.timer) {
            edit.timer = setTimeout(function () { if (canCapture()) { commitEdit(subtype); } }, EDIT_INTERVAL_MS - (timestamp - edit.lastSentAt));
        }
    }

    function closest(target, selector) {
        return target && typeof target.closest === 'function' ? target.closest(selector) : null;
    }

    function bindBlocklyChanges() {
        if (!active) { return; }
        var common = window.Blockly && window.Blockly.common;
        if (!common || typeof common.getAllWorkspaces !== 'function') {
            return;
        }
        common.getAllWorkspaces().forEach(function (workspace) {
            if (workspace.isFlyout || blocklyWorkspaces.indexOf(workspace) !== -1
                || typeof workspace.getParentSvg !== 'function') {
                return;
            }
            var root = closest(workspace.getParentSvg(), '[data-oj-blockly-editor]');
            if (!root) {
                return;
            }
            workspace.addChangeListener(function (event) {
                var editTypes = ['create', 'delete', 'change', 'move', 'block_field_intermediate_change',
                    'var_create', 'var_delete', 'var_rename', 'var_type_change'];
                if (!event.isUiEvent && editTypes.indexOf(event.type) !== -1 && !closest(root, '[hidden]')) {
                    onEdit('blockly_edit');
                }
            });
            blocklyWorkspaces.push(workspace);
        });
    }

    function onVisibilityChange() {
        if (!canCapture()) {
            return;
        }
        var previous = state;
        state = readState();
        if (state.visible === previous.visible && state.focused === previous.focused) {
            return;
        }
        commitEdits();
        pushEvent(state.visible ? 'visible' : 'hidden', stateMeta());
        flush(false);
    }

    function onWindowBlur() {
        if (canCapture() && state.focused) {
            commitEdits();
            state.focused = false;
            pushEvent('blur', stateMeta());
            flush(false);
        }
    }

    function onWindowFocus() {
        if (canCapture() && document.visibilityState !== 'hidden' && !state.focused) {
            state.visible = true;
            state.focused = true;
            pushEvent('focus', stateMeta());
            flush(false);
        }
    }

    function startSession() {
        if (active || !pagePresent || !gateState().active) { return; }
        sessionUid = window.crypto && typeof window.crypto.randomUUID === 'function'
            ? window.crypto.randomUUID() : 'ftk-' + now() + '-' + Math.random().toString(16).slice(2);
        sessionStartedAt = now();
        sequence = 0;
        edits = Object.create(null);
        state = readState();
        active = true;
        pushEvent('session_start', stateMeta());
        flushTimer = setInterval(function () { flush(false); }, config.flushMs || 7000);
        heartbeatTimer = setInterval(function () {
            if (canCapture()) { pushEvent('heartbeat', stateMeta()); }
        }, config.heartbeatMs || 15000);
        blocklyTimer = setInterval(bindBlocklyChanges, 1000);
        bindBlocklyChanges();
        flush(false);
    }

    function endSession(endedAt) {
        if (!active) {
            return;
        }
        commitEdits();
        state = readState();
        var boundary = Math.min(typeof endedAt === 'number' ? endedAt : now(), sessionAllowedUntil);
        pushEvent('session_end', stateMeta(), Math.max(sessionStartedAt, boundary));
        active = false;
        clearInterval(flushTimer);
        clearInterval(heartbeatTimer);
        clearInterval(blocklyTimer);
        clearTimeout(retryTimer);
        flush(true);
        if (pagePresent) { retryTimer = setTimeout(function () { flush(false); }, 0); }
    }

    function showNotice() {
        if (!config.noticeText || document.querySelector('[data-ftk-notice]')) {
            return;
        }
        try {
            if (window.localStorage.getItem('ftk-notice-dismissed') === '1') {
                return;
            }
        } catch (error) {
            // Show the notice even when storage is disabled.
        }
        var banner = document.createElement('div');
        banner.setAttribute('data-ftk-notice', '');
        banner.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:9999;'
            + 'background:var(--ojp-color-ink, #1d1d1f);color:var(--ojp-color-surface, #ffffff);font-size:12px;padding:6px 12px;'
            + 'display:flex;align-items:center;justify-content:space-between;gap:12px;';
        var text = document.createElement('span');
        text.textContent = config.noticeText;
        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.textContent = '×';
        dismiss.setAttribute('aria-label', '안내 닫기');
        dismiss.style.cssText = 'background:none;border:none;color:inherit;cursor:pointer;font-size:14px;line-height:1;';
        dismiss.addEventListener('click', function () {
            banner.remove();
            try {
                window.localStorage.setItem('ftk-notice-dismissed', '1');
            } catch (error) {
                // Closing the notice must also work without storage.
            }
        });
        banner.appendChild(text);
        banner.appendChild(dismiss);
        document.body.appendChild(banner);
    }

    function bindDom() {
        document.addEventListener('input', function (event) {
            if (closest(event.target, inputSelector) && !closest(event.target, '[hidden]')) {
                onEdit('code_edit');
            }
        });
        document.addEventListener('submit', function (event) {
            if (!canCapture() || event.defaultPrevented || !closest(event.target, formSelector)) {
                return;
            }
            commitEdits();
            pushEvent('submit', stateMeta());
            flush(false);
        });
        document.addEventListener('click', function (event) {
            var control = closest(event.target, runSelector);
            if (canCapture() && control && !control.disabled && !event.defaultPrevented) {
                commitEdits();
                pushEvent('run', stateMeta());
                flush(false);
            }
        });
        showNotice();
        bindBlocklyChanges();
    }

    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('blur', onWindowBlur);
    window.addEventListener('focus', onWindowFocus);
    window.addEventListener('pagehide', function () {
        pagePresent = false;
        endSession();
        clearTimeout(retryTimer);
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            pagePresent = true;
            updateGate(gateState());
            flush(false);
        }
    });
    window.addEventListener('online', function () {
        nextAttemptAt = 0;
        flush(false);
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindDom, { once: true });
    } else {
        bindDom();
    }
    refreshOutbox();
    if (config.activityGate && window.FocusTrackerActivityGate && typeof window.FocusTrackerActivityGate.subscribe === 'function') {
        window.FocusTrackerActivityGate.subscribe(updateGate);
    } else if (!config.activityGate) { startSession(); }
    flush(false);
})();

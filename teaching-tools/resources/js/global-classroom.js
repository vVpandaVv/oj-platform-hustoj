(function (window, document) {
    'use strict';

    var STORAGE_KEY = 'teachingTools.classroom.active.v1';
    var DISMISSED_KEY = 'teachingTools.classroom.dismissed.v1';
    var DISMISSED_TTL_MS = 8 * 60 * 60 * 1000;
    var DISCOVERY_POLL_MS = 5000;
    var PARTICIPANT_POLL_MS = 30000;
    var PARTICIPANT_HEARTBEAT_MS = 15000;
    var HOST_POLL_MS = 4000;
    var SESSION_CHECK_MS = 60000;
    var LEADER_LEASE_MS = 9000;
    var LEADER_HEARTBEAT_MS = 3000;
    var SIGNALS = [
        { key: 'ready', label: '준비됐어요' },
        { key: 'understood', label: '이해했어요' },
        { key: 'unsure', label: '조금 어려워요' },
        { key: 'repeat', label: '다시 설명해 주세요' },
        { key: 'help', label: '도움이 필요해요' },
        { key: 'done', label: '활동을 마쳤어요' },
    ];

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    ready(function () {
        var configElement = document.getElementById('htt-global-classroom-config');
        if (!configElement || document.querySelector('[data-htt-classroom-host]') || document.querySelector('[data-htt-join]')) {
            return;
        }

        var config;
        try {
            config = JSON.parse(configElement.textContent || '{}');
        } catch (error) {
            return;
        }
        if (!config.restUrl || !config.currentUserId || (!config.canJoin && !config.canHost)) {
            return;
        }

        var lifecycle = 0;
        var record = null;
        var role = '';
        var root = null;
        var fields = {};
        var expanded = false;
        var activity = null;
        var currentSignal = '';
        var signalSending = false;
        var participants = [];
        var newActivity = false;
        var realtime = null;
        var pollTimer = null;
        var heartbeatTimer = null;
        var sessionTimer = null;
        var retryTimer = null;
        var discoveryTimer = null;
        var sessionInFlight = false;
        var hostInFlight = false;
        var discoveryInFlight = false;
        var chooserRoot = null;
        var tabId = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
        var leaderKey = 'teachingTools.classroom.leader.v1.' + Number(config.currentUserId);
        var relayKey = 'teachingTools.classroom.relay.v1.' + Number(config.currentUserId);
        var isLeader = false;
        var leaderTimer = null;
        var channel = typeof window.BroadcastChannel === 'function'
            ? new window.BroadcastChannel('teachingTools.classroom.' + Number(config.currentUserId))
            : null;

        function readLeader() {
            try {
                return JSON.parse(window.localStorage.getItem(leaderKey) || '{}');
            } catch (error) {
                return {};
            }
        }

        function releaseLeadership() {
            var leader = readLeader();
            if (leader.tabId === tabId) {
                try { window.localStorage.removeItem(leaderKey); } catch (ignore) {}
            }
            isLeader = false;
        }

        function updateLeadership() {
            var previous = isLeader;
            if (document.visibilityState === 'hidden') {
                releaseLeadership();
            } else {
                var now = Date.now();
                var leader = readLeader();
                if (leader.tabId === tabId || !leader.tabId || now - Number(leader.at || 0) > LEADER_LEASE_MS) {
                    try {
                        window.localStorage.setItem(leaderKey, JSON.stringify({ tabId: tabId, at: now }));
                    } catch (ignore) {}
                    isLeader = readLeader().tabId === tabId;
                } else {
                    isLeader = false;
                }
            }
            if (previous !== isLeader && record && root) {
                clearTimers();
                if (realtime) {
                    realtime.stop();
                    realtime = null;
                }
                if (isLeader) {
                    beginRuntime();
                } else {
                    setConnection('waiting', '다른 탭에서 실시간 연결을 유지하고 있습니다.');
                }
            }
        }

        function relay(type, payload) {
            var message = { source: tabId, type: type, code: record ? record.code : '', payload: payload, at: Date.now() };
            if (channel) {
                channel.postMessage(message);
            }
            try {
                window.localStorage.setItem(relayKey, JSON.stringify(message));
            } catch (ignore) {}
        }

        function receiveRelay(message) {
            if (!message || message.source === tabId || !record || message.code !== record.code) {
                return;
            }
            if (message.type === 'session') {
                applySession(message.payload, true);
            } else if (message.type === 'participants' && role === 'host') {
                participants = Array.isArray(message.payload) ? message.payload : [];
                renderHost();
            } else if (message.type === 'activity') {
                updateActivity(message.payload || null, true);
            } else if (message.type === 'signal' && role === 'participant') {
                currentSignal = String(message.payload || '');
                renderParticipant();
            }
        }

        if (channel) {
            channel.addEventListener('message', function (event) { receiveRelay(event.data); });
        }

        function removeChooser() {
            if (chooserRoot) {
                chooserRoot.remove();
                chooserRoot = null;
            }
        }

        function showSessionChooser(sessions) {
            removeChooser();
            chooserRoot = document.createElement('section');
            chooserRoot.className = 'htt-global-classroom-chooser';
            chooserRoot.setAttribute('aria-label', '수업 선택');
            var heading = document.createElement('strong');
            heading.textContent = '연결할 수업을 선택하세요';
            chooserRoot.appendChild(heading);
            sessions.forEach(function (session) {
                var code = String(session.joinCode || '').trim().toUpperCase();
                if (!/^[A-Z0-9]{6}$/.test(code)) {
                    return;
                }
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = (session.label || '수업 신호') + ' · ' + code;
                button.addEventListener('click', function () {
                    var discovered = {
                        code: code,
                        actorId: Number(config.currentUserId),
                        role: session.role === 'host' ? 'host' : 'participant',
                        storedAt: Date.now(),
                        lastActivityAt: 0,
                    };
                    removeChooser();
                    persistRecord(discovered);
                    activate(discovered);
                });
                chooserRoot.appendChild(button);
            });
            document.body.appendChild(chooserRoot);
        }

        function normalizedRecord(value) {
            var parsed = value;
            try {
                if (typeof value === 'string') {
                    parsed = JSON.parse(value || '{}');
                }
            } catch (error) {
                return null;
            }
            if (!parsed || typeof parsed !== 'object') {
                return null;
            }
            var code = String(parsed.code || '').trim().toUpperCase();
            var actorId = Number(parsed.actorId || parsed.studentId || parsed.userId || 0);
            if (!/^[A-Z0-9]{6}$/.test(code) || actorId !== Number(config.currentUserId)) {
                return null;
            }
            return {
                code: code,
                actorId: actorId,
                role: parsed.role === 'host' ? 'host' : (parsed.role === 'participant' ? 'participant' : ''),
                storedAt: Number(parsed.storedAt || Date.now()),
                lastActivityAt: Number(parsed.lastActivityAt || 0),
            };
        }

        function readRecord(value) {
            try {
                return normalizedRecord(value === undefined ? window.localStorage.getItem(STORAGE_KEY) : value);
            } catch (error) {
                return null;
            }
        }

        function writeRecord(patch) {
            if (!record) {
                return;
            }
            Object.keys(patch || {}).forEach(function (key) {
                record[key] = patch[key];
            });
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(record));
            } catch (error) {
                // The active page still works when browser storage is blocked.
            }
        }

        function removeStoredRecord() {
            try {
                window.localStorage.removeItem(STORAGE_KEY);
            } catch (error) {
                // Nothing else to clean up when storage is unavailable.
            }
        }

        function readDismissedCode() {
            try {
                var dismissed = JSON.parse(window.localStorage.getItem(DISMISSED_KEY) || '{}');
                if (Number(dismissed.actorId || dismissed.studentId || dismissed.userId || 0) !== Number(config.currentUserId)) {
                    return '';
                }
                if (Date.now() - Number(dismissed.dismissedAt || 0) > DISMISSED_TTL_MS) {
                    clearDismissedCode();
                    return '';
                }
                var code = String(dismissed.code || '').trim().toUpperCase();
                return /^[A-Z0-9]{6}$/.test(code) ? code : '';
            } catch (error) {
                return '';
            }
        }

        function rememberDismissedCode(code) {
            try {
                window.localStorage.setItem(DISMISSED_KEY, JSON.stringify({
                    code: code,
                    actorId: Number(config.currentUserId),
                    dismissedAt: Date.now(),
                }));
            } catch (error) {
                // The local disconnect still clears the active record.
            }
        }

        function clearDismissedCode() {
            try {
                window.localStorage.removeItem(DISMISSED_KEY);
            } catch (error) {
                // Nothing else to clear when storage is unavailable.
            }
        }

        function persistRecord(nextRecord) {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(nextRecord));
            } catch (error) {
                // The current page can still use the discovered session.
            }
        }

        function request(path, method, body) {
            return window.fetch(String(config.restUrl).replace(/\/$/, '') + path, {
                method: method,
                headers: Object.assign(
                    { 'X-WP-Nonce': config.nonce },
                    body ? { 'Content-Type': 'application/json' } : {}
                ),
                body: body ? JSON.stringify(body) : undefined,
                credentials: 'same-origin',
            }).then(function (response) {
                return response.text().then(function (text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (error) {
                        data = {};
                    }
                    if (!response.ok) {
                        var requestError = new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                        requestError.status = response.status;
                        throw requestError;
                    }
                    return data;
                });
            });
        }

        function discoverActiveClassroom(force) {
            if (!isLeader || record || discoveryInFlight || document.visibilityState === 'hidden') {
                return;
            }
            discoveryInFlight = true;
            var discovery = window.HttDiscovery
                ? window.HttDiscovery.read(config, force === true).then(function (data) { return data && data.classroom; })
                : request('/classroom/active', 'GET');
            discovery.then(function (data) {
                discoveryInFlight = false;
                if (record || !data || !data.active) {
                    return;
                }
                var sessions = Array.isArray(data.sessions) && data.sessions.length
                    ? data.sessions
                    : [{ joinCode: data.joinCode, role: data.role, label: '' }];
                var dismissedCode = readDismissedCode();
                sessions = sessions.filter(function (session) {
                    var candidate = String(session.joinCode || '').trim().toUpperCase();
                    return /^[A-Z0-9]{6}$/.test(candidate) && candidate !== dismissedCode;
                });
                if (sessions.length > 1) {
                    showSessionChooser(sessions);
                    return;
                }
                if (!sessions.length) {
                    return;
                }
                var selected = sessions[0];
                var code = String(selected.joinCode || '').trim().toUpperCase();
                if (!/^[A-Z0-9]{6}$/.test(code)) {
                    return;
                }
                if (dismissedCode === code) {
                    return;
                }
                if (dismissedCode) {
                    clearDismissedCode();
                }
                var discovered = {
                    code: code,
                    actorId: Number(config.currentUserId),
                    role: selected.role === 'host' ? 'host' : 'participant',
                    storedAt: Date.now(),
                    lastActivityAt: 0,
                };
                persistRecord(discovered);
                activate(discovered);
            }).catch(function () {
                discoveryInFlight = false;
            });
        }

        function clearTimers() {
            [pollTimer, heartbeatTimer, sessionTimer, retryTimer].forEach(function (timer) {
                if (timer !== null) {
                    window.clearInterval(timer);
                    window.clearTimeout(timer);
                }
            });
            pollTimer = null;
            heartbeatTimer = null;
            sessionTimer = null;
            retryTimer = null;
        }

        function cleanup(removeStored) {
            clearTimers();
            removeChooser();
            if (realtime) {
                realtime.stop();
                realtime = null;
            }
            if (root) {
                root.remove();
                root = null;
            }
            fields = {};
            role = '';
            expanded = false;
            activity = null;
            currentSignal = '';
            participants = [];
            newActivity = false;
            signalSending = false;
            sessionInFlight = false;
            hostInFlight = false;
            if (removeStored) {
                removeStoredRecord();
                record = null;
            }
        }

        function notify(message, tone) {
            if (window.OJP && typeof window.OJP.toast === 'function') {
                window.OJP.toast(message, tone || 'info');
            } else if (fields.live) {
                fields.live.textContent = message;
            }
        }

        function endConnection(message) {
            lifecycle += 1;
            if (message) {
                notify(message, 'info');
            }
            cleanup(true);
        }

        function safeUrl(value) {
            try {
                var parsed = new URL(String(value || ''), window.location.origin);
                return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
            } catch (error) {
                return '';
            }
        }

        function signalLabel(signal) {
            var found = SIGNALS.find(function (item) { return item.key === signal; });
            return found ? found.label : '신호 없음';
        }

        function setConnection(status, text) {
            if (!root) {
                return;
            }
            root.setAttribute('data-connection', status);
            fields.status.textContent = text;
        }

        function markActivitySeen() {
            var sentAt = activity ? Number(activity.sentAt || 0) : 0;
            if (sentAt > Number(record && record.lastActivityAt || 0)) {
                writeRecord({ lastActivityAt: sentAt });
            }
            newActivity = false;
            renderBadge();
        }

        function setExpanded(next) {
            if (!root) {
                return;
            }
            expanded = Boolean(next);
            fields.panel.hidden = !expanded;
            fields.trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (expanded) {
                markActivitySeen();
                fields.close.focus();
            } else {
                fields.trigger.focus();
            }
        }

        function mount() {
            root = document.createElement('div');
            root.setAttribute('data-htt-global-classroom', '');
            root.setAttribute('data-role', role);
            root.setAttribute('data-connection', role === 'host' ? 'online' : 'waiting');
            root.innerHTML = [
                '<button type="button" class="htt-global-classroom-trigger" data-field="trigger" aria-expanded="false" aria-controls="htt-global-classroom-panel">',
                '<span class="htt-global-classroom-dot" aria-hidden="true"></span>',
                '<span>수업 신호</span>',
                '<span class="htt-global-classroom-badge" data-field="badge" hidden></span>',
                '</button>',
                '<section class="htt-global-classroom-panel" id="htt-global-classroom-panel" data-field="panel" aria-label="수업 신호" hidden>',
                '<header class="htt-global-classroom-head">',
                '<div class="htt-global-classroom-title"><strong>수업 신호</strong><span data-field="session-label"></span></div>',
                '<button type="button" class="htt-global-classroom-close" data-field="close" aria-label="수업 신호 패널 접기" title="접기">×</button>',
                '</header>',
                '<p class="htt-global-classroom-status" data-field="status" role="status"></p>',
                '<section class="htt-global-classroom-section" data-field="activity-section">',
                '<h2 data-field="activity-heading">현재 활동</h2>',
                '<p class="htt-global-classroom-empty" data-field="activity-empty"></p>',
                '<div class="htt-global-classroom-activity" data-field="activity" hidden>',
                '<strong data-field="activity-title"></strong>',
                '<span data-field="activity-note" hidden></span>',
                '<a class="htt-global-classroom-link" data-field="activity-link" href="#">활동 열기</a>',
                '</div>',
                '</section>',
                '<section class="htt-global-classroom-section" data-field="student" hidden>',
                '<h2>지금 상태를 알려주세요</h2>',
                '<div class="htt-global-classroom-signal-grid" data-field="signals"></div>',
                '<p class="htt-global-classroom-my-signal" data-field="my-signal"></p>',
                '</section>',
                '<section class="htt-global-classroom-section" data-field="host" hidden>',
                '<h2>학생 신호 요약</h2>',
                '<div class="htt-global-classroom-stats" data-field="stats"></div>',
                '<div class="htt-global-classroom-host-list" data-field="host-list"></div>',
                '</section>',
                '<footer class="htt-global-classroom-footer">',
                '<a class="htt-global-classroom-link" data-field="full-link" href="#">전체 화면 열기</a>',
                '<button type="button" class="htt-global-classroom-action htt-global-classroom-disconnect" data-field="disconnect">연결 해제</button>',
                '</footer>',
                '</section>',
                '<span class="htt-global-classroom-live" data-field="live" aria-live="polite"></span>',
            ].join('');
            document.body.appendChild(root);

            fields.trigger = root.querySelector('[data-field="trigger"]');
            fields.badge = root.querySelector('[data-field="badge"]');
            fields.panel = root.querySelector('[data-field="panel"]');
            fields.close = root.querySelector('[data-field="close"]');
            fields.sessionLabel = root.querySelector('[data-field="session-label"]');
            fields.status = root.querySelector('[data-field="status"]');
            fields.activityHeading = root.querySelector('[data-field="activity-heading"]');
            fields.activityEmpty = root.querySelector('[data-field="activity-empty"]');
            fields.activity = root.querySelector('[data-field="activity"]');
            fields.activityTitle = root.querySelector('[data-field="activity-title"]');
            fields.activityNote = root.querySelector('[data-field="activity-note"]');
            fields.activityLink = root.querySelector('[data-field="activity-link"]');
            fields.student = root.querySelector('[data-field="student"]');
            fields.signals = root.querySelector('[data-field="signals"]');
            fields.mySignal = root.querySelector('[data-field="my-signal"]');
            fields.host = root.querySelector('[data-field="host"]');
            fields.stats = root.querySelector('[data-field="stats"]');
            fields.hostList = root.querySelector('[data-field="host-list"]');
            fields.fullLink = root.querySelector('[data-field="full-link"]');
            fields.disconnect = root.querySelector('[data-field="disconnect"]');
            fields.live = root.querySelector('[data-field="live"]');

            fields.sessionLabel.textContent = (role === 'host' ? '교사 · ' : '학생 · ') + record.code;
            fields.student.hidden = role !== 'participant';
            fields.host.hidden = role !== 'host';
            fields.disconnect.hidden = role !== 'participant';
            fields.fullLink.href = role === 'host'
                ? config.classroomUrl
                : config.joinUrl + '?code=' + encodeURIComponent(record.code);
            fields.fullLink.textContent = role === 'host' ? '수업 신호 화면 열기' : '참여 화면 열기';

            SIGNALS.forEach(function (item) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'htt-global-classroom-signal';
                button.setAttribute('data-signal', item.key);
                button.textContent = item.label;
                fields.signals.appendChild(button);
            });

            fields.trigger.addEventListener('click', function () { setExpanded(!expanded); });
            fields.close.addEventListener('click', function () { setExpanded(false); });
            fields.signals.addEventListener('click', function (event) {
                var button = event.target.closest('[data-signal]');
                if (button) {
                    sendSignal(button.getAttribute('data-signal') === currentSignal ? '' : button.getAttribute('data-signal'));
                }
            });
            fields.hostList.addEventListener('click', function (event) {
                var button = event.target.closest('[data-clear-user]');
                if (button) {
                    clearHostSignal(Number(button.getAttribute('data-clear-user') || 0), button);
                }
            });
            fields.disconnect.addEventListener('click', disconnectParticipant);
            root.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && expanded) {
                    setExpanded(false);
                }
            });
        }

        function renderBadge() {
            if (!root) {
                return;
            }
            var text = '';
            if (role === 'participant' && newActivity) {
                text = '새 활동';
            } else if (role === 'host') {
                var helpCount = participants.filter(function (item) { return item.signal === 'help'; }).length;
                text = helpCount ? String(helpCount) : '';
            }
            fields.badge.textContent = text;
            fields.badge.hidden = !text;
        }

        function renderActivity() {
            if (!root) {
                return;
            }
            var url = activity ? safeUrl(activity.url) : '';
            var visible = Boolean(activity && url);
            fields.activityHeading.textContent = role === 'host' ? '현재 보낸 활동' : '현재 활동';
            fields.activityEmpty.textContent = role === 'host'
                ? '학생에게 보낸 활동이 없습니다.'
                : '지금은 안내된 활동이 없습니다.';
            fields.activityEmpty.hidden = visible;
            fields.activity.hidden = !visible;
            if (!visible) {
                fields.activityTitle.textContent = '';
                fields.activityNote.textContent = '';
                fields.activityNote.hidden = true;
                fields.activityLink.removeAttribute('href');
                return;
            }
            fields.activityTitle.textContent = activity.title || '수업 활동';
            fields.activityNote.textContent = activity.note || '';
            fields.activityNote.hidden = !activity.note;
            fields.activityLink.href = url;
        }

        function updateActivity(nextActivity, announce) {
            var nextUrl = nextActivity ? safeUrl(nextActivity.url) : '';
            activity = nextActivity && nextUrl ? nextActivity : null;
            var sentAt = activity ? Number(activity.sentAt || 0) : 0;
            if (announce && sentAt > Number(record && record.lastActivityAt || 0)) {
                newActivity = true;
                if (fields.live) {
                    fields.live.textContent = '새 수업 활동이 도착했습니다: ' + (activity.title || '수업 활동');
                }
            }
            renderActivity();
            renderBadge();
        }

        function renderParticipant() {
            if (!root || role !== 'participant') {
                return;
            }
            Array.prototype.forEach.call(fields.signals.querySelectorAll('[data-signal]'), function (button) {
                button.classList.toggle('is-selected', button.getAttribute('data-signal') === currentSignal);
                button.disabled = signalSending;
            });
            fields.mySignal.textContent = currentSignal
                ? '보낸 신호: ' + signalLabel(currentSignal) + ' · 같은 버튼을 누르면 취소됩니다.'
                : '현재 신호 없음';
        }

        function hostPriority(signal) {
            var priorities = { help: 0, repeat: 1, unsure: 2, done: 3, understood: 4, ready: 5 };
            return Object.prototype.hasOwnProperty.call(priorities, signal) ? priorities[signal] : 9;
        }

        function renderHost() {
            if (!root || role !== 'host') {
                return;
            }
            var onlineCount = participants.filter(function (item) { return Boolean(item.online); }).length;
            var helpCount = participants.filter(function (item) { return item.signal === 'help'; }).length;
            var repeatCount = participants.filter(function (item) { return item.signal === 'repeat'; }).length;
            var unsureCount = participants.filter(function (item) { return item.signal === 'unsure'; }).length;
            var stats = [
                { label: '접속', count: onlineCount, className: '' },
                { label: '도움', count: helpCount, className: 'is-help' },
                { label: '다시 설명', count: repeatCount, className: '' },
                { label: '조금 어려움', count: unsureCount, className: '' },
            ];
            fields.stats.innerHTML = '';
            stats.forEach(function (item) {
                var stat = document.createElement('div');
                stat.className = 'htt-global-classroom-stat ' + item.className;
                var count = document.createElement('strong');
                count.textContent = String(item.count);
                var label = document.createElement('span');
                label.textContent = item.label;
                stat.append(count, label);
                fields.stats.appendChild(stat);
            });

            fields.hostList.innerHTML = '';
            var active = participants.filter(function (item) { return Boolean(item.signal); }).sort(function (a, b) {
                return hostPriority(a.signal) - hostPriority(b.signal)
                    || Number(b.signalAt || 0) - Number(a.signalAt || 0)
                    || String(a.name || '').localeCompare(String(b.name || ''), 'ko');
            });
            if (!active.length) {
                var empty = document.createElement('p');
                empty.className = 'htt-global-classroom-empty';
                empty.textContent = participants.length
                    ? '새로 확인할 학생 신호가 없습니다.'
                    : '아직 참여한 학생이 없습니다.';
                fields.hostList.appendChild(empty);
            }
            active.slice(0, 6).forEach(function (participant) {
                var row = document.createElement('div');
                row.className = 'htt-global-classroom-host-row';
                var name = document.createElement('span');
                name.className = 'htt-global-classroom-host-name';
                name.textContent = participant.name || ('학생 #' + participant.id);
                var signal = document.createElement('span');
                signal.className = 'htt-global-classroom-host-signal is-' + participant.signal;
                signal.textContent = signalLabel(participant.signal);
                var clear = document.createElement('button');
                clear.type = 'button';
                clear.className = 'htt-global-classroom-host-clear';
                clear.setAttribute('data-clear-user', String(participant.id));
                clear.textContent = '확인';
                row.append(name, signal, clear);
                fields.hostList.appendChild(row);
            });
            if (active.length > 6) {
                var more = document.createElement('p');
                more.className = 'htt-global-classroom-empty';
                more.textContent = '전체 화면에서 ' + (active.length - 6) + '명의 신호를 더 확인할 수 있습니다.';
                fields.hostList.appendChild(more);
            }
            renderBadge();
        }

        function applySession(data, announceActivity) {
            if (!data || data.toolType !== 'classroom' || data.status !== 'active') {
                endConnection('수업 신호 연결이 종료되었습니다.');
                return false;
            }
            var serverRole = data.role === 'host' ? 'host' : 'participant';
            if (role && role !== serverRole) {
                role = serverRole;
                if (root) {
                    root.remove();
                    root = null;
                }
                mount();
            } else {
                role = serverRole;
            }
            writeRecord({ role: role });
            var state = data.state || {};
            if (role === 'participant' && Object.prototype.hasOwnProperty.call(state, 'mySignal')) {
                currentSignal = state.mySignal || '';
            }
            updateActivity(state.activity || null, announceActivity);
            renderParticipant();
            return true;
        }

        function handleRequestFailure(error, token) {
            if (token !== lifecycle) {
                return;
            }
            if (error && [400, 401, 403, 404, 410, 429].indexOf(Number(error.status || 0)) >= 0) {
                endConnection('수업 신호 연결이 종료되었습니다.');
                return;
            }
            setConnection('offline', '네트워크 연결을 확인하고 있습니다. 신호는 연결이 복구되면 다시 사용할 수 있습니다.');
        }

        function pollSession() {
            if (!isLeader || !record || sessionInFlight || document.visibilityState === 'hidden') {
                return;
            }
            var token = lifecycle;
            sessionInFlight = true;
            request('/sessions/' + record.code, 'GET').then(function (data) {
                sessionInFlight = false;
                if (token !== lifecycle || !applySession(data, true)) {
                    return;
                }
                relay('session', data);
                if (role === 'participant' && (!realtime || !realtime.ready)) {
                    setConnection('waiting', '자동 새로고침으로 수업에 연결되어 있습니다.');
                } else if (role === 'host') {
                    setConnection('online', '사이트 전체 연결 · 학생 신호를 4초마다 확인합니다.');
                }
            }).catch(function (error) {
                sessionInFlight = false;
                handleRequestFailure(error, token);
            });
        }

        function heartbeat() {
            if (!isLeader || !record || role !== 'participant' || document.visibilityState === 'hidden') {
                return;
            }
            var token = lifecycle;
            request('/sessions/' + record.code + '/participant-state', 'POST').catch(function (error) {
                handleRequestFailure(error, token);
            });
        }

        function refreshHost() {
            if (!isLeader || !record || role !== 'host' || hostInFlight || document.visibilityState === 'hidden') {
                return;
            }
            var token = lifecycle;
            hostInFlight = true;
            request('/sessions/' + record.code + '/participants', 'GET').then(function (data) {
                hostInFlight = false;
                if (token !== lifecycle) {
                    return;
                }
                participants = Array.isArray(data.participants) ? data.participants : [];
                relay('participants', participants);
                setConnection('online', '사이트 전체 연결 · ' + participants.length + '명 참여 · 학생 신호를 4초마다 확인합니다.');
                renderHost();
            }).catch(function (error) {
                hostInFlight = false;
                handleRequestFailure(error, token);
            });
        }

        function sendSignal(signal) {
            if (!record || role !== 'participant' || signalSending) {
                return;
            }
            signalSending = true;
            renderParticipant();
            var token = lifecycle;
            request('/sessions/' + record.code + '/participant-state', 'POST', { signal: signal }).then(function (data) {
                if (token !== lifecycle) {
                    return;
                }
                currentSignal = signal;
                relay('signal', signal);
                signalSending = false;
                renderParticipant();
                if (fields.live) {
                    fields.live.textContent = signal ? (signalLabel(signal) + ' 신호를 보냈습니다.') : '신호를 취소했습니다.';
                }
                if (realtime) {
                    realtime.send({
                        t: 'participant-event',
                        event: 'signal',
                        data: {
                            signal: signal,
                            signalAt: data && data.state ? data.state.signalAt : Math.round(Date.now() / 1000),
                        },
                    });
                }
            }).catch(function (error) {
                if (token !== lifecycle) {
                    return;
                }
                signalSending = false;
                renderParticipant();
                handleRequestFailure(error, token);
                notify(error.message, 'danger');
            });
        }

        function clearHostSignal(id, button) {
            if (!record || role !== 'host' || id < 1) {
                return;
            }
            button.disabled = true;
            var token = lifecycle;
            request('/sessions/' + record.code + '/signals/clear', 'POST', { id: id }).then(function (data) {
                if (token !== lifecycle) {
                    return;
                }
                participants = Array.isArray(data.participants) ? data.participants : [];
                renderHost();
            }).catch(function (error) {
                button.disabled = false;
                handleRequestFailure(error, token);
                notify(error.message, 'danger');
            });
        }

        function disconnectParticipant() {
            if (!record || role !== 'participant') {
                return;
            }
            fields.disconnect.disabled = true;
            var code = record.code;
            rememberDismissedCode(code);
            request('/sessions/' + code + '/participant-state', 'POST', { signal: '' }).catch(function () {
                // Local disconnect must remain available during a network outage.
            }).then(function () {
                endConnection('이 브라우저의 수업 신호 연결을 해제했습니다.');
            });
        }

        function startRealtime() {
            if (!isLeader || !window.HttRealtime || !record) {
                setConnection('waiting', role === 'host'
                    ? '자동 새로고침으로 학생 신호를 확인합니다.'
                    : '자동 새로고침으로 수업에 연결되어 있습니다.');
                return;
            }
            var token = lifecycle;
            realtime = window.HttRealtime.connect({
                ticket: function () {
                    return request('/sessions/' + record.code + '/realtime-ticket', 'POST');
                },
                onStatus: function (status, detail) {
                    if (token !== lifecycle || !root) {
                        return;
                    }
                    if (status === 'open' && role === 'host') {
                        setConnection('online', '실시간으로 학생 신호를 받고 있습니다.');
                    } else if (status === 'open' && (!detail || detail.hostOnline !== false)) {
                        setConnection('online', '실시간으로 수업에 연결되어 있습니다.');
                    } else if (status === 'open') {
                        setConnection('waiting', '수업에는 연결되어 있고 교사 화면의 재연결을 기다리고 있습니다.');
                    } else if (status === 'fallback') {
                        setConnection('waiting', '자동 새로고침으로 수업에 연결되어 있습니다.');
                    } else if (status === 'retry') {
                        setConnection('waiting', '실시간 연결을 다시 시도하고 있습니다.');
                    }
                },
                onMessage: function (message) {
                    if (token !== lifecycle) {
                        return;
                    }
                    if (message.t === 'snapshot' && message.state) {
                        updateActivity(message.state.activity || null, true);
                        relay('activity', message.state.activity || null);
                    } else if (message.t === 'event' && message.event === 'activity') {
                        updateActivity(message.data || null, true);
                        relay('activity', message.data || null);
                    } else if (message.t === 'event' && message.event === 'signals-cleared') {
                        var clearedId = message.data ? Number(message.data.id || 0) : 0;
                        if (clearedId === 0 || clearedId === Number(config.currentUserId)) {
                            currentSignal = '';
                            renderParticipant();
                        }
                    } else if (message.t === 'participant-event' && message.event === 'signal' && role === 'host') {
                        refreshHost();
                    } else if ((message.t === 'participant-online' || message.t === 'participant-offline') && role === 'host') {
                        refreshHost();
                    } else if (message.t === 'host-online') {
                        setConnection('online', '실시간으로 수업에 연결되어 있습니다.');
                    } else if (message.t === 'host-offline') {
                        setConnection('waiting', '수업에는 연결되어 있고 교사 화면의 재연결을 기다리고 있습니다.');
                    } else if (message.t === 'closed') {
                        endConnection('수업이 종료되었습니다.');
                    }
                },
            });
        }

        function beginRuntime() {
            if (!isLeader || document.visibilityState === 'hidden') {
                setConnection('waiting', '다른 탭에서 실시간 연결을 유지하고 있습니다.');
                return;
            }
            if (role === 'participant') {
                heartbeat();
                startRealtime();
                pollTimer = window.setInterval(pollSession, PARTICIPANT_POLL_MS);
                heartbeatTimer = window.setInterval(heartbeat, PARTICIPANT_HEARTBEAT_MS);
            } else {
                refreshHost();
                startRealtime();
                pollTimer = window.setInterval(refreshHost, HOST_POLL_MS);
            }
            sessionTimer = window.setInterval(pollSession, SESSION_CHECK_MS);
        }

        function activate(nextRecord) {
            lifecycle += 1;
            var token = lifecycle;
            cleanup(false);
            record = nextRecord;
            if (!record) {
                return;
            }

            request('/sessions/' + record.code, 'GET').then(function (data) {
                if (token !== lifecycle) {
                    return;
                }
                role = data && data.role === 'host' ? 'host' : 'participant';
                mount();
                if (!applySession(data, true)) {
                    return;
                }
                if (role === 'host') {
                    setConnection('online', '사이트 전체 연결 · 학생 신호를 4초마다 확인합니다.');
                } else {
                    setConnection('waiting', '수업 연결을 준비하고 있습니다.');
                }
                beginRuntime();
            }).catch(function (error) {
                if (token !== lifecycle) {
                    return;
                }
                if (error && [400, 401, 403, 404, 410, 429].indexOf(Number(error.status || 0)) >= 0) {
                    endConnection('수업 신호 연결이 종료되었습니다.');
                    return;
                }
                retryTimer = window.setTimeout(function () {
                    if (token === lifecycle && record) {
                        activate(record);
                    }
                }, 10000);
            });
        }

        window.addEventListener('storage', function (event) {
            if (event.key === relayKey && event.newValue) {
                try { receiveRelay(JSON.parse(event.newValue)); } catch (ignore) {}
                return;
            }
            if (event.key === leaderKey) {
                updateLeadership();
                return;
            }
            if (event.key !== STORAGE_KEY) {
                return;
            }
            var next = readRecord(event.newValue);
            if (!next) {
                lifecycle += 1;
                cleanup(false);
                record = null;
                discoverActiveClassroom(true);
                return;
            }
            if (!record || next.code !== record.code || next.role !== record.role) {
                activate(next);
            } else {
                record = next;
            }
        });

        document.addEventListener('visibilitychange', function () {
            updateLeadership();
            if (document.visibilityState !== 'visible') {
                return;
            }
            if (!record) {
                discoverActiveClassroom(true);
                return;
            }
            pollSession();
            if (role === 'participant') {
                heartbeat();
            } else if (role === 'host') {
                refreshHost();
            }
        });

        updateLeadership();
        var initialRecord = readRecord();
        if (initialRecord) {
            activate(initialRecord);
        } else {
            discoverActiveClassroom();
        }
        discoveryTimer = window.setInterval(discoverActiveClassroom, DISCOVERY_POLL_MS);
        leaderTimer = window.setInterval(updateLeadership, LEADER_HEARTBEAT_MS);
        window.addEventListener('beforeunload', function () {
            releaseLeadership();
            if (leaderTimer !== null) {
                window.clearInterval(leaderTimer);
            }
            if (channel) {
                channel.close();
            }
        });
    });
})(window, document);

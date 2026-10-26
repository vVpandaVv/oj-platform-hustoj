(function () {
    'use strict';

    var CLASSROOM_SESSION_STORAGE_KEY = 'teachingTools.classroom.active.v1';
    var SIGNALS = [
        { key: 'ready', label: '준비됨' },
        { key: 'understood', label: '이해함' },
        { key: 'unsure', label: '조금 어려움' },
        { key: 'repeat', label: '다시 설명' },
        { key: 'help', label: '도움 필요' },
        { key: 'done', label: '완료' },
    ];

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-classroom-host]');
        if (!root || !window.HttStudentIdentities) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var identities = window.HttStudentIdentities;
        var setupEl = root.querySelector('[data-field="setup"]');
        var sessionEl = root.querySelector('[data-field="session"]');
        var messageEl = root.querySelector('[data-field="message"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var connectionStatusEl = root.querySelector('[data-field="connection-status"]');
        var statsEl = root.querySelector('[data-field="stats"]');
        var participantsEl = root.querySelector('[data-field="participants"]');
        var participantSummaryEl = root.querySelector('[data-field="participant-summary"]');
        var presetsEl = root.querySelector('[data-field="presets"]');
        var activitySearchInput = root.querySelector('[data-field="activity-search"]');
        var activityTitleInput = root.querySelector('[data-field="activity-title"]');
        var activityUrlInput = root.querySelector('[data-field="activity-url"]');
        var activityNoteInput = root.querySelector('[data-field="activity-note"]');
        var activityTargetIdsInput = root.querySelector('[data-field="activity-target-ids"]');
        var activityTargetModeSelect = root.querySelector('[data-field="activity-target-mode"]');
        var activityTargetsEl = root.querySelector('[data-field="activity-targets"]');
        var activityTargetLabelInput = root.querySelector('[data-field="activity-target-label"]');
        var activityTargetLabelWrap = root.querySelector('[data-field="activity-target-label-wrap"]');
        var currentActivityEl = root.querySelector('[data-field="current-activity"]');
        var currentActivityTitleEl = root.querySelector('[data-field="current-activity-title"]');
        var startButton = root.querySelector('[data-action="start"]');
        var joinModeEl = root.querySelector('[data-field="join-mode"]');
        var contextPickerEl = root.querySelector('[data-field="context-picker"]');
        var contextSelectEl = root.querySelector('[data-field="context-select"]');
        var contextStatusEl = root.querySelector('[data-field="context-status"]');
        var loadContextsButton = root.querySelector('[data-action="load-contexts"]');
        var audienceEl = root.querySelector('[data-field="audience"]');
        var audienceLabelEl = root.querySelector('[data-field="audience-label"]');
        var hostIdInput = root.querySelector('[data-field="host-id"]');
        var hostRoleSelect = root.querySelector('[data-field="host-role"]');
        var hostsEl = root.querySelector('[data-field="hosts"]');

        var joinCode = '';
        var participants = [];
        var presenceOverrides = {};
        var currentActivity = null;
        var refreshHandle = null;
        var refreshInFlight = false;
        var realtime = null;
        var contextsLoaded = false;
        var contextsLoading = false;
        var currentAudience = null;
        var sessionRole = 'owner';

        function request(path, method, body) {
            return fetch(config.restUrl + path, {
                method: method,
                headers: Object.assign(
                    { 'X-WP-Nonce': config.nonce },
                    body ? { 'Content-Type': 'application/json' } : {}
                ),
                body: body ? JSON.stringify(body) : undefined,
                credentials: 'same-origin',
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        var requestError = new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                        requestError.status = response.status;
                        throw requestError;
                    }
                    return data;
                });
            });
        }

        function savedHostSession() {
            try {
                var saved = JSON.parse(window.localStorage.getItem(CLASSROOM_SESSION_STORAGE_KEY) || '{}');
                var code = String(saved.code || '').trim().toUpperCase();
                if (
                    saved.role !== 'host'
                    || Number(saved.actorId || saved.userId || 0) !== Number(config.currentUserId || 0)
                    || !/^[A-Z0-9]{6}$/.test(code)
                ) {
                    return null;
                }
                return { code: code, lastActivityAt: Number(saved.lastActivityAt || 0) };
            } catch (error) {
                return null;
            }
        }

        function rememberHostSession(code) {
            var saved = savedHostSession() || {};
            try {
                window.localStorage.setItem(CLASSROOM_SESSION_STORAGE_KEY, JSON.stringify({
                    code: code,
                    actorId: Number(config.currentUserId || 0),
                    role: 'host',
                    storedAt: Date.now(),
                    lastActivityAt: saved.code === code ? Number(saved.lastActivityAt || 0) : 0,
                }));
            } catch (error) {
                // The full host page remains usable without local storage.
            }
        }

        function forgetHostSession(code) {
            try {
                var saved = JSON.parse(window.localStorage.getItem(CLASSROOM_SESSION_STORAGE_KEY) || '{}');
                if (saved.role === 'host' && (!code || saved.code === code)) {
                    window.localStorage.removeItem(CLASSROOM_SESSION_STORAGE_KEY);
                }
            } catch (error) {
                // Nothing else to clean up when storage is blocked.
            }
        }

        function showMessage(text, type) {
            messageEl.textContent = text || '';
            messageEl.className = 'htt-classroom-message is-' + (type || 'info');
            messageEl.hidden = !text;
        }

        function selectedJoinMode() {
            var selected = joinModeEl.querySelector('input[name="htt-classroom-join-mode"]:checked');
            return selected ? selected.value : 'code';
        }

        function selectedContext() {
            if (!contextSelectEl.value) {
                return null;
            }
            try {
                var context = JSON.parse(contextSelectEl.value);
                if (!context.type || !context.id || !context.revision) {
                    return null;
                }
                return context;
            } catch (error) {
                return null;
            }
        }

        function loadContexts() {
            if (contextsLoading) {
                return;
            }
            contextsLoading = true;
            loadContextsButton.disabled = true;
            contextStatusEl.textContent = '학급·그룹 목록을 불러오고 있습니다.';
            var previousValue = contextSelectEl.value;

            request('/contexts', 'GET').then(function (data) {
                contextSelectEl.innerHTML = '';
                var empty = document.createElement('option');
                empty.value = '';
                empty.textContent = '학급/그룹 선택';
                contextSelectEl.appendChild(empty);

                var items = Array.isArray(data.items) ? data.items : [];
                items.forEach(function (item) {
                    var option = document.createElement('option');
                    option.value = JSON.stringify({
                        type: item.type,
                        id: item.id,
                        revision: item.revision,
                        label: item.label,
                    });
                    option.textContent = (item.type === 'group' ? '그룹 · ' : '학급 · ') + item.label;
                    contextSelectEl.appendChild(option);
                });
                if (previousValue && Array.prototype.some.call(contextSelectEl.options, function (option) {
                    return option.value === previousValue;
                })) {
                    contextSelectEl.value = previousValue;
                }
                contextsLoaded = true;
                contextsLoading = false;
                loadContextsButton.disabled = false;
                contextStatusEl.textContent = items.length
                    ? '대상을 선택하세요. 수업 시작 시점의 명단이 자동 등록됩니다.'
                    : '자동 참여로 사용할 학급이나 그룹이 없습니다.';
            }).catch(function (error) {
                contextsLoaded = false;
                contextsLoading = false;
                loadContextsButton.disabled = false;
                contextStatusEl.textContent = error.message;
            });
        }

        function renderJoinMode() {
            var contextMode = selectedJoinMode() === 'context';
            contextPickerEl.hidden = !contextMode;
            if (contextMode && !contextsLoaded) {
                loadContexts();
            }
        }

        function renderAudience(state) {
            var audience = state && state.audience && state.audience.mode === 'context'
                ? state.audience
                : null;
            currentAudience = audience;
            audienceEl.hidden = !audience;
            audienceLabelEl.textContent = audience
                ? ((audience.label || (audience.type === 'group' ? '지정 그룹' : '지정 학급'))
                    + ' · ' + Number(audience.memberCount || 0) + '명')
                : '';
        }

        function signalLabel(key) {
            var found = SIGNALS.find(function (signal) { return signal.key === key; });
            return found ? found.label : '신호 없음';
        }

        function validSignal(key) {
            return key === '' || SIGNALS.some(function (signal) { return signal.key === key; });
        }

        function isOnline(participant) {
            var override = presenceOverrides[String(participant.id)];
            if (override && Date.now() - override.at < 12000) {
                return override.online;
            }
            return Boolean(participant.online);
        }

        function renderStats() {
            var onlineCount = participants.filter(isOnline).length;
            var counts = {};
            SIGNALS.forEach(function (signal) { counts[signal.key] = 0; });
            participants.forEach(function (participant) {
                if (counts[participant.signal] !== undefined) {
                    counts[participant.signal] += 1;
                }
            });

            statsEl.innerHTML = '';
            var items = [{ key: 'online', label: '접속', count: onlineCount }].concat(SIGNALS.map(function (signal) {
                return { key: signal.key, label: signal.label, count: counts[signal.key] };
            }));

            items.forEach(function (item) {
                var stat = document.createElement('div');
                stat.className = 'htt-classroom-stat is-' + item.key;
                var count = document.createElement('strong');
                count.textContent = String(item.count);
                var label = document.createElement('span');
                label.textContent = item.label;
                stat.append(count, label);
                statsEl.appendChild(stat);
            });
        }

        function formatLastSeen(timestamp) {
            if (!timestamp) {
                return '확인 전';
            }
            var seconds = Math.max(0, Math.round(Date.now() / 1000 - Number(timestamp)));
            if (seconds < 20) {
                return '방금 확인';
            }
            if (seconds < 60) {
                return seconds + '초 전';
            }
            return Math.floor(seconds / 60) + '분 전';
        }

        function clearSignal(id) {
            request('/sessions/' + joinCode + '/signals/clear', 'POST', { id: id }).then(function (data) {
                participants = data.participants || [];
                renderParticipants();
                if (realtime) {
                    realtime.send({ t: 'event', event: 'signals-cleared', data: { id: id } });
                }
            }).catch(function (error) {
                showMessage(error.message, 'danger');
            });
        }

        function renderParticipants() {
            renderStats();
            participantsEl.innerHTML = '';
            var onlineCount = participants.filter(isOnline).length;
            participantSummaryEl.textContent = participants.length
                ? (currentAudience ? '대상 ' + participants.length + '명' : participants.length + '명 참여')
                    + ' · ' + onlineCount + '명 접속'
                : '아직 참여한 학생이 없습니다.';

            if (!participants.length) {
                renderActivityTargets();
                var empty = document.createElement('p');
                empty.className = 'ojp-empty-state';
                empty.textContent = '참여 학생이 없습니다.';
                participantsEl.appendChild(empty);
                return;
            }

            var sorted = participants.slice().sort(function (a, b) {
                if (a.signal === 'help' && b.signal !== 'help') { return -1; }
                if (b.signal === 'help' && a.signal !== 'help') { return 1; }
                if (isOnline(a) !== isOnline(b)) { return isOnline(a) ? -1 : 1; }
                return String(a.name).localeCompare(String(b.name), 'ko');
            });

            var table = document.createElement('table');
            table.className = 'htt-classroom-table';
            var head = document.createElement('thead');
            var headRow = document.createElement('tr');
            ['학생', '접속', '현재 신호', '마지막 확인', ''].forEach(function (text) {
                var th = document.createElement('th');
                th.scope = 'col';
                th.textContent = text;
                headRow.appendChild(th);
            });
            head.appendChild(headRow);
            table.appendChild(head);

            var body = document.createElement('tbody');
            sorted.forEach(function (participant) {
                var row = document.createElement('tr');
                if (participant.signal === 'help') {
                    row.classList.add('is-help');
                }

                var name = document.createElement('th');
                name.scope = 'row';
                name.textContent = participant.name;

                var presence = document.createElement('td');
                var presenceBadge = document.createElement('span');
                presenceBadge.className = 'htt-classroom-presence ' + (isOnline(participant) ? 'is-online' : 'is-offline');
                presenceBadge.textContent = isOnline(participant) ? '접속' : '연결 끊김';
                presence.appendChild(presenceBadge);

                var signal = document.createElement('td');
                var signalBadge = document.createElement('span');
                signalBadge.className = 'htt-classroom-signal is-' + (participant.signal || 'none');
                signalBadge.textContent = signalLabel(participant.signal);
                signal.appendChild(signalBadge);

                var lastSeen = document.createElement('td');
                lastSeen.textContent = formatLastSeen(participant.lastSeenAt);

                var action = document.createElement('td');
                if (participant.signal) {
                    var clearButton = document.createElement('button');
                    clearButton.type = 'button';
                    clearButton.className = 'ojp-button ojp-button--plain ojp-button--small';
                    clearButton.textContent = '확인';
                    clearButton.addEventListener('click', function () { clearSignal(participant.id); });
                    action.appendChild(clearButton);
                }

                row.append(name, presence, signal, lastSeen, action);
                body.appendChild(row);
            });
            table.appendChild(body);
            participantsEl.appendChild(table);
            renderActivityTargets();
        }

        function selectedActivityIds() {
            if (activityTargetModeSelect.value !== 'selected') {
                return [];
            }
            return Array.prototype.map.call(
                activityTargetsEl.querySelectorAll('input[type="checkbox"]:checked'),
                function (input) { return Number(input.value); }
            ).filter(function (id) { return Number.isInteger(id) && id > 0; });
        }

        function renderActivityTargets() {
            var selected = {};
            selectedActivityIds().forEach(function (id) { selected[String(id)] = true; });
            activityTargetsEl.innerHTML = '';
            participants.slice().sort(function (a, b) {
                return String(a.name).localeCompare(String(b.name), 'ko');
            }).forEach(function (participant) {
                var label = document.createElement('label');
                var input = document.createElement('input');
                input.type = 'checkbox';
                input.value = String(participant.id);
                input.checked = Boolean(selected[String(participant.id)]);
                var text = document.createElement('span');
                text.textContent = participant.name + ' · ID ' + participant.id;
                label.append(input, text);
                activityTargetsEl.appendChild(label);
            });
            var selectedMode = activityTargetModeSelect.value === 'selected';
            activityTargetsEl.hidden = !selectedMode;
            activityTargetLabelWrap.hidden = !selectedMode;
            activityTargetIdsInput.value = selectedActivityIds().join(',');
        }

        function refreshParticipants() {
            if (!joinCode || refreshInFlight) {
                return;
            }
            refreshInFlight = true;
            request('/sessions/' + joinCode + '/participants', 'GET').then(function (data) {
                participants = data.participants || [];
                refreshInFlight = false;
                renderParticipants();
            }).catch(function (error) {
                refreshInFlight = false;
                if (error && [400, 401, 403, 404, 410].indexOf(Number(error.status || 0)) >= 0) {
                    stopSessionUi();
                    showMessage('수업 신호 연결이 종료되었습니다.', 'info');
                    return;
                }
                showMessage(error.message, 'danger');
            });
        }

        function setPresence(id, online) {
            presenceOverrides[String(id)] = { online: online, at: Date.now() };
            var exists = participants.some(function (participant) {
                return Number(participant.id) === Number(id);
            });
            if (exists) {
                renderParticipants();
            } else if (online) {
                refreshParticipants();
            }
        }

        function applySignalEvent(id, data) {
            var nextSignal = data && typeof data.signal === 'string' ? data.signal : '';
            if (!validSignal(nextSignal)) {
                refreshParticipants();
                return;
            }
            var participant = participants.find(function (item) {
                return Number(item.id) === Number(id);
            });
            if (!participant) {
                refreshParticipants();
                return;
            }
            participant.signal = nextSignal;
            participant.signalAt = nextSignal ? Math.round(Date.now() / 1000) : 0;
            participant.lastSeenAt = Math.round(Date.now() / 1000);
            participant.online = true;
            setPresence(id, true);
        }

        function renderActivity() {
            currentActivityEl.hidden = !currentActivity;
            var targetLabel = currentActivity && currentActivity.target ? currentActivity.target.label : '';
            currentActivityTitleEl.textContent = currentActivity
                ? currentActivity.title + (targetLabel ? ' · ' + targetLabel : '')
                : '';
        }

        function renderHosts(hosts) {
            hostsEl.innerHTML = '';
            (hosts || []).forEach(function (host) {
                var row = document.createElement('div');
                row.className = 'htt-classroom-collaborator';
                var label = document.createElement('span');
                var roleLabels = { owner: '소유자', cohost: '공동 진행', observer: '관찰' };
                label.textContent = host.name + ' · ' + (roleLabels[host.role] || host.role) + ' · ID ' + host.id;
                row.appendChild(label);
                if (sessionRole === 'owner' && host.role !== 'owner') {
                    var transfer = document.createElement('button');
                    transfer.type = 'button';
                    transfer.className = 'ojp-button ojp-button--plain ojp-button--small';
                    transfer.textContent = '진행권 넘기기';
                    transfer.addEventListener('click', function () {
                        request('/sessions/' + joinCode + '/hosts/transfer', 'POST', { id: host.id }).then(function () {
                            sessionRole = 'cohost';
                            loadHosts();
                            showMessage('진행권을 넘겼습니다.', 'success');
                        }).catch(function (error) { showMessage(error.message, 'danger'); });
                    });
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'ojp-button ojp-button--danger ojp-button--small';
                    remove.textContent = '제거';
                    remove.addEventListener('click', function () {
                        request('/sessions/' + joinCode + '/hosts', 'DELETE', { id: host.id }).then(function (data) {
                            renderHosts(data.hosts || []);
                        }).catch(function (error) { showMessage(error.message, 'danger'); });
                    });
                    row.append(transfer, remove);
                }
                hostsEl.appendChild(row);
            });
        }

        function loadHosts() {
            if (!joinCode) {
                return;
            }
            request('/sessions/' + joinCode + '/hosts', 'GET').then(function (data) {
                renderHosts(data.hosts || []);
            }).catch(function () {
                hostsEl.innerHTML = '';
            });
        }

        function participantActivity(activity) {
            if (!activity) {
                return null;
            }
            return {
                id: String(activity.id || ''),
                title: String(activity.title || ''),
                url: String(activity.url || ''),
                note: String(activity.note || ''),
                sentAt: Number(activity.sentAt || 0),
            };
        }

        function publishActivity(activity, delivery, publicActivity) {
            currentActivity = activity;
            renderActivity();
            if (realtime) {
                delivery = delivery || (activity && activity.target) || { type: 'all', ids: [] };
                var message = {
                    t: delivery.type === 'ids' ? 'targeted-event' : 'event',
                    event: 'activity',
                    data: publicActivity === undefined ? participantActivity(activity) : publicActivity,
                };
                if (message.t === 'targeted-event') {
                    message.viewerUids = Array.isArray(delivery.ids) ? delivery.ids : [];
                }
                realtime.send(message);
            }
        }

        function updateActivity(clear) {
            var body = clear ? { clear: true } : {
                title: (activityTitleInput.value || '').trim(),
                url: (activityUrlInput.value || '').trim(),
                note: (activityNoteInput.value || '').trim(),
                targetIds: selectedActivityIds(),
                targetLabel: (activityTargetLabelInput.value || '').trim(),
            };
            request('/sessions/' + joinCode + '/activity', 'POST', body).then(function (data) {
                publishActivity(data.activity || null, data.delivery || null, data.participantActivity || null);
                showMessage(clear ? '활동 안내를 내렸습니다.' : '학생 화면에 활동을 보냈습니다.', 'success');
            }).catch(function (error) {
                showMessage(error.message, 'danger');
            });
        }

        function startRealtime() {
            if (!window.HttRealtime || !joinCode) {
                connectionStatusEl.textContent = '자동 새로고침으로 상태를 확인합니다.';
                return;
            }
            if (realtime) {
                realtime.stop();
            }
            realtime = window.HttRealtime.connect({
                ticket: function () {
                    return request('/sessions/' + joinCode + '/realtime-ticket', 'POST');
                },
                onStatus: function (status) {
                    if (status === 'open') {
                        connectionStatusEl.textContent = '실시간 연결됨';
                        if (currentActivity) {
                            publishActivity(currentActivity, currentActivity.target || null);
                        }
                    } else if (status === 'fallback') {
                        connectionStatusEl.textContent = '자동 새로고침으로 상태를 확인합니다.';
                    } else if (status === 'retry') {
                        connectionStatusEl.textContent = '실시간 연결을 다시 시도하고 있습니다.';
                    }
                },
                onMessage: function (message) {
                    if (message.t === 'ready' && Array.isArray(message.viewerUids)) {
                        message.viewerUids.forEach(function (userId) { setPresence(userId, true); });
                    } else if (message.t === 'participant-online') {
                        setPresence(message.uid, true);
                    } else if (message.t === 'participant-offline') {
                        setPresence(message.uid, false);
                    } else if (message.t === 'participant-event' && message.event === 'signal') {
                        applySignalEvent(message.uid, message.data || {});
                    }
                },
            });
        }

        function openSession(data, restored) {
            joinCode = String(data.joinCode || '').toUpperCase();
            participants = [];
            presenceOverrides = {};
            currentActivity = data.state && data.state.activity ? data.state.activity : null;
            sessionRole = data.hostRole || 'owner';
            joinCodeEl.textContent = joinCode;
            setupEl.hidden = true;
            sessionEl.hidden = false;
            startButton.disabled = false;
            rememberHostSession(joinCode);
            renderAudience(data.state || {});
            renderParticipants();
            renderActivity();
            loadHosts();
            if (refreshHandle !== null) {
                window.clearInterval(refreshHandle);
            }
            refreshParticipants();
            refreshHandle = window.setInterval(refreshParticipants, 5000);
            startRealtime();
            showMessage(restored ? '진행 중인 수업 신호에 다시 연결했습니다.' : '', restored ? 'success' : 'info');
        }

        function restoreHostSession() {
            var saved = savedHostSession();
            if (!saved) {
                return;
            }
            startButton.disabled = true;
            request('/sessions/' + saved.code, 'GET').then(function (data) {
                if (data.toolType !== 'classroom' || data.role !== 'host' || data.status !== 'active') {
                    forgetHostSession(saved.code);
                    startButton.disabled = false;
                    return;
                }
                openSession(data, true);
            }).catch(function (error) {
                startButton.disabled = false;
                if (error && [400, 401, 403, 404, 410].indexOf(Number(error.status || 0)) >= 0) {
                    forgetHostSession(saved.code);
                    return;
                }
                showMessage('진행 중인 수업을 자동으로 확인하지 못했습니다. 잠시 뒤 새로고침해 주세요.', 'danger');
            });
        }

        function stopSessionUi() {
            var endedCode = joinCode;
            if (refreshHandle !== null) {
                window.clearInterval(refreshHandle);
                refreshHandle = null;
            }
            if (realtime) {
                realtime.stop();
                realtime = null;
            }
            joinCode = '';
            participants = [];
            presenceOverrides = {};
            currentActivity = null;
            forgetHostSession(endedCode);
            renderAudience({});
            sessionEl.hidden = true;
            setupEl.hidden = false;
            startButton.disabled = false;
            renderParticipants();
            renderActivity();
        }

        startButton.addEventListener('click', function () {
            var payload = {
                toolType: 'classroom',
                mode: 'live',
                state: {
                    activity: null,
                    nameMode: identities.selectedMode(root),
                },
            };
            if (selectedJoinMode() === 'context') {
                var context = selectedContext();
                if (!context) {
                    showMessage('자동 참여시킬 학급이나 그룹을 선택해 주세요.', 'danger');
                    contextSelectEl.focus();
                    return;
                }
                payload.context = context;
                payload.autoOpen = root.querySelector('[data-field="audience-auto-open"]').checked;
            }

            startButton.disabled = true;
            showMessage('', 'info');
            request('/sessions', 'POST', payload).then(function (data) {
                openSession(data, false);
            }).catch(function (error) {
                startButton.disabled = false;
                showMessage(error.message, 'danger');
            });
        });

        root.querySelector('[data-action="copy-link"]').addEventListener('click', function () {
            var link = config.joinUrl + '?code=' + joinCode;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(link).then(function () {
                    showMessage('참여 링크를 복사했습니다.', 'success');
                }).catch(function () { window.prompt('참여 링크', link); });
            } else {
                window.prompt('참여 링크', link);
            }
        });

        root.querySelector('[data-action="download-report"]').addEventListener('click', function () {
            if (joinCode && config.reportUrl) {
                window.location.href = config.reportUrl + '&code=' + encodeURIComponent(joinCode);
            }
        });

        root.querySelector('[data-action="end"]').addEventListener('click', function () {
            if (realtime) {
                realtime.send({ t: 'close' });
            }
            request('/sessions/' + joinCode + '/end', 'POST').catch(function () {}).then(function () {
                stopSessionUi();
                showMessage('수업을 종료했습니다.', 'success');
            });
        });

        root.querySelector('[data-action="clear-all"]').addEventListener('click', function () {
            clearSignal(0);
        });
        root.querySelector('[data-action="send-activity"]').addEventListener('click', function () {
            updateActivity(false);
        });
        root.querySelector('[data-action="clear-activity"]').addEventListener('click', function () {
            updateActivity(true);
        });
        root.querySelector('[data-action="host-add"]').addEventListener('click', function () {
            var id = Number(hostIdInput.value || 0);
            if (!joinCode || id < 1) {
                showMessage('공동 교사의 사용자 ID를 입력하세요.', 'danger');
                return;
            }
            request('/sessions/' + joinCode + '/hosts', 'POST', { id: id, role: hostRoleSelect.value }).then(function (data) {
                hostIdInput.value = '';
                renderHosts(data.hosts || []);
            }).catch(function (error) { showMessage(error.message, 'danger'); });
        });
        root.querySelector('[data-action="refresh-roster"]').addEventListener('click', function () {
            request('/sessions/' + joinCode + '/roster-refresh', 'GET').then(function (diff) {
                var added = (diff.addedIds || []).length;
                var removed = (diff.removedIds || []).length;
                if (!added && !removed) {
                    showMessage('현재 명단과 같습니다.', 'success');
                    return;
                }
                var message = '추가 ' + added + '명, 제외 ' + removed + '명입니다. 적용할까요?';
                if (!window.confirm(message)) {
                    return;
                }
                return request('/sessions/' + joinCode + '/roster-refresh', 'POST').then(function (applied) {
                    showMessage('명단을 ' + applied.memberCount + '명으로 갱신했습니다.', 'success');
                    refreshParticipants();
                });
            }).catch(function (error) { showMessage(error.message, 'danger'); });
        });
        joinModeEl.addEventListener('change', renderJoinMode);
        loadContextsButton.addEventListener('click', loadContexts);
        identities.bindMode(root);
        activityTargetModeSelect.addEventListener('change', renderActivityTargets);
        activityTargetsEl.addEventListener('change', function () {
            activityTargetIdsInput.value = selectedActivityIds().join(',');
        });

        function loadActivities() {
            var search = activitySearchInput ? activitySearchInput.value.trim() : '';
            request('/integrations/activities?search=' + encodeURIComponent(search), 'GET').then(function (data) {
                var presets = Array.isArray(data.items) ? data.items : [];
                presetsEl.innerHTML = '';
                presetsEl.hidden = presets.length === 0;
                presets.forEach(function (preset) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'ojp-button ojp-button--tinted ojp-button--small';
                button.textContent = preset.title + (preset.source ? ' · ' + preset.source : '');
                button.addEventListener('click', function () {
                    activityTitleInput.value = preset.title;
                    activityUrlInput.value = preset.url;
                    activityNoteInput.value = preset.description || '';
                    activityNoteInput.focus();
                });
                presetsEl.appendChild(button);
                });
            }).catch(function (error) {
                presetsEl.hidden = true;
                showMessage(error.message, 'danger');
            });
        }
        root.querySelector('[data-action="search-activities"]').addEventListener('click', loadActivities);
        activitySearchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                loadActivities();
            }
        });
        loadActivities();

        var usageButton = root.querySelector('[data-action="show-usage"]');
        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('수업 신호 사용법', '<ul>'
                    + '<li>실시간 수업을 시작하고 참여 코드나 링크를 학생에게 전달하세요.</li>'
                    + '<li>학급·그룹 자동 참여를 선택하면 수업 시작 시점의 명단을 등록하고, 대상 학생 화면이 수업을 자동으로 찾습니다.</li>'
                    + '<li>자동 참여 수업의 코드는 지정 명단에 포함된 학생만 사용할 수 있습니다.</li>'
                    + '<li>한 번 참여한 학생은 사이트의 다른 화면에서도 수업 신호 바를 사용할 수 있습니다.</li>'
                    + '<li>교사도 다른 화면에서 접속 수와 주요 신호를 확인할 수 있습니다.</li>'
                    + '<li>활동 주소를 보내면 학생 화면에 바로 열기 버튼이 나타납니다.</li>'
                    + '<li>확인한 신호는 학생별로 지우거나 한 번에 모두 지울 수 있습니다.</li>'
                    + '</ul>');
            });
        }

        renderJoinMode();
        restoreHostSession();
    });
})();

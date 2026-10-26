(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-picker-host]');
        if (!root || !window.HttPickerEngine || !window.HttStudentIdentities) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var engine = window.HttPickerEngine;
        var identities = window.HttStudentIdentities;

        var setup = root.querySelector('[data-field="setup"]');
        var messagesEl = root.querySelector('[data-field="messages"]');
        var resultEl = root.querySelector('[data-field="result"]');
        var joinInfo = root.querySelector('[data-field="join-info"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var poolCountEl = root.querySelector('[data-field="pool-count"]');
        var latestEl = root.querySelector('[data-field="latest"]');
        var historyEl = root.querySelector('[data-field="history"]');

        var contextSelect = root.querySelector('[data-field="context-select"]');
        var studentsInput = root.querySelector('[data-field="students"]');
        var pickCountInput = root.querySelector('[data-field="pick-count"]');
        var excludeDrawnInput = root.querySelector('[data-field="exclude-drawn"]');

        var loadContextsButton = root.querySelector('[data-action="load-contexts"]');
        var loadRosterButton = root.querySelector('[data-action="load-roster"]');
        var startButton = root.querySelector('[data-action="start"]');
        var drawButton = root.querySelector('[data-action="draw"]');
        var restartButton = root.querySelector('[data-action="restart"]');
        var copyLinkButton = root.querySelector('[data-action="copy-link"]');
        var usageButton = root.querySelector('[data-action="show-usage"]');

        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('뽑기 사용법', '<ul>'
                    + '<li>명단을 불러오거나 입력한 뒤 인원수를 정하고 "뽑기 시작"을 누르세요.</li>'
                    + '<li>"이미 뽑힌 학생 제외"를 켜면 모두 뽑힐 때까지 중복 없이 뽑습니다.</li>'
                    + '<li>동시 참여 모드에서는 학생 화면에도 결과가 표시되고, 뽑힌 학생 화면엔 "당신이 뽑혔습니다"가 나타납니다.</li>'
                    + '<li>명단이 비거나 권한 오류가 뜨면 사용자 권한 화면에서 "교실 도구 진행" 권한 배정과, 이 계정이 학급/그룹의 담당 교사로 등록돼 있는지 확인하세요.</li>'
                    + '</ul>');
            });
        }

        var rosterEntries = [];
        var joinCode = null;
        var drawing = false;

        var pickerState = {
            excludeDrawn: true,
            pickCount: 1,
            totalRoster: [],
            pool: [],
            history: [],
        };

        function request(path, method, body) {
            try {
                if (window.HttAudience) { body = window.HttAudience.prepare(root, path, method, body); }
            } catch (error) { return Promise.reject(error); }
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
                        throw new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                    }
                    if (window.HttAudience) { window.HttAudience.completed(root, path, data); }
                    return data;
                });
            });
        }

        function showMessages(messages) {
            messagesEl.innerHTML = '';
            messages.forEach(function (message) {
                var item = document.createElement('div');
                item.className = 'message ' + (message.type || 'info');
                item.textContent = message.text;
                messagesEl.appendChild(item);
            });
        }

        function loadContexts() {
            request('/contexts', 'GET').then(function (data) {
                contextSelect.innerHTML = '';
                var empty = document.createElement('option');
                empty.value = '';
                empty.textContent = '학급/그룹 선택';
                contextSelect.appendChild(empty);

                (data.items || []).forEach(function (item) {
                    var option = document.createElement('option');
                    option.value = JSON.stringify({ type: item.type, id: item.id, revision: item.revision });
                    option.textContent = item.label;
                    contextSelect.appendChild(option);
                });
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        }

        function loadRoster() {
            if (!contextSelect.value) {
                showMessages([{ type: 'warning', text: '학급/그룹을 선택하세요.' }]);
                return;
            }
            var selected = JSON.parse(contextSelect.value);
            // See host-seating.js's loadRoster() for why this can't be a
            // plain '?revision=' — on plain-permalink sites restUrl already
            // contains its own '?rest_route=...' query string.
            var querySep = config.restUrl.indexOf('?') === -1 ? '?' : '&';
            request(
                '/contexts/' + selected.type + '/' + selected.id + '/roster' + querySep + 'revision=' + encodeURIComponent(selected.revision),
                'GET'
            ).then(function (data) {
                rosterEntries = identities.fromRoster(data.students || [], identities.selectedMode(root));
                studentsInput.value = identities.toText(rosterEntries);
                showMessages([{ type: 'info', text: rosterEntries.length + '명의 명단을 불러왔습니다.' }]);
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        }

        function renderPoolCount() {
            poolCountEl.textContent = '남은 인원: ' + pickerState.pool.length + ' / 전체: ' + pickerState.totalRoster.length + '명';
        }

        function renderHistory() {
            historyEl.innerHTML = '';
            pickerState.history.slice().reverse().forEach(function (entry, index) {
                var row = document.createElement('div');
                row.textContent = (pickerState.history.length - index) + '회차: ' + entry.map(function (e) { return e.name; }).join(', ');
                historyEl.appendChild(row);
            });
        }

        function renderLatest(picks) {
            latestEl.textContent = picks.map(function (entry) { return entry.name; }).join(', ');
        }

        function pushState() {
            if (!joinCode) {
                return;
            }
            request('/sessions/' + joinCode + '/state', 'POST', {
                state: {
                    pickCount: pickerState.pickCount,
                    excludeDrawn: pickerState.excludeDrawn,
                    pool: pickerState.pool,
                    latest: pickerState.history.length ? pickerState.history[pickerState.history.length - 1] : [],
                    drawCount: pickerState.history.length,
                },
            }).catch(function () {
                // Best-effort sync; the host's own view stays authoritative regardless.
            });
        }

        function animateDraw(picks, onDone) {
            if (drawing) {
                return;
            }
            drawing = true;
            drawButton.disabled = true;

            var pool = pickerState.totalRoster.length ? pickerState.totalRoster : picks;
            var ticks = 14;
            var tick = 0;
            var timer = window.setInterval(function () {
                tick += 1;
                var shuffled = engine.draw(pool, picks.length);
                renderLatest(shuffled.length ? shuffled : picks);
                if (tick >= ticks) {
                    window.clearInterval(timer);
                    drawing = false;
                    drawButton.disabled = false;
                    renderLatest(picks);
                    onDone();
                }
            }, 80);
        }

        startButton.addEventListener('click', function () {
            var entries = identities.fromText(studentsInput.value, rosterEntries);
            if (!entries.length) {
                showMessages([{ type: 'warning', text: '학생 이름을 한 명 이상 입력하세요.' }]);
                return;
            }

            rosterEntries = entries;
            pickerState.totalRoster = identities.publicEntries(entries);
            pickerState.pool = pickerState.totalRoster.slice();
            pickerState.pickCount = Math.max(1, parseInt(pickCountInput.value, 10) || 1);
            pickerState.excludeDrawn = excludeDrawnInput.checked;
            pickerState.history = [];

            var mode = root.querySelector('input[name="htt-picker-mode"]:checked').value;

            request('/sessions', 'POST', {
                toolType: 'picker',
                mode: mode,
                state: {
                    pickCount: pickerState.pickCount,
                    excludeDrawn: pickerState.excludeDrawn,
                    pool: pickerState.pool,
                    latest: [],
                    drawCount: 0,
                },
            }).then(function (data) {
                joinCode = data.joinCode;
                setup.hidden = true;
                resultEl.hidden = false;
                latestEl.textContent = '';
                historyEl.innerHTML = '';
                renderPoolCount();

                if (mode === 'live') {
                    joinInfo.hidden = false;
                    joinCodeEl.textContent = joinCode;
                } else {
                    joinInfo.hidden = true;
                }
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        });

        drawButton.addEventListener('click', function () {
            var sourcePool = pickerState.excludeDrawn ? pickerState.pool : pickerState.totalRoster;
            if (!sourcePool.length) {
                showMessages([{ type: 'warning', text: '더 뽑을 수 있는 학생이 없습니다.' }]);
                return;
            }

            var picks = engine.draw(sourcePool, pickerState.pickCount);
            animateDraw(picks, function () {
                if (pickerState.excludeDrawn) {
                    var pickedIds = picks.map(function (entry) { return String(entry.id); });
                    pickerState.pool = pickerState.pool.filter(function (entry) {
                        return pickedIds.indexOf(String(entry.id)) === -1;
                    });
                }
                pickerState.history.push(picks);
                renderPoolCount();
                renderHistory();
                pushState();
            });
        });

        restartButton.addEventListener('click', function () {
            var endRequest = joinCode ? request('/sessions/' + joinCode + '/end', 'POST').catch(function () {}) : Promise.resolve();
            endRequest.then(function () {
                joinCode = null;
                resultEl.hidden = true;
                setup.hidden = false;
                showMessages([]);
            });
        });

        if (copyLinkButton) {
            copyLinkButton.addEventListener('click', function () {
                if (!joinCode) {
                    return;
                }
                var link = config.joinUrl + '?code=' + joinCode;
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(link).then(function () {
                        if (window.OJP && typeof window.OJP.toast === 'function') {
                            window.OJP.toast('참여 링크를 복사했습니다');
                        }
                    }).catch(function () { window.prompt('참여 링크', link); });
                } else {
                    window.prompt('참여 링크', link);
                }
            });
        }

        loadContextsButton.addEventListener('click', loadContexts);
        loadRosterButton.addEventListener('click', loadRoster);
        identities.bindMode(root, function (mode) {
            if (!rosterEntries.length || !identities.allRoster(rosterEntries)) {
                return;
            }
            rosterEntries = identities.relabel(rosterEntries, mode);
            studentsInput.value = identities.toText(rosterEntries);
        });
        loadContexts();
    });
})();

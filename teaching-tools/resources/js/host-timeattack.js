(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-timeattack-host]');
        if (!root || !window.HttStudentIdentities) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var identities = window.HttStudentIdentities;

        var setup = root.querySelector('[data-field="setup"]');
        var messagesEl = root.querySelector('[data-field="messages"]');
        var resultEl = root.querySelector('[data-field="result"]');
        var joinInfo = root.querySelector('[data-field="join-info"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var problemResultsEl = root.querySelector('[data-field="problem-results"]');
        var selectedProblemsEl = root.querySelector('[data-field="selected-problems"]');
        var problemTitleEl = root.querySelector('[data-field="problem-title"]');
        var clockEl = root.querySelector('[data-field="clock"]');
        var solvedCountEl = root.querySelector('[data-field="solved-count"]');
        var statusListEl = root.querySelector('[data-field="status-list"]');

        var contextSelect = root.querySelector('[data-field="context-select"]');
        var studentsInput = root.querySelector('[data-field="students"]');
        var problemSearchInput = root.querySelector('[data-field="problem-search"]');
        var minutesInput = root.querySelector('[data-field="minutes"]');

        var loadContextsButton = root.querySelector('[data-action="load-contexts"]');
        var loadRosterButton = root.querySelector('[data-action="load-roster"]');
        var searchButton = root.querySelector('[data-action="search-problem"]');
        var startButton = root.querySelector('[data-action="start"]');
        var restartButton = root.querySelector('[data-action="restart"]');
        var copyLinkButton = root.querySelector('[data-action="copy-link"]');
        var usageButton = root.querySelector('[data-action="show-usage"]');

        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('문제 풀이 타임어택 사용법', '<ul>'
                    + '<li>문제 번호나 제목으로 검색해 도전할 문제를 선택하세요 — 여러 문제를 검색해 하나씩 추가할 수 있습니다.</li>'
                    + '<li>선택한 문제 목록에서 "×"를 누르면 그 문제를 뺄 수 있습니다.</li>'
                    + '<li>학급/그룹 명단을 불러오세요 — 제출 여부 확인을 위해 필요하며, 직접 입력한 명단은 쓸 수 없습니다.</li>'
                    + '<li>시간을 정하고 시작하면, 그 순간부터 낸 정답 제출만 인정됩니다.</li>'
                    + '<li>여러 문제를 선택했다면 교사 화면에 학생별로 어떤 문제를 풀었는지 표로 표시됩니다. 문제를 다 푼 학생은 완료한 순서대로 순위가 매겨집니다.</li>'
                    + '<li>이 사이트에서 채점 데이터에 접근할 수 없으면 기능을 사용할 수 없다는 안내가 뜹니다.</li>'
                    + '</ul>');
            });
        }

        var rosterEntries = [];
        var rosterIdentityEntries = [];
        var selectedContext = null;
        var selectedProblems = [];
        var joinCode = null;
        var tickHandle = null;
        var syncHandle = null;
        var statusPollHandle = null;
        var remainingSeconds = 0;
        var running = false;

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

        function formatClock(totalSeconds) {
            var seconds = Math.max(0, Math.round(totalSeconds));
            var minutes = Math.floor(seconds / 60);
            var rest = seconds % 60;
            return String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
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
                    option.value = JSON.stringify({ type: item.type, id: item.id, revision: item.revision, label: item.label });
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
            var querySep = config.restUrl.indexOf('?') === -1 ? '?' : '&';
            request(
                '/contexts/' + selected.type + '/' + selected.id + '/roster' + querySep + 'revision=' + encodeURIComponent(selected.revision),
                'GET'
            ).then(function (data) {
                selectedContext = selected;
                rosterIdentityEntries = identities.fromRoster(data.students || [], identities.selectedMode(root));
                rosterEntries = identities.publicEntries(rosterIdentityEntries);
                studentsInput.value = identities.toText(rosterIdentityEntries);
                showMessages([{ type: 'info', text: rosterEntries.length + '명의 명단을 불러왔습니다.' }]);
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        }

        function renderSelectedProblems() {
            selectedProblemsEl.innerHTML = '';
            selectedProblems.forEach(function (item) {
                var row = document.createElement('span');
                row.className = 'htt-timeattack-selected-item';
                row.textContent = item.problemId + '. ' + item.title;

                var removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.setAttribute('aria-label', '제외');
                removeButton.textContent = '×';
                removeButton.addEventListener('click', function () {
                    selectedProblems = selectedProblems.filter(function (p) {
                        return p.problemId !== item.problemId;
                    });
                    renderSelectedProblems();
                });
                row.appendChild(removeButton);
                selectedProblemsEl.appendChild(row);
            });
        }

        searchButton.addEventListener('click', function () {
            var search = (problemSearchInput.value || '').trim();
            request('/oj/problems?search=' + encodeURIComponent(search), 'GET').then(function (data) {
                problemResultsEl.innerHTML = '';
                (data.items || []).forEach(function (item) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = item.problemId + '. ' + item.title;
                    button.disabled = selectedProblems.some(function (p) { return p.problemId === item.problemId; });
                    button.addEventListener('click', function () {
                        if (!selectedProblems.some(function (p) { return p.problemId === item.problemId; })) {
                            selectedProblems.push({ problemId: item.problemId, title: item.title });
                            renderSelectedProblems();
                        }
                        problemResultsEl.innerHTML = '';
                    });
                    problemResultsEl.appendChild(button);
                });
                if (!data.items || !data.items.length) {
                    showMessages([{ type: 'warning', text: '검색 결과가 없습니다.' }]);
                }
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        });

        function rankLabel(rank) {
            if (rank === 1) {
                return '1위';
            }
            if (rank === 2) {
                return '2위';
            }
            if (rank === 3) {
                return '3위';
            }
            return rank + '위';
        }

        function renderStatusList(results, problems) {
            statusListEl.innerHTML = '';
            if (!results.length) {
                return;
            }

            var table = document.createElement('table');
            table.className = 'htt-timeattack-table';

            var thead = document.createElement('thead');
            var headRow = document.createElement('tr');
            ['순위', '이름'].forEach(function (label) {
                var th = document.createElement('th');
                th.textContent = label;
                headRow.appendChild(th);
            });
            problems.forEach(function (problem) {
                var th = document.createElement('th');
                th.textContent = String(problem.problemId);
                th.title = problem.title;
                headRow.appendChild(th);
            });
            var progressTh = document.createElement('th');
            progressTh.textContent = '진행률';
            headRow.appendChild(progressTh);
            thead.appendChild(headRow);
            table.appendChild(thead);

            var tbody = document.createElement('tbody');
            results.forEach(function (entry) {
                var row = document.createElement('tr');
                row.className = entry.finished ? 'is-finished' : '';

                var rankTd = document.createElement('td');
                rankTd.textContent = entry.rank ? rankLabel(entry.rank) : '';
                row.appendChild(rankTd);

                var nameTd = document.createElement('td');
                nameTd.className = 'htt-timeattack-name-cell';
                nameTd.textContent = entry.name;
                row.appendChild(nameTd);

                var solvedSet = {};
                (entry.solvedProblemIds || []).forEach(function (problemId) {
                    solvedSet[problemId] = true;
                });

                problems.forEach(function (problem) {
                    var td = document.createElement('td');
                    td.textContent = solvedSet[problem.problemId] ? '✓' : '';
                    row.appendChild(td);
                });

                var progressTd = document.createElement('td');
                progressTd.textContent = entry.solvedCount + ' / ' + entry.totalCount;
                row.appendChild(progressTd);

                tbody.appendChild(row);
            });
            table.appendChild(tbody);

            statusListEl.appendChild(table);
        }

        function pollStatus() {
            request('/sessions/' + joinCode + '/timeattack-status', 'GET').then(function (data) {
                renderStatusList(data.results || [], data.problems || []);
                solvedCountEl.textContent = '전체 완료: ' + data.finishedCount + ' / ' + (data.results || []).length + '명';
            }).catch(function () {
                window.clearInterval(statusPollHandle);
            });
        }

        function pushState() {
            if (!joinCode) {
                return;
            }
            request('/sessions/' + joinCode + '/state', 'POST', {
                state: {
                    problems: selectedProblems,
                    remainingSeconds: remainingSeconds,
                    running: running,
                    roster: rosterEntries,
                    context: selectedContext,
                },
            }).catch(function () {});
        }

        function tick() {
            if (!running) {
                return;
            }
            remainingSeconds = Math.max(0, remainingSeconds - 1);
            clockEl.textContent = formatClock(remainingSeconds);
            if (remainingSeconds <= 0) {
                running = false;
                pushState();
            }
        }

        function renderProblemList(container, problems) {
            container.innerHTML = '';
            var list = document.createElement('ul');
            problems.forEach(function (item) {
                var li = document.createElement('li');
                li.textContent = item.problemId + '. ' + item.title;
                list.appendChild(li);
            });
            container.appendChild(list);
        }

        startButton.addEventListener('click', function () {
            if (!selectedProblems.length) {
                showMessages([{ type: 'warning', text: '문제를 검색해 하나 이상 선택하세요.' }]);
                return;
            }
            if (!rosterEntries.length) {
                showMessages([{ type: 'warning', text: '학급/그룹 명단을 불러오세요.' }]);
                return;
            }

            var minutes = Math.max(1, parseInt(minutesInput.value, 10) || 10);
            remainingSeconds = minutes * 60;
            running = true;

            var mode = root.querySelector('input[name="htt-timeattack-mode"]:checked').value;

            request('/sessions', 'POST', {
                toolType: 'timeattack',
                mode: mode,
                context: selectedContext,
                state: {
                    problems: selectedProblems,
                    remainingSeconds: remainingSeconds,
                    running: true,
                    roster: rosterEntries,
                },
            }).then(function (data) {
                joinCode = data.joinCode;
                setup.hidden = true;
                resultEl.hidden = false;
                renderProblemList(problemTitleEl, selectedProblems);
                clockEl.textContent = formatClock(remainingSeconds);
                solvedCountEl.textContent = '';
                statusListEl.innerHTML = '';

                if (mode === 'live') {
                    joinInfo.hidden = false;
                    joinCodeEl.textContent = joinCode;
                } else {
                    joinInfo.hidden = true;
                }

                tickHandle = window.setInterval(tick, 1000);
                syncHandle = window.setInterval(pushState, 2000);
                statusPollHandle = window.setInterval(pollStatus, 3000);
                pollStatus();
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
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

        restartButton.addEventListener('click', function () {
            [tickHandle, syncHandle, statusPollHandle].forEach(function (handle) {
                if (handle) {
                    window.clearInterval(handle);
                }
            });
            var endRequest = joinCode ? request('/sessions/' + joinCode + '/end', 'POST').catch(function () {}) : Promise.resolve();
            endRequest.then(function () {
                joinCode = null;
                resultEl.hidden = true;
                setup.hidden = false;
                showMessages([]);
            });
        });

        loadContextsButton.addEventListener('click', loadContexts);
        loadRosterButton.addEventListener('click', loadRoster);
        identities.bindMode(root, function (mode) {
            if (!rosterIdentityEntries.length) {
                return;
            }
            rosterIdentityEntries = identities.relabel(rosterIdentityEntries, mode);
            rosterEntries = identities.publicEntries(rosterIdentityEntries);
            studentsInput.value = identities.toText(rosterIdentityEntries);
        });
        loadContexts();
    });
})();

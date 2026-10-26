(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-grouping-host]');
        if (!root || !window.HttGroupingEngine || !window.HttStudentIdentities) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var engine = window.HttGroupingEngine;
        var identities = window.HttStudentIdentities;

        var setup = root.querySelector('[data-field="setup"]');
        var messagesEl = root.querySelector('[data-field="messages"]');
        var resultEl = root.querySelector('[data-field="result"]');
        var joinInfo = root.querySelector('[data-field="join-info"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var groupsEl = root.querySelector('[data-field="groups"]');

        var contextSelect = root.querySelector('[data-field="context-select"]');
        var studentsInput = root.querySelector('[data-field="students"]');
        var groupCountInput = root.querySelector('[data-field="group-count"]');
        var balancedInput = root.querySelector('[data-field="balanced"]');
        var liveModeInput = root.querySelector('[data-field="live-mode-input"]');

        var loadContextsButton = root.querySelector('[data-action="load-contexts"]');
        var loadRosterButton = root.querySelector('[data-action="load-roster"]');
        var runButton = root.querySelector('[data-action="run"]');
        var restartButton = root.querySelector('[data-action="restart"]');
        var copyLinkButton = root.querySelector('[data-action="copy-link"]');
        var usageButton = root.querySelector('[data-action="show-usage"]');

        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('조 짜기 사용법', '<ul>'
                    + '<li>학급/그룹 명단을 불러오거나 직접 입력한 뒤 조 개수를 정하세요.</li>'
                    + '<li>"실력 균형 배정"을 켜면 제출 기록(해결한 문제 수)을 기준으로 스네이크 드래프트 방식으로 실력이 균형 잡힌 조를 만듭니다 — 학급에서 명단을 가져왔을 때만 사용할 수 있고, 이 사이트에서 채점 데이터에 접근할 수 없으면 안내와 함께 무작위 배정으로 대체됩니다.</li>'
                    + '<li>"동시 참여" 모드에서는 학생이 참여 코드로 들어와 자기 조를 확인할 수 있습니다.</li>'
                    + '</ul>');
            });
        }

        var rosterEntries = [];
        var selectedContext = null;
        var joinCode = null;

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
                rosterEntries = identities.fromRoster(data.students || [], identities.selectedMode(root));
                studentsInput.value = identities.toText(rosterEntries);
                liveModeInput.disabled = rosterEntries.length === 0;
                showMessages([{ type: 'info', text: rosterEntries.length + '명의 명단을 불러왔습니다.' }]);
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        }

        function renderGroups(groups, entryById) {
            groupsEl.innerHTML = '';
            groups.forEach(function (members, index) {
                var card = document.createElement('div');
                card.className = 'htt-grouping-card';

                var title = document.createElement('h3');
                title.textContent = (index + 1) + '조 (' + members.length + '명)';
                card.appendChild(title);

                var list = document.createElement('ul');
                members.forEach(function (id) {
                    var item = document.createElement('li');
                    item.textContent = entryById[String(id)].name;
                    list.appendChild(item);
                });
                card.appendChild(list);

                groupsEl.appendChild(card);
            });
        }

        runButton.addEventListener('click', function () {
            var entries = identities.fromText(studentsInput.value, rosterEntries);
            if (entries.length < 2) {
                showMessages([{ type: 'warning', text: '학생 이름을 2명 이상 입력하세요.' }]);
                return;
            }
            rosterEntries = entries;
            var entryById = entries.reduce(function (result, entry) {
                result[String(entry.id)] = entry;
                return result;
            }, {});
            var studentIds = entries.map(function (entry) { return String(entry.id); });

            var groupCount = Math.max(2, Math.min(20, parseInt(groupCountInput.value, 10) || 4));
            if (groupCount > studentIds.length) {
                showMessages([{ type: 'warning', text: '조 개수가 학생 수보다 많을 수 없습니다.' }]);
                return;
            }

            var wantsBalanced = balancedInput.checked;
            var haveRosterIds = identities.allRoster(entries) && selectedContext;

            function finish(skillById, notice) {
                var groups = engine.assign(studentIds, groupCount, skillById);
                var mode = root.querySelector('input[name="htt-grouping-mode"]:checked').value;

                var groupsState = groups.map(function (members, index) {
                    return {
                        name: (index + 1) + '조',
                        members: members.map(function (id) {
                            var entry = entryById[String(id)];
                            return { id: entry.id, name: entry.name };
                        }),
                    };
                });

                request('/sessions', 'POST', {
                    toolType: 'grouping',
                    mode: mode,
                    context: selectedContext,
                    state: { groups: groupsState },
                }).then(function (data) {
                    joinCode = data.joinCode;
                    setup.hidden = true;
                    resultEl.hidden = false;
                    renderGroups(groups, entryById);
                    if (notice) {
                        showMessages([{ type: 'info', text: notice }]);
                    }

                    if (mode === 'live') {
                        joinInfo.hidden = false;
                        joinCodeEl.textContent = joinCode;
                    } else {
                        joinInfo.hidden = true;
                    }
                }).catch(function (error) {
                    showMessages([{ type: 'warning', text: error.message }]);
                });
            }

            if (!wantsBalanced || !haveRosterIds) {
                if (wantsBalanced && !haveRosterIds) {
                    showMessages([{ type: 'warning', text: '실력 균형 배정은 학급에서 가져온 명단에서만 쓸 수 있어 무작위로 배정합니다.' }]);
                }
                finish(null, null);
                return;
            }

            var ids = entries.map(function (entry) { return Number(entry.id); });
            request('/oj/accepted-counts', 'POST', {
                ids: ids,
                context: selectedContext,
            }).then(function (data) {
                var counts = data.counts || {};
                var skillById = {};
                ids.forEach(function (id) {
                    skillById[String(id)] = counts[id] || 0;
                });
                finish(skillById, '제출 기록 기반으로 실력이 균형 잡힌 조를 만들었습니다.');
            }).catch(function () {
                showMessages([{ type: 'warning', text: '채점 데이터에 접근할 수 없어 무작위로 배정합니다.' }]);
                finish(null, null);
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
            if (!rosterEntries.length || !identities.allRoster(rosterEntries)) {
                return;
            }
            rosterEntries = identities.relabel(rosterEntries, mode);
            studentsInput.value = identities.toText(rosterEntries);
        });
        loadContexts();
    });
})();

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-ladder-host]');
        if (!root || !window.HttLadderEngine) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var engine = window.HttLadderEngine;

        var setup = root.querySelector('[data-field="setup"]');
        var messagesEl = root.querySelector('[data-field="messages"]');
        var progressEl = root.querySelector('[data-field="progress"]');
        var joinInfo = root.querySelector('[data-field="join-info"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var claimCountEl = root.querySelector('[data-field="claim-count"]');
        var columnsEl = root.querySelector('[data-field="columns"]');
        var soloAnimationEl = root.querySelector('[data-field="solo-animation"]');
        var revealEl = root.querySelector('[data-field="reveal"]');

        var participantCountInput = root.querySelector('[data-field="participant-count"]');
        var resultsInput = root.querySelector('[data-field="results"]');

        var startButton = root.querySelector('[data-action="start"]');
        var revealButton = root.querySelector('[data-action="reveal"]');
        var restartButton = root.querySelector('[data-action="restart"]');
        var copyLinkButton = root.querySelector('[data-action="copy-link"]');
        var usageButton = root.querySelector('[data-action="show-usage"]');

        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('사다리타기 사용법', '<ul>'
                    + '<li>인원수와, 그 인원수와 같은 개수의 결과 목록을 입력한 뒤 "사다리 생성"을 누르세요.</li>'
                    + '<li>동시 참여 모드에서는 학생이 각자 기기로 시작 줄을 선택합니다. "혼자 진행"에서는 교사가 직접 각 줄을 클릭합니다.</li>'
                    + '<li>줄을 선택하면 결과가 애니메이션으로 공개됩니다. "혼자 진행"에서는 결과를 확인한 뒤 "확인"을 눌러야 다음 줄을 고를 수 있습니다.</li>'
                    + '<li>"전체 결과 공개"를 누르기 전까지는 교사 화면에도 전체 결과가 보이지 않습니다 — 학생이 미리 알 수 없도록 하기 위함입니다.</li>'
                    + '</ul>');
            });
        }

        var joinCode = null;
        var pollHandle = null;
        var ladder = null; // { columnCount, results, rungs, mapping }
        var revealed = false;
        var currentMode = 'live';
        var soloRevealedColumns = {};
        var soloAnimating = false;
        var latestClaims = [];

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

        function parseResults(raw) {
            return String(raw).split(/\r?\n/).map(function (line) {
                return line.replace(/\s+/g, ' ').trim();
            }).filter(Boolean);
        }

        function renderColumns(claims) {
            latestClaims = claims || [];

            if ('solo' === currentMode) {
                renderSoloColumns();
                return;
            }

            var byColumn = {};
            (claims || []).forEach(function (claim) {
                byColumn[claim.column] = claim;
            });

            columnsEl.innerHTML = '';
            for (var c = 0; c < ladder.columnCount; c += 1) {
                var claim = byColumn[c];
                var col = document.createElement('div');
                col.className = 'htt-ladder-column' + (claim ? ' is-claimed' : '');
                col.textContent = (c + 1) + '번줄: ' + (claim ? claim.name : '비어있음');
                columnsEl.appendChild(col);
            }
            claimCountEl.textContent = (claims || []).length + ' / ' + ladder.columnCount + '명 선택 완료';
        }

        function renderSoloColumns() {
            columnsEl.innerHTML = '';
            for (var c = 0; c < ladder.columnCount; c += 1) {
                var revealedResult = soloRevealedColumns[c];
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'htt-ladder-column' + (revealedResult ? ' is-claimed' : '');
                button.disabled = Boolean(revealedResult) || soloAnimating;
                button.textContent = revealedResult
                    ? ((c + 1) + '번줄: ' + revealedResult)
                    : ((c + 1) + '번줄 선택');
                button.addEventListener('click', (function (column) {
                    return function () { playSoloColumn(column); };
                }(c)));
                columnsEl.appendChild(button);
            }
        }

        function playSoloColumn(column) {
            if (soloAnimating || soloRevealedColumns[column]) {
                return;
            }

            var trace = engine.traceColumn(ladder.rungs, column, ladder.columnCount);
            var result = ladder.results[trace.endColumn];

            if (!window.HttLadderRender) {
                soloRevealedColumns[column] = result;
                renderSoloColumns();
                claimCountEl.textContent = Object.keys(soloRevealedColumns).length + ' / ' + ladder.columnCount + '명 선택 완료';
                return;
            }

            soloAnimating = true;
            columnsEl.hidden = true;
            soloAnimationEl.hidden = false;
            soloAnimationEl.innerHTML = '';

            var layout = window.HttLadderRender.createSvg(ladder.columnCount, ladder.rungs.length);
            var labels = [];
            for (var i = 0; i < ladder.columnCount; i += 1) {
                labels.push(i + 1);
            }
            window.HttLadderRender.labelColumns(layout, labels, column);
            soloAnimationEl.appendChild(layout.svg);

            window.HttLadderRender.animatePath(layout, trace.path, {}, function () {
                soloRevealedColumns[column] = result;

                var resultLine = document.createElement('p');
                resultLine.className = 'htt-ladder-solo-result';
                resultLine.textContent = (column + 1) + '번줄 → ' + result;
                soloAnimationEl.appendChild(resultLine);

                var confirmButton = document.createElement('button');
                confirmButton.type = 'button';
                confirmButton.textContent = '확인';
                confirmButton.addEventListener('click', function () {
                    soloAnimating = false;
                    columnsEl.hidden = false;
                    soloAnimationEl.hidden = true;
                    renderSoloColumns();
                    claimCountEl.textContent = Object.keys(soloRevealedColumns).length + ' / ' + ladder.columnCount + '명 선택 완료';
                });
                soloAnimationEl.appendChild(confirmButton);
            });
        }

        function renderReveal() {
            revealEl.innerHTML = '';

            if (!revealed || !ladder) {
                revealEl.hidden = true;
                return;
            }
            revealEl.hidden = false;

            if (!window.HttLadderRender) {
                for (var c = 0; c < ladder.columnCount; c += 1) {
                    var row = document.createElement('div');
                    row.textContent = (c + 1) + '번줄 → ' + ladder.results[ladder.mapping[c]];
                    revealEl.appendChild(row);
                }
                return;
            }

            var byColumn = {};
            latestClaims.forEach(function (claim) {
                byColumn[claim.column] = claim;
            });

            var layout = window.HttLadderRender.createSvg(ladder.columnCount, ladder.rungs.length);
            window.HttLadderRender.drawFullLadder(layout, ladder.rungs);

            var topLabels = [];
            var bottomLabels = [];
            for (var i = 0; i < ladder.columnCount; i += 1) {
                var claim = byColumn[i];
                topLabels.push(claim ? claim.name : ((i + 1) + '번'));
                bottomLabels.push(ladder.results[ladder.mapping[i]]);
            }
            window.HttLadderRender.labelColumns(layout, topLabels, -1);
            window.HttLadderRender.bottomLabels(layout, bottomLabels);

            revealEl.appendChild(layout.svg);
        }

        function poll() {
            request('/sessions/' + joinCode, 'GET').then(function (data) {
                renderColumns(data.state && data.state.claims);
            }).catch(function () {
                window.clearInterval(pollHandle);
            });
        }

        startButton.addEventListener('click', function () {
            var participantCount = Math.max(2, Math.min(30, parseInt(participantCountInput.value, 10) || 2));
            var results = parseResults(resultsInput.value);

            if (results.length !== participantCount) {
                showMessages([{ type: 'warning', text: '결과 개수(' + results.length + ')가 인원수(' + participantCount + ')와 같아야 합니다.' }]);
                return;
            }

            var built = engine.build(participantCount, participantCount * 2);
            ladder = { columnCount: built.columnCount, results: results, rungs: built.rungs, mapping: built.mapping };
            revealed = false;
            soloRevealedColumns = {};

            var mode = root.querySelector('input[name="htt-ladder-mode"]:checked').value;
            currentMode = mode;

            request('/sessions', 'POST', {
                toolType: 'ladder',
                mode: mode,
                state: {
                    participantCount: ladder.columnCount,
                    results: ladder.results,
                    rowCount: built.rowCount,
                    rungs: ladder.rungs,
                    mapping: ladder.mapping,
                    claims: [],
                },
            }).then(function (data) {
                joinCode = data.joinCode;
                setup.hidden = true;
                progressEl.hidden = false;
                renderColumns([]);
                renderReveal();

                if (mode === 'live') {
                    joinInfo.hidden = false;
                    joinCodeEl.textContent = joinCode;
                    pollHandle = window.setInterval(poll, 1500);
                } else {
                    joinInfo.hidden = true;
                }
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

        revealButton.addEventListener('click', function () {
            revealed = true;
            renderReveal();
        });

        restartButton.addEventListener('click', function () {
            if (pollHandle) {
                window.clearInterval(pollHandle);
                pollHandle = null;
            }
            var endRequest = joinCode ? request('/sessions/' + joinCode + '/end', 'POST').catch(function () {}) : Promise.resolve();
            endRequest.then(function () {
                joinCode = null;
                ladder = null;
                revealed = false;
                soloRevealedColumns = {};
                progressEl.hidden = true;
                setup.hidden = false;
                showMessages([]);
            });
        });
    });
})();

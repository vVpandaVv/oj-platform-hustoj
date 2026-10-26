(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-htt-seating-host]');
        if (!root || !window.HttSeatingEngine || !window.HttStudentIdentities) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var engine = window.HttSeatingEngine;
        var identities = window.HttStudentIdentities;

        var setup = root.querySelector('[data-field="setup"]');
        var messagesEl = root.querySelector('[data-field="messages"]');
        var resultEl = root.querySelector('[data-field="result"]');
        var joinInfo = root.querySelector('[data-field="join-info"]');
        var joinCodeEl = root.querySelector('[data-field="join-code"]');
        var boardEl = root.querySelector('[data-field="board"]');
        var exclusionGridEl = root.querySelector('[data-field="exclusion-grid"]');

        var contextSelect = root.querySelector('[data-field="context-select"]');
        var studentsInput = root.querySelector('[data-field="students"]');
        var rowsInput = root.querySelector('[data-field="rows"]');
        var colsInput = root.querySelector('[data-field="cols"]');
        var frontInput = root.querySelector('[data-field="front-names"]');
        var apartInput = root.querySelector('[data-field="apart-pairs"]');
        var apartGapInput = root.querySelector('[data-field="apart-gap"]');
        var diagonalInput = root.querySelector('[data-field="diagonal"]');
        var buddyInput = root.querySelector('[data-field="buddy-pairs"]');
        var revealModeInput = root.querySelector('[data-field="reveal-mode"]');
        var liveModeInput = root.querySelector('[data-field="live-mode-input"]');
        var layoutSelect = root.querySelector('[data-field="layout-select"]');
        var layoutNameInput = root.querySelector('[data-field="layout-name"]');
        var saveLayoutStatusEl = root.querySelector('[data-field="save-layout-status"]');

        var loadContextsButton = root.querySelector('[data-action="load-contexts"]');
        var loadRosterButton = root.querySelector('[data-action="load-roster"]');
        var arrangeButton = root.querySelector('[data-action="arrange"]');
        var newArrangementButton = root.querySelector('[data-action="new-arrangement"]');
        var copyLinkButton = root.querySelector('[data-action="copy-link"]');
        var loadLayoutButton = root.querySelector('[data-action="load-layout"]');
        var deleteLayoutButton = root.querySelector('[data-action="delete-layout"]');
        var saveLayoutButton = root.querySelector('[data-action="save-layout"]');
        var revealAllButton = root.querySelector('[data-action="reveal-all"]');
        var downloadImageButton = root.querySelector('[data-action="download-image"]');
        var printButton = root.querySelector('[data-action="print"]');
        var usageButton = root.querySelector('[data-action="show-usage"]');

        if (usageButton && window.HttUsageModal) {
            usageButton.addEventListener('click', function () {
                window.HttUsageModal.show('자리배치 사용법', '<ul>'
                    + '<li>학급/그룹 명단을 불러오거나 직접 입력한 뒤 배치를 실행하세요.</li>'
                    + '<li>제외할 자리는 미리보기 그리드를 클릭해 표시할 수 있습니다.</li>'
                    + '<li>"선택 조건"에는 이름, 관리용 이름 또는 #학생ID를 입력할 수 있습니다. 동명이인은 명단에 보이는 구분 이름이나 #학생ID를 사용하세요.</li>'
                    + '<li>앞자리 배치·떨어뜨리기·짝 지어 앉히기를 설정할 수 있으며, 앞자리를 희망하는 인원이 많으면 앞자리 구역이 자동으로 몇 줄로 늘어납니다.</li>'
                    + '<li>"짝 지어 앉히기"는 각 행에서 1·2번, 3·4번, 5·6번처럼 좌우로 붙은 실제 책상 자리에만 나란히 앉힙니다(앞뒤로는 붙이지 않음).</li>'
                    + '<li>배치 결과에서 자리 두 개를 순서대로 클릭하면 서로 자리를 바꿉니다.</li>'
                    + '<li>배치를 이름 붙여 저장하고 나중에 다시 불러올 수 있습니다. 결과 화면에서도 이름·관리용 이름·익명 번호 표시를 바꿀 수 있습니다.</li>'
                    + '<li>명단을 학급에서 가져왔을 때만 "동시 참여" 모드로 학생이 자기 자리를 찾을 수 있습니다.</li>'
                    + '<li>명단이 비거나 권한 오류가 뜨면 사용자 권한 화면에서 "교실 도구 진행" 권한 배정과, 이 계정이 학급/그룹의 담당 교사로 등록돼 있는지 확인하세요.</li>'
                    + '</ul>');
            });
        }

        var rosterEntries = [];
        var joinCode = null;
        var excludedSeatIds = {};
        var currentSeats = [];
        var currentRows = 0;
        var currentCols = 0;
        var selectedSeatId = null;
        var revealMode = false;

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

        function clampDimension(value, fallback) {
            var parsed = parseInt(value, 10);
            if (!Number.isFinite(parsed)) {
                return fallback;
            }
            return Math.max(1, Math.min(20, parsed));
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
            // On a plain-permalink site, config.restUrl is itself
            // "...?rest_route=/teaching-tools/v1" — a plain '?revision=' here
            // would inject a second '?' into that query string and corrupt
            // the route WordPress tries to match, always 404ing as "no route
            // found". Pick '&' whenever restUrl already has a '?'.
            var querySep = config.restUrl.indexOf('?') === -1 ? '?' : '&';
            request(
                '/contexts/' + selected.type + '/' + selected.id + '/roster' + querySep + 'revision=' + encodeURIComponent(selected.revision),
                'GET'
            ).then(function (data) {
                rosterEntries = identities.fromRoster(data.students || [], identities.selectedMode(root));
                studentsInput.value = identities.toText(rosterEntries);
                liveModeInput.disabled = rosterEntries.length === 0;
                showMessages([{ type: 'info', text: rosterEntries.length + '명의 명단을 불러왔습니다.' }]);
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        }

        function renderExclusionGrid() {
            var rows = clampDimension(rowsInput.value, 5);
            var cols = clampDimension(colsInput.value, 6);

            // Dropping ids outside the current grid keeps the set from
            // silently accumulating stale entries as the teacher tweaks
            // rows/cols back and forth.
            var next = {};
            Object.keys(excludedSeatIds).forEach(function (id) {
                var parts = id.split('-');
                var row = parseInt(parts[0], 10);
                var col = parseInt(parts[1], 10);
                if (row < rows && col < cols) {
                    next[id] = true;
                }
            });
            excludedSeatIds = next;

            exclusionGridEl.style.setProperty('--htt-seat-cols', String(cols));
            exclusionGridEl.innerHTML = '';

            for (var r = 0; r < rows; r += 1) {
                for (var c = 0; c < cols; c += 1) {
                    var id = engine.makeSeatId(r, c);
                    var isExcluded = Boolean(excludedSeatIds[id]);

                    var cell = document.createElement('button');
                    cell.type = 'button';
                    cell.className = 'htt-seat' + (isExcluded ? ' is-disabled' : ' is-empty');

                    var index = document.createElement('span');
                    index.className = 'htt-seat-index';
                    index.textContent = (r + 1) + '줄 ' + (c + 1) + '칸';

                    var label = document.createElement('span');
                    label.textContent = isExcluded ? '제외됨' : '사용';

                    cell.append(index, label);
                    cell.addEventListener('click', (function (seatId) {
                        return function () {
                            if (excludedSeatIds[seatId]) {
                                delete excludedSeatIds[seatId];
                            } else {
                                excludedSeatIds[seatId] = true;
                            }
                            renderExclusionGrid();
                        };
                    }(id)));
                    exclusionGridEl.appendChild(cell);
                }
            }
        }

        function renderBoard(seats, cols) {
            currentSeats = seats;
            currentCols = cols;

            boardEl.style.setProperty('--htt-seat-cols', String(cols));
            boardEl.innerHTML = '';

            seats.forEach(function (seat) {
                var seatEl = document.createElement('button');
                seatEl.type = 'button';
                seatEl.className = 'htt-seat'
                    + (seat.disabled ? ' is-disabled' : (seat.student ? '' : ' is-empty'))
                    + (seat.locked ? ' is-locked' : '')
                    + (seat.student && seat.revealed === false ? ' is-covered' : '')
                    + (seat.id === selectedSeatId ? ' is-selected' : '');
                seatEl.disabled = Boolean(seat.disabled);

                var index = document.createElement('span');
                index.className = 'htt-seat-index';
                index.textContent = (seat.row + 1) + '줄 ' + (seat.col + 1) + '칸';

                var name = document.createElement('span');
                name.textContent = seat.disabled
                    ? '사용 안함'
                    : (seat.student ? (seat.revealed === false ? '클릭해서 공개' : seat.student.name) : '빈자리');

                seatEl.append(index, name);
                seatEl.addEventListener('click', (function (seatId) {
                    return function () { onSeatClick(seatId); };
                }(seat.id)));
                boardEl.appendChild(seatEl);
            });
        }

        function relabelSeats(seats, mode) {
            var source = Array.isArray(seats) ? seats : [];
            var storedStudents = source.filter(function (seat) {
                return Boolean(seat.student);
            }).map(function (seat) {
                return seat.student;
            });
            var relabeled = identities.fromStored(storedStudents, rosterEntries, mode);
            var studentIndex = 0;

            return source.map(function (seat) {
                var nextSeat = Object.assign({}, seat);
                if (seat.student) {
                    nextSeat.student = identities.storedStudent(relabeled[studentIndex] || seat.student);
                    studentIndex += 1;
                }
                return nextSeat;
            });
        }

        function onSeatClick(seatId) {
            var seat = currentSeats.find(function (s) { return s.id === seatId; });
            if (!seat || seat.disabled) {
                return;
            }

            if (seat.student && seat.revealed === false) {
                seat.revealed = true;
                renderBoard(currentSeats, currentCols);
                pushSeatingState();
                return;
            }

            if (selectedSeatId === seatId) {
                selectedSeatId = null;
                renderBoard(currentSeats, currentCols);
                return;
            }

            if (selectedSeatId === null) {
                selectedSeatId = seatId;
                renderBoard(currentSeats, currentCols);
                return;
            }

            var other = currentSeats.find(function (s) { return s.id === selectedSeatId; });
            if (other) {
                var swapStudent = seat.student;
                var swapLocked = seat.locked;
                seat.student = other.student;
                seat.locked = other.locked;
                other.student = swapStudent;
                other.locked = swapLocked;
            }

            selectedSeatId = null;
            renderBoard(currentSeats, currentCols);
            pushSeatingState();
        }

        function pushSeatingState() {
            if (!joinCode) {
                return;
            }
            request('/sessions/' + joinCode + '/state', 'POST', {
                state: { rows: currentRows, cols: currentCols, seats: currentSeats, revealMode: revealMode },
            }).catch(function () {
                // Best-effort sync; the host's own board stays authoritative regardless.
            });
        }

        function showArrangement(seats, rows, cols, mode, concealResults) {
            currentRows = rows;
            revealMode = Boolean(concealResults);
            seats = relabelSeats(seats, identities.selectedMode(root)).map(function (seat) {
                return Object.assign({}, seat, {
                    revealed: seat.student ? !revealMode : true,
                });
            });
            selectedSeatId = null;
            renderBoard(seats, cols);
            revealAllButton.hidden = !revealMode;

            return request('/sessions', 'POST', {
                toolType: 'seating',
                mode: mode,
                state: { rows: currentRows, cols: currentCols, seats: currentSeats, revealMode: revealMode },
            }).then(function (data) {
                joinCode = data.joinCode;
                setup.hidden = true;
                resultEl.hidden = false;
                if (mode === 'live') {
                    joinInfo.hidden = false;
                    joinCodeEl.textContent = joinCode;
                } else {
                    joinInfo.hidden = true;
                }
            });
        }

        function runArrangement() {
            var entries = identities.fromText(studentsInput.value, rosterEntries);
            if (!entries.length) {
                showMessages([{ type: 'warning', text: '학생 이름을 한 명 이상 입력하세요.' }]);
                return;
            }
            rosterEntries = entries;
            var entryByName = identities.byName(entries);
            var students = entries.map(function (entry) { return entry.name; });

            var rows = clampDimension(rowsInput.value, 5);
            var cols = clampDimension(colsInput.value, 6);
            var frontCondition = identities.parseConditionNames(frontInput.value, entries);
            var apartCondition = identities.parseConditionPairs(apartInput.value, entries);
            var buddyCondition = identities.parseConditionPairs(buddyInput.value, entries);
            var conditionIssues = frontCondition.issues
                .concat(apartCondition.issues, buddyCondition.issues)
                .filter(function (issue, index, all) { return all.indexOf(issue) === index; });
            if (conditionIssues.length) {
                showMessages(conditionIssues.map(function (issue) {
                    return { type: 'warning', text: issue };
                }));
                return;
            }
            var frontNames = frontCondition.values;
            var apartPairs = apartCondition.values;
            var buddyPairs = buddyCondition.values;
            var apartGap = parseInt(apartGapInput.value, 10) || 2;

            var result = engine.arrange({
                rows: rows,
                cols: cols,
                students: students,
                disabledSeatIds: Object.keys(excludedSeatIds),
                frontNames: frontNames,
                apartPairs: apartPairs,
                buddyPairs: buddyPairs,
                apartGap: apartGap,
                diagonal: diagonalInput.checked,
            });

            if (result.error) {
                showMessages([{ type: 'warning', text: result.error }]);
                return;
            }

            var hasConflict = result.evaluation.hard.length || result.evaluation.soft.length || result.notes.length;
            var summaryMessages = result.unseated.length
                ? [{ type: 'warning', text: '좌석이 부족해 일부 학생은 배치되지 않았습니다: ' + result.unseated.join(', ') }]
                : [{ type: hasConflict ? 'warning' : 'info', text: hasConflict ? '가능한 자리를 우선해 좌석을 배치했습니다.' : '좌석 배치가 완료되었습니다.' }];
            showMessages(summaryMessages.concat(
                result.evaluation.hard.map(function (text) { return { type: 'warning', text: text }; }),
                result.evaluation.soft.map(function (text) { return { type: 'warning', text: text }; }),
                result.notes.map(function (text) { return { type: 'warning', text: text }; })
            ));

            var enrichedSeats = result.seats.map(function (seat) {
                var entry = seat.student ? entryByName[seat.student] : null;
                return {
                    id: seat.id,
                    row: seat.row,
                    col: seat.col,
                    disabled: seat.disabled,
                    locked: seat.locked,
                    student: entry ? identities.storedStudent(entry) : null,
                };
            });

            var mode = root.querySelector('input[name="htt-seating-mode"]:checked').value;
            showArrangement(enrichedSeats, rows, cols, mode, revealModeInput.checked).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        }

        function loadLayoutList() {
            request('/seating-layouts', 'GET').then(function (data) {
                layoutSelect.innerHTML = '';
                var empty = document.createElement('option');
                empty.value = '';
                empty.textContent = '저장된 배치 선택';
                layoutSelect.appendChild(empty);

                (data.items || []).forEach(function (item) {
                    var option = document.createElement('option');
                    option.value = String(item.id);
                    option.textContent = item.name + ' (' + item.rows + '×' + item.cols + ')';
                    layoutSelect.appendChild(option);
                });
            }).catch(function () {
                // Non-fatal — the dropdown just stays with its default option.
            });
        }

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
            if (rosterEntries.length && identities.allRoster(rosterEntries)) {
                rosterEntries = identities.relabel(rosterEntries, mode);
                studentsInput.value = identities.toText(rosterEntries);
            }
            if (currentSeats.length) {
                currentSeats = relabelSeats(currentSeats, mode);
                renderBoard(currentSeats, currentCols);
                pushSeatingState();
            }
        });
        arrangeButton.addEventListener('click', runArrangement);
        rowsInput.addEventListener('input', renderExclusionGrid);
        colsInput.addEventListener('input', renderExclusionGrid);

        loadLayoutButton.addEventListener('click', function () {
            if (!layoutSelect.value) {
                showMessages([{ type: 'warning', text: '불러올 배치를 선택하세요.' }]);
                return;
            }

            request('/seating-layouts/' + layoutSelect.value, 'GET').then(function (data) {
                var mode = root.querySelector('input[name="htt-seating-mode"]:checked').value;
                showArrangement(data.seats, data.rows, data.cols, mode, revealModeInput.checked).then(function () {
                    showMessages([{ type: 'info', text: '"' + data.name + '" 배치를 불러왔습니다.' }]);
                }).catch(function (error) {
                    showMessages([{ type: 'warning', text: error.message }]);
                });
            }).catch(function (error) {
                showMessages([{ type: 'warning', text: error.message }]);
            });
        });

        deleteLayoutButton.addEventListener('click', function () {
            if (!layoutSelect.value) {
                showMessages([{ type: 'warning', text: '삭제할 배치를 선택하세요.' }]);
                return;
            }
            var layoutId = layoutSelect.value;
            var asked = window.OJP && typeof window.OJP.confirm === 'function'
                ? window.OJP.confirm({
                    title: '선택한 배치를 삭제할까요?',
                    message: '삭제한 배치는 되돌릴 수 없습니다.',
                    confirmLabel: '삭제',
                    destructive: true
                })
                : Promise.resolve(window.confirm('선택한 배치를 삭제할까요?'));

            asked.then(function (confirmed) {
                if (!confirmed) {
                    return;
                }

                request('/seating-layouts/' + layoutId, 'DELETE').then(function () {
                    loadLayoutList();
                    showMessages([{ type: 'info', text: '삭제했습니다.' }]);
                }).catch(function (error) {
                    showMessages([{ type: 'warning', text: error.message }]);
                });
            });
        });

        saveLayoutButton.addEventListener('click', function () {
            var name = (layoutNameInput.value || '').trim();
            if (!name) {
                saveLayoutStatusEl.textContent = '배치 이름을 입력하세요.';
                return;
            }
            if (!currentSeats.length) {
                saveLayoutStatusEl.textContent = '저장할 배치가 없습니다.';
                return;
            }

            request('/seating-layouts', 'POST', {
                name: name,
                rows: currentRows,
                cols: currentCols,
                seats: currentSeats,
            }).then(function () {
                saveLayoutStatusEl.textContent = '"' + name + '" 이름으로 저장했습니다.';
                loadLayoutList();
            }).catch(function (error) {
                saveLayoutStatusEl.textContent = error.message;
            });
        });

        revealAllButton.addEventListener('click', function () {
            currentSeats.forEach(function (seat) {
                if (seat.student) {
                    seat.revealed = true;
                }
            });
            revealMode = false;
            revealAllButton.hidden = true;
            renderBoard(currentSeats, currentCols);
            pushSeatingState();
        });

        function fitCanvasText(context, value, maximumWidth) {
            var text = String(value || '');
            while (text.length > 1 && context.measureText(text).width > maximumWidth) {
                text = text.slice(0, -1);
            }
            return text === String(value || '') ? text : text.slice(0, -1) + '…';
        }

        downloadImageButton.addEventListener('click', function () {
            if (!currentSeats.length) {
                return;
            }
            var cellWidth = 180;
            var cellHeight = 104;
            var gap = 12;
            var padding = 32;
            var headerHeight = 88;
            var width = padding * 2 + currentCols * cellWidth + Math.max(0, currentCols - 1) * gap;
            var height = padding * 2 + headerHeight + currentRows * cellHeight + Math.max(0, currentRows - 1) * gap;
            var scale = 2;
            var canvas = document.createElement('canvas');
            canvas.width = width * scale;
            canvas.height = height * scale;
            var context = canvas.getContext('2d');
            context.scale(scale, scale);
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, width, height);
            context.fillStyle = '#111827';
            context.font = '700 28px sans-serif';
            context.textAlign = 'left';
            context.fillText('자리 배치표', padding, 38);
            context.font = '600 15px sans-serif';
            context.textAlign = 'center';
            context.fillStyle = '#475569';
            context.fillText('칠판', width / 2, 66);
            context.strokeStyle = '#94a3b8';
            context.beginPath();
            context.moveTo(width * 0.32, 74);
            context.lineTo(width * 0.68, 74);
            context.stroke();

            currentSeats.forEach(function (seat) {
                var x = padding + seat.col * (cellWidth + gap);
                var y = padding + headerHeight + seat.row * (cellHeight + gap);
                context.fillStyle = seat.disabled ? '#e5e7eb' : (seat.student ? '#eef6ff' : '#f8fafc');
                context.strokeStyle = seat.student ? '#2563eb' : '#cbd5e1';
                context.lineWidth = seat.student ? 2 : 1;
                context.fillRect(x, y, cellWidth, cellHeight);
                context.strokeRect(x + 0.5, y + 0.5, cellWidth - 1, cellHeight - 1);
                context.textAlign = 'left';
                context.fillStyle = '#64748b';
                context.font = '500 13px sans-serif';
                context.fillText((seat.row + 1) + '줄 ' + (seat.col + 1) + '칸', x + 12, y + 24);
                context.fillStyle = '#111827';
                context.font = '700 19px sans-serif';
                var name = seat.disabled
                    ? '사용 안함'
                    : (seat.student ? (seat.revealed === false ? '공개 전' : seat.student.name) : '빈자리');
                context.fillText(fitCanvasText(context, name, cellWidth - 24), x + 12, y + 65);
            });

            var link = document.createElement('a');
            link.download = '자리배치-' + new Date().toISOString().slice(0, 10) + '.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        });

        function finishPrint() {
            document.body.classList.remove('htt-print-seating');
        }

        printButton.addEventListener('click', function () {
            document.body.classList.add('htt-print-seating');
            window.print();
            window.setTimeout(finishPrint, 1000);
        });
        window.addEventListener('afterprint', finishPrint);

        newArrangementButton.addEventListener('click', function () {
            var endRequest = joinCode ? request('/sessions/' + joinCode + '/end', 'POST').catch(function () {}) : Promise.resolve();
            endRequest.then(function () {
                joinCode = null;
                currentSeats = [];
                selectedSeatId = null;
                revealMode = false;
                revealAllButton.hidden = true;
                resultEl.hidden = true;
                setup.hidden = false;
                showMessages([]);
            });
        });

        loadContexts();
        loadLayoutList();
        renderExclusionGrid();
    });
})();

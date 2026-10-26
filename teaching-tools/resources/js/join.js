(function () {
    'use strict';

    var DEFAULT_POLL_INTERVAL_MS = 1500;
    var WHITEBOARD_POLL_INTERVAL_MS = 600;
    var CLASSROOM_POLL_INTERVAL_MS = 10000;
    var QUICKBOARD_POLL_INTERVAL_MS = 5000;
    var CLASSROOM_HEARTBEAT_INTERVAL_MS = 10000;
    var CLASSROOM_DISCOVERY_INTERVAL_MS = 5000;
    var CLASSROOM_SESSION_STORAGE_KEY = 'teachingTools.classroom.active.v1';
    var CLASSROOM_DISMISSED_STORAGE_KEY = 'teachingTools.classroom.dismissed.v1';
    var CLASSROOM_DISMISSED_TTL_MS = 8 * 60 * 60 * 1000;

    function initialize() {
        var root = document.querySelector('[data-htt-join]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var form = root.querySelector('[data-field="form"]');
        var codeInput = root.querySelector('[data-field="code"]');
        var errorEl = root.querySelector('[data-field="error"]');
        var view = root.querySelector('[data-field="view"]');
        var timerView = root.querySelector('[data-field="timer-view"]');
        var timerClockEl = root.querySelector('[data-field="timer-clock"]');
        var timerEndedEl = root.querySelector('[data-field="timer-ended"]');
        var timerSoundPlayed = false;
        var seatingView = root.querySelector('[data-field="seating-view"]');
        var seatingMySeatEl = root.querySelector('[data-field="seating-my-seat"]');
        var seatingBoardEl = root.querySelector('[data-field="seating-board"]');
        var pickerView = root.querySelector('[data-field="picker-view"]');
        var pickerYouEl = root.querySelector('[data-field="picker-you"]');
        var pickerLatestEl = root.querySelector('[data-field="picker-latest"]');
        var ladderView = root.querySelector('[data-field="ladder-view"]');
        var ladderPickEl = root.querySelector('[data-field="ladder-pick"]');
        var ladderColumnsEl = root.querySelector('[data-field="ladder-columns"]');
        var ladderAnimationEl = root.querySelector('[data-field="ladder-animation"]');
        var ladderResultEl = root.querySelector('[data-field="ladder-result"]');
        var whiteboardView = root.querySelector('[data-field="whiteboard-view"]');
        var whiteboardCanvas = root.querySelector('[data-field="whiteboard-canvas"]');
        var whiteboardMaterialFrame = root.querySelector('[data-field="whiteboard-material-frame"]');
        var whiteboardCtx = whiteboardCanvas ? whiteboardCanvas.getContext('2d') : null;
        var whiteboardConnection = root.querySelector('[data-field="whiteboard-connection"]');
        var whiteboardMaterialError = root.querySelector('[data-field="whiteboard-material-error"]');
        var whiteboardMaterialMessage = root.querySelector('[data-field="whiteboard-material-message"]');
        var whiteboardMaterialRetry = root.querySelector('[data-action="retry-whiteboard-material"]');
        var quickboardView = root.querySelector('[data-field="quickboard-view"]');
        var quickboardPromptEl = root.querySelector('[data-field="quickboard-prompt"]');
        var quickboardCanvas = root.querySelector('[data-field="quickboard-canvas"]');
        var quickboardCtx = quickboardCanvas ? quickboardCanvas.getContext('2d') : null;
        var quickboardColorsEl = root.querySelector('[data-field="quickboard-colors"]');
        var quickboardWidthEl = root.querySelector('[data-field="quickboard-width"]');
        var quickboardStatusEl = root.querySelector('[data-field="quickboard-status"]');
        var quizView = root.querySelector('[data-field="quiz-view"]');
        var quizQuestionEl = root.querySelector('[data-field="quiz-question"]');
        var quizOptionsEl = root.querySelector('[data-field="quiz-options"]');
        var quizScaleWrapEl = root.querySelector('[data-field="quiz-scale-wrap"]');
        var quizScaleEl = root.querySelector('[data-field="quiz-scale"]');
        var quizScaleLowEl = root.querySelector('[data-field="quiz-scale-low"]');
        var quizScaleHighEl = root.querySelector('[data-field="quiz-scale-high"]');
        var quizResponseFormEl = root.querySelector('[data-field="quiz-response-form"]');
        var quizResponseTextEl = root.querySelector('[data-field="quiz-response-text"]');
        var quizResponseSubmitEl = root.querySelector('[data-field="quiz-response-submit"]');
        var quizVotedEl = root.querySelector('[data-field="quiz-voted"]');
        var quizResultsEl = root.querySelector('[data-field="quiz-results"]');
        var quizTextResultsEl = root.querySelector('[data-field="quiz-text-results"]');
        var quizWordResultsEl = root.querySelector('[data-field="quiz-word-results"]');
        var quizOwnEl = root.querySelector('[data-field="quiz-own"]');
        var quizApprovedEl = root.querySelector('[data-field="quiz-approved"]');
        var groupingView = root.querySelector('[data-field="grouping-view"]');
        var groupingMyGroupEl = root.querySelector('[data-field="grouping-my-group"]');
        var groupingGroupsEl = root.querySelector('[data-field="grouping-groups"]');
        var timeattackView = root.querySelector('[data-field="timeattack-view"]');
        var timeattackProblemEl = root.querySelector('[data-field="timeattack-problem"]');
        var timeattackClockEl = root.querySelector('[data-field="timeattack-clock"]');
        var classroomView = root.querySelector('[data-field="classroom-view"]');
        var classroomConnectionEl = root.querySelector('[data-field="classroom-connection"]');
        var classroomActivityEmptyEl = root.querySelector('[data-field="classroom-activity-empty"]');
        var classroomActivityEl = root.querySelector('[data-field="classroom-activity"]');
        var classroomActivityTitleEl = root.querySelector('[data-field="classroom-activity-title"]');
        var classroomActivityNoteEl = root.querySelector('[data-field="classroom-activity-note"]');
        var classroomActivityLinkEl = root.querySelector('[data-field="classroom-activity-link"]');
        var classroomSignalsEl = root.querySelector('[data-field="classroom-signals"]');
        var classroomMySignalEl = root.querySelector('[data-field="classroom-my-signal"]');
        var unsupportedView = root.querySelector('[data-field="unsupported-view"]');
        var joinButton = root.querySelector('[data-action="join"]');
        var assignedEl = root.querySelector('[data-field="assigned-sessions"]');
        var assignedSignature = '';
        var joinInFlight = false;
        var leaveButton = root.querySelector('[data-action="leave-session"]');
        var fullscreenButton = root.querySelector('[data-action="student-fullscreen"]');
        var participationNotice = root.querySelector('[data-field="participation-notice"]');
        var participationTitle = root.querySelector('[data-field="participation-title"]');
        var participationEpoch = 0;
        var autoJoinPaused = false;
        var activeRequests = new Set();
        var expandedPlaceholder = null;

        var pollHandle = null;
        var pollInFlight = false;
        var pollRequested = false;
        var activeToolType = '';
        var joinCode = null;
        var myLadderResult = null;
        var claimingColumn = false;
        var ladderParticipantCount = 0;
        var ladderAnimationStarted = false;
        var myQuizVote = null;
        var localQuizResponses = [];
        var currentQuizType = 'choice';
        var quizVoting = false;
        var realtimeConnection = null;
        var realtimeOpen = false;
        var realtimeWhiteboardState = null;
        var realtimePreview = null;
        var lastWhiteboardMessageAt = 0;
        var whiteboardRevision = 0;
        var quickboardElements = [];
        var quickboardRedo = [];
        var quickboardStroke = null;
        var quickboardTool = 'pen';
        var quickboardColor = '#1a1a1a';
        var quickboardRevision = 0;
        var quickboardSaving = false;
        var quickboardDirty = false;
        var classroomState = { activity: null };
        var classroomSignal = '';
        var classroomSignalSending = false;
        var classroomHeartbeatHandle = null;
        var classroomDiscoveryHandle = null;
        var classroomDiscoveryInFlight = false;

        if (config.prefillCode) {
            codeInput.value = config.prefillCode;
        }

        function rememberClassroomSession(code) {
            var previous = {};
            try {
                previous = JSON.parse(window.localStorage.getItem(CLASSROOM_SESSION_STORAGE_KEY) || '{}');
            } catch (error) {
                previous = {};
            }
            try {
                window.localStorage.setItem(CLASSROOM_SESSION_STORAGE_KEY, JSON.stringify({
                    code: code,
                    studentId: Number(config.currentStudentId || 0),
                    role: 'participant',
                    storedAt: Date.now(),
                    lastActivityAt: previous.code === code ? Number(previous.lastActivityAt || 0) : 0,
                }));
                window.localStorage.removeItem(CLASSROOM_DISMISSED_STORAGE_KEY);
            } catch (error) {
                // The full participation page still works without local storage.
            }
        }

        function forgetClassroomSession() {
            try {
                var saved = JSON.parse(window.localStorage.getItem(CLASSROOM_SESSION_STORAGE_KEY) || '{}');
                if (!joinCode || saved.code === joinCode) {
                    window.localStorage.removeItem(CLASSROOM_SESSION_STORAGE_KEY);
                }
            } catch (error) {
                // Nothing else to clean up when storage is blocked.
            }
        }

        function isClassroomDismissed(code) {
            try {
                var dismissed = JSON.parse(window.localStorage.getItem(CLASSROOM_DISMISSED_STORAGE_KEY) || '{}');
                return Number(dismissed.studentId || 0) === Number(config.currentStudentId || 0)
                    && Date.now() - Number(dismissed.dismissedAt || 0) <= CLASSROOM_DISMISSED_TTL_MS
                    && String(dismissed.code || '').toUpperCase() === String(code || '').toUpperCase();
            } catch (error) {
                return false;
            }
        }

        function request(path, method, body) {
            var abort = new AbortController();
            activeRequests.add(abort);
            var timeout = window.setTimeout(function () { abort.abort(); }, 10000);
            return fetch(config.restUrl + path, {
                method: method,
                headers: Object.assign(
                    { 'X-WP-Nonce': config.nonce },
                    body ? { 'Content-Type': 'application/json' } : {}
                ),
                body: body ? JSON.stringify(body) : undefined,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: abort.signal,
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        var requestError = new Error((data && data.message) || '참여 코드를 확인해 주세요.');
                        requestError.status = response.status;
                        throw requestError;
                    }
                    return data;
                });
            }).finally(function () { window.clearTimeout(timeout); activeRequests.delete(abort); });
        }

        function formatClock(totalSeconds) {
            var seconds = Math.max(0, Math.round(totalSeconds));
            var minutes = Math.floor(seconds / 60);
            var rest = seconds % 60;
            return String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
        }

        function renderSeatingState(state) {
            var seats = (state && state.seats) || [];
            var cols = (state && state.cols) || 1;
            var mySeat = seats.find(function (seat) {
                return seat.student && Number(seat.student.id) === Number(config.currentStudentId);
            });

            seatingMySeatEl.textContent = mySeat
                ? ('내 자리: ' + (mySeat.row + 1) + '줄 ' + (mySeat.col + 1) + '칸')
                : '아직 배치 결과에서 내 자리를 찾지 못했습니다.';

            seatingBoardEl.style.setProperty('--htt-seat-cols', String(cols));
            seatingBoardEl.innerHTML = '';
            seats.forEach(function (seat) {
                var seatEl = document.createElement('div');
                var isMe = Boolean(mySeat) && seat.id === mySeat.id;
                seatEl.className = 'htt-seat'
                    + (seat.disabled ? ' is-disabled' : (seat.student ? '' : ' is-empty'))
                    + (isMe ? ' is-me' : '');

                var index = document.createElement('span');
                index.className = 'htt-seat-index';
                index.textContent = (seat.row + 1) + '줄 ' + (seat.col + 1) + '칸';

                var name = document.createElement('span');
                name.textContent = seat.disabled ? '사용 안함' : (seat.student ? seat.student.name : '빈자리');

                seatEl.append(index, name);
                seatingBoardEl.appendChild(seatEl);
            });
        }

        function renderPickerState(state) {
            var latest = (state && state.latest) || [];
            var amPicked = latest.some(function (entry) {
                return entry.mine || (entry.id && Number(entry.id) === Number(config.currentStudentId));
            });

            pickerYouEl.textContent = amPicked ? '당신이 뽑혔습니다!' : '';
            pickerLatestEl.textContent = latest.length
                ? latest.map(function (entry) { return entry.name; }).join(', ')
                : '아직 뽑기 결과가 없습니다.';
        }

        function playLadderAnimation(path, chosenColumn, result) {
            ladderAnimationStarted = true;
            ladderPickEl.hidden = true;
            ladderAnimationEl.hidden = false;
            ladderAnimationEl.innerHTML = '';
            ladderResultEl.hidden = true;

            if (!window.HttLadderRender) {
                ladderAnimationEl.hidden = true;
                ladderResultEl.hidden = false;
                ladderResultEl.textContent = result ? ('내 결과: ' + result) : '결과를 확인했습니다.';
                return;
            }

            var rowCount = Math.max(0, path.length - 1);
            var layout = window.HttLadderRender.createSvg(Math.max(ladderParticipantCount, chosenColumn + 1), rowCount);
            var labels = [];
            for (var c = 0; c < layout.colX.length; c += 1) {
                labels.push(c + 1);
            }
            window.HttLadderRender.labelColumns(layout, labels, chosenColumn);
            ladderAnimationEl.appendChild(layout.svg);

            var waiting = document.createElement('p');
            waiting.className = 'htt-ladder-waiting';
            waiting.textContent = '사다리를 타고 내려가는 중...';
            ladderAnimationEl.appendChild(waiting);

            window.HttLadderRender.animatePath(layout, path, {}, function () {
                waiting.remove();
                ladderResultEl.hidden = false;
                ladderResultEl.textContent = result ? ('내 결과: ' + result) : '결과를 확인했습니다.';
            });
        }

        function claimLadderColumn(column) {
            if (claimingColumn) {
                return;
            }
            claimingColumn = true;

            request('/sessions/' + joinCode + '/ladder-claim', 'POST', { column: column }).then(function (data) {
                claimingColumn = false;
                myLadderResult = data.result;
                playLadderAnimation(data.path || [column], column, data.result);
            }).catch(function (error) {
                claimingColumn = false;
                if (window.OJP && typeof window.OJP.toast === 'function') {
                    window.OJP.toast(error.message, 'danger');
                } else {
                    window.alert(error.message);
                }
                poll();
            });
        }

        function renderLadderState(state) {
            if (ladderAnimationStarted) {
                return;
            }

            var claims = (state && state.claims) || [];
            var participantCount = (state && state.participantCount) || 0;
            ladderParticipantCount = participantCount;

            var mine = myLadderResult !== null ? { result: myLadderResult } : claims.find(function (claim) {
                return claim.id && Number(claim.id) === Number(config.currentStudentId);
            });

            if (mine) {
                // Reached only via a page reload after already claiming — the
                // one-time descent animation already played in the original
                // tab, so a plain result is shown here instead of replaying it.
                ladderPickEl.hidden = true;
                ladderResultEl.hidden = false;
                ladderResultEl.textContent = mine.result ? ('내 결과: ' + mine.result) : '결과를 확인했습니다.';
                return;
            }

            ladderResultEl.hidden = true;
            ladderPickEl.hidden = false;

            var takenColumns = {};
            claims.forEach(function (claim) {
                takenColumns[claim.column] = true;
            });

            ladderColumnsEl.innerHTML = '';
            for (var c = 0; c < participantCount; c += 1) {
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = (c + 1) + '번줄';
                button.disabled = Boolean(takenColumns[c]) || claimingColumn;
                button.addEventListener('click', (function (column) {
                    return function () { claimLadderColumn(column); };
                }(c)));
                ladderColumnsEl.appendChild(button);
            }
        }

        var whiteboardImageCache = {};
        var lastWhiteboardSignature = '';
        var lastWhiteboardPaintState = null;
        var lastWhiteboardBackground = '';
        var whiteboardPaintFrame = null;
        var pendingWhiteboardPaint = null;
        var whiteboardBackgroundGeneration = 0;
        var whiteboardInk = whiteboardCanvas && window.HttWhiteboardRender
            ? window.HttWhiteboardRender.createLiveLayer(whiteboardCanvas, whiteboardImageCache) : null;
        var whiteboardViewer = window.HttWhiteboardViewer ? window.HttWhiteboardViewer.create({
            root: whiteboardView,
            onResize: function () {
                if (realtimeWhiteboardState) { renderWhiteboardState(realtimeWhiteboardState, realtimePreview, true); }
            },
        }) : null;

        function acceptWhiteboardState(state) {
            var revision = Number(state && state.revision || 0);
            if (!Number.isFinite(revision) || revision < whiteboardRevision) { return false; }
            whiteboardRevision = revision;
            return true;
        }

        function renderWhiteboardState(state, preview, force) {
            if (!whiteboardInk) { return; }
            pendingWhiteboardPaint = { state: state, preview: preview, force: Boolean(force || (pendingWhiteboardPaint && pendingWhiteboardPaint.force)) };
            if (whiteboardPaintFrame !== null) { return; }
            whiteboardPaintFrame = window.requestAnimationFrame(function () {
                whiteboardPaintFrame = null;
                var paint = pendingWhiteboardPaint;
                pendingWhiteboardPaint = null;
                paintWhiteboardState(paint.state, paint.preview, paint.force);
            });
        }

        function paintWhiteboardState(state, preview, force) {
            var pages = (state && state.pages) || [];
            var currentPage = (state && state.currentPage) || 0;
            var background = pages[currentPage] && pages[currentPage].background;
            var sharingPending = background && state.materialSharing === 'session' && !background.shared;
            if (background && state.materialSharing === 'session') {
                background = background.shared ? Object.assign({ type: 'image' }, background.shared) : null;
            }
            if (whiteboardViewer && (state !== lastWhiteboardPaintState || force)) { whiteboardViewer.setBackground(background, currentPage); }
            var rasterOptions = { resolution: whiteboardViewer ? whiteboardViewer.resolution() : 1 };
            var backgroundKey = JSON.stringify([currentPage, background || null, rasterOptions.resolution, Boolean(sharingPending)]);
            if (whiteboardMaterialFrame && backgroundKey !== lastWhiteboardBackground) {
                lastWhiteboardBackground = backgroundKey;
                var generation = ++whiteboardBackgroundGeneration;
                whiteboardMaterialError.hidden = !sharingPending;
                whiteboardMaterialMessage.textContent = sharingPending ? '교사가 공유할 자료 배경을 준비하고 있습니다. 필기는 계속 표시됩니다.' : '';
                whiteboardMaterialRetry.hidden = Boolean(sharingPending);
                whiteboardView.classList.toggle('has-material', Boolean(background));
                whiteboardMaterialFrame.hidden = !background;
                if (window.HttPdfPages) {
                    window.HttPdfPages.render(background, whiteboardMaterialFrame, rasterOptions).then(function () {
                        window.HttPdfPages.preload(background, rasterOptions);
                    }).catch(function (error) {
                        if (whiteboardBackgroundGeneration !== generation) { return; }
                        whiteboardMaterialFrame.hidden = true;
                        whiteboardMaterialMessage.textContent = '자료 배경을 표시할 수 없습니다. ' + window.HttPdfPages.errorMessage(error) + ' 필기는 계속 표시됩니다.';
                        whiteboardMaterialError.hidden = false;
                        whiteboardMaterialRetry.hidden = false;
                    });
                }
            }
            var elements = (pages[currentPage] && pages[currentPage].elements) || [];
            if (state !== lastWhiteboardPaintState || force) {
                var signature = JSON.stringify([currentPage, elements]);
                if (force || signature !== lastWhiteboardSignature) { whiteboardInk.setBase(elements); }
                lastWhiteboardSignature = signature;
                lastWhiteboardPaintState = state;
            }
            var visiblePreview = preview && Number(preview.page) === Number(currentPage) && preview.element;
            whiteboardInk.preview(visiblePreview || null, visiblePreview ? (preview.id || preview.element) : null);
        }

        whiteboardMaterialRetry.addEventListener('click', function () {
            lastWhiteboardBackground = '';
            renderWhiteboardState(realtimeWhiteboardState, realtimePreview);
        });

        function redrawQuickboard() {
            if (!quickboardCtx || !window.HttWhiteboardRender) {
                return;
            }
            var elements = quickboardElements.slice();
            if (quickboardStroke) {
                elements.push(quickboardStroke);
            }
            window.HttWhiteboardRender.redrawElements(quickboardCtx, quickboardCanvas, elements, {}, function () {});
        }

        function quickboardPoint(event) {
            var rect = quickboardCanvas.getBoundingClientRect();
            return [
                Math.round((event.clientX - rect.left) / rect.width * 1000),
                Math.round((event.clientY - rect.top) / rect.height * 600),
            ];
        }

        function resetQuickboard(elements, revision, message) {
            quickboardElements = Array.isArray(elements) ? elements : [];
            quickboardRedo = [];
            quickboardStroke = null;
            quickboardRevision = Number(revision || 0);
            quickboardDirty = false;
            quickboardStatusEl.textContent = message || (quickboardRevision ? '제출한 판서입니다. 수정해 다시 제출할 수 있습니다.' : '그린 뒤 제출하세요.');
            redrawQuickboard();
        }

        function renderQuickboardState(state) {
            state = state || {};
            quickboardPromptEl.textContent = state.prompt || '';
            var board = state.myBoard || {};
            var serverRevision = Number(board.revision || 0);
            if (!quickboardDirty && !quickboardSaving && serverRevision !== quickboardRevision) {
                resetQuickboard(board.elements || [], serverRevision);
            }
        }

        function submitQuickboard() {
            if (!joinCode || quickboardSaving) {
                return;
            }
            if (!quickboardElements.length) {
                quickboardStatusEl.textContent = '먼저 그림을 그려 주세요.';
                return;
            }
            quickboardSaving = true;
            quickboardStatusEl.textContent = '제출하고 있습니다.';
            var nextRevision = quickboardRevision + 1;
            request('/sessions/' + joinCode + '/quickboard-response', 'POST', {
                elements: quickboardElements,
                revision: nextRevision,
            }).then(function (data) {
                quickboardSaving = false;
                quickboardRevision = Number(data.revision || nextRevision);
                quickboardDirty = false;
                quickboardStatusEl.textContent = '제출했습니다. 수정한 뒤 다시 제출할 수 있습니다.';
                if (realtimeConnection) {
                    realtimeConnection.send({
                        t: 'participant-event',
                        event: 'board-updated',
                        data: { revision: quickboardRevision },
                    });
                }
            }).catch(function (error) {
                quickboardSaving = false;
                quickboardStatusEl.textContent = error.message;
            });
        }

        if (quickboardCanvas) {
            quickboardCanvas.addEventListener('pointerdown', function (event) {
                quickboardCanvas.setPointerCapture(event.pointerId);
                quickboardStroke = {
                    type: quickboardTool === 'eraser' ? 'erase' : 'draw',
                    color: quickboardColor,
                    width: parseInt(quickboardWidthEl.value, 10) || 4,
                    points: [quickboardPoint(event)],
                };
            });
            quickboardCanvas.addEventListener('pointermove', function (event) {
                if (!quickboardStroke || quickboardStroke.points.length >= 400) {
                    return;
                }
                var point = quickboardPoint(event);
                var last = quickboardStroke.points[quickboardStroke.points.length - 1];
                var dx = point[0] - last[0];
                var dy = point[1] - last[1];
                if (dx * dx + dy * dy < 4) {
                    return;
                }
                quickboardStroke.points.push(point);
                redrawQuickboard();
            });
            var finishQuickboardStroke = function () {
                if (!quickboardStroke) {
                    return;
                }
                if (quickboardStroke.points.length > 1) {
                    quickboardElements.push(quickboardStroke);
                    if (quickboardElements.length > 100) {
                        quickboardElements.shift();
                    }
                    quickboardRedo = [];
                    quickboardDirty = true;
                    quickboardStatusEl.textContent = '변경 사항을 제출해 주세요.';
                }
                quickboardStroke = null;
                redrawQuickboard();
            };
            quickboardCanvas.addEventListener('pointerup', finishQuickboardStroke);
            quickboardCanvas.addEventListener('pointercancel', finishQuickboardStroke);
        }

        Array.prototype.forEach.call(root.querySelectorAll('[data-quickboard-tool]'), function (button) {
            button.addEventListener('click', function () {
                quickboardTool = button.getAttribute('data-quickboard-tool') || 'pen';
                Array.prototype.forEach.call(root.querySelectorAll('[data-quickboard-tool]'), function (item) {
                    item.classList.toggle('is-active', item === button);
                });
            });
        });

        if (quickboardColorsEl) {
            quickboardColorsEl.addEventListener('click', function (event) {
                var button = event.target.closest('[data-color]');
                if (!button) {
                    return;
                }
                quickboardColor = button.getAttribute('data-color') || '#1a1a1a';
                Array.prototype.forEach.call(quickboardColorsEl.querySelectorAll('[data-color]'), function (item) {
                    item.classList.toggle('is-active', item === button);
                });
            });
        }

        var quickboardUndoButton = root.querySelector('[data-action="quickboard-undo"]');
        if (quickboardUndoButton) {
            quickboardUndoButton.addEventListener('click', function () {
                if (!quickboardElements.length) {
                    return;
                }
                quickboardRedo.push(quickboardElements.pop());
                quickboardDirty = true;
                quickboardStatusEl.textContent = '변경 사항을 제출해 주세요.';
                redrawQuickboard();
            });
        }
        var quickboardClearButton = root.querySelector('[data-action="quickboard-clear"]');
        if (quickboardClearButton) {
            quickboardClearButton.addEventListener('click', function () {
                quickboardElements = [];
                quickboardRedo = [];
                quickboardDirty = true;
                quickboardStatusEl.textContent = '판서를 지웠습니다. 새로 그려 제출하세요.';
                redrawQuickboard();
            });
        }
        var quickboardSubmitButton = root.querySelector('[data-action="quickboard-submit"]');
        if (quickboardSubmitButton) {
            quickboardSubmitButton.addEventListener('click', submitQuickboard);
        }

        function renderQuizBars(container, options, counts, correctIndex) {
            var total = counts.reduce(function (sum, n) { return sum + n; }, 0);
            container.innerHTML = '';
            options.forEach(function (label, index) {
                var count = counts[index] || 0;
                var pct = total ? Math.round((count / total) * 100) : 0;

                var row = document.createElement('div');
                row.className = 'htt-quiz-bar-row' + (correctIndex === index ? ' is-correct' : '');

                var labelEl = document.createElement('span');
                labelEl.className = 'htt-quiz-bar-label';
                labelEl.textContent = label + (correctIndex === index ? ' ✓' : '');

                var track = document.createElement('div');
                track.className = 'htt-quiz-bar-track';
                var fill = document.createElement('div');
                fill.className = 'htt-quiz-bar-fill';
                fill.style.width = pct + '%';
                track.appendChild(fill);

                var countEl = document.createElement('span');
                countEl.className = 'htt-quiz-bar-count';
                countEl.textContent = count + '표 (' + pct + '%)';

                row.append(labelEl, track, countEl);
                container.appendChild(row);
            });
        }

        function submitQuizResponse(payload) {
            if (quizVoting) {
                return;
            }
            quizVoting = true;
            renderQuizState({
                responseType: currentQuizType,
                question: quizQuestionEl.textContent,
                myResponses: localQuizResponses,
            });

            request('/sessions/' + joinCode + '/quiz-response', 'POST', payload).then(function (data) {
                quizVoting = false;
                if (currentQuizType === 'choice') {
                    myQuizVote = payload.optionIndex;
                }
                localQuizResponses.push(Object.assign({}, payload, {
                    id: data.id || '',
                    status: currentQuizType === 'qna' ? 'pending' : '',
                }));
                if (quizResponseTextEl) {
                    quizResponseTextEl.value = '';
                }
                poll();
            }).catch(function (error) {
                quizVoting = false;
                if (window.OJP && typeof window.OJP.toast === 'function') {
                    window.OJP.toast(error.message, 'danger');
                } else {
                    window.alert(error.message);
                }
            });
        }

        function renderQuizWordcloud(container, words) {
            container.innerHTML = '';
            var max = (words || []).reduce(function (value, word) {
                return Math.max(value, Number(word.count || 0));
            }, 1);
            (words || []).forEach(function (word) {
                var item = document.createElement('span');
                item.textContent = word.text || '';
                item.title = Number(word.count || 0) + '명';
                item.style.setProperty('--htt-word-weight', String(Number(word.count || 0) / max));
                container.appendChild(item);
            });
        }

        function renderQuizTextResults(items) {
            quizTextResultsEl.innerHTML = '';
            (items || []).forEach(function (text) {
                var item = document.createElement('p');
                item.textContent = text;
                quizTextResultsEl.appendChild(item);
            });
        }

        function renderApprovedQuestions(questions) {
            quizApprovedEl.innerHTML = '';
            var title = document.createElement('h3');
            title.textContent = '공개된 질문';
            quizApprovedEl.appendChild(title);
            if (!(questions || []).length) {
                var empty = document.createElement('p');
                empty.className = 'ojp-empty-state';
                empty.textContent = '아직 공개된 질문이 없습니다.';
                quizApprovedEl.appendChild(empty);
                return;
            }
            var list = document.createElement('ul');
            questions.forEach(function (question) {
                var item = document.createElement('li');
                item.textContent = question.text || '';
                list.appendChild(item);
            });
            quizApprovedEl.appendChild(list);
        }

        function renderQuizState(state) {
            quizQuestionEl.textContent = (state && state.question) || '';
            currentQuizType = (state && state.responseType) || 'choice';
            var options = (state && state.options) || [];
            if (state && Array.isArray(state.myResponses)) {
                localQuizResponses = state.myResponses;
            }
            var myResponses = localQuizResponses || [];
            var myVote = (state && state.myVote !== null && state.myVote !== undefined)
                ? state.myVote
                : (myQuizVote !== null ? myQuizVote : (myResponses[0] && myResponses[0].optionIndex));
            var acceptingResponses = !state || state.acceptingResponses !== false;
            var canRespond = acceptingResponses
                && (currentQuizType === 'qna' ? myResponses.length < 3 : myResponses.length === 0);

            quizOptionsEl.hidden = true;
            quizScaleWrapEl.hidden = true;
            quizResponseFormEl.hidden = true;
            quizVotedEl.hidden = true;
            quizResultsEl.hidden = true;
            quizTextResultsEl.hidden = true;
            quizWordResultsEl.hidden = true;
            quizOwnEl.hidden = true;
            quizApprovedEl.hidden = true;

            if (currentQuizType === 'choice' && canRespond && (myVote === null || myVote === undefined)) {
                quizOptionsEl.hidden = false;
                quizOptionsEl.innerHTML = '';
                options.forEach(function (label, index) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = label;
                    button.disabled = quizVoting;
                    button.addEventListener('click', function () { submitQuizResponse({ optionIndex: index }); });
                    quizOptionsEl.appendChild(button);
                });
            } else if (currentQuizType === 'choice') {
                quizVotedEl.hidden = false;
                quizVotedEl.textContent = myVote === null || myVote === undefined
                    ? '응답 접수가 마감되었습니다.'
                    : '응답했습니다: ' + (options[myVote] || '');
            } else if (currentQuizType === 'scale' && canRespond) {
                var minimum = Number((state && state.scaleMin) || 1);
                var maximum = Number((state && state.scaleMax) || 5);
                quizScaleWrapEl.hidden = false;
                quizScaleEl.innerHTML = '';
                quizScaleEl.style.setProperty('--htt-scale-count', String(maximum - minimum + 1));
                for (var value = minimum; value <= maximum; value += 1) {
                    var scaleButton = document.createElement('button');
                    scaleButton.type = 'button';
                    scaleButton.className = 'ojp-button ojp-button--tinted';
                    scaleButton.textContent = String(value);
                    scaleButton.disabled = quizVoting;
                    scaleButton.addEventListener('click', (function (selectedValue) {
                        return function () { submitQuizResponse({ value: selectedValue }); };
                    }(value)));
                    quizScaleEl.appendChild(scaleButton);
                }
                quizScaleLowEl.textContent = (state && state.scaleLowLabel) || '';
                quizScaleHighEl.textContent = (state && state.scaleHighLabel) || '';
            } else if (currentQuizType === 'scale') {
                quizVotedEl.hidden = false;
                quizVotedEl.textContent = myResponses[0]
                    ? '응답했습니다: ' + myResponses[0].value
                    : '응답 접수가 마감되었습니다.';
            } else {
                quizResponseFormEl.hidden = !canRespond;
                quizResponseTextEl.maxLength = currentQuizType === 'qna' ? 200 : (currentQuizType === 'wordcloud' ? 30 : 100);
                quizResponseTextEl.placeholder = currentQuizType === 'qna'
                    ? '익명 질문을 입력하세요.'
                    : (currentQuizType === 'wordcloud' ? '핵심어를 입력하세요.' : '짧은 답을 입력하세요.');
                quizResponseSubmitEl.textContent = currentQuizType === 'qna' ? '익명으로 보내기' : '보내기';
                quizResponseSubmitEl.disabled = quizVoting;
                if (myResponses.length) {
                    quizOwnEl.hidden = false;
                    if (currentQuizType === 'qna') {
                        quizOwnEl.textContent = '보낸 질문 ' + myResponses.length + '/3';
                    } else {
                        quizOwnEl.textContent = '응답했습니다: ' + (myResponses[0].text || '');
                    }
                } else if (!acceptingResponses) {
                    quizVotedEl.hidden = false;
                    quizVotedEl.textContent = '응답 접수가 마감되었습니다.';
                }
            }

            if (state && state.revealed && state.counts) {
                quizResultsEl.hidden = false;
                if (currentQuizType === 'scale') {
                    var scaleOptions = [];
                    for (var scaleValue = Number(state.scaleMin || 1); scaleValue <= Number(state.scaleMax || 5); scaleValue += 1) {
                        scaleOptions.push(String(scaleValue));
                    }
                    renderQuizBars(quizResultsEl, scaleOptions, state.counts, null);
                } else {
                    renderQuizBars(quizResultsEl, options, state.counts, state.correctIndex);
                }
            }
            if (state && state.revealed && Array.isArray(state.items)) {
                quizTextResultsEl.hidden = false;
                renderQuizTextResults(state.items);
            }
            if (state && state.revealed && Array.isArray(state.words)) {
                quizWordResultsEl.hidden = false;
                renderQuizWordcloud(quizWordResultsEl, state.words);
            }
            if (currentQuizType === 'qna') {
                quizApprovedEl.hidden = false;
                renderApprovedQuestions((state && state.approvedQuestions) || []);
            }
        }

        if (quizResponseFormEl) {
            quizResponseFormEl.addEventListener('submit', function (event) {
                event.preventDefault();
                var text = (quizResponseTextEl.value || '').trim();
                if (!text || quizVoting) {
                    return;
                }
                submitQuizResponse({ text: text });
            });
        }

        function renderGroupingState(state) {
            var groups = (state && state.groups) || [];
            var myGroupIndex = -1;
            groups.forEach(function (group, index) {
                var isMine = (group.members || []).some(function (member) {
                    return member.mine || (member.id && Number(member.id) === Number(config.currentStudentId));
                });
                if (isMine) {
                    myGroupIndex = index;
                }
            });

            groupingMyGroupEl.textContent = myGroupIndex >= 0
                ? ('내 조: ' + groups[myGroupIndex].name)
                : '아직 배정 결과에서 내 조를 찾지 못했습니다.';

            groupingGroupsEl.innerHTML = '';
            groups.forEach(function (group, index) {
                var card = document.createElement('div');
                card.className = 'htt-grouping-card' + (index === myGroupIndex ? ' is-me' : '');

                var title = document.createElement('h3');
                title.textContent = group.name;
                card.appendChild(title);

                var list = document.createElement('ul');
                (group.members || []).forEach(function (member) {
                    var item = document.createElement('li');
                    item.textContent = member.name;
                    list.appendChild(item);
                });
                card.appendChild(list);

                groupingGroupsEl.appendChild(card);
            });
        }

        function renderTimeattackState(state) {
            var problems = (state && state.problems) || [];
            timeattackProblemEl.innerHTML = '';
            if (problems.length) {
                var list = document.createElement('ul');
                problems.forEach(function (item) {
                    var li = document.createElement('li');
                    li.textContent = item.problemId + '. ' + item.title;
                    list.appendChild(li);
                });
                timeattackProblemEl.appendChild(list);
            }
            timeattackClockEl.textContent = formatClock((state && state.remainingSeconds) || 0);
        }

        function classroomSignalLabel(signal) {
            var labels = {
                ready: '준비됐어요',
                understood: '이해했어요',
                unsure: '조금 어려워요',
                repeat: '다시 설명해 주세요',
                help: '도움이 필요해요',
                done: '활동을 마쳤어요',
            };
            return labels[signal] || '';
        }

        function renderClassroomSignal() {
            if (!classroomSignalsEl) {
                return;
            }
            Array.prototype.forEach.call(classroomSignalsEl.querySelectorAll('[data-signal]'), function (button) {
                button.classList.toggle('is-selected', button.getAttribute('data-signal') === classroomSignal);
                button.disabled = classroomSignalSending;
            });
            classroomMySignalEl.textContent = classroomSignal
                ? ('보낸 신호: ' + classroomSignalLabel(classroomSignal))
                : '현재 신호 없음';
        }

        function safeClassroomUrl(value) {
            try {
                var parsed = new URL(String(value || ''), window.location.origin);
                return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
            } catch (error) {
                return '';
            }
        }

        function renderClassroomState(state) {
            classroomState = state && typeof state === 'object' ? state : { activity: null };
            if (Object.prototype.hasOwnProperty.call(classroomState, 'mySignal')) {
                classroomSignal = classroomState.mySignal || '';
                renderClassroomSignal();
            }
            var activity = classroomState.activity || null;
            var activityUrl = activity ? safeClassroomUrl(activity.url) : '';
            if (!activityUrl) {
                activity = null;
            }
            classroomActivityEmptyEl.hidden = Boolean(activity);
            classroomActivityEl.hidden = !activity;
            if (!activity) {
                classroomActivityTitleEl.textContent = '';
                classroomActivityNoteEl.textContent = '';
                classroomActivityNoteEl.hidden = true;
                classroomActivityLinkEl.removeAttribute('href');
                return;
            }

            classroomActivityTitleEl.textContent = activity.title || '수업 활동';
            classroomActivityNoteEl.textContent = activity.note || '';
            classroomActivityNoteEl.hidden = !activity.note;
            classroomActivityLinkEl.href = activityUrl;
        }

        function updateParticipantState(signal) {
            if (!joinCode || activeToolType !== 'classroom' || classroomSignalSending) {
                return;
            }
            classroomSignalSending = true;
            renderClassroomSignal();
            request('/sessions/' + joinCode + '/participant-state', 'POST', { signal: signal }).then(function (data) {
                classroomSignalSending = false;
                classroomSignal = signal;
                renderClassroomSignal();
                if (realtimeConnection) {
                    realtimeConnection.send({
                        t: 'participant-event',
                        event: 'signal',
                        data: {
                            signal: signal,
                            signalAt: data && data.state ? data.state.signalAt : Math.round(Date.now() / 1000),
                        },
                    });
                }
            }).catch(function (error) {
                classroomSignalSending = false;
                renderClassroomSignal();
                if (window.OJP && typeof window.OJP.toast === 'function') {
                    window.OJP.toast(error.message, 'danger');
                }
            });
        }

        function heartbeatClassroom() {
            if (!joinCode || activeToolType !== 'classroom' || document.visibilityState === 'hidden') {
                return;
            }
            request('/sessions/' + joinCode + '/participant-state', 'POST').catch(function () {});
        }

        function startClassroomHeartbeat() {
            if (classroomHeartbeatHandle !== null) {
                window.clearInterval(classroomHeartbeatHandle);
            }
            heartbeatClassroom();
            classroomHeartbeatHandle = window.setInterval(heartbeatClassroom, CLASSROOM_HEARTBEAT_INTERVAL_MS);
        }

        function renderState(data) {
            activeToolType = data.toolType || '';
            root.classList.toggle('is-whiteboard-active', activeToolType === 'whiteboard');
            root.classList.toggle('is-quickboard-active', activeToolType === 'quickboard');
            root.classList.toggle('is-classroom-active', activeToolType === 'classroom');
            timerView.hidden = true;
            seatingView.hidden = true;
            pickerView.hidden = true;
            ladderView.hidden = true;
            whiteboardView.hidden = true;
            quickboardView.hidden = true;
            quizView.hidden = true;
            groupingView.hidden = true;
            timeattackView.hidden = true;
            classroomView.hidden = true;
            unsupportedView.hidden = true;

            if (data.toolType === 'timer') {
                timerView.hidden = false;
                var timerState = data.state || {};
                var timerRemaining = timerState.remainingSeconds || 0;
                timerClockEl.textContent = formatClock(timerRemaining);
                var timerEnded = timerRemaining <= 0;
                timerClockEl.classList.toggle('is-ended', timerEnded);
                if (timerEndedEl) {
                    timerEndedEl.hidden = !timerEnded;
                }
                if (timerEnded) {
                    if (!timerSoundPlayed && window.HttTimerSound) {
                        window.HttTimerSound.play(timerState.sound);
                    }
                    timerSoundPlayed = true;
                } else {
                    timerSoundPlayed = false;
                }
            } else if (data.toolType === 'seating') {
                seatingView.hidden = false;
                renderSeatingState(data.state);
            } else if (data.toolType === 'picker') {
                pickerView.hidden = false;
                renderPickerState(data.state);
            } else if (data.toolType === 'ladder') {
                ladderView.hidden = false;
                renderLadderState(data.state);
            } else if (data.toolType === 'whiteboard') {
                whiteboardView.hidden = false;
                if (acceptWhiteboardState(data.state)) { realtimeWhiteboardState = data.state || {}; }
                if (!realtimeOpen) {
                    realtimePreview = null;
                }
                renderWhiteboardState(realtimeWhiteboardState, realtimePreview);
            } else if (data.toolType === 'quickboard') {
                quickboardView.hidden = false;
                renderQuickboardState(data.state);
            } else if (data.toolType === 'quiz') {
                quizView.hidden = false;
                renderQuizState(data.state);
            } else if (data.toolType === 'grouping') {
                groupingView.hidden = false;
                renderGroupingState(data.state);
            } else if (data.toolType === 'timeattack') {
                timeattackView.hidden = false;
                renderTimeattackState(data.state);
            } else if (data.toolType === 'classroom') {
                classroomView.hidden = false;
                renderClassroomState(data.state);
                renderClassroomSignal();
            } else {
                unsupportedView.hidden = false;
            }
        }

        function pollInterval() {
            if (activeToolType === 'whiteboard') {
                return realtimeOpen ? 5000 : WHITEBOARD_POLL_INTERVAL_MS;
            }
            if (activeToolType === 'classroom') {
                return CLASSROOM_POLL_INTERVAL_MS;
            }
            if (activeToolType === 'quickboard') {
                return QUICKBOARD_POLL_INTERVAL_MS;
            }
            return DEFAULT_POLL_INTERVAL_MS;
        }

        function schedulePoll(delay) {
            if (!joinCode || pollHandle !== null || pollInFlight) {
                return;
            }
            pollHandle = window.setTimeout(function () {
                pollHandle = null;
                poll();
            }, typeof delay === 'number' ? delay : pollInterval());
        }

        function poll() {
            if (!joinCode) {
                return;
            }
            if (pollHandle !== null) {
                window.clearTimeout(pollHandle);
                pollHandle = null;
            }
            if (pollInFlight) {
                pollRequested = true;
                return;
            }

            pollInFlight = true;
            pollRequested = false;
            var requestedCode = joinCode;
            var requestedEpoch = participationEpoch;
            var requestedAt = Date.now();
            request('/sessions/' + requestedCode, 'GET').then(function (data) {
                if (requestedEpoch !== participationEpoch) { return; }
                if (requestedCode !== joinCode) { pollInFlight = false; schedulePoll(0); return; }
                // Keep fresh live ink, but recover from an authenticated socket that stops relaying.
                if (activeToolType !== 'whiteboard' || !realtimeOpen || data.status !== 'active'
                    || (lastWhiteboardMessageAt <= requestedAt && Date.now() - lastWhiteboardMessageAt > 2000)) {
                    renderState(data);
                }
                if (whiteboardConnection) { whiteboardConnection.textContent = realtimeOpen ? '실시간 연결됨' : '자동 새로고침으로 연결됨'; }
                pollInFlight = false;
                schedulePoll(pollRequested ? 0 : pollInterval());
            }).catch(function (error) {
                if (requestedEpoch !== participationEpoch) { return; }
                pollInFlight = false;
                pollRequested = false;
                if (requestedCode !== joinCode) { schedulePoll(0); return; }
                if (whiteboardConnection) { whiteboardConnection.textContent = '연결 확인 중 · 마지막 판서를 표시하고 있습니다.'; }
                if (error && [400, 401, 403, 404, 410].indexOf(Number(error.status || 0)) >= 0) {
                    forgetClassroomSession();
                    joinCode = null;
                    if (realtimeConnection) { realtimeConnection.stop(); realtimeConnection = null; }
                    if (classroomHeartbeatHandle !== null) { window.clearInterval(classroomHeartbeatHandle); classroomHeartbeatHandle = null; }
                    form.hidden = false;
                    view.hidden = true;
                    discoverAssignedClassroom();
                } else {
                    schedulePoll(5000);
                }
            });
        }

        function stopPollTimer() {
            if (pollHandle !== null) {
                window.clearTimeout(pollHandle);
                pollHandle = null;
            }
        }

        function startRealtime() {
            if (!window.HttRealtime || !joinCode) {
                return;
            }
            if (realtimeConnection) {
                realtimeConnection.stop();
            }

            realtimeConnection = window.HttRealtime.connect({
                ticket: function () {
                    return request('/sessions/' + joinCode + '/realtime-ticket', 'POST', { role: 'viewer' });
                },
                onStatus: function (status, detail) {
                    realtimeOpen = status === 'open' && (!detail || detail.hostOnline !== false);
                    if (realtimeOpen) {
                        if (activeToolType === 'classroom') {
                            classroomConnectionEl.textContent = '실시간으로 연결되었습니다.';
                            schedulePoll(CLASSROOM_POLL_INTERVAL_MS);
                        } else {
                            schedulePoll();
                        }
                    } else {
                        realtimePreview = null;
                        if (activeToolType === 'classroom') {
                            classroomConnectionEl.textContent = '자동 새로고침으로 연결 상태를 확인하고 있습니다.';
                        }
                        schedulePoll(0);
                    }
                },
                onMessage: function (message) {
                    if (message.t === 'snapshot' && message.state && activeToolType === 'whiteboard') {
                        if (!acceptWhiteboardState(message.state)) { return; }
                        lastWhiteboardMessageAt = Date.now();
                        realtimeWhiteboardState = message.state;
                        realtimePreview = null;
                        renderWhiteboardState(realtimeWhiteboardState, null);
                    } else if (message.t === 'preview-start' && message.element) {
                        lastWhiteboardMessageAt = Date.now();
                        realtimePreview = {
                            id: String(message.id || ''),
                            page: message.page,
                            element: message.element,
                        };
                        renderWhiteboardState(realtimeWhiteboardState, realtimePreview);
                    } else if (
                        message.t === 'preview-points'
                        && realtimePreview
                        && realtimePreview.id === String(message.id || '')
                        && realtimePreview.element
                        && Array.isArray(realtimePreview.element.points)
                        && Array.isArray(message.points)
                    ) {
                        lastWhiteboardMessageAt = Date.now();
                        Array.prototype.push.apply(realtimePreview.element.points, message.points);
                        renderWhiteboardState(realtimeWhiteboardState, realtimePreview);
                    } else if (message.t === 'preview-shape' && message.element) {
                        lastWhiteboardMessageAt = Date.now();
                        realtimePreview = {
                            id: String(message.id || ''),
                            page: message.page,
                            element: message.element,
                        };
                        renderWhiteboardState(realtimeWhiteboardState, realtimePreview);
                    } else if (message.t === 'preview' && realtimeWhiteboardState) {
                        lastWhiteboardMessageAt = Date.now();
                        realtimePreview = message;
                        renderWhiteboardState(realtimeWhiteboardState, realtimePreview);
                    } else if (message.t === 'host-online') {
                        realtimeOpen = true;
                        if (activeToolType === 'classroom') {
                            classroomConnectionEl.textContent = '실시간으로 연결되었습니다.';
                            schedulePoll(CLASSROOM_POLL_INTERVAL_MS);
                        } else {
                            schedulePoll();
                        }
                    } else if (message.t === 'host-offline') {
                        realtimeOpen = false;
                        realtimePreview = null;
                        if (activeToolType === 'classroom') {
                            classroomConnectionEl.textContent = '수업 화면에 다시 연결하고 있습니다.';
                        }
                        schedulePoll(0);
                    } else if (message.t === 'closed') {
                        realtimeOpen = false;
                        realtimePreview = null;
                        if (activeToolType === 'classroom') {
                            forgetClassroomSession();
                            classroomConnectionEl.textContent = '수업이 종료되었습니다.';
                        }
                        schedulePoll(0);
                    } else if (message.t === 'snapshot' && message.state && activeToolType === 'classroom') {
                        renderClassroomState(message.state);
                    } else if (message.t === 'event' && message.event === 'activity' && activeToolType === 'classroom') {
                        renderClassroomState({ activity: message.data || null });
                    } else if (message.t === 'event' && message.event === 'signals-cleared' && activeToolType === 'classroom') {
                        var clearedId = message.data ? Number(message.data.id || 0) : 0;
                        if (clearedId === 0 || clearedId === Number(config.currentStudentId)) {
                            classroomSignal = '';
                            renderClassroomSignal();
                        }
                    } else if (message.t === 'event' && message.event === 'boards-cleared' && activeToolType === 'quickboard') {
                        var clearedBoardId = message.data ? Number(message.data.id || 0) : 0;
                        if (clearedBoardId === 0 || clearedBoardId === Number(config.currentStudentId)) {
                            resetQuickboard([], 0, '선생님이 판서를 지웠습니다. 새로 그려 제출하세요.');
                        }
                    }
                },
            });
        }

        function isExpanded() { return document.fullscreenElement === root || root.classList.contains('is-expanded'); }

        function fitStudentCanvas() {
            if (whiteboardViewer) { whiteboardViewer.refresh(); }
        }

        function updateFullscreenButton() {
            var expanded = isExpanded();
            var label = expanded ? '전체화면 해제' : '전체화면';
            fullscreenButton.setAttribute('aria-pressed', String(expanded));
            fullscreenButton.setAttribute('aria-label', label);
            fullscreenButton.title = label;
            window.requestAnimationFrame(fitStudentCanvas);
        }

        function leaveExpanded() {
            root.classList.remove('is-expanded');
            if (expandedPlaceholder) { expandedPlaceholder.replaceWith(root); expandedPlaceholder = null; }
            updateFullscreenButton();
        }

        function expandInWindow() {
            if (!joinCode) { return; }
            expandedPlaceholder = document.createComment('student activity position');
            root.before(expandedPlaceholder);
            document.body.appendChild(root);
            root.classList.add('is-expanded');
            updateFullscreenButton();
            fullscreenButton.focus();
        }

        function exitStudentFullscreen() {
            leaveExpanded();
            if (document.fullscreenElement === root && document.exitFullscreen) {
                return Promise.resolve(document.exitFullscreen()).catch(function () {});
            }
            return Promise.resolve();
        }

        fullscreenButton.addEventListener('click', function () {
            if (isExpanded()) { exitStudentFullscreen(); return; }
            var requestedEpoch = participationEpoch;
            try {
                if ((window.HttWhiteboardRender && window.HttWhiteboardRender.prefersWindowExpansion()) || !root.requestFullscreen) { expandInWindow(); return; }
                Promise.resolve(root.requestFullscreen()).then(function () {
                    if (requestedEpoch !== participationEpoch) { exitStudentFullscreen(); }
                }).catch(function () { if (requestedEpoch === participationEpoch) { expandInWindow(); } });
            } catch (error) { if (requestedEpoch === participationEpoch) { expandInWindow(); } }
        });
        document.addEventListener('fullscreenchange', updateFullscreenButton);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root.classList.contains('is-expanded')) { leaveExpanded(); fullscreenButton.focus(); }
        });
        window.addEventListener('resize', fitStudentCanvas);
        if (window.ResizeObserver) {
            var studentResizeObserver = new ResizeObserver(fitStudentCanvas);
            studentResizeObserver.observe(root);
            studentResizeObserver.observe(root.querySelector('.htt-participation-controls'));
        }

        function leaveSession() {
            if (!joinCode) { return; }
            var leavingCode = joinCode;
            var classroom = activeToolType === 'classroom';
            participationEpoch += 1;
            autoJoinPaused = true;
            if (window.HttActivityNavigation) { window.HttActivityNavigation.dismiss(leavingCode, config.currentStudentId); }
            if (classroom) {
                forgetClassroomSession();
                try {
                    window.localStorage.setItem(CLASSROOM_DISMISSED_STORAGE_KEY, JSON.stringify({ code: leavingCode, studentId: Number(config.currentStudentId), dismissedAt: Date.now() }));
                } catch (error) {}
            }
            joinCode = null;
            stopPollTimer();
            if (realtimeConnection) { realtimeConnection.stop(); realtimeConnection = null; }
            if (classroomHeartbeatHandle !== null) { window.clearInterval(classroomHeartbeatHandle); classroomHeartbeatHandle = null; }
            activeRequests.forEach(function (abort) { abort.abort(); });
            activeRequests.clear();
            pollInFlight = false;
            pollRequested = false;
            joinInFlight = false;
            realtimeOpen = false;
            realtimeWhiteboardState = null;
            realtimePreview = null;
            if (whiteboardPaintFrame !== null) { window.cancelAnimationFrame(whiteboardPaintFrame); whiteboardPaintFrame = null; }
            pendingWhiteboardPaint = null;
            whiteboardBackgroundGeneration++;
            activeToolType = '';
            config.prefillCode = '';
            codeInput.value = '';
            errorEl.hidden = true;
            form.hidden = false;
            view.hidden = true;
            root.classList.remove('is-whiteboard-active', 'is-quickboard-active', 'is-classroom-active');
            participationNotice.textContent = '수업 참여를 종료했습니다.';
            participationNotice.hidden = false;
            try {
                var url = new URL(window.location.href);
                url.searchParams.delete('code');
                window.history.replaceState(window.history.state, '', url.href);
            } catch (error) {}
            exitStudentFullscreen().then(function () { codeInput.focus(); });
            if (classroom) { request('/sessions/' + leavingCode + '/participant-state', 'POST', { signal: '' }).catch(function () {}); }
        }
        leaveButton.addEventListener('click', leaveSession);

        function openJoinedSession(code, data) {
            stopPollTimer();
            if (whiteboardPaintFrame !== null) { window.cancelAnimationFrame(whiteboardPaintFrame); whiteboardPaintFrame = null; }
            pendingWhiteboardPaint = null;
            realtimeWhiteboardState = null;
            realtimePreview = null;
            if (realtimeConnection) { realtimeConnection.stop(); realtimeConnection = null; }
            if (classroomHeartbeatHandle !== null) { window.clearInterval(classroomHeartbeatHandle); classroomHeartbeatHandle = null; }
            realtimeOpen = false;
            lastWhiteboardMessageAt = 0;
            whiteboardRevision = 0;
            myLadderResult = null;
            ladderAnimationStarted = false;
            myQuizVote = null;
            localQuizResponses = [];
            quickboardElements = [];
            quickboardRevision = 0;
            quickboardDirty = false;
            lastWhiteboardSignature = '';
            lastWhiteboardPaintState = null;
            lastWhiteboardBackground = '';
            whiteboardBackgroundGeneration++;
            if (whiteboardViewer) { whiteboardViewer.reset(); }
            joinCode = code;
            participationNotice.hidden = true;
            participationTitle.textContent = '수업 참여 중 · ' + code;
            if (window.HttActivityNavigation) { window.HttActivityNavigation.mark(code, config.currentStudentId); }
            form.hidden = true;
            view.hidden = false;
            renderState(data);
            window.requestAnimationFrame(fitStudentCanvas);
            if (data.toolType === 'classroom') {
                rememberClassroomSession(code);
            }
            schedulePoll();
            if (data.toolType === 'whiteboard' || data.toolType === 'classroom' || data.toolType === 'quickboard') {
                startRealtime();
            }
            if (data.toolType === 'classroom') {
                startClassroomHeartbeat();
            }
        }

        function discoverAssignedClassroom() {
            if (classroomDiscoveryInFlight || document.visibilityState === 'hidden') {
                return;
            }
            classroomDiscoveryInFlight = true;
            request('/sessions/assigned', 'GET').then(function (data) {
                var items = (data.items || []).filter(function (item) {
                    return /^[A-Z0-9]{6}$/.test(item.joinCode || '');
                });
                var signature = JSON.stringify(items);
                if (assignedEl && signature !== assignedSignature) {
                    assignedSignature = signature;
                    assignedEl.innerHTML = '';
                    assignedEl.hidden = !items.length;
                    items.forEach(function (item) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'ojp-button ojp-button--plain';
                        button.textContent = item.label + (item.contextLabel ? ' · ' + item.contextLabel : '');
                        button.addEventListener('click', function () { joinAssigned(item.joinCode); });
                        assignedEl.appendChild(button);
                    });
                }
                if (autoJoinPaused) { return; }
                var navigation = window.HttActivityNavigation;
                var eligible = items.filter(function (item) { return !navigation || !navigation.isDismissed(item.joinCode, config.currentStudentId); });
                var automatic = navigation && navigation.next(items, joinCode, config.currentStudentId);
                var selected = automatic || eligible.find(function (item) { return item.joinCode === config.prefillCode; });
                if (!selected) { selected = eligible.find(function (item) { return item.toolType !== 'classroom'; }) || eligible[0]; }
                if (selected && (automatic || !joinCode || activeToolType === 'classroom' && selected.toolType !== 'classroom') && !isClassroomDismissed(selected.joinCode)) {
                    return joinAssigned(selected.joinCode, true);
                }
            }).catch(function () {
                // Keep the normal code-entry form available when discovery fails.
            }).finally(function () {
                classroomDiscoveryInFlight = false;
            });
        }

        function joinAssigned(code, automatic) {
            if (joinInFlight || code === joinCode) { return Promise.resolve(); }
            if (automatic && window.HttActivityNavigation && window.HttActivityNavigation.isDismissed(code, config.currentStudentId)) { return Promise.resolve(); }
            var requestedEpoch = participationEpoch;
            joinInFlight = true;
            return request('/sessions/' + code + '/join', 'POST').then(function (data) {
                if (requestedEpoch !== participationEpoch) { return; }
                if (!automatic) {
                    autoJoinPaused = false;
                    if (window.HttActivityNavigation) { window.HttActivityNavigation.resume(code, config.currentStudentId); }
                }
                errorEl.hidden = true;
                openJoinedSession(code, data);
            }).catch(function (error) {
                if (requestedEpoch !== participationEpoch) { return; }
                errorEl.textContent = error.message;
                errorEl.hidden = false;
            }).finally(function () { if (requestedEpoch === participationEpoch) { joinInFlight = false; } });
        }

        if (classroomSignalsEl) {
            classroomSignalsEl.addEventListener('click', function (event) {
                var button = event.target.closest('[data-signal]');
                if (!button) {
                    return;
                }
                var signal = button.getAttribute('data-signal') || '';
                updateParticipantState(signal === classroomSignal ? '' : signal);
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                heartbeatClassroom();
            }
        });

        joinButton.addEventListener('click', function () {
            var code = (codeInput.value || '').trim().toUpperCase();
            errorEl.hidden = true;

            if (code.length !== 6) {
                errorEl.textContent = '6자리 참여 코드를 입력해 주세요.';
                errorEl.hidden = false;
                return;
            }

            joinAssigned(code);
        });
        codeInput.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); joinButton.click(); } });

        discoverAssignedClassroom();
        classroomDiscoveryHandle = window.setInterval(discoverAssignedClassroom, CLASSROOM_DISCOVERY_INTERVAL_MS);
        if (/^[A-Z0-9]{6}$/.test(config.prefillCode || '')) { joinAssigned(config.prefillCode, true); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initialize); }
    else { initialize(); }
})();

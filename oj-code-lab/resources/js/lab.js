/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
/** Student notebook UI. Python execution is confined to a cancellable Web Worker. */
(function () {
    'use strict';

    // Pinned CDN build — bump deliberately, never track "latest".
    var PYODIDE_VERSION = '0.26.4';
    var PYODIDE_INDEX_URL = 'https://cdn.jsdelivr.net/pyodide/v' + PYODIDE_VERSION + '/full/';

    // Lucide "play" and "bug" for the cell buttons, styled by the platform .ojp-icon rule.
    var ICON_OPEN = '<svg class="ojp-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">';
    var ICON_PLAY = ICON_OPEN + '<polygon points="6 3 20 12 6 21 6 3"/></svg>';
    var ICON_BUG = ICON_OPEN + '<path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/>'
        + '<path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/>'
        + '<path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/>'
        + '<path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ocl-lab]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var cellsEl = root.querySelector('[data-field="cells"]');
        var statusEl = root.querySelector('[data-field="status"]');
        var cells = [];
        var dirty = false;
        var saving = false;
        var notebookLoaded = false;
        var editRevision = 0;
        var serverRevision = 0;
        var saveConflict = false;
        var pendingInput = null;
        var editors = new Map();
        var editorManager = window.OclCellEditors ? new window.OclCellEditors(config.editor) : null;

        function setStatus(text) {
            statusEl.textContent = text || '';
        }

        function uid() {
            return 'c' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
        }

        function apiFetch(path, options) {
            options = options || {};
            options.headers = Object.assign({ 'X-WP-Nonce': config.nonce }, options.headers || {});
            options.credentials = 'same-origin';
            return fetch(config.restUrl + path, options);
        }

        function apiJson(path, options) {
            return apiFetch(path, options).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                    }
                    return data;
                });
            });
        }

        function markDirty() {
            dirty = true;
            editRevision += 1;
        }

        var runGeneration = 0;
        var Runtime = new window.OclWorkerRuntime({
            url: config.workerUrl,
            indexUrl: PYODIDE_INDEX_URL,
            onStatus: setStatus,
            onInput: showInlineInput,
        });

        function showInlineInput(cellId, onSubmit, onCancel) {
            var cell = cellId ? findCell(cellId) : null;
            var outputEl = cellId ? cellElOutput(cellId) : null;
            if (!outputEl) {
                // No cell context (shouldn't normally happen) — still let the
                // student answer via the notebook-wide status area's box.
                outputEl = cellsEl;
            }

            var runningIndicator = outputEl.querySelector('.ocl-output-running');
            if (runningIndicator) {
                runningIndicator.remove();
            }

            // If the line just printed is still "open" (no trailing
            // newline yet — e.g. input("이름: ") writes "이름: " via
            // input()'s own prompt, with nothing after it), attach the
            // input box to the end of that exact line instead of starting
            // a new block below it — matches a real terminal, where a
            // prompt and what you type after it share one line.
            var lastOutput = cell && cell.outputs.length ? cell.outputs[cell.outputs.length - 1] : null;
            var lastChild = outputEl.lastElementChild;
            var inlineTarget = null;
            if (
                lastOutput && 'stream' === lastOutput.type && lastOutput.text && !/\n$/.test(lastOutput.text)
                && lastChild && 'PRE' === lastChild.tagName && lastChild.classList.contains('ocl-output-text')
                && !lastChild.classList.contains('ocl-input-echo')
            ) {
                inlineTarget = lastChild;
            }

            var wrap = document.createElement('form');
            wrap.className = inlineTarget ? 'ocl-input-request ocl-input-request-inline' : 'ocl-input-request';
            wrap.innerHTML = (inlineTarget ? '' : '<label>입력을 기다리는 중입니다 (input())</label>')
                + '<span class="ocl-input-request-row">'
                + '<input type="text" class="ojp-input" autocomplete="off" aria-label="입력">'
                + '<button type="submit" class="ojp-button ojp-button--tinted ojp-button--small ocl-btn">전달</button>'
                + '<button type="button" class="ojp-button ojp-button--plain ojp-button--small ocl-btn" data-action="cancel">취소</button>'
                + '</span>';

            var input = wrap.querySelector('input');
            (inlineTarget || outputEl).appendChild(wrap);
            input.focus();

            // Persisted into cell.outputs (not just drawn on screen) so the
            // record of what was typed survives the next render pass and
            // "저장" — otherwise it's indistinguishable from a value that
            // was never recorded at all once the cell finishes running.
            function finish(text) {
                pendingInput = null;
                wrap.remove();

                if (inlineTarget) {
                    // Folded into the same stream entry (not a separate
                    // output item) so it stays on the same visual line and
                    // any later print() in this cell keeps merging into
                    // this same block correctly.
                    lastOutput.text += text + '\n';
                    inlineTarget.appendChild(document.createTextNode(text + '\n'));
                    return;
                }

                var output = { type: 'input', text: '> ' + text };
                if (cell) {
                    cell.outputs.push(output);
                }
                appendOutputEl(outputEl, output);
            }

            wrap.addEventListener('submit', function (event) {
                event.preventDefault();
                var value = input.value;
                finish(value);
                onSubmit(value);
            });

            function cancelInput() {
                finish('(입력 취소됨 — EOFError)');
                onCancel();
            }
            pendingInput = { cellId: cellId, cancel: cancelInput };
            wrap.querySelector('[data-action="cancel"]').addEventListener('click', cancelInput);
        }

        // ---- Cells --------------------------------------------------------

        function newCell(code, type) {
            return { id: uid(), type: type || 'code', code: code || '', outputs: [] };
        }

        function renderAll() {
            editors.forEach(function (editor) { editor.destroy(); });
            editors.clear();
            cellsEl.innerHTML = '';
            cellsEl.appendChild(renderInsertControl(null));
            cells.forEach(function (cell) {
                var wrap = renderCell(cell);
                cellsEl.appendChild(wrap);
                cellsEl.appendChild(renderInsertControl(cell.id));
                // Sizing a textarea by scrollHeight only works once it has
                // real layout, so this has to happen after the DOM insert
                // above, not while building the detached node in renderCell().
                mountEditor(cell, wrap);
            });
        }

        function renderInsertControl(afterCellId) {
            var row = document.createElement('div');
            row.className = 'ocl-cell-insert';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ojp-button ojp-button--small ocl-btn ocl-btn-insert';
            button.textContent = '+ 코드';
            button.setAttribute('aria-label', '여기에 코드 셀 추가');
            button.title = '이 위치에 코드 셀 추가';
            button.addEventListener('click', function () {
                var cell = addCell('', afterCellId);
                if (!cell) { return; }
                focusCell(cell.id);
            });
            row.appendChild(button);
            var textButton = document.createElement('button');
            textButton.type = 'button';
            textButton.className = 'ojp-button ojp-button--small ocl-btn ocl-btn-insert-text';
            textButton.textContent = '+ 텍스트';
            textButton.setAttribute('aria-label', '여기에 텍스트 셀 추가');
            textButton.addEventListener('click', function () {
                var cell = addCell('', afterCellId, 'text');
                if (cell) { focusCell(cell.id); }
            });
            row.appendChild(textButton);
            return row;
        }

        function focusCell(id) {
            var wrap = cellElement(id);
            var editor = editors.get(id);
            if (editor) { editor.focus(); }
            else { wrap.querySelector('.ocl-cell-input').focus({ preventScroll: true }); }
            wrap.scrollIntoView({ block: 'nearest' });
        }

        function mountEditor(cell, wrap) {
            var textarea = wrap.querySelector('.ocl-cell-input');
            autoResizeTextarea(textarea);
            if (cell.type === 'code' && editorManager) {
                editors.set(cell.id, editorManager.attach(textarea, function () {
                    cell.code = textarea.value;
                    markDirty();
                    onCellEdited(cell.id);
                }, function () { runCell(cell.id); }));
            } else if (cell.type === 'text' && window.OclTextCell) {
                editors.set(cell.id, window.OclTextCell.attach(textarea, function () {
                    cell.code = textarea.value;
                    markDirty();
                    autoResizeTextarea(textarea);
                }));
            }
        }

        // Grows a code textarea to fit all of its content — no cap, no
        // internal scrollbar — so a long cell is never clipped to a fixed
        // number of visible rows. The page itself scrolls normally past it.
        function autoResizeTextarea(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = textarea.scrollHeight + 'px';
        }

        function renderCell(cell) {
            var wrap = document.createElement('div');
            wrap.className = 'ocl-cell' + (cell.type === 'text' ? ' ocl-text-cell' : '');
            wrap.dataset.cellId = cell.id;

            var toolbar = document.createElement('div');
            toolbar.className = 'ocl-cell-toolbar';

            var runBtn = document.createElement('button');
            runBtn.type = 'button';
            runBtn.className = 'ojp-button ojp-button--tinted ojp-button--small ocl-btn ocl-btn-run';
            runBtn.innerHTML = ICON_PLAY + '<span>실행</span>';
            runBtn.title = '이 셀 실행 (Ctrl+Enter)';
            runBtn.addEventListener('click', function () { runCell(cell.id); });

            var debugBtn = document.createElement('button');
            debugBtn.type = 'button';
            debugBtn.className = 'ojp-button ojp-button--plain ojp-button--small ocl-btn ocl-btn-debug';
            debugBtn.innerHTML = ICON_BUG + '<span>디버그</span>';
            debugBtn.title = '한 줄씩 실행하며 변수 값 확인';
            debugBtn.addEventListener('click', function () { debugCell(cell.id); });

            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'ojp-button ojp-button--plain ojp-button--small ocl-btn ocl-btn-danger';
            delBtn.textContent = '삭제';
            delBtn.addEventListener('click', function () { deleteCell(cell.id); });

            if (cell.type === 'code') { toolbar.appendChild(runBtn); toolbar.appendChild(debugBtn); }
            var label = document.createElement('span');
            label.className = 'ocl-cell-label';
            label.textContent = cell.type === 'text' ? '텍스트 · Markdown' : 'Python · Ctrl+Enter 실행';
            toolbar.appendChild(label);
            toolbar.appendChild(delBtn);

            var textarea = document.createElement('textarea');
            textarea.className = 'ocl-cell-input ' + (cell.type === 'text' ? 'ocl-text' : 'ocl-code');
            textarea.setAttribute('aria-label', cell.type === 'text' ? '마크다운 텍스트' : 'Python 코드');
            textarea.spellcheck = cell.type === 'text';
            if (cell.type === 'text') { textarea.placeholder = '# 제목\n설명, 풀이 과정, 배운 점을 마크다운으로 기록하세요.'; }
            textarea.value = cell.code;
            textarea.rows = 3;
            textarea.addEventListener('input', function () {
                cell.code = textarea.value;
                markDirty();
                onCellEdited(cell.id);
                autoResizeTextarea(textarea);
            });
            textarea.addEventListener('keydown', function (event) {
                var isRunCombo = (event.ctrlKey || event.metaKey) && event.key === 'Enter';
                if (isRunCombo && cell.type === 'code' && !event.isComposing) {
                    event.preventDefault();
                    runCell(cell.id);
                }
            });

            var output = document.createElement('div');
            output.className = 'ocl-output';
            renderOutputs(output, cell.outputs);

            wrap.appendChild(toolbar);
            wrap.appendChild(textarea);
            if (cell.type === 'code') { wrap.appendChild(output); }

            return wrap;
        }

        // Cold rendering (loading a saved notebook, clearing a cell, …):
        // wipes outputEl and redraws every item from scratch.
        function renderOutputs(outputEl, outputs) {
            outputEl.innerHTML = '';
            outputs.forEach(function (o) {
                appendOutputEl(outputEl, o);
            });
        }

        // Appends exactly one output item's DOM representation — shared by
        // renderOutputs() (bulk, cold) and runOneCell()/showInlineInput()
        // (incremental, live) so both draw identically. Returns the created
        // element (or null for an empty/unrecognized item) so a caller that
        // wants to keep streaming into the *same* text block can find it
        // again — see runOneCell()'s stream-merging.
        function appendOutputEl(outputEl, o) {
            var running = outputEl.querySelector('.ocl-output-running');
            if (running) {
                running.remove();
            }

            if ('image' === o.type && o.image) {
                var img = document.createElement('img');
                img.className = 'ocl-output-image';
                img.src = 'data:image/png;base64,' + o.image;
                img.alt = '';
                outputEl.appendChild(img);
                return img;
            }

            if (!o.text) {
                return null;
            }

            var pre = document.createElement('pre');
            pre.className = 'error' === o.type
                ? 'ocl-output-error'
                : ('input' === o.type ? 'ocl-output-text ocl-input-echo' : 'ocl-output-text');
            pre.textContent = o.text;
            outputEl.appendChild(pre);
            return pre;
        }

        function findCell(id) {
            for (var i = 0; i < cells.length; i++) {
                if (cells[i].id === id) {
                    return cells[i];
                }
            }
            return null;
        }

        function cellElement(id) {
            return Array.from(cellsEl.querySelectorAll('[data-cell-id]')).find(function (item) { return item.dataset.cellId === id; });
        }

        function cellElOutput(id) {
            var wrap = cellElement(id);
            return wrap ? wrap.querySelector('.ocl-output') : null;
        }

        function deleteCell(id) {
            if (activeDebug && activeDebug.cellId === id) { stop(); }
            onCellEdited(id);
            if (pendingInput && pendingInput.cellId === id) { pendingInput.cancel(); }
            if (editors.has(id)) { editors.get(id).destroy(); editors.delete(id); }
            cells = cells.filter(function (c) { return c.id !== id; });
            Array.from(cellsEl.querySelectorAll('[data-cell-id]')).forEach(function (wrap) {
                if (wrap.dataset.cellId === id) {
                    wrap.nextElementSibling.remove();
                    wrap.remove();
                }
            });
            markDirty();
        }

        function addCell(code, afterCellId, type) {
            var index = cells.length;
            if (afterCellId === null) {
                index = 0;
            } else if (typeof afterCellId === 'string') {
                index = cells.findIndex(function (item) { return item.id === afterCellId; });
                if (index < 0) { return null; }
                index += 1;
            }
            var beforeNode = index < cells.length ? cellElement(cells[index].id) : null;
            var cell = newCell(code, type);
            cells.splice(index, 0, cell);
            markDirty();
            // Keep existing output nodes and any active stdin form mounted.
            var wrap = renderCell(cell);
            cellsEl.insertBefore(wrap, beforeNode);
            cellsEl.insertBefore(renderInsertControl(cell.id), beforeNode);
            mountEditor(cell, wrap);
            return cell;
        }

        // Runs one cell, building cell.outputs (and the matching DOM)
        // incrementally as things actually happen — a stream chunk the
        // moment it's printed, an input echo the moment it's answered —
        // rather than replacing the whole output area once at the very
        // end. That final wholesale replace was exactly what made an
        // earlier input's echoed value disappear the moment the cell
        // finished, and what made everything printed before a later
        // input() call invisible until the entire cell was done.
        function runOneCell(cell, debug) {
            var outputEl;
            var runningIndicator = null;
            var lastStreamEl = null;
            var session = null;
            function begin() {
                outputEl = cellElOutput(cell.id);
                cell.outputs = [];
                markDirty();
            if (outputEl) {
                outputEl.innerHTML = '';
                runningIndicator = document.createElement('p');
                runningIndicator.className = 'ocl-output-running';
                runningIndicator.textContent = debug ? '디버그 실행 중…' : '실행 중…';
                outputEl.appendChild(runningIndicator);
            }
                if (debugReview && debugReview.cellId === cell.id) { closeDebugSession(debugReview); }
                if (debug) { session = startDebugSession(cell, outputEl); }
            }

            function appendOutput(output) {
                markDirty();
                // Consecutive stream chunks merge into one growing text
                // block instead of one <pre> per individual write() call
                // (print() alone can trigger two: the text, then its
                // trailing newline) — otherwise output would look like a
                // stack of tiny fragments instead of continuous text.
                if ('stream' === output.type) {
                    var last = cell.outputs[cell.outputs.length - 1];
                    if (last && 'stream' === last.type) {
                        last.text += output.text;
                        if (lastStreamEl) {
                            lastStreamEl.textContent = last.text;
                        }
                        return;
                    }
                }

                cell.outputs.push(output);
                var el = outputEl ? appendOutputEl(outputEl, output) : null;
                lastStreamEl = 'stream' === output.type ? el : null;
            }

            var onStream = function (text) {
                appendOutput({ type: 'stream', text: text });
            };
            var execution = debug
                ? Runtime.debug(cell.id, cell.code, onStream, begin, function (snapshot, resume) {
                    if (session) { showDebugPause(session, snapshot, resume); }
                }, cellBreakpoints(cell.id))
                : Runtime.run(cell.id, cell.code, onStream, begin);
            return execution.then(function (result) {
                if (session && result.debugEnd) { recordDebugStep(session, result.debugEnd); }
                if (result.error) {
                    appendOutput({ type: 'error', text: result.error });
                }
                (result.images || []).forEach(function (image) {
                    appendOutput({ type: 'image', image: image });
                });
            }).catch(function (error) {
                if (!error.cancelled) { appendOutput({ type: 'error', text: String((error && error.message) || error) }); }
            }).finally(function () {
                // A queued run may already have started in the same cell.
                // Remove only the indicator created for this completed run.
                if (runningIndicator) { runningIndicator.remove(); }
                if (session) { finishDebugSession(session); }
            });
        }

        // ---- Step debugger ------------------------------------------------

        // A session is "live" while its run is in progress, then stays open
        // for review (its step history) until closed, edited or re-run.
        var activeDebug = null;
        var debugReview = null;
        var DEBUG_HISTORY_LIMIT = 500;
        var DEBUG_ACTIONS = [
            { action: 'over', label: '다음 줄', title: '다음 줄로 이동 (F10). 함수 호출은 한 번에 실행' },
            { action: 'step', label: '함수 안으로', title: '호출하는 함수 안으로 들어가기 (F11)' },
            { action: 'out', label: '함수 밖으로', title: '현재 함수를 끝까지 실행하고 나오기 (Shift+F11)' },
            { action: 'continue', label: '계속 실행', title: '다음 중단점까지 실행. 중단점이 없으면 끝까지 실행' },
        ];

        function debugCell(id) {
            var cell = findCell(id);
            if (!cell || cell.type !== 'code') { return; }
            if (activeDebug) { setStatus('이미 디버깅 중인 셀이 있습니다. 먼저 끝내거나 중단해 주세요.'); return; }
            runOneCell(cell, true).then(function () { markDirty(); });
        }

        function cellBreakpoints(cellId) {
            var editor = editors.get(cellId);
            return editor && editor.getBreakpoints ? editor.getBreakpoints() : [];
        }

        // Locks the cell while debugging so the shown line numbers stay
        // valid, and marks the line about to run.
        function setDebugView(cellId, locked, line) {
            var editor = editors.get(cellId);
            if (editor) {
                editor.setReadOnly(locked);
                editor.highlightLine(line);
                return;
            }
            var wrap = cellElement(cellId);
            var textarea = wrap ? wrap.querySelector('.ocl-cell-input') : null;
            if (textarea) { textarea.readOnly = locked; }
        }

        function debugElement(tag, className, text) {
            var el = document.createElement(tag);
            if (className) { el.className = className; }
            if (text !== undefined) { el.textContent = text; }
            return el;
        }

        function debugButton(className, label, title, onClick) {
            var button = debugElement('button', 'ojp-button ojp-button--small ocl-btn ' + className, label);
            button.type = 'button';
            button.title = title;
            button.addEventListener('click', onClick);
            return button;
        }

        function startDebugSession(cell, outputEl) {
            if (debugReview) { closeDebugSession(debugReview); }
            var panel = debugElement('div', 'ocl-debug-panel');
            panel.setAttribute('role', 'region');
            panel.setAttribute('aria-label', '디버거');
            var session = {
                cellId: cell.id, code: cell.code, panel: panel, buttons: [], resume: null,
                live: true, running: true, closed: false, history: [], dropped: 0, view: -1,
            };
            session.where = debugElement('div', 'ocl-debug-where', '디버거를 준비하는 중…');
            session.where.setAttribute('aria-live', 'polite');
            session.actions = debugElement('div', 'ocl-debug-actions');
            DEBUG_ACTIONS.forEach(function (item) {
                var button = debugButton('ocl-debug-' + item.action, item.label, item.title, function () { resumeDebug(session, item.action); });
                button.disabled = true;
                session.buttons.push(button);
                session.actions.appendChild(button);
            });
            session.stopButton = debugButton('ojp-button--danger ocl-btn-danger ocl-debug-stop', '디버깅 중단', '실행을 멈춥니다. 런타임이 초기화되어 변수도 지워집니다.', function () { stopDebugSession(session); });
            session.actions.appendChild(session.stopButton);

            // Step history: move back and forth through every pause so far.
            session.nav = debugElement('div', 'ocl-debug-nav');
            session.prevButton = debugButton('ocl-debug-prev', '이전 단계', '이전 단계의 줄과 변수 보기', function () { viewDebugStep(session, session.view - 1); });
            session.nextButton = debugButton('ocl-debug-next', '다음 단계', '다음 단계의 줄과 변수 보기', function () { viewDebugStep(session, session.view + 1); });
            session.latestButton = debugButton('ocl-debug-latest', '마지막 단계로', '가장 최근 단계로 돌아가기', function () { viewDebugStep(session, session.history.length - 1); });
            session.counter = debugElement('span', 'ocl-debug-counter', '');
            session.nav.appendChild(session.prevButton);
            session.nav.appendChild(session.counter);
            session.nav.appendChild(session.nextButton);
            session.nav.appendChild(session.latestButton);
            session.nav.hidden = true;

            session.hint = debugElement('p', 'ocl-debug-hint', editors.has(cell.id)
                ? '줄 번호를 누르거나 F9를 누르면 중단점을 놓거나 뺍니다. ‘계속 실행’은 다음 중단점에서 멈춥니다.'
                : '');
            session.hint.hidden = !editors.has(cell.id);
            session.body = debugElement('div', 'ocl-debug-body');
            panel.appendChild(session.where);
            panel.appendChild(session.actions);
            panel.appendChild(session.nav);
            panel.appendChild(session.hint);
            panel.appendChild(session.body);
            var wrap = cellElement(cell.id);
            if (wrap && outputEl) { wrap.insertBefore(panel, outputEl); }
            setDebugView(cell.id, true, null);
            activeDebug = session;
            return session;
        }

        function recordDebugStep(session, snapshot) {
            session.history.push(snapshot || {});
            if (session.history.length > DEBUG_HISTORY_LIMIT) {
                session.history.shift();
                session.dropped += 1;
            }
            session.view = session.history.length - 1;
        }

        function showDebugPause(session, snapshot, resume) {
            session.resume = resume;
            session.running = false;
            recordDebugStep(session, snapshot);
            renderDebug(session);
        }

        function viewDebugStep(session, index) {
            if (index < 0 || index >= session.history.length) { return; }
            session.view = index;
            renderDebug(session);
        }

        function describeStep(session, snapshot, latest) {
            var line = Number(snapshot.line) || 0;
            var fn = snapshot.function && snapshot.function !== '<module>' ? ' · 함수 ' + snapshot.function + '()' : '';
            if (snapshot.event === 'finished') { return '실행이 끝났습니다 · 마지막 변수 값'; }
            if (snapshot.event === 'exception') { return line + '번째 줄에서 오류가 났습니다' + fn; }
            if (session.live && latest) { return line + '번째 줄을 실행하기 직전입니다' + fn; }
            return line + '번째 줄을 실행하기 직전' + fn + (session.live ? ' · 지난 단계 보기 (실행은 마지막 단계에서 멈춰 있음)' : '');
        }

        function renderDebug(session) {
            var history = session.history;
            var latest = session.view === history.length - 1;
            var snapshot = history[session.view];
            var previous = session.view > 0 ? history[session.view - 1] : null;

            session.buttons.forEach(function (button) { button.disabled = !session.resume; });
            session.nav.hidden = history.length < 2;
            session.prevButton.disabled = session.view <= 0;
            session.nextButton.disabled = latest;
            session.latestButton.hidden = latest;
            session.counter.textContent = '단계 ' + (session.dropped + session.view + 1) + ' / ' + (session.dropped + history.length);

            if (!snapshot || (session.running && latest)) {
                session.where.textContent = session.running ? '실행 중…' : '디버거를 준비하는 중…';
                setDebugView(session.cellId, session.live, null);
                if (!snapshot) { session.body.innerHTML = ''; return; }
            } else {
                session.where.textContent = describeStep(session, snapshot, latest);
                setDebugView(session.cellId, session.live, Number(snapshot.line) || null);
            }

            var body = session.body;
            body.innerHTML = '';
            var line = Number(snapshot.line) || 0;
            var source = line ? String(session.code).split('\n')[line - 1] : undefined;
            if (source !== undefined) {
                body.appendChild(debugElement('pre', 'ocl-debug-code', line + ' │ ' + source));
            }
            var stack = Array.isArray(snapshot.stack) ? snapshot.stack : [];
            if (stack.length > 1) {
                body.appendChild(debugElement('p', 'ocl-debug-stack', '호출 순서: ' + stack.map(function (frame) {
                    return (frame.function === '<module>' ? '셀' : frame.function + '()') + ' ' + frame.line + '줄';
                }).join(' → ')));
            }
            var before = previous ? variableSignatures(previous) : null;
            if (Array.isArray(snapshot.locals)) {
                body.appendChild(variableTable('지역 변수 · ' + snapshot.function + '()', localScope(snapshot), snapshot.locals, before));
            }
            body.appendChild(variableTable('전역 변수', 'G', Array.isArray(snapshot.globals) ? snapshot.globals : [], before));
        }

        // Locals are compared only with the same function at the same depth,
        // so stepping into another call doesn't mark every local "changed".
        function localScope(snapshot) {
            return 'L' + (Array.isArray(snapshot.stack) ? snapshot.stack.length : 0) + ':' + snapshot.function;
        }

        function variableSignatures(snapshot) {
            var map = {};
            function add(scope, variables) {
                (Array.isArray(variables) ? variables : []).forEach(function (variable) {
                    map[scope + ':' + variable.name] = variable.type + '|' + variable.value;
                });
            }
            add(localScope(snapshot), snapshot.locals);
            add('G', snapshot.globals);
            return map;
        }

        // Rows whose value differs from the previous step are marked so a
        // student sees at a glance what the line they just ran changed.
        function variableTable(title, scope, variables, before) {
            var section = debugElement('div', 'ocl-debug-vars');
            section.appendChild(debugElement('h3', '', title));
            if (!variables.length) {
                section.appendChild(debugElement('p', 'ocl-debug-empty', '아직 변수가 없습니다.'));
                return section;
            }
            var table = debugElement('table', '');
            var head = debugElement('tr', '');
            ['이름', '종류', '값'].forEach(function (label) { head.appendChild(debugElement('th', '', label)); });
            table.appendChild(head);
            variables.forEach(function (variable) {
                var changed = before !== null && before[scope + ':' + variable.name] !== variable.type + '|' + variable.value;
                var row = debugElement('tr', changed ? 'ocl-debug-changed' : '');
                row.appendChild(debugElement('td', 'ocl-debug-name', String(variable.name)));
                row.appendChild(debugElement('td', 'ocl-debug-type', String(variable.type) + (variable.size ? ' (' + variable.size + ')' : '')));
                row.appendChild(debugElement('td', 'ocl-debug-value', String(variable.value)));
                table.appendChild(row);
            });
            section.appendChild(table);
            return section;
        }

        function resumeDebug(session, action) {
            if (!session.resume) { return; }
            var resume = session.resume;
            session.resume = null;
            session.running = true;
            session.view = session.history.length - 1;
            renderDebug(session);
            resume({ action: action, breakpoints: cellBreakpoints(session.cellId) });
        }

        // The run is over: keep the panel as a read-only step history (the
        // cell becomes editable again; editing it closes the history).
        function finishDebugSession(session) {
            if (activeDebug === session) { activeDebug = null; }
            if (session.closed) { return; }
            session.live = false;
            session.running = false;
            session.resume = null;
            if (!session.history.length) { closeDebugSession(session); return; }
            session.buttons.forEach(function (button) { button.hidden = true; });
            session.stopButton.title = '디버그 기록과 줄 강조를 닫습니다. 실행 결과와 변수는 유지됩니다.';
            session.hint.hidden = true;
            debugReview = session;
            session.view = session.history.length - 1;
            renderDebug(session);
        }

        function stopDebugSession(session) {
            if (session.closed) { return; }
            if (session.live) { stop(); return; }
            // A later cell may already be running: only dismiss this review.
            closeDebugSession(session);
            setStatus('디버그 기록을 닫았습니다. 실행 결과와 변수는 유지됩니다.');
        }

        function closeDebugSession(session) {
            if (session.closed) { return; }
            session.closed = true;
            session.panel.remove();
            setDebugView(session.cellId, false, null);
            if (activeDebug === session) { activeDebug = null; }
            if (debugReview === session) { debugReview = null; }
        }

        // Line numbers in the history no longer match once the code changes.
        function onCellEdited(cellId) {
            if (debugReview && debugReview.cellId === cellId) { closeDebugSession(debugReview); }
        }

        function runCell(id) {
            var cell = findCell(id);
            if (!cell || cell.type !== 'code') {
                return;
            }

            runOneCell(cell).then(function () {
                markDirty();
            });
        }

        function runAll() {
            var generation = runGeneration;
            var chain = Promise.resolve();
            cells.filter(function (cell) { return cell.type === 'code'; }).forEach(function (cell) {
                chain = chain.then(function () {
                    if (generation === runGeneration && findCell(cell.id)) { return runOneCell(cell); }
                });
            });
            chain.then(function () { markDirty(); });
        }

        function stop() {
            runGeneration += 1;
            Runtime.stop();
            pendingInput = null;
            if (activeDebug) { closeDebugSession(activeDebug); }
            if (debugReview) { closeDebugSession(debugReview); }
            cells.forEach(function (cell) {
                var output = cellElOutput(cell.id);
                if (output) { renderOutputs(output, cell.outputs); }
            });
            setStatus('실행을 중단했습니다. 코드와 실행 결과는 유지됩니다.');
        }

        function restart() {
            stop();
            setStatus('런타임을 초기화했습니다. 다음 실행에서 다시 시작합니다. 가져온 데이터는 다시 가져와 주세요.');
        }

        function downloadNotebook() {
            var blob = new Blob([JSON.stringify({formatVersion: 2, cells: cells, revision: serverRevision}, null, 2)], {type: 'application/json'});
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a'); link.href = url; link.download = 'oj-code-lab-notebook.json'; link.click();
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        }

        function reloadNotebook() {
            if (!dirty) { window.location.reload(); return; }
            var message = '저장하지 않은 변경 내용이 사라집니다. 필요하면 먼저 노트북을 다운로드해 주세요.';
            if (window.OJP && typeof window.OJP.confirm === 'function') {
                window.OJP.confirm({ title: '서버의 최신 노트북으로 바꿀까요?', message: message, confirmLabel: '불러오기', destructive: true })
                    .then(function (ok) { if (ok) { window.location.reload(); } });
                return;
            }
            if (window.confirm('서버의 최신 노트북으로 바꿀까요? ' + message)) { window.location.reload(); }
        }

        function save() {
            if (saveConflict) { setStatus("저장 충돌이 있습니다. 현재 내용을 다운로드한 뒤 최신 노트북을 불러와 주세요."); return; }
            if (!notebookLoaded) {
                setStatus('노트북을 불러오지 못해 저장을 중지했습니다. 새로고침 후 다시 시도해 주세요.');
                return;
            }
            if (saving) {
                return;
            }
            saving = true;
            var savedRevision = editRevision;
            setStatus('저장 중…');

            var payload = cells.map(function (c) {
                return { id: c.id, type: c.type, code: c.code, outputs: c.outputs };
            });

            apiFetch('/notebook', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ formatVersion: 2, cells: payload, revision: serverRevision }),
            })
                .then(function (response) {
                    // Parse the body even on failure — the server's WP_Error
                    // response carries the actual reason (payload too large,
                    // too many cells, permission denied, …), and showing that
                    // instead of a generic "couldn't save" is the difference
                    // between a student being able to fix it and not.
                    return response.json().catch(function () { return null; }).then(function (data) {
                        if (response.status === 409 || response.status === 428) { saveConflict = true; }
                        if (!response.ok) {
                            throw new Error((data && data.message) || ('저장 실패 (HTTP ' + response.status + ')'));
                        }
                        if (!data || typeof data.updatedAt !== 'string' || !data.updatedAt || !Number.isSafeInteger(data.revision) || data.revision !== serverRevision + 1) {
                            throw new Error('저장 결과를 확인하지 못했습니다. 다시 시도해 주세요.');
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    serverRevision = data.revision;
                    dirty = editRevision !== savedRevision;
                    setStatus(dirty ? '추가 변경 사항이 있습니다. 다음 저장을 기다리는 중…' : '저장됨');
                    if (!dirty) {
                        window.setTimeout(function () {
                            if (!dirty && !saving) { setStatus(''); }
                        }, 2000);
                    }
                })
                .catch(function (error) {
                    setStatus((error && error.message) || '저장하지 못했습니다. 잠시 뒤 다시 시도해 주세요.');
                })
                .finally(function () {
                    saving = false;
                });
        }

        // ---- Datasets ("데이터 가져오기") -----------------------------------

        var datasetsPanel = root.querySelector('[data-field="datasets-panel"]');
        var recommendedListEl = root.querySelector('[data-field="recommended-list"]');
        var searchForm = root.querySelector('[data-field="search-form"]');
        var searchResultsEl = root.querySelector('[data-field="search-results"]');
        var filesSection = root.querySelector('[data-field="files-section"]');
        var filesTitleEl = root.querySelector('[data-field="files-title"]');
        var filesListEl = root.querySelector('[data-field="files-list"]');
        var datasetsLoaded = false;

        function datasetRow(labelHtmlEl, subtitle, onImport) {
            var row = document.createElement('div');
            row.className = 'ocl-dataset-row';

            var main = document.createElement('div');
            main.className = 'ocl-dataset-row-main';
            main.appendChild(labelHtmlEl);
            if (subtitle) {
                var span = document.createElement('span');
                span.textContent = subtitle;
                main.appendChild(span);
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ojp-button ojp-button--small ocl-btn';
            button.textContent = onImport.label;
            button.disabled = !!onImport.disabled;
            if (!onImport.disabled) {
                button.addEventListener('click', function () {
                    button.disabled = true;
                    button.textContent = '처리 중…';
                    onImport.run().finally(function () {
                        button.disabled = false;
                        button.textContent = onImport.label;
                    });
                });
            }

            row.appendChild(main);
            row.appendChild(button);
            return row;
        }

        function loadRecommendedDatasets() {
            recommendedListEl.innerHTML = '<p class="ocl-empty">불러오는 중…</p>';
            apiJson('/datasets/recommended').then(function (datasets) {
                recommendedListEl.innerHTML = '';
                if (0 === datasets.length) {
                    recommendedListEl.innerHTML = '<p class="ocl-empty">아직 추천 데이터가 없습니다.</p>';
                    return;
                }
                var SOURCE_LABEL = { kaggle: 'Kaggle', url: 'URL', upload: '업로드' };
                datasets.forEach(function (dataset) {
                    var strong = document.createElement('strong');
                    strong.textContent = (dataset.icon ? dataset.icon + ' ' : '') + dataset.label;
                    var subtitle = 'kaggle' === dataset.source
                        ? dataset.kaggleOwner + '/' + dataset.kaggleSlug
                        : (SOURCE_LABEL[dataset.source] || dataset.source);
                    recommendedListEl.appendChild(datasetRow(strong, subtitle, {
                        label: '가져오기',
                        run: function () {
                            return importDataset('/datasets/recommended/' + dataset.id + '/import', {});
                        },
                    }));
                });
            }).catch(function () {
                recommendedListEl.innerHTML = '<p class="ocl-empty">불러오지 못했습니다.</p>';
            });
        }

        function showFiles(owner, slug) {
            filesSection.hidden = false;
            filesTitleEl.textContent = owner + '/' + slug + ' — CSV · JSONL · NDJSON · ZIP 파일 선택';
            filesListEl.innerHTML = '<p class="ocl-empty">불러오는 중…</p>';

            apiJson('/datasets/files?owner=' + encodeURIComponent(owner) + '&slug=' + encodeURIComponent(slug))
                .then(function (files) {
                    filesListEl.innerHTML = '';
                    if (0 === files.length) {
                        filesListEl.innerHTML = '<p class="ocl-empty">파일을 찾지 못했습니다.</p>';
                        return;
                    }
                    files.forEach(function (file) {
                        var strong = document.createElement('strong');
                        strong.textContent = file.name;
                        var supported = /\.(csv|jsonl|ndjson|zip)$/i.test(file.name || '');
                        filesListEl.appendChild(datasetRow(strong, file.size, {
                            label: supported ? (/\.zip$/i.test(file.name) ? 'ZIP 가져오기' : '가져오기') : '지원하지 않는 형식',
                            disabled: !supported,
                            run: function () {
                                return importDataset('/datasets/import', { owner: owner, slug: slug, file: file.name });
                            },
                        }));
                    });
                })
                .catch(function (error) {
                    filesListEl.textContent = error.message || '불러오지 못했습니다.';
                });
        }

        function runSearch(query) {
            searchResultsEl.innerHTML = '<p class="ocl-empty">검색 중…</p>';
            apiJson('/datasets/search?q=' + encodeURIComponent(query)).then(function (results) {
                searchResultsEl.innerHTML = '';
                if (0 === results.length) {
                    searchResultsEl.innerHTML = '<p class="ocl-empty">검색 결과가 없습니다.</p>';
                    return;
                }
                results.forEach(function (item) {
                    var strong = document.createElement('strong');
                    strong.textContent = item.title || item.ref;
                    var parts = item.ref.split('/');
                    searchResultsEl.appendChild(datasetRow(strong, item.subtitle || item.ref, {
                        label: '파일 보기',
                        run: function () {
                            if (2 === parts.length) {
                                showFiles(parts[0], parts[1]);
                            }
                            return Promise.resolve();
                        },
                    }));
                });
            }).catch(function (error) {
                searchResultsEl.textContent = error.message || '검색하지 못했습니다.';
            });
        }

        function importDataset(path, body) {
            setStatus('데이터를 가져오는 중…');
            return apiJson(path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            }).then(function (result) {
                if (!result || typeof result.content !== 'string') {
                    throw new Error('데이터 응답 형식이 올바르지 않습니다.');
                }
                var fileName = String(result.fileName || (result.format === 'jsonl' ? 'data.jsonl' : 'data.csv')).split(/[\\/]/).pop();
                var format = result.format == null ? (/\.(jsonl|ndjson)$/i.test(fileName) ? 'jsonl' : 'csv') : result.format;
                if (format !== 'csv' && format !== 'jsonl') {
                    throw new Error('지원하지 않는 데이터 형식입니다. CSV 또는 JSONL 자료를 선택해 주세요.');
                }
                var bytes = new TextEncoder().encode(result.content);
                return Runtime.writeFile(fileName, bytes).then(function (filePath) {
                    var read = format === 'jsonl'
                        ? 'pd.read_json(' + JSON.stringify(filePath) + ', lines=True)'
                        : 'pd.read_csv(' + JSON.stringify(filePath) + ')';
                    var cell = addCell(
                        'import pandas as pd\n\n'
                        + 'df = ' + read + '\n'
                        + 'print(df.head())'
                    );
                    datasetsPanel.hidden = true;
                    setStatus((result.label ? result.label + ' ' : '') + '데이터를 가져왔습니다.');
                    window.setTimeout(function () { setStatus(''); }, 2500);
                    runCell(cell.id);
                });
            }).catch(function (error) {
                setStatus(error.message || '데이터를 가져오지 못했습니다.');
            });
        }

        root.querySelector('[data-action="toggle-datasets"]').addEventListener('click', function () {
            datasetsPanel.hidden = !datasetsPanel.hidden;
            if (!datasetsPanel.hidden && !datasetsLoaded) {
                datasetsLoaded = true;
                loadRecommendedDatasets();
            }
        });

        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var query = searchForm.querySelector('[data-field="search-input"]').value.trim();
            if (query) {
                filesSection.hidden = true;
                runSearch(query);
            }
        });

        // ---- Wiring -----------------------------------------------------

        root.querySelector('[data-action="run-all"]').addEventListener('click', runAll);
        root.querySelector('[data-action="add-cell"]').addEventListener('click', function () { addCell(''); });
        root.querySelector('[data-action="add-text-cell"]').addEventListener('click', function () { focusCell(addCell('', undefined, 'text').id); });
        root.querySelector('[data-action="restart"]').addEventListener('click', restart);
        root.querySelector('[data-action="stop"]').addEventListener('click', stop);
        root.querySelector('[data-action="download"]').addEventListener('click', downloadNotebook);
        root.querySelector('[data-action="reload"]').addEventListener('click', reloadNotebook);
        root.querySelector('[data-action="save"]').addEventListener('click', save);

        window.addEventListener('pagehide', function (event) {
            Runtime.stop();
            if (!event.persisted) {
                editors.forEach(function (editor) { editor.destroy(); });
                editors.clear();
                if (editorManager) { editorManager.destroy(); }
            }
        });
        window.addEventListener('keydown', function (event) {
            if (!activeDebug || !activeDebug.resume) { return; }
            var action = event.key === 'F10' ? 'over' : (event.key === 'F11' ? (event.shiftKey ? 'out' : 'step') : null);
            if (action) {
                event.preventDefault();
                resumeDebug(activeDebug, action);
            }
        });
        window.addEventListener('beforeunload', function (event) {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        window.setInterval(function () {
            if (dirty && !saving && !saveConflict) {
                save();
            }
        }, 30000);

        var initialControls = root.querySelectorAll('button, input');
        initialControls.forEach(function (control) { control.disabled = true; });
        setStatus('노트북을 불러오는 중…');
        apiJson('/notebook')
            .then(function (data) {
                if (!data || data.formatVersion !== 2 || !Array.isArray(data.cells) || !Number.isSafeInteger(data.revision) || data.revision < 0) {
                    throw new Error('노트북 응답 형식이 올바르지 않습니다.');
                }
                var loaded = data.cells.map(function (c) {
                    if (!c || (c.type !== undefined && c.type !== 'code' && c.type !== 'text') || typeof c.code !== 'string' || !Array.isArray(c.outputs)) {
                        throw new Error('지원하지 않는 셀 형식입니다. 새로고침해 주세요.');
                    }
                    return { id: c.id || uid(), type: c.type || 'code', code: c.code, outputs: c.type === 'text' ? [] : c.outputs };
                });
                cells = loaded.length ? loaded : [newCell('print("Hello, OJ Code Lab!")\n')];
                renderAll();
                serverRevision = data.revision;
                notebookLoaded = true;
                initialControls.forEach(function (control) { control.disabled = false; });
                setStatus('');
            })
            .catch(function (error) {
                setStatus((error.message || '노트북을 불러오지 못했습니다.') + ' 기존 노트북 보호를 위해 저장을 중지했습니다. 새로고침해 주세요.');
            });
    });
})();

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
/**
 * Teacher console dashboard behind [data-ocl-dashboard]
 * (Addon\Console_Dashboard_Page_Handler): pick a class/group, see every
 * student's notebook status, open one read-only. Never executes any
 * student's code — this is a viewer, not a runtime.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ocl-dashboard]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');

        var messagesEl = root.querySelector('[data-field="messages"]');
        var contextSelect = root.querySelector('[data-field="context"]');
        var rosterPanel = root.querySelector('[data-field="roster-panel"]');
        var rosterBody = root.querySelector('[data-field="roster-body"]');
        var viewerPanel = root.querySelector('[data-field="viewer-panel"]');
        var viewerTitle = root.querySelector('[data-field="viewer-title"]');
        var viewerCells = root.querySelector('[data-field="viewer-cells"]');
        var closeViewerButton = root.querySelector('[data-action="close-viewer"]');

        function request(path) {
            return fetch(config.restUrl + path, {
                headers: { 'X-WP-Nonce': config.nonce },
                credentials: 'same-origin',
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error((data && data.message) || '요청을 처리하지 못했습니다.');
                    }
                    return data;
                });
            });
        }

        // Completed actions use the platform toast where it exists (OJ
        // Platform 2.1+); errors and older platforms keep an inline notice.
        var NOTICE_TONE = { info: 'info', success: 'success', error: 'danger' };
        function showMessage(text, type) {
            var ojp = 'undefined' !== typeof window ? window.OJP : null;
            messagesEl.innerHTML = '';
            if ('success' === type && ojp && 'function' === typeof ojp.toast) {
                ojp.toast(text);
                return;
            }
            var item = document.createElement('div');
            item.className = 'ojp-notice ojp-notice--' + (NOTICE_TONE[type] || 'info');
            item.textContent = text;
            messagesEl.appendChild(item);
        }

        function loadContexts() {
            request('/contexts').then(function (contexts) {
                contextSelect.innerHTML = '';
                if (0 === contexts.length) {
                    contextSelect.innerHTML = '<option value="">해당하는 학급/모둠이 없습니다</option>';
                    return;
                }
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '학급/모둠 선택';
                contextSelect.appendChild(placeholder);
                contexts.forEach(function (context) {
                    var option = document.createElement('option');
                    option.value = context.type + ':' + context.id;
                    option.textContent = context.label + ' (' + (context.type === 'class' ? '학급' : '모둠') + ')';
                    contextSelect.appendChild(option);
                });
            }).catch(function (error) {
                contextSelect.innerHTML = '<option value="">불러오기 실패</option>';
                showMessage(error.message, 'error');
            });
        }

        function formatDate(value) {
            if (!value) {
                return '저장 기록 없음';
            }
            return String(value).replace('T', ' ');
        }

        var rosterRequest = 0;
        var viewerRequest = 0;
        var moreButton = document.createElement('button');
        moreButton.type = 'button'; moreButton.className = 'ojp-button ojp-button--small ocl-more'; moreButton.hidden = true;
        moreButton.textContent = '다음 학생 보기'; rosterPanel.appendChild(moreButton);
        function loadRoster(type, id, cursor, revision) {
            var requestId = ++rosterRequest;
            viewerRequest += 1;
            moreButton.hidden = true;
            viewerPanel.hidden = true;
            rosterPanel.hidden = false;
            if (!cursor) { rosterBody.innerHTML = '<tr><td colspan="5">불러오는 중…</td></tr>'; }

            request('/contexts/' + type + '/' + encodeURIComponent(id) + '/notebooks' + (cursor ? '?cursor=' + encodeURIComponent(cursor) + '&rosterRevision=' + encodeURIComponent(revision) : '')).then(function (data) {
                if (requestId !== rosterRequest) { return; }
                if (!cursor) { rosterBody.innerHTML = ''; }
                moreButton.hidden = !data.nextCursor;
                moreButton.onclick = function () { loadRoster(type, id, data.nextCursor, data.rosterRevision); };
                if (0 === data.rows.length) {
                    rosterBody.innerHTML = '<tr><td colspan="5">명단을 찾을 수 없습니다.</td></tr>';
                    return;
                }

                data.rows.forEach(function (row) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td></td><td></td><td></td><td></td><td><button type="button" class="ojp-button ojp-button--small" data-action="view">보기</button></td>';

                    tr.children[0].textContent = row.displayName;
                    tr.children[1].textContent = row.cellCount === null ? '집계 중' : row.cellCount + '개';
                    tr.children[2].textContent = formatDate(row.updatedAt);
                    if (row.hasError === null) { tr.children[3].textContent = '집계 중'; }
                    else if (row.hasError) {
                        var badge = document.createElement('span');
                        badge.className = 'ojp-status-badge ojp-status-badge--danger';
                        badge.textContent = '있음';
                        tr.children[3].appendChild(badge);
                    } else {
                        tr.children[3].textContent = '없음';
                    }

                    tr.querySelector('[data-action="view"]').addEventListener('click', function () {
                        showNotebook(type, id, row.userId, row.displayName);
                    });

                    rosterBody.appendChild(tr);
                });
            }).catch(function (error) {
                if (requestId !== rosterRequest) { return; }
                rosterBody.innerHTML = '<tr><td colspan="5">불러오지 못했습니다.</td></tr>';
                showMessage(error.message, 'error');
            });
        }

        function renderReadOnlyOutputs(container, outputs) {
            (outputs || []).forEach(function (o) {
                if ('image' === o.type && o.image) {
                    var img = document.createElement('img');
                    img.className = 'ocl-output-image';
                    img.src = 'data:image/png;base64,' + o.image;
                    img.alt = '';
                    container.appendChild(img);
                    return;
                }
                if (!o.text) {
                    return;
                }
                var pre = document.createElement('pre');
                pre.className = 'error' === o.type
                    ? 'ocl-output-error'
                    : ('input' === o.type ? 'ocl-output-text ocl-input-echo' : 'ocl-output-text');
                pre.textContent = o.text;
                container.appendChild(pre);
            });
        }

        function showNotebook(type, id, userId, displayName) {
            var requestId = ++viewerRequest;
            viewerPanel.hidden = false;
            viewerTitle.textContent = displayName + '의 노트북';
            viewerCells.innerHTML = '<p class="ocl-empty">불러오는 중…</p>';

            request('/contexts/' + type + '/' + encodeURIComponent(id) + '/notebooks/' + userId).then(function (data) {
                if (requestId !== viewerRequest) { return; }
                viewerCells.innerHTML = '';
                if (0 === data.cells.length) {
                    viewerCells.innerHTML = '<p class="ocl-empty">아직 작성한 셀이 없습니다.</p>';
                    return;
                }
                data.cells.forEach(function (cell) {
                    var wrap = document.createElement('div');
                    var isText = cell.type === 'text';
                    wrap.className = 'ocl-viewer-cell' + (isText ? ' ocl-viewer-text-cell' : '');
                    wrap.setAttribute('aria-label', isText ? '텍스트 셀' : '코드 셀');

                    var markdown = isText && typeof window !== 'undefined' && window.OclMarkdown;
                    var pre = document.createElement(markdown ? 'div' : 'pre');
                    pre.className = isText ? (markdown ? 'ocl-markdown' : 'ocl-text-view') : 'ocl-code-view';
                    if (markdown) {
                        markdown.render(pre, cell.code || '');
                    } else {
                        if (isText) { pre.style.whiteSpace = 'pre-wrap'; pre.style.overflowWrap = 'anywhere'; }
                        pre.textContent = cell.code || '';
                    }
                    wrap.appendChild(pre);

                    if (!isText) { renderReadOnlyOutputs(wrap, cell.outputs); }
                    viewerCells.appendChild(wrap);
                });
            }).catch(function (error) {
                if (requestId !== viewerRequest) { return; }
                viewerCells.innerHTML = '<p class="ocl-empty">불러오지 못했습니다.</p>';
                showMessage(error.message, 'error');
            });
        }

        contextSelect.addEventListener('change', function () {
            rosterRequest += 1;
            viewerRequest += 1;
            var value = contextSelect.value;
            if (!value) {
                rosterPanel.hidden = true;
                viewerPanel.hidden = true;
                return;
            }
            var parts = value.split(':');
            loadRoster(parts[0], parts.slice(1).join(':'));
        });

        closeViewerButton.addEventListener('click', function () {
            viewerRequest += 1;
            viewerPanel.hidden = true;
        });

        loadContexts();
    });
})();

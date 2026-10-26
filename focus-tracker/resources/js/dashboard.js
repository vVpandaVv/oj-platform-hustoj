/**
 * Teacher console dashboard behind [data-ftk-dashboard]
 * (Addon\Console_Dashboard_Page_Handler): create an activity, list
 * activities, show a roster+score table, drill into one learner's timeline.
 * Normal cookie-authenticated fetch() with an X-WP-Nonce header — unlike
 * tracker.js this never uses sendBeacon, so there's no query-string nonce
 * workaround needed here.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ftk-dashboard]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');

        var messagesEl = root.querySelector('[data-field="messages"]');
        var contextSelect = root.querySelector('[data-field="context"]');
        var createForm = root.querySelector('[data-field="create-form"]');
        var activityListEl = root.querySelector('[data-field="activity-list"]');
        var scoresPanel = root.querySelector('[data-field="scores-panel"]');
        var scoresTitle = root.querySelector('[data-field="scores-title"]');
        var scoresBody = root.querySelector('[data-field="scores-body"]');
        var timelinePanel = root.querySelector('[data-field="timeline-panel"]');
        var timelineTitle = root.querySelector('[data-field="timeline-title"]');
        var timelineChart = root.querySelector('[data-field="timeline-chart"]');
        var closeTimelineButton = root.querySelector('[data-action="close-timeline"]');
        var activityFilter = root.querySelector('[data-field="activity-context-filter"]');
        var editPanel = root.querySelector('[data-field="edit-panel"]');
        var editForm = root.querySelector('[data-field="edit-form"]');
        var editingActivity = null;
        var activityRequest = 0;
        var scoreRequest = 0;
        var timelineRequest = 0;
        var scheduleRows = [];
        var pendingDeletes = Object.create(null);
        var boundaryTimer = null;
        var clockStart = Number(config.serverNowMs) || Date.now();
        var monoStart = window.performance && window.performance.now ? window.performance.now() : null;
        var wallStart = Date.now();

        function clockNow() {
            return clockStart + (monoStart === null ? Date.now() - wallStart : window.performance.now() - monoStart);
        }

        // Only the presentation changes at a known boundary. No collection,
        // activity mutation or background database polling is needed here.
        function updateSchedules() {
            clearTimeout(boundaryTimer);
            var clock = clockNow();
            var next = Infinity;
            scheduleRows.forEach(function (entry) {
                var activity = entry.activity;
                var start = Date.parse(activity.startsAt);
                var end = Date.parse(activity.endsAt);
                var status = activity.status === 'closed' || end <= clock ? 'closed' : start > clock ? 'scheduled' : 'active';
                entry.badge.className = 'ojp-status-badge ojp-status-badge--' + (STATUS_TONE[status] || 'neutral');
                entry.badge.textContent = STATUS_LABEL[status] || status;
                entry.end.hidden = status !== 'active';
                if (activity.status !== 'closed') {
                    if (start > clock) { next = Math.min(next, start); }
                    if (end > clock) { next = Math.min(next, end); }
                }
            });
            if (Number.isFinite(next)) { boundaryTimer = setTimeout(updateSchedules, Math.min(2147483647, Math.max(1, next - clock))); }
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'hidden') { updateSchedules(); }
        });
        window.addEventListener('pageshow', updateSchedules);

        function request(path, method, body) {
            return fetch(config.restUrl + path, {
                method: method || 'GET',
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
                    return data;
                });
            });
        }

        // Success goes to the platform toast (2.1+); errors stay on the page
        // as an ojp-notice so the teacher can read them.
        function showMessage(text, type) {
            messagesEl.innerHTML = '';
            if ('success' === type && window.OJP && 'function' === typeof window.OJP.toast) {
                window.OJP.toast(text);
                return;
            }
            var tone = 'error' === type ? 'danger' : (type || 'info');
            var item = document.createElement('div');
            item.className = 'ojp-notice ojp-notice--' + tone;
            item.setAttribute('role', 'danger' === tone ? 'alert' : 'status');
            item.textContent = text;
            messagesEl.appendChild(item);
        }

        function emptyState(title, message) {
            var box = document.createElement('div');
            box.className = 'ojp-empty-state';
            var inner = document.createElement('div');
            var strong = document.createElement('strong');
            strong.textContent = title;
            inner.appendChild(strong);
            if (message) {
                var p = document.createElement('p');
                p.textContent = message;
                inner.appendChild(p);
            }
            box.appendChild(inner);
            return box;
        }

        function tableMessageRow(text) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 8;
            td.className = 'ojp-empty-cell';
            td.textContent = text;
            tr.appendChild(td);
            return tr;
        }

        function loadContexts() {
            return request('/contexts').then(function (contexts) {
                contextSelect.innerHTML = '';
                activityFilter.innerHTML = '<option value="">모든 학급/그룹</option>';
                if (0 === contexts.length) {
                    contextSelect.innerHTML = '<option value="">해당하는 학급/그룹이 없습니다</option>';
                    return;
                }
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '학급/그룹 선택';
                contextSelect.appendChild(placeholder);
                contexts.forEach(function (context) {
                    var option = document.createElement('option');
                    option.value = context.type + ':' + context.id;
                    option.textContent = context.label + ' (' + (context.type === 'class' ? '학급' : '그룹') + ')';
                    contextSelect.appendChild(option);
                    activityFilter.appendChild(option.cloneNode(true));
                });
            }).catch(function (error) {
                contextSelect.innerHTML = '<option value="">불러오기 실패</option>';
                showMessage(error.message, 'error');
            });
        }

        function formatWindow(activity) {
            var start = new Date(activity.startsAt);
            var end = new Date(activity.endsAt);
            var fmt = function (d) {
                return d.getFullYear() + '.' + (d.getMonth() + 1) + '.' + d.getDate() + ' '
                    + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':' + String(d.getSeconds()).padStart(2, '0');
            };
            return fmt(start) + ' ~ ' + fmt(end);
        }

        var STATUS_LABEL = { scheduled: '예정', active: '진행 중', closed: '종료' };
        var STATUS_TONE = { scheduled: 'info', active: 'success', closed: 'neutral' };

        function localValue(value) {
            var date = new Date(value);
            var pad = function (number) { return String(number).padStart(2, '0'); };
            return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
                + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
        }

        function isoValue(value) {
            var date = new Date(value);
            if (!value || !Number.isFinite(date.getTime()) || localValue(date) !== (value.length === 16 ? value + ':00' : value)) {
                throw new Error('실제 존재하는 날짜와 시각을 입력해 주세요.');
            }
            return date.toISOString();
        }

        function problemIds(value) {
            if (!value.trim()) { return null; }
            var parts = value.split(',').map(function (part) { return part.trim(); });
            if (parts.some(function (part) { return !/^[1-9][0-9]*$/.test(part) || !Number.isSafeInteger(Number(part)); })) {
                throw new Error('대상 문제 번호를 양의 정수로 입력해 주세요.');
            }
            return parts.map(Number);
        }

        function contextLabel(activity) {
            return (activity.contextLabel || activity.contextId) + ' (' + (activity.contextType === 'group' ? '그룹' : '학급') + ')';
        }

        function hideReports() {
            scoreRequest += 1; timelineRequest += 1;
            scoresPanel.hidden = true; timelinePanel.hidden = true;
            var statistics = root.querySelector('#ftk-teacher-statistics');
            if (statistics) { statistics.hidden = true; }
        }

        function editActivity(activity) {
            editingActivity = activity;
            editPanel.hidden = false;
            editPanel.querySelector('[data-field="edit-context"]').textContent = contextLabel(activity);
            editForm.querySelector('[data-field="edit-name"]').value = activity.name;
            editForm.querySelector('[data-field="edit-starts-at"]').value = localValue(activity.startsAt);
            editForm.querySelector('[data-field="edit-ends-at"]').value = localValue(activity.endsAt);
            editForm.querySelector('[data-field="edit-problem-ids"]').value = (activity.problemIds || []).join(', ');
            ['focus', 'selfreg', 'activity'].forEach(function (key, index) {
                editForm.querySelector('[data-field="edit-weight-' + key + '"]').value = activity[['weightFocus', 'weightSelfreg', 'weightActivity'][index]];
            });
            editForm.querySelector('[data-field="edit-starts-at"]').focus();
        }

        function confirmEnd(activity, row) {
            activityListEl.querySelectorAll('[data-field="end-confirmation"]').forEach(function (item) { item.remove(); });
            var confirmation = document.createElement('tr');
            confirmation.setAttribute('data-field', 'end-confirmation');
            var cell = document.createElement('td');
            cell.colSpan = 3;
            var notice = document.createElement('div');
            notice.className = 'ojp-notice ojp-notice--warning ftk-end-confirmation';
            notice.setAttribute('role', 'status');
            var text = document.createElement('p');
            text.textContent = '“' + activity.name + '”을 지금 종료할까요? 종료한 활동은 다시 열 수 없습니다. 이 활동의 기록 수집과 자동 학습 확인을 중단합니다. 다른 진행 중인 활동이 있으면 해당 활동은 계속됩니다.';
            notice.appendChild(text);
            var finish = document.createElement('button');
            finish.type = 'button'; finish.className = 'ojp-button ojp-button--small'; finish.textContent = '지금 종료 확인';
            finish.setAttribute('data-action', 'confirm-end');
            finish.addEventListener('click', function () {
                finish.disabled = true;
                request('/activities/' + activity.id, 'PATCH', { status: 'closed' }).then(function () {
                    showMessage('활동을 종료했습니다.', 'success');
                    hideReports();
                    if (editingActivity && editingActivity.id === activity.id) { editPanel.hidden = true; editingActivity = null; }
                    loadActivities();
                }).catch(function (error) { finish.disabled = false; showMessage(error.message, 'error'); });
            });
            var cancel = document.createElement('button');
            cancel.type = 'button'; cancel.className = 'ojp-button ojp-button--small ojp-button--plain'; cancel.textContent = '취소';
            cancel.setAttribute('data-action', 'cancel-end');
            cancel.addEventListener('click', function () { confirmation.remove(); });
            notice.appendChild(finish); notice.appendChild(cancel); cell.appendChild(notice); confirmation.appendChild(cell);
            row.parentNode.insertBefore(confirmation, row.nextSibling);
        }

        function focusWithoutScroll(target) {
            if (!target || typeof target.focus !== 'function') { return; }
            try { target.focus({ preventScroll: true }); } catch (error) { target.focus(); }
        }

        function hasFocus(container) {
            return container && container.contains(document.activeElement);
        }

        function confirmDelete(activity, row) {
            if (Object.keys(pendingDeletes).length) { return; }
            activityListEl.querySelectorAll('[data-field="delete-confirmation"]').forEach(function (item) { item.remove(); });
            var confirmation = document.createElement('tr');
            confirmation.setAttribute('data-field', 'delete-confirmation');
            var cell = document.createElement('td'); cell.colSpan = 3;
            var notice = document.createElement('div');
            notice.className = 'ojp-notice ojp-notice--warning ftk-delete-confirmation';
            notice.setAttribute('role', 'group');
            notice.setAttribute('aria-labelledby', 'ftk-delete-name-' + activity.id + ' ftk-delete-title-' + activity.id);
            var name = document.createElement('p');
            name.id = 'ftk-delete-name-' + activity.id;
            name.textContent = activity.name;
            notice.appendChild(name);
            var text = document.createElement('p');
            text.id = 'ftk-delete-title-' + activity.id;
            text.textContent = '활동을 삭제할까요?';
            text.setAttribute('tabindex', '-1');
            notice.appendChild(text);
            var policy = document.createElement('p');
            policy.textContent = '활동을 목록과 결과에서 삭제하고 이 활동의 기록 수집·자동 확인을 중단합니다. 기존 학습 기록과 주간·월간 누적 통계는 보관기간에 따라 유지되며 다른 활동은 계속됩니다. 삭제한 활동은 복구할 수 없습니다.';
            notice.appendChild(policy);
            var errorText = document.createElement('p');
            errorText.setAttribute('role', 'alert');
            errorText.setAttribute('data-field', 'delete-error');
            notice.appendChild(errorText);
            var finish = document.createElement('button');
            finish.type = 'button'; finish.className = 'ojp-button ojp-button--small';
            finish.textContent = '삭제 확인'; finish.setAttribute('data-action', 'confirm-delete');
            var cancel = document.createElement('button');
            cancel.type = 'button'; cancel.className = 'ojp-button ojp-button--small ojp-button--plain';
            cancel.textContent = '취소'; cancel.setAttribute('data-action', 'cancel-delete');
            cancel.addEventListener('click', function () {
                if (pendingDeletes[activity.id]) { return; }
                confirmation.remove();
                var trigger = row.querySelector('[data-action="delete-activity"]');
                focusWithoutScroll(trigger);
                trigger.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            });
            finish.addEventListener('click', function () {
                if (pendingDeletes[activity.id]) { return; }
                pendingDeletes[activity.id] = true;
                var inside = hasFocus(confirmation);
                if (inside) { focusWithoutScroll(text); }
                finish.disabled = true; cancel.disabled = true;
                activityListEl.querySelectorAll('[data-action="delete-activity"]').forEach(function (button) { button.disabled = true; });
                notice.setAttribute('aria-busy', 'true');
                errorText.textContent = '';
                request('/activities/' + activity.id, 'DELETE').then(function (result) {
                    if (!result || result.deleted !== true || String(result.id) !== String(activity.id)) {
                        throw new Error('삭제 결과를 확인하지 못했습니다. 목록을 다시 확인해 주세요.');
                    }
                    var statistics = root.querySelector('#ftk-teacher-statistics');
                    var focused = document.activeElement;
                    var restore = hasFocus(confirmation) || hasFocus(scoresPanel) || hasFocus(timelinePanel) || hasFocus(statistics)
                        || (editingActivity && editingActivity.id === activity.id && hasFocus(editPanel));
                    hideReports();
                    if (editingActivity && editingActivity.id === activity.id) { editPanel.hidden = true; editingActivity = null; }
                    delete pendingDeletes[activity.id];
                    showMessage('활동을 삭제했습니다.', 'success');
                    return loadActivities().then(function () {
                        if (restore && (document.activeElement === focused || document.activeElement === document.body || !document.activeElement.isConnected)) {
                            focusWithoutScroll(activityFilter);
                        }
                    });
                }).catch(function (error) {
                    delete pendingDeletes[activity.id];
                    finish.disabled = false; cancel.disabled = false;
                    notice.setAttribute('aria-busy', 'false');
                    activityListEl.querySelectorAll('[data-action="delete-activity"]').forEach(function (button) { button.disabled = false; });
                    var explanation = error instanceof TypeError
                        ? '활동을 삭제하지 못했습니다. 연결 상태를 확인하고 다시 시도해 주세요.' : error.message;
                    errorText.textContent = explanation;
                    showMessage(explanation, 'error');
                    if (document.activeElement === text && finish.isConnected) { focusWithoutScroll(finish); }
                });
            });
            notice.appendChild(finish); notice.appendChild(cancel); cell.appendChild(notice); confirmation.appendChild(cell);
            row.parentNode.insertBefore(confirmation, row.nextSibling);
            var tableWrap = row.closest('.ojp-table-wrap');
            if (tableWrap) { tableWrap.scrollLeft = 0; }
            // Explicit deletion opens the inline confirmation; its safe action
            // receives focus so a repeated Enter cannot confirm accidentally.
            focusWithoutScroll(cancel);
        }

        function loadActivities() {
            var requestId = ++activityRequest;
            var value = activityFilter.value;
            var separator = value.indexOf(':');
            var query = value ? '?context_type=' + encodeURIComponent(value.slice(0, separator))
                + '&context_id=' + encodeURIComponent(value.slice(separator + 1)) : '';
            return request('/activities' + query).then(function (activities) {
                if (requestId !== activityRequest) { return; }
                clearTimeout(boundaryTimer);
                scheduleRows = [];
                activityListEl.innerHTML = '';
                if (0 === activities.length) {
                    activityListEl.appendChild(emptyState('아직 만든 활동이 없습니다', '새 활동에서 대상과 기간을 정해 만들면 여기에 표시됩니다.'));
                    return;
                }

                var wrap = document.createElement('div');
                wrap.className = 'ojp-table-wrap';
                wrap.innerHTML = '<table class="ojp-table"><thead><tr>'
                    + '<th scope="col">활동</th>'
                    + '<th scope="col">상태</th>'
                    + '<th scope="col"><span class="ojp-visually-hidden">작업</span></th>'
                    + '</tr></thead><tbody></tbody></table>';
                var tbody = wrap.querySelector('tbody');

                activities.forEach(function (activity) {
                    var tr = document.createElement('tr');
                    tr.setAttribute('data-activity-id', activity.id);
                    tr.innerHTML = '<td><strong></strong><span class="ftk-activity-context"></span><span class="ftk-activity-window"></span></td>'
                        + '<td><span class="ojp-status-badge"></span></td>'
                        + '<td class="ftk-row-actions"><div class="ftk-schedule-actions">'
                        + '<button type="button" class="ojp-button ojp-button--small" data-action="edit-activity">시간 수정</button>'
                        + '<button type="button" class="ojp-button ojp-button--small ojp-button--plain" data-action="end-activity">지금 종료</button>'
                        + '<button type="button" class="ojp-button ojp-button--small ojp-button--plain" data-action="delete-activity">삭제</button>'
                        + '<button type="button" class="ojp-button ojp-button--small" data-action="view-scores">결과 보기</button></div></td>';

                    tr.querySelector('strong').textContent = activity.name;
                    tr.querySelector('.ftk-activity-context').textContent = contextLabel(activity);
                    tr.querySelector('.ftk-activity-window').textContent = formatWindow(activity);
                    var badge = tr.querySelector('.ojp-status-badge');
                    var status = activity.effectiveStatus || activity.status;
                    badge.className = 'ojp-status-badge ojp-status-badge--' + (STATUS_TONE[status] || 'neutral');
                    badge.textContent = STATUS_LABEL[status] || status;
                    var edit = tr.querySelector('[data-action="edit-activity"]');
                    edit.disabled = activity.status === 'closed' || activity.canEditSchedule === false;
                    if (edit.disabled) { edit.title = '종료했거나 원시 기록 보관 기간이 끝난 활동입니다.'; }
                    edit.addEventListener('click', function () { editActivity(activity); });
                    var end = tr.querySelector('[data-action="end-activity"]');
                    end.hidden = status !== 'active';
                    end.addEventListener('click', function () { confirmEnd(activity, tr); });
                    scheduleRows.push({ activity: activity, badge: badge, end: end });
                    var remove = tr.querySelector('[data-action="delete-activity"]');
                    remove.disabled = Object.keys(pendingDeletes).length > 0;
                    remove.setAttribute('aria-label', activity.name + ' 삭제');
                    remove.addEventListener('click', function () { confirmDelete(activity, tr); });

                    tr.querySelector('[data-action="view-scores"]').addEventListener('click', function () {
                        showScores(activity);
                    });

                    tbody.appendChild(tr);
                });

                activityListEl.appendChild(wrap);
                updateSchedules();
            }).catch(function (error) {
                if (requestId !== activityRequest) { return; }
                activityListEl.innerHTML = '';
                activityListEl.appendChild(emptyState('활동 목록을 불러오지 못했습니다', '잠시 후 화면을 새로 고쳐 주세요.'));
                showMessage(error.message, 'error');
            });
        }

        function pct(value) {
            return Number(value).toFixed(1) + '%';
        }

        function statusLabel(row) {
            return ({ provisional: '진행 중', complete: '계산 완료', incomplete: '기록 일부 누락',
                archived: '최종 집계 보관', no_data: '관측 자료 없음', unavailable: '원시 기록 없음' })[row.dataStatus] || '확인 필요';
        }

        function showScores(activity) {
            var requestId = ++scoreRequest;
            timelineRequest += 1;
            timelinePanel.hidden = true;
            scoresPanel.hidden = false;
            scoresTitle.textContent = activity.name + ' 결과';
            scoresBody.innerHTML = '';
            scoresBody.appendChild(tableMessageRow('불러오는 중…'));

            request('/activities/' + activity.id + '/scores').then(function (data) {
                if (requestId !== scoreRequest) { return; }
                scoresBody.innerHTML = '';
                if (0 === data.rows.length) {
                    scoresBody.appendChild(tableMessageRow('명단을 찾을 수 없습니다.'));
                    return;
                }

                data.rows.forEach(function (row) {
                    var available = row.dataStatus !== 'no_data' && row.dataStatus !== 'unavailable';
                    var scoreAvailable = available && row.dataStatus !== 'incomplete' && !row.hasDeliveryGaps && !row.hasObservationGaps;
                    var tr = document.createElement('tr');
                    if (row.needsReview) {
                        tr.className = 'ftk-needs-review';
                    }

                    tr.innerHTML = '<td data-f="name"></td>'
                        + '<td>' + (available ? pct(row.focusRatio) : '—') + '</td>'
                        + '<td>' + (available ? Number(row.blurCount) : '—') + '</td>'
                        + '<td>' + (available ? Number(row.avgRecoverySeconds).toFixed(1) + '초' : '—') + '</td>'
                        + '<td>' + (available ? pct(row.activityWindowRatio) : '—') + '</td>'
                        + '<td><strong>' + (scoreAvailable ? Number(row.scoreTotal).toFixed(1) : '—') + '</strong></td>'
                        + '<td data-f="status"></td>'
                        + '<td class="ftk-row-actions"><button type="button" class="ojp-button ojp-button--small ojp-button--plain" data-action="view-timeline">상세 보기</button>'
                        + '<button type="button" class="ojp-button ojp-button--small ojp-button--plain" data-action="view-statistics">누적 통계</button></td>';

                    // Names go in as text, not markup.
                    var nameCell = tr.querySelector('[data-f="name"]');
                    nameCell.textContent = row.displayName;
                    tr.querySelector('[data-f="status"]').textContent = statusLabel(row);
                    if (row.needsReview) {
                        var flag = document.createElement('span');
                        flag.className = 'ojp-status-badge ojp-status-badge--warning ftk-review-badge';
                        flag.title = '관측 자료와 수업 맥락을 함께 확인해 주세요. 입력이 적어도 읽거나 생각하는 중일 수 있습니다.';
                        flag.textContent = '검토 필요';
                        nameCell.appendChild(flag);
                    }

                    tr.querySelector('[data-action="view-timeline"]').addEventListener('click', function () {
                        showTimeline(activity, row.userId, row.displayName);
                    });
                    tr.querySelector('[data-action="view-statistics"]').addEventListener('click', function () {
                        document.dispatchEvent(new CustomEvent('ftk:student-statistics', { detail: {
                            userId: row.userId, displayName: row.displayName,
                            contextType: activity.contextType, contextId: activity.contextId,
                            contextLabel: activity.contextLabel || activity.contextId,
                        } }));
                    });

                    scoresBody.appendChild(tr);
                });
            }).catch(function (error) {
                if (requestId !== scoreRequest) { return; }
                scoresBody.innerHTML = '';
                scoresBody.appendChild(tableMessageRow('불러오지 못했습니다.'));
                showMessage(error.message, 'error');
            });
        }

        function showTimeline(activity, userId, displayName) {
            var requestId = ++timelineRequest;
            timelinePanel.hidden = false;
            timelineTitle.textContent = activity.name + ' · ' + displayName;
            timelineChart.textContent = '불러오는 중…';

            request('/activities/' + activity.id + '/participants/' + userId + '/timeline').then(function (data) {
                if (requestId !== timelineRequest) { return; }
                timelineChart.innerHTML = '';
                if (['archived', 'unavailable', 'no_data'].indexOf(data.dataStatus) !== -1) {
                    timelineChart.appendChild(emptyState('원시 타임라인을 표시할 수 없습니다', data.dataStatus === 'archived'
                        ? '보관 기간이 끝나 최종 집계만 남아 있습니다.' : '관측 자료가 없습니다. 이탈로 단정할 수 없습니다.'));
                    return;
                }
                if (data.dataStatus === 'incomplete') {
                    timelineChart.appendChild(emptyState('기록 일부가 누락됐습니다', '관측된 구간만 표시합니다. 전체 집중 상태로 해석하지 마세요.'));
                }
                timelineChart.appendChild(renderTimelineSvg(data));
            }).catch(function (error) {
                if (requestId !== timelineRequest) { return; }
                timelineChart.textContent = '불러오지 못했습니다.';
                showMessage(error.message, 'error');
            });
        }

        function renderTimelineSvg(data) {
            var width = 800;
            var height = 90;
            var barY = 30;
            var barHeight = 30;
            var windowStart = data.windowStartsAtMs;
            var windowSpan = Math.max(1, data.windowEndsAtMs - windowStart);

            function x(ms) {
                return ((ms - windowStart) / windowSpan) * width;
            }

            var svgParts = [];
            svgParts.push(
                '<svg viewBox="0 0 ' + width + ' ' + height + '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="관측된 화면 활성 타임라인">'
            );
            // Background = away/hidden (nothing tracked here). Colours come
            // from the ftk-tl-* classes in console.css (platform tokens).
            svgParts.push('<rect class="ftk-tl-away" x="0" y="' + barY + '" width="' + width + '" height="' + barHeight + '" />');

            (data.spans || []).forEach(function (span) {
                var sx = Math.max(0, x(span.startedAt));
                var ex = Math.min(width, x(span.endedAt));
                if (ex > sx) {
                    svgParts.push('<rect class="ftk-tl-active" x="' + sx + '" y="' + barY + '" width="' + (ex - sx) + '" height="' + barHeight + '" />');
                }
            });

            (data.markers || []).forEach(function (marker) {
                var mx = x(marker.occurredAt);
                var markerClass = marker.type === 'submit' ? 'ftk-tl-submit' : (marker.type === 'run' ? 'ftk-tl-run' : 'ftk-tl-other');
                svgParts.push('<circle class="' + markerClass + '" cx="' + mx + '" cy="' + (barY + barHeight + 12) + '" r="4" />');
            });

            svgParts.push('</svg>');

            var wrapper = document.createElement('div');
            wrapper.innerHTML = svgParts.join('');

            var legend = document.createElement('p');
            legend.className = 'ftk-timeline-legend';
            legend.innerHTML = '<span><span class="ftk-legend-swatch ftk-legend-swatch--active"></span>활성</span>'
                + '<span><span class="ftk-legend-swatch ftk-legend-swatch--away"></span>비활성/관측 안 됨</span>'
                + '<span><span class="ftk-legend-swatch ftk-legend-swatch--dot ftk-legend-swatch--submit"></span>제출</span>'
                + '<span><span class="ftk-legend-swatch ftk-legend-swatch--dot ftk-legend-swatch--run"></span>실행</span>';
            wrapper.appendChild(legend);

            return wrapper;
        }

        createForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var contextValue = contextSelect.value;
            if (!contextValue) {
                showMessage('대상 학급/그룹을 선택해 주세요.', 'error');
                return;
            }
            var contextParts = contextValue.split(':');

            var payload;
            try { payload = {
                name: createForm.querySelector('[data-field="name"]').value.trim(),
                context_type: contextParts[0],
                context_id: contextParts.slice(1).join(':'),
                starts_at: isoValue(createForm.querySelector('[data-field="starts-at"]').value),
                ends_at: isoValue(createForm.querySelector('[data-field="ends-at"]').value),
                problem_ids: problemIds(createForm.querySelector('[data-field="problem-ids"]').value),
            }; } catch (error) { showMessage(error.message, 'error'); return; }

            ['weight-focus', 'weight-selfreg', 'weight-activity'].forEach(function (field, index) {
                var key = ['weight_focus', 'weight_selfreg', 'weight_activity'][index];
                var value = createForm.querySelector('[data-field="' + field + '"]').value;
                if ('' !== value) {
                    payload[key] = parseFloat(value);
                }
            });

            request('/activities', 'POST', payload).then(function () {
                showMessage('활동을 만들었습니다.', 'success');
                createForm.reset();
                loadActivities();
            }).catch(function (error) {
                showMessage(error.message, 'error');
            });
        });

        editForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!editingActivity) { return; }
            var payload;
            try {
                payload = {
                    name: editForm.querySelector('[data-field="edit-name"]').value.trim(),
                    starts_at: isoValue(editForm.querySelector('[data-field="edit-starts-at"]').value),
                    ends_at: isoValue(editForm.querySelector('[data-field="edit-ends-at"]').value),
                    problem_ids: problemIds(editForm.querySelector('[data-field="edit-problem-ids"]').value),
                };
                ['focus', 'selfreg', 'activity'].forEach(function (key) {
                    payload['weight_' + key] = Number(editForm.querySelector('[data-field="edit-weight-' + key + '"]').value);
                });
            } catch (error) { showMessage(error.message, 'error'); return; }
            var save = editForm.querySelector('[data-action="save-activity"]');
            save.disabled = true;
            request('/activities/' + editingActivity.id, 'PATCH', payload).then(function () {
                showMessage('활동 시간을 저장했습니다.', 'success');
                hideReports();
                editPanel.hidden = true; editingActivity = null; loadActivities();
            }).catch(function (error) { showMessage(error.message, 'error'); }).then(function () { save.disabled = false; });
        });
        root.querySelector('[data-action="cancel-edit"]').addEventListener('click', function () { editPanel.hidden = true; editingActivity = null; });
        activityFilter.addEventListener('change', function () {
            editPanel.hidden = true; editingActivity = null;
            hideReports();
            loadActivities();
        });
        var zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '브라우저 설정';
        root.querySelectorAll('[data-field="schedule-timezone"]').forEach(function (item) { item.textContent = '입력·표시 시각: ' + zone + ' (현재 브라우저의 시간대)'; });

        closeTimelineButton.addEventListener('click', function () {
            timelineRequest += 1;
            timelinePanel.hidden = true;
        });

        loadContexts().then(loadActivities);
    });
})();

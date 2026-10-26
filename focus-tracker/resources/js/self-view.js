/**
 * Student self-view page behind [data-ftk-self-view]
 * (Addon\Self_View_Page_Handler) — see docs/DESIGN.md §8/§10 Phase 2.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ftk-self-view]');
        if (!root) {
            return;
        }

        var config = JSON.parse(root.getAttribute('data-config') || '{}');
        var summaryEl = root.querySelector('[data-field="summary"]');

        function emptyState(title, message) {
            summaryEl.innerHTML = '<div class="ojp-empty-state"><div><strong></strong><p></p></div></div>';
            summaryEl.querySelector('strong').textContent = title;
            summaryEl.querySelector('p').textContent = message;
        }

        function renderChecks(data) {
            if (!data) { return; }
            var section = document.createElement('section');
            section.className = 'ojp-card';
            var heading = document.createElement('h2');
            heading.textContent = '자동 학습 확인 · 최근 24시간';
            section.appendChild(heading);
            var detail = document.createElement('p');
            if (data.available === false) {
                detail.textContent = '학습 확인 기록을 불러오지 못했습니다.';
            } else {
                var counts = data.counts || {};
                var labels = {correct: '정답 확인', incorrect: '오답', unknown: '모름', skipped: '건너뛰기', unanswered: '무응답', pending: '응답 대기', invalidated: '취소'};
                detail.textContent = Object.keys(labels).map(function (key) { return labels[key] + ' ' + Number(counts[key] || 0) + '건'; }).join(' · ');
            }
            section.appendChild(detail);
            var note = document.createElement('p');
            note.className = 'ojp-muted';
            note.textContent = '문항별 응답 기록이며 집중 점수와 별개입니다. 오답·모름·무응답을 미집중으로 판단하지 않습니다.';
            section.appendChild(note);
            summaryEl.appendChild(section);
        }

        fetch(config.restUrl + '/me/summary', {
            headers: { 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('요약을 불러오지 못했습니다.'); }
                return response.json();
            })
            .then(function (data) {
                if (!data.hasActivity) {
                    emptyState('아직 기록된 활동이 없습니다', '선생님이 연 활동에서 문제를 풀면 여기에 점수가 표시됩니다.');
                    renderChecks(data.learningChecks);
                    return;
                }

                summaryEl.innerHTML = ''
                    + '<h2></h2>'
                    + '<div class="ojp-metric-grid ojp-metric-grid--four">'
                    + '  <div class="ojp-metric"><span>화면 활성률</span><strong data-f="focus"></strong></div>'
                    + '  <div class="ojp-metric"><span>이탈 횟수</span><strong data-f="blur"></strong></div>'
                    + '  <div class="ojp-metric"><span>평균 복귀시간</span><strong data-f="recovery"></strong></div>'
                    + '  <div class="ojp-metric"><span>입력·제출 활동률</span><strong data-f="activity"></strong></div>'
                    + '</div>'
                    + '<p class="ftk-self-total">참여 지표 <strong data-f="total"></strong></p>'
                    + '<p data-f="status" class="ojp-muted"></p>';

                var available = data.dataStatus !== 'no_data' && data.dataStatus !== 'unavailable';
                var scoreAvailable = available && data.dataStatus !== 'incomplete' && !data.hasDeliveryGaps && !data.hasObservationGaps;
                summaryEl.querySelector('h2').textContent = data.activityName;
                summaryEl.querySelector('[data-f="focus"]').textContent = available ? Number(data.focusRatio).toFixed(1) + '%' : '—';
                summaryEl.querySelector('[data-f="blur"]').textContent = available ? data.blurCount + '회' : '—';
                summaryEl.querySelector('[data-f="recovery"]').textContent = available ? Number(data.avgRecoverySeconds).toFixed(1) + '초' : '—';
                summaryEl.querySelector('[data-f="activity"]').textContent = available ? Number(data.activityWindowRatio).toFixed(1) + '%' : '—';
                summaryEl.querySelector('[data-f="total"]').textContent = scoreAvailable ? Number(data.scoreTotal).toFixed(1) : '—';
                var label = ({ provisional: '진행 중인 지표입니다.', complete: '활동 종료 후 계산한 지표입니다.', incomplete: '기록 일부가 누락되어 지표를 확정할 수 없습니다.',
                    archived: '보관 기간이 끝나 최종 집계만 남아 있습니다.', no_data: '관측 자료가 없습니다. 미참여로 단정할 수 없습니다.', unavailable: '원시 기록이 없어 지표를 계산할 수 없습니다.' })[data.dataStatus] || '';
                summaryEl.querySelector('[data-f="status"]').textContent = label + (data.needsReview ? ' 선생님이 수업 맥락과 함께 확인해야 합니다.' : '');
                renderChecks(data.learningChecks);
            })
            .catch(function () {
                emptyState('요약을 불러오지 못했습니다', '잠시 후 화면을 새로 고쳐 주세요.');
            });
    });
})();

/** Weekly/monthly learning evidence. Missing observations remain missing. */
(function () {
    'use strict';

    var namespace = 'http://www.w3.org/2000/svg';
    var statuses = {
        complete: '관측 기록 있음', incomplete: '기록 일부 누락', no_data: '관측 자료 없음',
        unavailable: '상세 기록 확인 불가', archived: '최종 집계만 보관',
    };
    var checkLabels = { correct: '정답 확인', incorrect: '오답', unknown: '모름', skipped: '건너뛰기',
        unanswered: '무응답', pending: '응답 대기', invalidated: '취소' };

    function node(tag, className, text) {
        var result = document.createElement(tag);
        if (className) { result.className = className; }
        if (text !== undefined) { result.textContent = text; }
        return result;
    }
    function svgNode(tag, attributes, text) {
        var result = document.createElementNS(namespace, tag);
        Object.keys(attributes || {}).forEach(function (key) { result.setAttribute(key, String(attributes[key])); });
        if (text !== undefined) { result.textContent = text; }
        return result;
    }
    function field(tag, name, className, text) {
        var result = node(tag, className, text);
        result.setAttribute('data-stat', name);
        return result;
    }
    function finite(value) { return typeof value === 'number' && Number.isFinite(value); }
    function minutes(value) { return finite(value) ? (value / 60).toFixed(1) + '분' : '—'; }
    function percentage(value) { return finite(value) ? (Math.max(0, Math.min(1, value)) * 100).toFixed(1) + '%' : '—'; }
    function count(value) { return finite(value) ? String(value) + '회' : '—'; }
    function isoDate(value) { return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value); }
    function offsetDate(value, period, direction) {
        if (!isoDate(value)) { return ''; }
        var parts = value.split('-').map(Number);
        var date = new Date(Date.UTC(parts[0], parts[1] - 1, period === 'month' ? 1 : parts[2]));
        if (period === 'month') { date.setUTCMonth(date.getUTCMonth() + direction); }
        else { date.setUTCDate(date.getUTCDate() + direction * 7); }
        return date.toISOString().slice(0, 10);
    }

    function initialize(root) {
        if (root.__focusStatisticsStarted) { return; }
        root.__focusStatisticsStarted = true;
        var config;
        try { config = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (error) { return; }
        if (!config.restUrl || typeof window.fetch !== 'function') { return; }
        var teacher = root.getAttribute('data-mode') === 'teacher';
        var state = { period: 'week', date: '', today: '', selected: null, sequence: 0, last: null };
        var title = field('h2', 'title', '', teacher ? '학생별 누적 기록' : '주간·월간 누적 기록');
        var help = node('p', 'ojp-muted', '화면 활성과 학습 활동의 누적 기록입니다. 실제 집중·이해를 직접 측정하지 않습니다.');
        var toolbar = node('div', 'ftk-statistics-toolbar');
        var periodLabel = node('label', 'ftk-statistics-field', '조회 단위');
        var period = field('select', 'period', 'ojp-select');
        period.setAttribute('aria-label', '조회 단위');
        [['week', '주간'], ['month', '월간']].forEach(function (entry) {
            var option = node('option', '', entry[1]); option.value = entry[0]; period.appendChild(option);
        });
        period.value = 'week';
        periodLabel.appendChild(period);
        var dateLabel = node('label', 'ftk-statistics-field', '기준 날짜');
        var date = field('input', 'date', 'ojp-input');
        date.type = 'date';
        date.setAttribute('aria-label', '기준 날짜');
        dateLabel.appendChild(date);
        function control(name, label, handler) {
            var result = field('button', name, 'ojp-button ojp-button--small', label);
            result.type = 'button'; result.addEventListener('click', handler); return result;
        }
        var previous = control('previous', '이전 주', function () { navigate(-1); });
        var next = control('next', '다음 주', function () { navigate(1); });
        var refresh = control('refresh', '새로고침', load);
        toolbar.appendChild(periodLabel); toolbar.appendChild(dateLabel);
        var navigation = node('div', 'ftk-statistics-navigation');
        navigation.appendChild(previous); navigation.appendChild(next); navigation.appendChild(refresh);
        toolbar.appendChild(navigation);
        var live = field('p', 'message', 'ojp-muted', teacher ? '학생의 누적 기록 보기를 선택해 주세요.' : '누적 기록을 불러오는 중…');
        live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite');
        var results = field('div', 'results');
        root.replaceChildren(title, help, toolbar, live, results);

        function clear() { results.replaceChildren(); state.last = null; }
        function endpoint() {
            var query = { period: state.period };
            if (state.date) { query.date = state.date; }
            if (teacher) {
                query.context_type = state.selected.contextType;
                query.context_id = state.selected.contextId;
                query.user_id = state.selected.userId;
            }
            return config.restUrl.replace(/\/$/, '') + (teacher ? '/statistics' : '/me/statistics') + '?'
                + Object.keys(query).map(function (key) { return encodeURIComponent(key) + '=' + encodeURIComponent(query[key]); }).join('&');
        }
        function navigate(direction) {
            var target = offsetDate((state.last && state.last.startDate) || state.date, state.period, direction);
            if (!target || (direction > 0 && state.today && target > state.today)) { return; }
            state.date = target; date.value = target; load();
        }
        function comparison(data) {
            var previousLabel = state.period === 'month' ? '이전 달' : '이전 주';
            var delta = data.delta || {};
            if (!finite(delta.activeSeconds) && !finite(delta.focusRatio)) {
                return previousLabel + '와 비교할 관측 자료가 부족합니다.';
            }
            var changes = [];
            if (finite(delta.activeSeconds)) { changes.push('화면 활성 시간 ' + (delta.activeSeconds > 0 ? '+' : '') + (delta.activeSeconds / 60).toFixed(1) + '분'); }
            if (finite(delta.focusRatio)) { changes.push('화면 활성률 ' + (delta.focusRatio > 0 ? '+' : '') + (delta.focusRatio * 100).toFixed(1) + '%p'); }
            return previousLabel + '의 같은 경과 구간 대비 ' + changes.join(' · ');
        }
        function card(label, value) {
            var item = node('div', 'ojp-metric');
            item.appendChild(node('span', '', label)); item.appendChild(node('strong', '', value)); return item;
        }
        function render(data) {
            state.last = data;
            if (!state.date) {
                state.date = isoDate(data.today) && data.today >= data.startDate && data.today <= data.endDate ? data.today : data.startDate;
            }
            date.value = state.date;
            if (isoDate(data.today)) { state.today = data.today; date.max = data.today; }
            previous.textContent = state.period === 'month' ? '이전 달' : '이전 주';
            next.textContent = state.period === 'month' ? '다음 달' : '다음 주';
            next.disabled = !!(state.today && offsetDate(data.startDate, state.period, 1) > state.today);
            var totals = data.totals || {};
            live.textContent = String(data.startDate || '') + ' ~ ' + String(data.endDate || '') + ' · ' + String(data.timeZone || '')
                + ' · ' + (statuses[totals.status] || '기록 상태 확인 필요')
                + (data.provisional ? ' · 진행 중인 기간입니다.' : '');
            var metrics = node('div', 'ojp-metric-grid ftk-statistics-metrics');
            metrics.appendChild(card('화면 활성 시간', minutes(totals.activeSeconds)));
            metrics.appendChild(card('관측 시간', minutes(totals.observedSeconds)));
            metrics.appendChild(card('화면 활성률', percentage(totals.focusRatio)));
            metrics.appendChild(card('편집 신호', count(totals.editCount)));
            metrics.appendChild(card('실행 신호', count(totals.runCount)));
            metrics.appendChild(card('제출 시도', count(totals.submitCount)));
            var detail = node('p', 'ftk-statistics-comparison', comparison(data));
            var quality = node('p', 'ojp-muted', '관측이 없는 날은 빈칸(—)이며 0으로 계산하지 않습니다. 제출은 채점 성공 횟수가 아닌 제출 시도 신호입니다.');
            if (totals.hasDeliveryGaps || totals.hasObservationGaps || totals.coverageUnknown || totals.status === 'incomplete') {
                quality.textContent += ' 일부 누락 또는 보관 기간 때문에 전체 기간의 상태를 확정할 수 없습니다.';
            }
            var checks = node('p', 'ftk-statistics-checks');
            checks.textContent = '자동 학습 확인: ' + Object.keys(checkLabels).map(function (key) {
                var value = totals.checks && totals.checks[key];
                return checkLabels[key] + ' ' + (finite(value) ? value + '건' : '—');
            }).join(' · ');
            var checksNote = node('p', 'ojp-muted', '확인 문항의 정답·오답·무응답은 집중 점수와 별개이며 미집중으로 단정하지 않습니다.');
            results.replaceChildren(metrics, detail, quality, checks, checksNote);
            var days = Array.isArray(data.days) ? data.days : [];
            renderChart(days);
            renderTable(days);
        }
        function renderChart(days) {
            var figure = field('figure', 'chart', 'ftk-statistics-chart');
            var caption = node('figcaption', '', '일별 변화 · 막대: 화면 활성 시간(분, 왼쪽 축) · 선: 화면 활성률(%, 오른쪽 축)');
            figure.appendChild(caption);
            if (!days.length) { figure.appendChild(node('p', 'ojp-muted', '표시할 일별 기록이 없습니다.')); results.appendChild(figure); return; }
            var width = 900; var height = 280; var left = 58; var right = 52; var top = 28; var bottom = 48;
            var plotWidth = width - left - right; var plotHeight = height - top - bottom;
            var max = Math.max(1, Math.ceil(Math.max.apply(null, days.map(function (day) {
                return finite(day.activeSeconds) ? day.activeSeconds / 60 : 0;
            }))));
            var chart = svgNode('svg', { viewBox: '0 0 ' + width + ' ' + height, role: 'img',
                'aria-label': '일별 화면 활성 시간과 화면 활성률. 관측 자료가 없는 날은 연결하지 않습니다.' });
            chart.appendChild(svgNode('title', {}, '일별 화면 활성 기록. 아래 일별 수치 표에서 같은 정보를 확인할 수 있습니다.'));
            [0, 0.5, 1].forEach(function (fraction) {
                var y = top + plotHeight * (1 - fraction);
                chart.appendChild(svgNode('line', { x1: left, x2: width - right, y1: y, y2: y, class: 'ftk-stat-grid' }));
                chart.appendChild(svgNode('text', { x: left - 8, y: y + 4, 'text-anchor': 'end', class: 'ftk-stat-axis' }, (max * fraction).toFixed(max < 10 ? 1 : 0)));
                chart.appendChild(svgNode('text', { x: width - right + 8, y: y + 4, class: 'ftk-stat-axis' }, String(fraction * 100) + '%'));
            });
            var step = plotWidth / days.length;
            var segment = [];
            function commitLine() {
                if (segment.length > 1) { chart.appendChild(svgNode('polyline', { points: segment.join(' '), class: 'ftk-stat-line' })); }
                segment = [];
            }
            days.forEach(function (day, index) {
                var x = left + (index + 0.5) * step;
                var active = finite(day.activeSeconds) ? Math.max(0, day.activeSeconds) : null;
                if (active !== null) {
                    var barHeight = active / 60 / max * plotHeight;
                    var bar = svgNode('rect', { x: x - step * 0.28, y: top + plotHeight - barHeight,
                        width: step * 0.56, height: barHeight, class: 'ftk-stat-bar', 'data-stat-date': day.date || '' });
                    bar.appendChild(svgNode('title', {}, String(day.date || '') + ': ' + minutes(active)));
                    chart.appendChild(bar);
                } else {
                    chart.appendChild(svgNode('text', { x: x, y: top + plotHeight - 4, 'text-anchor': 'middle', class: 'ftk-stat-gap' }, '—'));
                }
                if (finite(day.focusRatio)) {
                    var y = top + plotHeight * (1 - Math.max(0, Math.min(1, day.focusRatio)));
                    segment.push(x + ',' + y);
                    var point = svgNode('circle', { cx: x, cy: y, r: 3, class: 'ftk-stat-point' });
                    point.appendChild(svgNode('title', {}, String(day.date || '') + ': ' + percentage(day.focusRatio)));
                    chart.appendChild(point);
                } else { commitLine(); }
                if (days.length <= 7 || index % 5 === 0 || index === days.length - 1) {
                    chart.appendChild(svgNode('text', { x: x, y: height - 16, 'text-anchor': 'middle', class: 'ftk-stat-axis' }, String(day.date || '').slice(5)));
                }
            });
            commitLine();
            figure.appendChild(chart); results.appendChild(figure);
        }
        function renderTable(days) {
            var disclosure = node('details', 'ftk-statistics-table');
            disclosure.appendChild(node('summary', '', '일별 수치 보기'));
            var wrap = node('div', 'ojp-table-wrap');
            var table = field('table', 'table', 'ojp-table');
            var caption = node('caption', '', '일별 화면 활성과 학습 활동 기록'); table.appendChild(caption);
            var head = node('thead'); var row = node('tr');
            ['날짜', '화면 활성', '관측 시간', '활성률', '편집', '실행', '제출 시도', '기록 상태'].forEach(function (label) {
                var th = node('th', '', label); th.setAttribute('scope', 'col'); row.appendChild(th);
            });
            head.appendChild(row); table.appendChild(head);
            var body = node('tbody');
            days.forEach(function (day) {
                var tr = node('tr'); tr.setAttribute('data-stat-day', String(day.date || ''));
                [day.date || '', minutes(day.activeSeconds), minutes(day.observedSeconds), percentage(day.focusRatio),
                    count(day.editCount), count(day.runCount), count(day.submitCount), statuses[day.status] || '기록 상태 확인 필요'].forEach(function (value, index) {
                    var cell = node(index === 0 ? 'th' : 'td', '', value);
                    if (index === 0) { cell.setAttribute('scope', 'row'); }
                    tr.appendChild(cell);
                });
                body.appendChild(tr);
            });
            table.appendChild(body); wrap.appendChild(table); disclosure.appendChild(wrap); results.appendChild(disclosure);
        }
        function load() {
            if (teacher && !state.selected) { return; }
            var sequence = ++state.sequence;
            clear(); root.setAttribute('aria-busy', 'true'); live.textContent = '누적 기록을 불러오는 중…';
            var timeout;
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var deadline = new Promise(function (resolve, reject) {
                timeout = setTimeout(function () { if (controller) { controller.abort(); } reject(new Error('Statistics timeout')); }, 15000);
            });
            var fetchRequest;
            try {
                fetchRequest = window.fetch(endpoint(), { method: 'GET', credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': config.nonce || '' }, signal: controller ? controller.signal : undefined }).then(function (response) {
                    if (!response.ok) { throw new Error('Statistics request rejected'); }
                    return response.json();
                });
            } catch (error) { fetchRequest = Promise.reject(error); }
            Promise.race([fetchRequest, deadline]).then(function (data) {
                if (sequence !== state.sequence) { return; }
                if (!data || !Array.isArray(data.days) || !data.totals || !isoDate(data.startDate) || !isoDate(data.endDate)) {
                    throw new Error('Invalid statistics response');
                }
                render(data);
            }).catch(function () {
                if (sequence !== state.sequence) { return; }
                clear();
                live.textContent = '누적 기록을 불러오지 못했습니다. 권한과 연결 상태를 확인한 후 새로고침해 주세요.';
            }).then(function () {
                clearTimeout(timeout);
                if (sequence === state.sequence) { root.removeAttribute('aria-busy'); }
            });
        }
        period.addEventListener('change', function () { state.period = period.value === 'month' ? 'month' : 'week'; load(); });
        date.addEventListener('change', function () {
            if (isoDate(date.value) && (!state.today || date.value <= state.today)) {
                state.date = date.value; load();
            } else {
                date.value = state.date;
                live.textContent = '오늘까지의 실제 날짜를 선택해 주세요.';
            }
        });
        if (teacher) {
            document.addEventListener('ftk:student-statistics', function (event) {
                var detail = event.detail || {};
                if (!(Number(detail.userId) > 0) || ['class', 'group'].indexOf(detail.contextType) === -1
                    || typeof detail.contextId !== 'string' || !detail.contextId || detail.contextId.length > 200) { return; }
                state.selected = { userId: Number(detail.userId), contextType: detail.contextType, contextId: detail.contextId };
                title.textContent = String(detail.displayName || '학생') + ' · '
                    + String(detail.contextLabel || (detail.contextType === 'group' ? '모둠별' : '학급별')) + ' · 주간·월간 누적 기록';
                root.hidden = false; load();
            });
        } else { load(); }
    }
    function bind() { document.querySelectorAll('[data-ftk-statistics]').forEach(initialize); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bind, { once: true }); }
    else { bind(); }
})();

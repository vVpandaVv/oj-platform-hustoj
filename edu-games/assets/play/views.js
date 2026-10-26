(() => {
    'use strict';
    const h = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const button = (label, move, primary = false, disabled = false) => '<button type="button" data-focus="' + h(JSON.stringify(move)) + '" data-move="' + h(JSON.stringify(move)) + '"' + (primary ? ' class="ep-primary"' : '') + (disabled ? ' disabled' : '') + '>' + h(label) + '</button>';
    const pickButton = (label, kind, id, selected = false) => '<button type="button" data-pick="' + kind + '" data-id="' + id + '" aria-pressed="' + selected + '">' + h(label) + '</button>';
    const options = (items, value) => Object.entries(items).map(([k, v]) => '<option value="' + h(k) + '"' + (String(value) === k ? ' selected' : '') + '>' + h(v) + '</option>').join('');
    const select = (name, label, items, value) => '<label>' + h(label) + '<select name="' + name + '">' + options(items, value) + '</select></label>';
    const number = (name, label, value, min, max) => '<label>' + h(label) + '<input name="' + name + '" type="number" value="' + value + '" min="' + min + '" max="' + max + '" step="1" required></label>';
    const form = (type, body, text = '적용', data = {}) => '<form data-play-form="' + type + '" data-extra="' + h(JSON.stringify(data)) + '">' + body + '<button type="submit">' + h(text) + '</button></form>';
    const table = (headers, rows) => '<div class="ep-scroll"><table><thead><tr>' + headers.map(x => '<th>' + h(x) + '</th>').join('') + '</tr></thead><tbody>' + rows.map(row => '<tr>' + row.map(x => '<td>' + h(x) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div>';
    const metrics = values => '<div class="ep-metrics">' + Object.entries(values).map(([label, value]) => '<span>' + h(label) + ' <b>' + h(value) + '</b></span>').join('') + '</div>';
    const chart = key => '<div class="ep-chart"><canvas data-chart="' + key + '" role="img" aria-label="관측값 또는 주문량 그래프"></canvas></div>';
    const plots = (points, gates = [], incoming = [], results = false) => {
        const px = x => 40 + x * 36, py = y => 400 - y * 36;
        let svg = '<svg class="ep-scatter" viewBox="0 0 440 440" role="img" aria-label="가로와 세로 특성 값 0부터 10까지의 분류 지도">';
        for (let i = 0; i <= 10; i++) svg += '<path d="M' + px(i) + ' 40V400M40 ' + py(i) + 'H400" stroke="#d2e0e2"/><text x="' + px(i) + '" y="418" text-anchor="middle">' + i + '</text><text x="28" y="' + (py(i) + 4) + '" text-anchor="end">' + i + '</text>';
        svg += '<text x="220" y="438" text-anchor="middle">가로 특성</text><text transform="translate(12 220) rotate(-90)" text-anchor="middle">세로 특성</text>';
        gates.forEach((g, i) => { svg += '<rect class="ep-gate" x="' + px(g[0]) + '" y="' + py(g[3]) + '" width="' + ((g[2] - g[0]) * 36) + '" height="' + ((g[3] - g[1]) * 36) + '"/><text x="' + (px(g[0]) + 4) + '" y="' + (py(g[3]) + 14) + '">게이트 ' + (i + 1) + '</text>'; });
        [...points, ...incoming].forEach(p => {
            const known = p.good !== undefined, color = known ? (p.good ? 'ep-good' : 'ep-bad') : 'ep-unknown';
            const title = '좌표 ' + p.x + ', ' + p.y + (known ? (p.good ? ' / 가능' : ' / 불가') : ' / 미확인');
            svg += '<circle class="' + color + '" cx="' + px(p.x) + '" cy="' + py(p.y) + '" r="' + (known ? 7 : 5) + '"><title>' + h(title) + '</title></circle>';
            if (p.id !== undefined) svg += '<text x="' + (px(p.x) + 8) + '" y="' + (py(p.y) - 7) + '">' + (p.id + 1) + '</text>';
            if (results && !p.correct) svg += '<circle cx="' + px(p.x) + '" cy="' + py(p.y) + '" r="11" fill="none" stroke="#922238" stroke-width="2"/>';
        });
        return svg + '</svg>';
    };
    function outlier(s, local) {
        const id = local.station ?? 0, station = s.stations[id], zone = local.zone ?? 'all';
        const policies = { raw: '원값 전송', celsius: '°F → °C 변환', hold: '전송 보류' };
        return metrics({ '관측 주기': s.turn + ' / 6', '출동 자원': s.energy }) + '<div class="ep-grid-layout"><div class="ep-workspace"><div class="ep-map"><svg viewBox="0 0 600 400" preserveAspectRatio="none" aria-hidden="true"><path d="M0 80L95 5L170 85L260 10L330 100V0H0Z" fill="#b1d3ae"/><path d="M390 0Q440 130 410 200T460 400H600V0Z" fill="#a7dadd"/><path d="M65 110L300 100L490 110L450 310L250 300L90 320Z" fill="none" stroke="#fff" stroke-width="12"/></svg><div class="ep-stations">' + s.stations.map((p, i) => '<button type="button" data-pick="station" data-id="' + i + '" class="ep-station ' + (p.reading >= 32 ? 'hot' : '') + '" aria-pressed="' + (i === id) + '">' + h(p.name) + '<strong>' + p.reading + '</strong><small>' + h(p.zone) + ' · ' + h(policies[p.policy]) + '</small></button>').join('') + '</div></div><div class="ep-actions">' + ['all', '내륙', '해안'].map(v => pickButton(v === 'all' ? '전체 비교' : v, 'zone', v, zone === v)).join('') + '</div>' + chart('observations') + table(['관측소', '지도로 보낸 값', '경보', ...(s.status !== 'playing' ? ['최종 독립 검증 °C'] : [])], s.stations.map(p => [p.name, p.published ?? '미전송', p.alarm ? '발령' : '-', ...(s.status !== 'playing' ? [p.verified] : [])])) + '</div><div class="ep-workspace"><h3>' + h(station.name) + ' 관측소</h3><p>현재 장치 보고 <strong>' + station.reading + '</strong> · 비교 지역 ' + h(station.zone) + '</p><div class="ep-actions">' + Object.entries(policies).map(([policy, label]) => button(label, { type: 'policy', station: id, policy }, station.policy === policy)).join('') + '</div><div class="ep-actions">' + button('현장 일지 · 자원 1', { type: 'inspect', station: id }, false, station.inspected || s.energy < 1) + button('센서 교체 · 자원 3', { type: 'policy', station: id, policy: 'repair' }, false, station.repaired || s.energy < 3) + '</div>' + (station.evidence ? '<p>독립 온도계 ' + station.evidence.range.join('~') + '°C<br>' + station.evidence.time + '회 시점 · 전송 단위 ' + h(station.evidence.unit) + '</p>' : '') + '<div class="ep-controls"><h4>관측망 운영</h4><div class="ep-actions">' + button('다음 관측 전송', { type: 'run', steps: 1 }, true) + button('남은 관측 자동 전송', { type: 'run', steps: 6 }) + '</div></div></div></div>';
    }
    function missing(s, local) {
        const pos = s.pos, adjacent = i => Math.abs(Math.floor(pos / 5) - Math.floor(i / 5)) + Math.abs(pos % 5 - i % 5) === 1;
        let id = local.cell;
        if (id === undefined || !adjacent(id)) id = s.cells.findIndex((_, i) => adjacent(i));
        const c = s.cells[id], range = s.estimates[id];
        return metrics({ '연료': Math.max(0, s.fuel), '위치': (Math.floor(pos / 5) + 1) + '행 ' + (pos % 5 + 1) + '열' }) + '<div class="ep-grid-layout"><div><div class="ep-map-grid">' + s.cells.map((c, i) => '<button type="button" class="ep-tile ' + (c.value === null ? 'unknown ' : '') + (c.rough ? 'rough ' : '') + (s.visited.includes(i) ? 'visited ' : '') + (i === pos ? 'current ' : '') + (c.value === 9 ? 'blocked ' : '') + '" data-pick="cell" data-id="' + i + '" aria-label="' + (Math.floor(i / 5) + 1) + '행 ' + (i % 5 + 1) + '열 비용 ' + (c.value ?? '미관측') + '" aria-pressed="' + (i === id) + '">' + (i === pos ? '탐험대' : c.value === 9 ? '절벽' : c.value ?? '?') + '<small>' + (i === s.goal ? '보급소' : s.estimates[i] && c.value === null ? s.estimates[i].join('~') + ' 추정' : c.rough ? '바위 지대' : '') + '</small></button>').join('') + '</div><p class="ep-legend">실선: 관측 · 점선: 결측 · 사선: 바위 지대</p></div><div class="ep-workspace"><h3>' + (Math.floor(id / 5) + 1) + '행 ' + (id % 5 + 1) + '열</h3><p>이동 비용: ' + (c.value === null ? '알 수 없음' : c.value === 9 ? '통과 불가' : c.value) + '</p>' + (c.value === null ? form('estimate', '<div class="ep-actions">' + number('low', '최소', range?.[0] ?? 1, 1, 9) + number('high', '최대', range?.[1] ?? 3, 1, 9) + '</div>', '추정 범위 기록', { cell: id }) + '<div class="ep-actions">' + button('정찰 · 연료 2', { type: 'scout', cell: id }, false, s.fuel < 2) + '</div>' : '') + '<div class="ep-actions">' + button('이 칸으로 이동', { type: 'walk', cell: id }, true, c.value === 9 || (c.value === null && !range)) + '</div><h4>맞닿은 관측</h4>' + table(['칸', '비용'], s.cells.flatMap((v, i) => Math.abs(Math.floor(i / 5) - Math.floor(id / 5)) + Math.abs(i % 5 - id % 5) === 1 ? [[(Math.floor(i / 5) + 1) + ',' + (i % 5 + 1), v.value ?? '?']] : [])) + '</div></div>';
    }
    function harbor(s) {
        let html = metrics({ '운항': s.turn + ' / 3', '배송': s.delivered, '미배송': s.late });
        if (s.phase === 'analyst') {
            html += '<h3>분석가의 항구 원장</h3>' + table(['시각', ...s.ports], s.source.map((r, i) => [s.times[i], ...r]));
            let fields = '';
            for (let i = 0; i < 2; i++) fields += '<div class="ep-actions">' + select('group' + i, '차트 ' + (i + 1), i === 0 ? { time: '시간대별 주문', total: '항구별 하루 총량' } : { off: '사용 안 함', time: '시간대별 주문', total: '항구별 하루 총량' }, s.charts[i]?.group || 'off') + select('kind' + i, '표현', { bar: '막대', line: '꺾은선' }, s.charts[i]?.kind || 'bar') + '</div>';
            html += form('charts', fields, '차트 구성 적용');
        }
        html += '<div class="ep-chart-pair">' + s.plots.map((_, i) => chart('port-' + i)).join('') + '</div>';
        if (s.phase === 'analyst') return html + '<div class="ep-actions">' + button('지휘관에게 차트 전달', { type: 'handoff' }, true) + '</div>';
        html += '<h3>' + h(s.times[Math.min(2, s.turn)]) + ' 출항 배치</h3><svg class="ep-port-scene" viewBox="0 0 700 160" role="img" aria-label="세 항구와 두 화물선"><path d="M0 36H700" stroke="#c29e67" stroke-width="50"/>' + s.ports.map((name, i) => '<rect x="' + (70 + i * 235) + '" y="22" width="80" height="58" fill="#778785"/><text x="' + (110 + i * 235) + '" y="20" text-anchor="middle" font-size="16">' + name + '</text>').join('') + '<g class="ship" fill="#268c79"><path d="M70 113H195L173 140H91Z"/><rect x="100" y="90" width="25" height="22" fill="#e3b748"/><rect x="127" y="90" width="25" height="22" fill="#d35c65"/></g><g class="ship" fill="#465e68"><path d="M420 113H545L523 140H441Z"/><rect x="450" y="90" width="25" height="22" fill="#e3b748"/><rect x="477" y="90" width="25" height="22" fill="#d35c65"/></g></svg><div class="ep-ships">' + s.assign.map((ship, i) => '<section class="ep-ship"><h4>화물선 ' + (i + 1) + ' · 최대 6상자</h4>' + form('assign', select('port', '목적지', Object.fromEntries(s.ports.map((p, j) => [j, p])), ship.port) + number('cargo', '화물', ship.cargo, 0, 6), '배치', { ship: i }) + '</section>').join('') + '</div><div class="ep-actions">' + button('두 배 출항', { type: 'sail' }, true) + '</div>';
        s.history.forEach(r => { html += '<h4>' + h(r.time) + ' 도착</h4>' + table(['항구', '주문', '도착', '미배송'], s.ports.map((name, i) => [name, r.demand[i], r.arrival[i], Math.max(0, r.demand[i] - r.arrival[i])])); });
        return html;
    }
    function factory(s, local) {
        const id = local.node ?? 1, n = s.nodes[id - 1];
        const rules = { all: '모두 통과', red: '빨강만', blue: '파랑만', large: '크기 3 이상', small: '크기 3 미만' }, names = { id: '번호', name: '이름', color: '색', size: '크기', private: '내부 원가' };
        const sourceOptions = max => Object.fromEntries(Array.from({ length: max + 1 }, (_, i) => [i, i === 0 ? '입고 원본' : i + '번 출력']));
        const points = [[50, 5], [50, 23], [23, 44], [23, 66], [77, 44], [77, 66], [23, 90], [77, 90]];
        let wires = '<svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">';
        const wire = (a, b) => '<path d="M' + points[a][0] + ' ' + (points[a][1] + 4) + 'L' + points[b][0] + ' ' + (points[b][1] - 5) + '" stroke="#508d7b" stroke-width=".6"/>';
        s.nodes.forEach((p, i) => { wires += wire(p.source, i + 1); });
        s.sinks.forEach((p, i) => { wires += wire(p, i + 6); });
        wires += '</svg>';
        const graph = '<div class="ep-circuit">' + wires + '<span class="ep-circuit-source">입고 원본</span>' + s.nodes.map((p, i) => '<button type="button" data-pick="node" data-id="' + (i + 1) + '" style="left:' + points[i + 1][0] + '%;top:' + points[i + 1][1] + '%" aria-pressed="' + (id === i + 1) + '"><strong>' + (i + 1) + '번</strong><span>' + h(p.kind === 'filter' ? rules[p.rule] : p.fields.map(f => names[f]).join(' · ')) + '</span><small>' + (s.trace[i] ? s.trace[i].in + ' → ' + s.trace[i].out + '개' : p.kind === 'filter' ? '필터' : '열 추출') + '</small></button>').join('') + s.sinks.map((p, i) => '<span class="ep-circuit-customer" style="left:' + points[i + 6][0] + '%;top:90%">고객 ' + (i === 0 ? 'A' : 'B') + '<br>' + (s.outputs[i] ? '도착 ' + s.outputs[i].accepted.length + ' / 반송 ' + s.outputs[i].wrong : '배송 대기') + '</span>').join('') + '</div>';
        const editor = '<h3>' + id + '번 처리기</h3>' + form('node', '<div class="ep-node-editor">' + select('source', '입력 연결', sourceOptions(id - 1), n.source) + select('kind', '동작', { filter: '조건 필터', project: '열 추출' }, n.kind) + '<div data-node-filter>' + select('rule', '필터 조건', rules, n.rule) + '</div><fieldset class="ep-node-fields" data-node-project><legend>추출 열</legend>' + Object.entries(names).map(([key, name]) => '<label><input type="checkbox" name="fields" value="' + key + '"' + (n.fields.includes(key) ? ' checked' : '') + '>' + name + '</label>').join('') + '</fieldset></div>', '연결 적용', { node: id });
        return metrics({ '처리 묶음': s.turn + ' / 3', '배송 성공': s.correct, '반송': s.wrong }) + '<div class="ep-actions"><strong>' + h(s.contracts.join(' / ')) + '</strong><span>전달 열: 번호, 이름</span></div><div class="ep-grid-layout"><div>' + graph + '<div class="ep-actions">' + button('다음 입고 처리', { type: 'run' }, true) + button('남은 입고 자동 처리', { type: 'run', steps: 3 }) + '</div></div><div class="ep-workspace">' + editor + '<div class="ep-controls"><h4>배송 연결</h4>' + s.sinks.map((source, i) => form('sink', select('source', '고객 ' + (i === 0 ? 'A' : 'B'), sourceOptions(5), source), '연결', { customer: i })).join('') + '</div><details open><summary>입고 원본 · 8개</summary>' + table(Object.values(names), s.input.map(r => [r.id, r.name, r.color === 'red' ? '빨강' : '파랑', r.size, r.private])) + '</details></div></div>' + (s.outputs.length ? '<h3>직전 자동 배송</h3>' + table(['고객', '배송 번호', '반송', '누락'], s.outputs.map(p => [p.customer, p.accepted.join(', ') || '없음', p.wrong, p.missing])) : '');
    }
    function maze(s) {
        const next = s.container === 'queue' ? 0 : s.frontier.length - 1;
        return metrics({ '탐색한 칸': s.visited.length, '대기': s.frontier.length + ' / ' + s.limit, '최대 메모리': s.peak }) + '<div class="ep-grid-layout"><div><div class="ep-maze">' + s.map.flatMap((line, r) => [...line].map((v, c) => {
            const id = r * 7 + c;
            return '<span class="' + (v === '#' ? 'wall' : s.path.includes(id) ? 'route' : s.frontier.includes(id) ? 'waiting' : s.visited.includes(id) ? 'seen' : '') + '" title="' + id + '번 칸">' + (v === '#' ? '' : v === 'S' ? '출발' : v === 'G' ? '출구' : id) + '</span>';
        })).join('') + '</div><div class="ep-legend"><span style="--swatch:#e5f1e5">탐색 완료</span><span style="--swatch:#f9d966">대기 중</span><span style="--swatch:#21896c">복원 경로</span></div></div><div class="ep-workspace">' + form('configure', '<div class="ep-actions">' + select('container', '대기 목록', { queue: '큐 · 먼저 넣은 칸부터', stack: '스택 · 나중에 넣은 칸부터' }, s.container) + select('order', '이웃을 넣는 순서', { ESWN: '동 → 남 → 서 → 북', SENW: '남 → 동 → 북 → 서', WNES: '서 → 북 → 동 → 남', NWSE: '북 → 서 → 남 → 동' }, s.order) + '</div>', '탐색 설정 적용') + '<h3>대기 목록 · 다음 ' + (s.frontier[next] ?? '-') + '번</h3><div class="ep-frontier ' + s.container + '">' + s.frontier.map((n, i) => '<span class="' + (i === next ? 'next' : '') + '">' + n + '</span>').join('') + '</div><div class="ep-actions">' + button('3칸 탐색', { type: 'run', steps: 3 }, true) + button('출구까지 자동 탐색', { type: 'run', steps: 49 }) + '</div></div></div>';
    }
    function classifier(s) {
        const g = [...s.gates, [6, 0, 10, 4]].slice(0, 2);
        const controls = select('count', '게이트 개수', { 1: '1개', 2: '2개' }, s.gates.length) + g.map((r, i) => '<section class="ep-gate-row"><h4>게이트 ' + (i + 1) + '</h4>' + number('x0-' + i, '가로 최소', r[0], 0, 9) + number('x1-' + i, '가로 최대', r[2], 1, 10) + number('y0-' + i, '세로 최소', r[1], 0, 9) + number('y1-' + i, '세로 최대', r[3], 1, 10) + '</section>').join('');
        return metrics({ '유입': s.turn + ' / 4', '남은 개별 검사': s.inspection }) + '<div class="ep-grid-layout"><div data-gate-plot>' + plots(s.training, s.gates, s.incoming.filter(p => !s.inspectIds.includes(p.id)).concat(s.revealed)) + '<div class="ep-legend"><span style="--swatch:#258871">먹을 수 있음</span><span style="--swatch:#c64f60">상함</span><span style="--swatch:#354d58">유입 중 · 미확인</span></div></div><div class="ep-workspace"><h3>배송 게이트</h3>' + form('gates', controls, '게이트 설치') + '<div class="ep-actions">' + button('이번 유입 자동 분류', { type: 'run' }, true) + '</div><details><summary>유입 열매 개별 검사</summary><div class="ep-actions">' + s.incoming.map(p => button((p.id + 1) + '번 검사', { type: 'inspect', id: p.id }, false, s.inspection === 0 || s.inspectIds.includes(p.id))).join('') + '</div></details></div></div>' + (s.history.length ? '<h3>직전 분류 결과</h3>' + table(['번호', '실제 상태', '통로', '결과'], s.history.map(p => [p.id + 1, p.good ? '먹을 수 있음' : '상함', p.accept ? '배송' : '반송', p.correct ? '성공' : p.accept ? '상한 열매 배송' : '먹을 열매 놓침'])) : '');
    }
    const specimen = (c) => '<svg viewBox="0 0 100 80" role="img" aria-label="재료 특성 가로 ' + c.x + ', 세로 ' + c.y + '"><path d="M10 70L50 8L90 70Z" fill="#e7edef" stroke="#a9b8b9"/><rect x="' + (50 - c.x * 3) + '" y="' + (66 - c.y * 6) + '" width="' + c.x * 6 + '" height="' + c.y * 6 + '" rx="2" fill="' + (c.label ? '#279279' : '#ca606b') + '" opacity=".85"/><text x="50" y="77" text-anchor="middle" font-size="11">' + c.x + ', ' + c.y + '</text></svg>';
    function deck(s, local) {
        const selected = local.deck ?? s.deck;
        const dirty = JSON.stringify([...selected].sort()) !== JSON.stringify([...s.deck].sort());
        const body = '<div class="ep-samples">' + s.cards.map(c => '<article class="ep-sample ' + (selected.includes(c.id) ? 'selected' : '') + '">' + pickButton((c.id + 1) + '번 표본', 'deck', c.id, selected.includes(c.id)) + specimen(c) + '<p>라벨: ' + (c.label ? '재활용 가능' : '재활용 불가') + '</p>' + button(s.audits.includes(c.id) ? '검수 완료' : '원본 검사', { type: 'audit', card: c.id }, false, s.trials >= 3 || s.audits.includes(c.id) || s.audits.length >= 3) + '</article>').join('') + '</div>';
        return metrics({ '선택 표본': selected.length + ' / 4', '남은 훈련': 3 - s.trials, '남은 검수': 3 - s.audits.length }) + '<div class="ep-robot"><svg viewBox="0 0 110 95" role="img" aria-label="표본을 학습하는 분류 로봇"><path d="M55 8V20" stroke="#456466" stroke-width="4"/><circle cx="55" cy="6" r="5" fill="#e4b336"/><rect x="24" y="22" width="62" height="48" rx="7" fill="#388b7c"/><rect x="31" y="32" width="48" height="22" rx="3" fill="#e4f6f0"/><circle cx="42" cy="43" r="5" fill="#28444b"/><circle cx="68" cy="43" r="5" fill="#28444b"/><path d="M35 70V87M75 70V87M24 38H10V60M86 38H100V60" stroke="#456466" stroke-width="9"/></svg><div><strong>' + (s.trained ? '학습된 로봇' : '아직 학습하지 않은 로봇') + '</strong><p>재료 특성: 가로 / 세로 측정값<br>' + h(s.engine) + '</p></div></div>' + body + '<div class="ep-actions">' + button('선택한 4개로 덱 확정', { type: 'deck', cards: selected }, true, selected.length !== 4 || s.trials >= 3) + button('덱으로 훈련 · 실험', { type: 'train' }, false, dirty || s.trials >= 3) + button('새 재료로 최종 작업', { type: 'deploy' }, false, dirty || !s.trained) + '</div>' + (s.results.length ? '<div class="ep-grid-layout"><div><h3>' + (s.phase === 'final' ? '새 재료 최종 결과' : '실험 재료 결과') + '</h3>' + plots(s.results, [], [], true) + '</div><div><h3>재료별 예측</h3>' + table(['특성', '실제', '로봇 예측', '결과'], s.results.map(p => [p.x + ', ' + p.y, p.good ? '가능' : '불가', p.prediction ? '가능' : '불가', p.correct ? '성공' : '오류'])) + '</div></div>' : '');
    }
    const renderers = { 'outlier-detection': outlier, 'missing-values': missing, 'data-visualization': harbor, 'data-extraction': factory, 'data-structure-lab': maze, 'ai-classifier-lab': classifier, 'ai-model-lab': deck };
    function render(game, s, local) {
        const done = s.status !== 'playing';
        return '<p class="ep-objective">' + h(s.objective) + '</p>' + (done ? '<section class="ep-result ' + s.status + '" data-result tabindex="-1"><h3>' + (s.status === 'won' ? '목표 달성' : '도전 종료') + '</h3><strong>' + s.score + '점</strong><p>' + h(s.message) + '</p></section>' : '') + '<fieldset class="ep-stage" style="border:0;padding:0;margin:0;min-width:0"' + (done ? ' disabled' : '') + '>' + renderers[game](s, local) + '</fieldset>' + metrics(s.metrics) + (s.log.length ? '<details' + (done ? ' open' : '') + '><summary>진행 일지</summary><ol class="ep-history">' + s.log.map(x => '<li>' + h(x) + '</li>').join('') + '</ol></details>' : '');
    }
    function pick(button, local) {
        const type = button.dataset.pick;
        if (type === 'zone') local.zone = button.dataset.id;
        else if (type === 'deck') {
            const root = button.closest('[data-play]');
            const s = root.__Play.state || root.__Play.preview;
            if (s.trials >= 3) return;
            if (!local.deck) local.deck = [...s.deck];
            const id = Number(button.dataset.id);
            if (local.deck.includes(id)) local.deck = local.deck.filter(x => x !== id);
            else if (local.deck.length < 4) local.deck.push(id);
        } else local[type] = Number(button.dataset.id);
    }
    function submit(f) {
        const data = new FormData(f), type = f.dataset.playForm, extra = JSON.parse(f.dataset.extra);
        const n = key => Number(data.get(key)), str = key => String(data.get(key));
        if (type === 'estimate') return { type, ...extra, low: n('low'), high: n('high') };
        if (type === 'assign') return { type, ...extra, port: n('port'), cargo: n('cargo') };
        if (type === 'sink') return { type, ...extra, source: n('source') };
        if (type === 'node') return { type, ...extra, source: n('source'), kind: str('kind'), rule: str('rule'), fields: data.getAll('fields') };
        if (type === 'configure') return { type, container: str('container'), order: str('order') };
        if (type === 'gates') return { type, gates: Array.from({ length: n('count') }, (_, i) => [n('x0-' + i), n('y0-' + i), n('x1-' + i), n('y1-' + i)]) };
        if (type === 'charts') return { type, charts: [0, 1].filter(i => str('group' + i) !== 'off').map(i => ({ group: str('group' + i), kind: str('kind' + i) })) };
        throw Error('입력할 행동을 확인하세요.');
    }
    function mount(game, root, s, local) {
        const charts = [], colors = ['#147d68', '#c04d5c', '#397fba', '#b67e21', '#765da4', '#49664c'];
        if (game === 'data-extraction') {
            const field = root.querySelector('[name=kind]');
            const show = () => {
                root.querySelector('[data-node-filter]').hidden = field.value !== 'filter';
                root.querySelector('[data-node-project]').hidden = field.value !== 'project';
            };
            field.addEventListener('change', show); show();
        }
        const addChart = (canvas, type, labels, datasets, yTitle) => {
            if (!window.Chart || !canvas) return;
            charts.push(new Chart(canvas, { type, data: { labels, datasets }, options: { responsive: true, maintainAspectRatio: false, animation: { duration: 200 }, scales: { y: { beginAtZero: true, title: { display: true, text: yTitle } } }, plugins: { legend: { position: 'bottom' } } } }));
        };
        if (game === 'outlier-detection') {
            const zone = local.zone ?? 'all';
            addChart(root.querySelector('[data-chart=observations]'), 'line', Array.from({ length: Math.min(6, s.turn + 1) }, (_, i) => (i + 1) + '회'),
                s.stations.filter(p => zone === 'all' || p.zone === zone).map((p, i) => ({ label: p.name, data: [...p.history, ...(s.turn < 6 ? [p.reading] : [])], borderColor: colors[i], backgroundColor: colors[i], tension: 0, pointRadius: 4 })), '장치 보고값');
        }
        if (game === 'data-visualization') s.plots.forEach((p, i) => {
            addChart(root.querySelector('[data-chart="port-' + i + '"]'), p.kind, p.labels, p.series.map((series, j) => ({ label: series.name, data: series.values, backgroundColor: colors[j], borderColor: colors[j], tension: 0 })), '주문량 (상자)');
        });
        if (game === 'ai-classifier-lab') {
            const f = root.querySelector('[data-play-form=gates]');
            f?.addEventListener('input', () => {
                const move = submit(f);
                root.querySelector('[data-gate-plot]').innerHTML = plots(s.training, move.gates.filter(g => g.every(Number.isFinite) && g[0] < g[2] && g[1] < g[3]), s.incoming);
            });
        }
        return charts;
    }
    window.EduPlayViews = { h, render, pick, form: submit, mount };
})();

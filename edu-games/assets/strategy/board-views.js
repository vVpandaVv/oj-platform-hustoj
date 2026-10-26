(() => {
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const button = (label, move, disabled = false) => `<button type="button" data-move="${esc(JSON.stringify(move))}" ${disabled ? 'disabled' : ''}>${esc(label)}</button>`;
    const options = (name, values, selected, numeric = false) => `<select name="${name}" ${numeric ? 'data-number' : ''}>${Object.entries(values).map(([v, label]) => `<option value="${esc(v)}" ${String(v) === String(selected) ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select>`;
    const form = (type, params, fields, label) => `<form class="es-controls" data-type="${type}" data-params="${esc(JSON.stringify(params))}">${fields}<button type="submit">${esc(label)}</button></form>`;
    const number = (name, value) => `<input name="${name}" type="number" data-number min="0" max="100" step="1" value="${value}">`;
    const stars = count => '★'.repeat(count) + '☆'.repeat(3 - count);
    const table = rows => {
        if (!rows.length) return '<p class="es-muted">0건</p>';
        const keys = Object.keys(rows[0]), labels = { id: 'ID', name: '이름', group: '분류', value: '값', finding: '판정', evidence: '확인 근거' };
        return `<div class="es-table-scroll"><table><thead><tr>${keys.map(k => `<th>${esc(labels[k] || k)}</th>`).join('')}</tr></thead><tbody>${rows.map(r => `<tr>${keys.map(k => `<td>${r[k] === null ? '<span class="es-missing">결측</span>' : esc(r[k])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    };
    const matrix = m => `<table class="es-matrix"><caption>실제 행 / 예측 열</caption><thead><tr><th></th><th>A 예측</th><th>B 예측</th></tr></thead><tbody><tr><th>실제 A</th><td>${m[0][0]}</td><td>${m[0][1]}</td></tr><tr><th>실제 B</th><td>${m[1][0]}</td><td>${m[1][1]}</td></tr></tbody></table>`;
    const modelNames = { tree: '결정트리', knn: '최근접 이웃', linear: '로지스틱 회귀' };
    const recognition = metrics => `<div class="es-recognition">${metrics.matrix.map((row, i) => `<div><strong>${i === 0 ? 'A' : 'B'} ${row[i]} / ${row[0] + row[1]}개 발견</strong><progress max="100" value="${metrics.recall[i]}" aria-label="${i === 0 ? 'A' : 'B'} 재현율 ${metrics.recall[i]}%"></progress><span>놓친 ${i === 0 ? 'A' : 'B'} ${row[1 - i]}개</span></div>`).join('')}</div>`;
    const renderers = {};
    renderers['data-extraction'] = s => `<div class="es-columns"><section class="es-section"><h3>입고 자료</h3>${table(s.source)}</section><section class="es-section"><h3>출고 주문</h3><div class="es-orders">${s.orders.map((o, i) => `<div class="es-order ${s.delivered.includes(i) ? 'is-done' : ''}">${s.delivered.includes(i) ? '완료' : `주문 ${i + 1}`} · ${esc(o)}</div>`).join('')}</div></section></div><div class="es-section es-slots">${s.slots.map((box, i) => {
        let tools = `<div class="es-controls">${!box ? button('원자료 입고 · 작업권 1', { type: 'load', slot: i }) : button('상자 비우기 · 1', { type: 'clear', slot: i })}</div>`;
        if (box) {
            let advanced = button('이름·값만 남기기 · 1', { type: 'project', slot: i }, box.shape !== 'full');
            advanced += form('copy', { slot: i }, `<label>복사 목적지${options('to', Object.fromEntries(s.slots.map((_, n) => [n, `상자 ${n + 1}`])), (i + 1) % 4, true)}</label>`, '복사 · 1');
            advanced += form('sort', { slot: i }, `<label>정렬 열${options('by', box.shape === 'group' ? { group: '분류', value: '집계 값' } : { value: '값' }, box.shape === 'group' ? 'group' : 'value')}</label><label>방향${options('direction', { asc: '작은 값부터', desc: '큰 값부터' }, 'asc')}</label><label>같은 값이면${options('tie', box.shape === 'full' ? { name: '이름', id: 'ID' } : { name: box.shape === 'group' ? '분류 이름' : '이름' }, 'name')}</label>`, '순서 바꾸기 · 1');
            if (box.shape === 'full') {
                tools += form('filter', { slot: i, field: 'group' }, `<label>분류 조건${options('op', { eq: '같음', ne: '제외' }, 'eq')}</label><label>분류${options('value', Object.fromEntries(s.groups.map(g => [g, g])), s.groups[0])}</label>`, '필터 배치 · 1');
                tools += form('filter', { slot: i, field: 'value' }, `<label>값 조건${options('op', { lte: '이하', gte: '이상', eq: '같음', range: '범위 포함', missing: '결측', present: '관측됨' }, 'lte')}</label><label data-filter-value>기준 값${number('value', 5)}</label><label data-filter-upper hidden>끝 값${number('upper', 10)}</label>`, '필터 배치 · 1');
                advanced += form('group', { slot: i }, `<label>분류별 계산${options('aggregate', { sum: '합계', mean: '평균', count: '자료 개수' }, 'sum')}</label><label>빈 값 처리${options('missing', { strict: '빈 값 있으면 중단', skip: '확인된 값만 계산' }, 'strict')}</label>`, '계산 · 2');
            }
            tools += `<details class="es-technical" data-detail="box-${i}"><summary>필요한 열 · 정렬 · 복사 · 계산</summary>${advanced}</details>`;
            tools += form('ship', { slot: i }, `<label>출고 주문${options('order', Object.fromEntries(s.orders.map((_, n) => [n, `주문 ${n + 1}`])), s.orders.findIndex((_, n) => !s.delivered.includes(n)), true)}</label>`, '출고 · 1');
        }
        return `<section class="es-slot"><h3>작업 상자 ${i + 1} · ${box ? `${box.rows.length}건` : '미입고'}</h3><div class="es-trail">${esc(box?.trail.join(' → ') || '처리 경로 없음')}</div>${box ? table(box.rows) : ''}${tools}</section>`;
    }).join('')}</div>`;
    function repairChoices(n) {
        const tools = [['measure', '새로 측정', 3, n.canMeasure], ['carry', `${n.values[0]} · 이전 값`, 1, n.values[0] !== null]];
        if (n.kind === 'number') {
            if (n.values[0] !== null && n.values[2] !== null) tools.push(['interpolate', `${(n.values[0] + n.values[2]) / 2} · 양끝의 중간값`, 1, true]);
            if (n.peers.length) {
                const sorted = [...n.peers].sort((a, b) => a - b), mid = Math.floor(sorted.length / 2);
                tools.push(['mean', `${Math.round(n.peers.reduce((a, b) => a + b, 0) / n.peers.length * 100) / 100} · 이웃 평균`, 1, true], ['median', `${sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2} · 이웃 중앙값`, 1, true]);
            }
        }
        if (n.kind === 'category') {
            const counts = new Map(); for (const value of (n.peers.length ? n.peers : n.values.filter(v => v !== null))) counts.set(value, (counts.get(value) || 0) + 1);
            const sorted = [...counts].sort((a, b) => b[1] - a[1]);
            if (sorted.length && (sorted.length === 1 || sorted[0][1] > sorted[1][1])) tools.push(['mode', `${sorted[0][0]} · 가장 잦은 상태`, 1, true]);
        }
        return tools.concat([['hold', '모르는 값으로 남기기', 1, true]]).filter(([, , , available]) => available);
    }
    renderers['missing-values'] = s => {
        const recent = s.trace.at(-1);
        const comparison = recent?.checks ? `<section class="es-day-result"><h3>${recent.day}일차 · 실제 관측과 대조</h3>${table(recent.checks.map(c => ({ '관측소': c.name, '내가 남긴 값': `${c.value ?? '미확정'}${c.estimated ? ' (추정/보류)' : ' (실측)'}`, '확인된 실제 값': c.actual ?? '확인 불가', '신뢰도 변화': c.loss ? `-${c.loss}` : '유지' })))}</section>` : '';
        return `<div class="es-hud"><strong>${Math.min(3, s.day + 1)} / 3일</strong><strong>관측망 신뢰도 ${s.integrity} / 10</strong>${s.status === 'playing' ? `<span>복구 ${Object.keys(s.repairs).length} / ${s.nodes.length}</span>` : ''}</div>${comparison}<div class="es-network">${(s.status === 'playing' ? s.nodes : []).map((n, i) => {
            const r = s.repairs[i];
            return `<section class="es-node"><h3>${esc(n.name)}</h3><p class="es-muted">${esc(n.unit)} · ${n.critical ? '필수 관측' : '보류 가능'}</p><div class="es-values"><span class="es-value">${esc(n.values[0] ?? '?')}<small>이전</small></span><span class="es-value is-gap">${esc(r ? r.value ?? '미확정' : '?')}<small>${r ? r.held ? '보류' : r.estimated ? '추정' : '실측' : '빈 관측값'}</small></span><span class="es-value">${esc(n.values[2] ?? '?')}<small>이후</small></span></div><p class="es-context">${esc(n.context)}</p>${n.peers.length ? `<p>이웃 관측: ${n.peers.map(esc).join(' · ')}</p>` : ''}<div class="es-repair-tools">${repairChoices(n).map(([tool, name, cost]) => button(`${name} · 전력 ${cost}`, { type: 'repair', node: i, tool }, r?.tool === tool)).join('')}</div></section>`;
        }).join('')}</div>${s.status === 'playing' ? `<div class="es-section">${button('관측값 전달 · 다음 날 · 1', { type: 'advance' }, Object.keys(s.repairs).length !== 3)}</div>` : ''}${s.trace.length ? `<details class="es-technical" data-detail="transmission"><summary>지난 관측 기록</summary>${table(s.trace.map(t => ({ '일': t.day, '전달된 값': t.values.map((v, i) => `${v ?? '미확정'}${t.estimated[i] ? '(추정/보류)' : '(실측)'}`).join(' / '), '신뢰도 손실': t.damage, '신뢰도': t.integrity })))}</details>` : ''}`;
    };
    const evidenceNames = { context: '현장 메모', unit: '원본 기록', range: '측정기 점검', repeat: '다시 측정' };
    const routeNames = { keep: '그대로 사용', flag: '확인 전 보류', quarantine: '오류로 제외' };
    renderers['outlier-detection'] = function outlierView(s, app) {
        const comparing = s.phase === 'compare', finished = s.status !== 'playing';
        const index = Math.max(0, Math.min(s.records.length - 1, app.recordIndex || 0)), r = s.records[index];
        const evidence = s.revealed[index] || {}, review = s.reviews[index];
        const low = Math.min(0, ...s.records.map(r => Math.min(r.value, r.reference[0]))), high = Math.max(1, ...s.records.map(r => Math.max(r.value, r.reference[1])));
        const span = high - low, zero = -low / span * 100;
        const stateNames = { unseen: '사용 대기', investigating: '조사 중', ready: '판단 대기', recheck: '다시 판단', settled: '판단 완료' };
        const readings = `<section class="es-readings"><h3>${comparing ? '평소와 다른 관측값' : '관측값과 첫 선택'}</h3><p class="es-reference-key"><span></span> 같은 조건의 평소 관측 범위</p><div class="es-reading-axis"><span>${low}</span><span>${Math.round((low + high) / 2 * 10) / 10}</span><span>${high} ${esc(r.unit)}</span></div>${s.records.map((row, i) => {
            const marked = s.marked.includes(i), selected = comparing ? marked : index === i;
            const status = comparing ? marked ? '조사 선택' : '미선택' : !s.revealed[i] && s.routes[i] === undefined ? '조사 대기' : stateNames[s.reviews[i].state];
            const action = comparing ? `data-mark="${i}" data-move="${esc(JSON.stringify({type:'mark',record:i}))}"` : `data-record="${i}"`;
            return `<button type="button" ${action} class="es-reading ${s.reviews[i].state === 'recheck' ? 'needs-review' : ''}" aria-pressed="${selected}" aria-label="기록 ${row.id}, ${row.value} ${esc(row.unit)}, 평소 ${row.reference[0]}에서 ${row.reference[1]}, ${esc(row.group)}, ${status}"><span class="es-reading-id">${row.id}</span><span class="es-reading-main"><span>${esc(row.group)} · 평소 ${row.reference[0]}~${row.reference[1]}</span><span class="es-reading-track" style="--zero:${zero}%"><em style="left:${(row.reference[0]-low)/span*100}%;width:${(row.reference[1]-row.reference[0])/span*100}%"></em><i style="left:${Math.min(zero,(row.value-low)/span*100)}%;width:${Math.abs(row.value)/span*100}%"></i></span></span><strong>${row.value}<small>${esc(row.unit)}</small></strong><span class="es-reading-state">${status}${!comparing && marked ? '<small>첫 선택</small>' : ''}</span></button>`;
        }).join('')}</section>`;
        if (comparing) return `<div class="es-case-progress"><strong>첫 발견 · 조사 대상 ${s.marked.length}개 선택</strong><span>잘못 고르거나 놓친 기록당 -100점</span></div><div class="es-compare-board">${readings}</div><div class="es-compare-submit">${button(s.marked.length ? '조사 대상 확정' : '튀는 값 없음으로 확정', {type:'investigate'})}</div>`;
        const pending = s.records.findIndex((_, i) => s.routes[i] === undefined || s.reviews[i].state === 'recheck');
        const tools = Object.entries(evidenceNames).map(([key, label]) => button(`${label} · 조사권 ${r.costs[key]}`, {type:'inspect',record:index,evidence:key}, !!evidence[key] || r.costs[key] > s.budget-s.spent || key === 'repeat' && !s.repeatRemaining)).join('');
        const facts = Object.entries(evidence).map(([key,text]) => `<article class="es-fact"><h4>${evidenceNames[key]}</h4><p>${esc(text)}</p></article>`).join('');
        const verdict = s.routes[index] ? `<div class="es-verdict ${review.state}" data-verdict><strong>${routeNames[s.routes[index]]}</strong><p>${esc(review.message)}</p></div>` : `<p class="es-case-status">${esc(review.message)}</p>`;
        const choices = Object.entries(routeNames).map(([route,label]) => `<button type="button" class="es-decision ${route}" data-move="${esc(JSON.stringify({type:'route',record:index,route}))}" aria-pressed="${s.routes[index] === route}" ${!review.supported || s.routes[index] === route ? 'disabled' : ''}><strong>${label}</strong><small>조사권 1</small></button>`).join('');
        const canFinish = Object.keys(s.routes).length === s.records.length;
        const desk = `<section class="es-investigation"><div class="es-investigation-heading"><h3 tabindex="-1" data-case-heading>기록 ${r.id} <strong>${r.value} ${esc(r.unit)}</strong></h3><button type="button" data-command="readings">전체 기록</button></div><p class="es-muted">${esc(r.group)} · 평소 ${r.reference[0]}~${r.reference[1]}${esc(r.unit)}</p>${!finished ? `<div class="es-evidence-tools">${tools}</div>` : ''}<div class="es-facts">${facts || '<div class="es-unopened">추가 조사 전<span>현장 · 원본 · 측정기 · 재측정</span></div>'}</div>${verdict}${!finished ? `<div class="es-decisions">${choices}</div><div class="es-case-next">${pending >= 0 && pending !== index ? `<button type="button" class="es-primary" data-record="${pending}" data-case-next>남은 조사 →</button>` : ''}${canFinish ? button('조사 결과 제출 · 조사권 1',{type:'finish'}) : ''}</div>` : ''}</section>`;
        const trays = `<div class="es-case-trays">${Object.entries(routeNames).map(([route,label]) => `<div class="es-tray ${route}"><strong>${label}</strong><span>${s.records.filter((_,i)=>s.routes[i]===route).map(row=>row.id).join(' · ') || '-'}</span></div>`).join('')}</div>`;
        const audit = s.audit.length ? `<section class="es-case-learning"><h3>첫 발견과 조사 결과</h3><p>평소와 다른 값 ${s.audit.filter(r=>r.unusual).length}개 · 확인된 오류 ${s.audit.filter(r=>r.finding==='확인된 오류').length}개. 이상치 후보와 오류는 같은 뜻이 아닙니다.</p>${s.audit.map(item=>`<article><strong>기록 ${item.id} · ${item.unusual ? '평소 범위 밖' : '평소 범위 안'} · ${item.selected ? '처음 선택함' : '처음 선택하지 않음'} · ${esc(item.finding)}</strong><p>${esc(item.evidence)}</p></article>`).join('')}</section>` : '';
        return `<div class="es-case-progress"><strong>원인 조사 · 재측정 ${s.repeatRemaining} / 2회 남음</strong><span>추가 조사권당 -20점 · 잘못된 판단당 -50점</span></div><div class="es-investigation-layout"><div>${readings}${trays}</div>${desk}</div>${audit}`;
    };

    function coordinateBoard(p) {
        let cells = '<span class="es-axis-corner">Y / X</span>' + Array.from({ length: 11 }, (_, x) => `<span class="es-axis">${x}</span>`).join('');
        for (let y = 10; y >= 0; y--) {
            cells += `<span class="es-axis">${y}</span>`;
            for (let x = 0; x <= 10; x++) {
                const ids = Object.entries(p.positions).filter(([, point]) => point.x === x && point.y === y).map(([id]) => p.rows[id].name);
                cells += `<button type="button" class="es-point-cell ${ids.length ? 'is-filled' : ''}" data-point-x="${x}" data-point-y="${y}" tabindex="${x === 0 && y === 0 ? 0 : -1}" title="X ${x}, Y ${y}${ids.length ? ': ' + esc(ids.join(', ')) : ''}" aria-label="X ${x}, Y ${y}${ids.length ? ': ' + esc(ids.join(', ')) : ''}">${ids.length > 1 ? ids.length : esc(ids[0] || '')}</button>`;
            }
        }
        return `<div class="es-coordinate-board" role="group" aria-label="좌표 배치판">${cells}</div>`;
    }
    renderers['data-visualization'] = (s, app) => {
        const p = s.plots[app.panel], params = app.panel ? { panel: app.panel } : {}, placed = p.kind === 'scatter' ? Object.keys(p.positions).map(Number) : p.slots.flat();
        const configure = (key, label, values, numeric = false) => form('configure', { key, ...params }, `<label>${label}${options('value', values, p[key], numeric)}</label>`, '적용 · 1');
        return `<section class="es-plot-heading"><h3>${app.panel + 1}. ${esc(p.title)}</h3><p>${esc(p.goal)}</p><strong>배치 ${placed.length} / ${p.rows.length}</strong></section><div class="es-controls">${configure('kind', '차트', { bar: '막대', line: '선', histogram: '히스토그램', pie: '원', doughnut: '도넛', scatter: '산점도' })}<details class="es-technical es-chart-settings" data-detail="chart-axes"><summary>축 · 구간 설정</summary><div class="es-controls">${configure('x', '가로 기준', { name: '범주 이름', time: '시간', range: '수치 구간', x: '특성 X', y: '특성 Y' })}${configure('y', '표현 값', { value: '측정값', count: '빈도', share: '전체 대비 %', x: '특성 X', y: '특성 Y' })}${configure('baseline', '세로축 시작', { 0: '0', 2: '2' }, true)}${p.kind === 'histogram' ? configure('binWidth', '구간 폭', { 2: '2', 4: '4' }, true) : ''}</div></details></div><div class="es-deck">${p.rows.map(r => `<button type="button" data-pick="${r.id}" class="${app.selected === r.id ? 'is-selected' : ''}" ${placed.includes(r.id) ? 'disabled' : ''}>${esc(r.name)}<strong>${p.purpose === 'relationship' ? `(${r.x}, ${r.y})` : `${r.value}`}</strong><small>${p.purpose === 'relationship' ? 'X, Y' : esc(p.unit)}</small></button>`).join('')}</div>${p.kind === 'scatter' ? coordinateBoard(p) : `<div class="es-chart-slots">${p.slots.map((rows, i) => `<button type="button" class="es-chart-slot" data-place="${i}"><strong>${p.kind === 'histogram' ? `${i * p.binWidth} ≤ 값 < ${(i + 1) * p.binWidth}` : `${i + 1}번 위치`}</strong>${rows.map(row => `<span>${esc(p.rows[row].name)}: ${p.rows[row].value}</span>`).join('') || '<span>빈 칸</span>'}</button>`).join('')}</div>`}<div class="es-controls">${placed.map(row => button(`${p.rows[row].name} 회수 · 1`, { type: 'remove', row, ...params })).join('')}</div><div class="es-chart"><canvas data-chart aria-label="현재 배치와 축으로 구성한 차트" role="img"></canvas></div>`;
    };
    function predicted(x, y, s) {
        if (s.method === 'knn') return s.surface[Math.floor(x) * 10 + Math.floor(y)] === 1;
        const checks = s.boundaries.filter(Boolean).map(b => b.op === 'gte' ? ({ x, y })[b.axis] >= b.cut : ({ x, y })[b.axis] < b.cut);
        return checks.length && (s.mode === 'all' ? checks.every(Boolean) : checks.some(Boolean));
    }
    function plane(s) {
        let svg = '';
        for (let x = 0; x < 10; x++) for (let y = 0; y < 10; y++) if (predicted(x + .5, y + .5, s)) svg += `<rect x="${40 + x * 36}" y="${40 + (9 - y) * 36}" width="36" height="36" fill="#d6eddf"/>`;
        for (let n = 0; n <= 10; n++) svg += `<path d="M${40 + n * 36} 40V400 M40 ${400 - n * 36}H400" stroke="#c3d2c8"/><text x="${40 + n * 36}" y="420" text-anchor="middle" font-size="12">${n}</text><text x="28" y="${404 - n * 36}" text-anchor="end" font-size="12">${n}</text>`;
        if (s.method === 'boundary') for (const b of s.boundaries.filter(Boolean)) svg += `<path d="${b.axis === 'x' ? `M${40 + b.cut * 36} 40V400` : `M40 ${400 - b.cut * 36}H400`}" stroke="#263d30" stroke-width="3" ${b.op === 'lt' ? 'stroke-dasharray="5 4"' : ''}/>`;
        for (const p of [...s.points, ...Object.values(s.surveys)]) {
            const x = 40 + p.x * 36, y = 400 - p.y * 36;
            svg += p.label === 1 ? `<circle cx="${x}" cy="${y}" r="5" fill="#267450" stroke="white"><title>A (${p.x}, ${p.y})</title></circle>` : `<rect x="${x - 5}" y="${y - 5}" width="10" height="10" fill="#c4563f" stroke="white"><title>B (${p.x}, ${p.y})</title></rect>`;
        }
        return svg + '<text x="430" y="420" font-size="14">X</text><text x="20" y="25" font-size="14">Y</text>';
    }
    renderers['ai-classifier-lab'] = s => `<details class="es-technical" data-detail="classifier-method"><summary>다른 분류 방식</summary><div class="es-method">${button('경계 조각 · 1', { type: 'method', method: 'boundary' }, s.method === 'boundary')}${button('최근접 이웃 · 1', { type: 'method', method: 'knn' }, s.method === 'knn')}</div></details><div class="es-columns"><div><svg class="es-plane" viewBox="0 0 440 440" role="img" aria-label="X Y 좌표, 초록 원 A와 주황 사각형 B, 초록 면은 A 예측">${plane(s)}</svg><p data-boundary-preview role="status"></p><p class="es-muted">가로 X · 세로 Y · 눈금 단위 1<br>초록 원 A / 주황 사각형 B · 채운 영역은 A 예측<br>경계 ≥는 포함, &lt;는 제외</p></div><section><h3>${s.method === 'knn' ? '최근접 이웃 분류' : '경계 조각'}</h3>${s.method === 'knn' ? form('neighbors', {}, `<label>이웃 수 k${options('k', { 1: '1개', 3: '3개', 5: '5개' }, s.k, true)}</label>`, '이웃 수 변경 · 1') : s.boundaries.map((b, i) => `${i ? `<details class="es-technical" data-detail="boundary-${i}"><summary>경계 ${i + 1} ${b ? '배치됨' : '추가'}</summary>` : ''}<div class="es-boundary"><strong>조각 ${i + 1} · ${b ? `${b.axis.toUpperCase()} ${b.op === 'gte' ? '≥' : '<'} ${b.cut}` : '미배치'}</strong>${form('boundary', { slot: i }, `<label>축${options('axis', { x: 'X', y: 'Y' }, b?.axis || 'x')}</label><label>A 방향${options('op', { gte: '이상 ≥', lt: '미만 <' }, b?.op || 'gte')}</label><label>눈금 <output>${b?.cut || 5}</output><input type="range" name="cut" data-number min="1" max="9" step="1" value="${b?.cut || 5}"></label>`, '경계 배치 · 2')}${button('경계 회수 · 1', { type: 'remove', slot: i }, !b)}</div>${i ? '</details>' : ''}`).join('') + '<details class="es-technical" data-detail="boundary-mode"><summary>경계 조합</summary>' + form('mode', {}, `<label>조각 조합${options('mode', { all: '모두 충족 AND', any: '하나 이상 OR' }, s.mode)}</label>`, '조합 변경 · 1') + '</details>'}<details class="es-technical" data-detail="classifier-survey"><summary>새 표본 조사</summary><div class="es-controls">${s.sites.map((p, region) => button(`표본 ${region + 1} (${p.x}, ${p.y}) · 2`, { type: 'survey', region }, !!s.surveys[region])).join('')}</div></details><p>훈련 정확도 ${s.training.accuracy}% · 검증권 ${3 - s.trials}회</p>${button('검증 실행 · 2', { type: 'validate' }, s.trials >= 3 || s.method === 'boundary' && !s.boundaries.some(Boolean))}${s.validation ? `<p>검증 정확도 ${s.validation.accuracy}% · ${s.validation.count}개</p>` : ''}</section></div>`;
    renderers['ai-model-lab'] = function renderModel(s) {
        const targets = s.targets || { macroF1: 85, recall: 75 };
        const progress = s.validation ? [
            ['A 찾기', s.validation.recall[0], targets.recall],
            ['B 찾기', s.validation.recall[1], targets.recall],
            ['두 종류 균형 점수', s.validation.macroF1, targets.macroF1],
        ] : [];
        const gate = `<section class="es-model-goal"><h3>배포 목표 ${progress.length ? `· ${progress.filter(([, value, target]) => value >= target).length} / 3 달성` : ''}</h3><p>A와 B 각각 ${targets.recall}% 이상 찾기 · 균형 점수 ${targets.macroF1} 이상</p>${progress.length ? `<ul>${progress.map(([label, value, target]) => `<li class="${value >= target ? 'is-done' : ''}"><strong>${label}</strong><span>${value} / 목표 ${target}</span><b>${value >= target ? '달성' : '부족'}</b></li>`).join('')}</ul>` : ''}<details class="es-technical" data-detail="model-targets"><summary>실험 조건 · 점수 기준</summary><p>${esc(s.context)}</p><p>각 종류를 찾은 비율은 재현율, 균형 점수는 매크로 F1입니다. 놓친 자료와 다른 종류를 잘못 고른 결과를 함께 평가합니다. 검증 자료와 배포 시 새로 확인하는 최종 자료 모두 같은 기준을 적용합니다.</p></details></section>`;
        const metrics = (m, detail) => m ? `<p>전체 ${m.count}개 중 ${m.matrix[0][0] + m.matrix[1][1]}개 맞힘</p>${recognition(m)}<details class="es-technical" data-detail="${detail}"><summary>세부 통계 · 예측 대조표</summary><p>정확도 ${m.accuracy}% · 매크로 F1 ${m.macroF1}<br>A 재현율 ${m.recall[0]}% / B 재현율 ${m.recall[1]}%</p>${matrix(m.matrix)}</details>` : '<p>아직 분류한 자료 없음</p>';
        return `${gate}<div class="es-lab-stage"><strong>수집 ${s.batches.filter(b => b.status > 0).length} / ${s.batches.length}</strong><strong>훈련 ${s.rounds} / 3</strong><strong>${s.validated ? '새 자료 검증 완료' : '새 자료 검증 전'}</strong></div>
        <div class="es-lab-flow">${s.batches.map(b => `<section class="es-slot ${b.status === 2 ? 'is-done' : ''}"><h3>${esc(b.name)}</h3><p>${['미수집', '수집 · 미검수', '검수 완료'][b.status]} · ${b.count}개</p><div class="es-samples"><span style="width:${100 * b.counts[0] / b.count}%"></span><span style="width:${100 * b.counts[1] / b.count}%"></span></div><p>현재 이름표 A ${b.counts[0]} / B ${b.counts[1]}</p><div class="es-controls">${button(`자료 수집 · ${b.cost}`, { type: 'collect', batch: b.id }, b.status !== 0)}${b.status === 1 ? button(`이름표 바로잡기 · ${b.reviewCost}`, { type: 'review', batch: b.id }) : ''}</div></section>`).join('')}</div>
        <section class="es-section" ${s.batches.some(b => b.status > 0) ? '' : 'hidden'}><h3>훈련 작업대</h3><div class="es-controls">${Object.entries({ tree: 5, knn: 6, linear: 8 }).map(([model, cost]) => button(`${({tree:'질문으로 나누기',knn:'가까운 사례 찾기',linear:'한 줄로 나누기'})[model]} · ${cost}`, { type: 'train', model }, s.rounds >= 3)).join('')}${button('처음 보는 자료로 시험 · 2', { type: 'validate' }, !s.trained || s.validated)}</div>${s.singleClass ? '<p class="es-message">배운 이름표가 한 종류뿐입니다. 지금은 모든 자료를 그 종류로만 분류합니다.</p>' : ''}
        <div class="es-metrics"><section><h4>이미 배운 자료</h4>${metrics(s.training, 'training-matrix')}</section><section><h4>처음 보는 자료</h4>${metrics(s.validation, 'validation-matrix')}</section></div>
        ${s.log.length ? `<details class="es-technical" data-detail="model-log"><summary>지난 훈련 비교</summary>${table(s.log.map(l => ({ '훈련 차수': l.round, '모델': modelNames[l.model], '훈련 정확도': l.training, '검증 F1': l.validation ?? '-' })))}</details>` : ''}</section>`;
    };

    function chart(canvas, p) {
        if (!canvas || !window.Chart) return null;
        const colors = ['#2b7c59', '#c36a47', '#477caa', '#a88b27', '#965784', '#528f90'];
        const circular = ['pie', 'doughnut'].includes(p.kind), scatter = p.kind === 'scatter', timed = p.kind === 'line' && p.x === 'time';
        const total = p.rows.reduce((n, r) => n + r.value, 0), share = p.y === 'share';
        let labels = p.slots.map((rows, i) => p.kind === 'histogram' ? `${i * p.binWidth}~${(i + 1) * p.binWidth} 미만` : rows.length ? p.rows[rows[0]].name : `빈 칸 ${i + 1}`);
        let values = p.slots.map(rows => p.kind === 'histogram' ? rows.length : rows.length ? share ? 100 * p.rows[rows[0]].value / total : p.y === 'count' ? rows.length : p.rows[rows[0]].value : null);
        if (scatter) values = Object.entries(p.positions).map(([id, point]) => ({ x: point.x, y: point.y, label: p.rows[id].name }));
        if (timed) values = p.slots.map((rows, i) => rows.length ? { x: p.rows[rows[0]].time, y: values[i] } : null).filter(Boolean);
        if (circular) {
            const missing = (share ? 100 : total) - values.reduce((a, b) => a + (b || 0), 0);
            if (missing > .001) { labels.push('미배치'); values.push(missing); }
        }
        const yTitle = { count: '빈도', share: '비중 (%)', value: p.unit, x: 'X', y: 'Y' }[p.y];
        const axes = circular ? {} : { x: { ...(scatter ? { type: 'linear', min: 0, max: 10 } : timed ? { type: 'linear' } : {}), title: { display: true, text: { name: '범주', time: '관측 시점', range: '수치 구간', x: 'X', y: 'Y' }[p.x] } }, y: { min: p.baseline, ...(scatter ? { max: 10 } : share ? { max: 100 } : {}), title: { display: true, text: yTitle }, ticks: { precision: 0 } } };
        return new Chart(canvas, { type: p.kind === 'histogram' ? 'bar' : p.kind, data: { labels, datasets: [{ label: yTitle, data: values, backgroundColor: circular ? values.map((_, i) => labels[i] === '미배치' ? '#d9dfda' : colors[i % colors.length]) : '#629e78', borderColor: circular ? '#ffffff' : '#286649', borderWidth: 2, pointRadius: scatter ? 6 : 4 }] }, options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: circular, position: 'bottom' } }, scales: axes } });
    }
    const previewBoundary = (s, slot, boundary) => {
        const boundaries = [...s.boundaries]; boundaries[slot] = boundary;
        return plane({ ...s, boundaries });
    };
    window.EduStrategyUI = { esc, button, table, stars, render: (game, s, app) => renderers[game](s, app), chart, previewBoundary };
})();

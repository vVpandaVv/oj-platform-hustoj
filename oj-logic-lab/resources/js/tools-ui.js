import { TYPES, spec, pinWidth, nodeWidth, parseSignal, formatSignal, validateProject, emptyProject, makeNode } from './model.js?v=1.0.2';
import { diagnose, truthTable, tableCSV } from './analysis.js?v=1.0.2';
import { EXERCISES, exerciseProject, gradeExercise } from './exercises.js?v=1.0.2';
import { makeComponent, componentNode } from './compile.js?v=1.0.2';
import { schematicSVG } from './schematic.js?v=1.0.2';
import { modeOf } from './modes.js?v=1.0.2';
import { waveRange } from './waveform.js?v=1.0.2';
const esc = v => String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export class LabTools {
    constructor(app) {
        this.app = app; this.root = app.root; this.issues = []; this.library = []; this.cursors = [null, null]; this.cursorNext = 0;
        try { const raw = localStorage.getItem(`${app.storageKey}:components`); if (raw) this.library = validateProject({ ...emptyProject(), components: JSON.parse(raw) }).components; } catch { app.message = '부품함을 복원하지 못했습니다. 저장된 회로 JSON에서 부품을 불러올 수 있습니다.'; }
        try { const raw = localStorage.getItem(`${app.storageKey}:practice-return`); this.practiceReturn = raw ? validateProject(JSON.parse(raw)) : null; } catch { this.practiceReturn = null; }
        this.root.querySelector('.ll-modebar').insertAdjacentHTML('afterend', `<nav class="ll-toolsbar" aria-label="회로 분석과 실습 도구"><button data-tool="table" data-beginner-only>진리표 · 상태표</button><button data-tool="issues">회로 검사 <span data-issue-count>0</span></button><button data-tool="practice" data-beginner-only>실습 과제 <span data-task-status></span></button><button data-tool="component" data-advanced-only>현재 회로를 부품으로</button><button data-tool="library" data-advanced-only>내 부품함</button><button data-tool="scenario" data-advanced-only>입력 시나리오</button><button data-tool="cursors" data-advanced-only>파형 측정</button><button data-tool="svg">회로도 SVG</button></nav>`);
        this.root.querySelector('.ll-inspector').insertAdjacentHTML('afterbegin', '<section class="ll-issue-summary" data-issue-summary hidden></section>');
        this.root.querySelector('[data-palette]').insertAdjacentHTML('afterend', '<div class="ll-component-palette" data-component-palette data-advanced-only></div>');
        this.$('.ll-library .ll-section-heading > span').textContent = Object.keys(TYPES).length - 1;
        this.root.insertAdjacentHTML('beforeend', '<dialog class="ll-dialog ll-tools-dialog" data-tools-dialog><div class="ll-section-heading"><h2 data-tools-title></h2><button data-tool="close" aria-label="분석 도구 닫기">닫기</button></div><p class="ll-tool-error" role="status" data-tools-error></p><div data-tools-content></div></dialog>');
        this.root.querySelector('[data-help] ol').insertAdjacentHTML('beforeend', '<li>초보 모드의 진리표·상태표와 정답 검사는 회로 사본에서 수행합니다. 고급 모드에는 내 부품·다중 비트 버스·입력 시나리오·파형 A/B 커서를 제공합니다. 회로도 SVG는 현재 배치와 연결로 자동 생성합니다.</li>');
        this.root.addEventListener('click', e => {
            const el = e.target.closest('[data-tool]'); if (!el) return;
            try { this.handle(el.dataset.tool, el); } catch (error) { this.error(error.message); }
        });
        this.root.querySelector('[data-wave]').addEventListener('click', e => {
            if (modeOf(app.project) !== 'advanced') return;
            const svg = e.target.closest('svg'); if (!svg) return;
            const rect = svg.getBoundingClientRect(), x = (e.clientX - rect.left) / rect.width * 760;
            if (x < 158) return;
            const { end, start } = waveRange(app.project, app.engine.now);
            this.cursors[this.cursorNext] = Math.round(start + Math.min(1, (x - 158) / 586) * (end - start)); this.cursorNext = 1 - this.cursorNext;
            app.renderWave(); app.say(this.measurement());
        });
    }
    $(selector) { return this.root.querySelector(selector); }
    error(message) { this.app.say(message, true); if (this.$('[data-tools-dialog]').open) this.$('[data-tools-error]').textContent = message; }
    open(title, html) { this.$('[data-tools-title]').textContent = title; this.$('[data-tools-content]').innerHTML = html; this.$('[data-tools-error]').textContent = ''; if (!this.$('[data-tools-dialog]').open) this.$('[data-tools-dialog]').showModal(); }
    refresh() {
        this.issues = diagnose(this.app.project, this.app.engine, this.app.route);
        if (this.focused && !this.issues.some(issue => issue.id === this.focused.id)) this.focused = null;
        this.$('[data-issue-count]').textContent = this.issues.length;
        this.$('[data-task-status]').textContent = this.app.project.exercise ? this.app.project.exercise.solved ? '✓' : '진행 중' : '';
        const summary = this.$('[data-issue-summary]'); summary.hidden = !this.issues.length;
        summary.innerHTML = `<strong>회로 확인 · ${this.issues.length}건</strong>${this.issues.slice(0, 2).map((issue, i) => `<button data-tool="focus" data-index="${i}">${esc(issue.message)}</button>`).join('')}<button data-tool="issues">수정 방법 보기</button>`;
        if (this.app.project.exercise) this.$('[data-task-status]').title = EXERCISES.find(e => e.id === this.app.project.exercise.id)?.goal || '';
        const defs = [...new Map([...this.library, ...(this.app.project.components || [])].map(d => [d.id, d])).values()], signature = defs.map(d => d.id).join('|');
        if (signature !== this.paletteSignature) { this.paletteSignature = signature; this.$('[data-component-palette]').innerHTML = defs.length ? `<h3>내 부품 · 드래그하여 추가</h3>${defs.map(d => `<button data-component="${d.id}">▣ ${esc(d.name)}</button>`).join('')}` : ''; }
    }
    measurement() { return this.cursors.every(v => v !== null) ? `A=${this.cursors[0]} ns · B=${this.cursors[1]} ns · Δt=${Math.abs(this.cursors[1] - this.cursors[0])} ns` : '파형을 차례로 클릭하여 A/B 커서를 놓으세요.'; }
    newComponentNode(id, x, y) {
        const d = [...(this.app.project.components || []), ...this.library].find(d => d.id === id); if (!d) throw new Error('부품 정의가 없습니다.');
        return componentNode(d, makeNode('CUSTOM', x, y));
    }
    withComponent(project, id) {
        const all = new Map([...this.library, ...(project.components || [])].map(d => [d.id, d])), defs = new Map((project.components || []).map(d => [d.id, d]));
        const include = id => { const d = all.get(id); if (!d) throw new Error('부품 의존성을 찾지 못했습니다.'); if (defs.has(id)) return; defs.set(id, d); for (const n of d.nodes) if (n.type === 'CUSTOM') include(n.config.componentId); };
        include(id); if (defs.size > 24) throw new Error('현재 회로의 부품 정의가 24종을 넘습니다.');
        return [...defs.values()];
    }
    handle(tool, el) {
        const a = this.app, p = a.project;
        if (tool === 'close') return this.$('[data-tools-dialog]').close();
        if (tool === 'svg') { a.download(schematicSVG(p, a.engine), 'logic-lab-schematic.svg', 'image/svg+xml;charset=utf-8'); a.say('현재 회로의 배치·핀·배선·신호값으로 SVG 회로도를 생성했습니다.'); return; }
        if (tool === 'table') {
            this.table = truthTable(p);
            const t = this.table, columns = [...t.inputs.map(n => n.label), ...t.states.map(n => `${n.label} 현재 Q`), ...t.states.map(n => `${n.label} 다음 Q`), ...t.outputs.map(n => n.label)];
            this.open(t.kind === 'state' ? '상태 전이표' : '진리표', `<p>지연과 시나리오를 제외한 이상적 논리입니다. 현재 회로와 실행 상태는 바꾸지 않습니다. ${t.kind === 'state' ? '초기 Q와 입력을 조합하고 공통 상승 클록 한 번 후의 값을 구합니다.' : ''}</p><button data-tool="table-csv">표 CSV 내보내기</button><div class="ll-table-scroll"><table><thead><tr>${columns.map(c => `<th>${esc(c)}</th>`).join('')}<th>적용</th></tr></thead><tbody>${t.rows.map((r, i) => `<tr>${[...t.inputs.map(n => r.values[n.id]), ...t.states.map(n => r.initial[n.id]), ...t.states.map(n => r.next[n.id]), ...r.outputs].map(v => `<td>${esc(formatSignal(v))}</td>`).join('')}<td><button data-tool="table-row" data-row="${i}">입력만 적용</button></td></tr>`).join('')}</tbody></table></div><p class="ll-muted">최대 8 입력·상태 비트, 256행까지 계산합니다. 현재 Q 열은 각 행에서 가정한 초기 상태입니다.</p>`); return;
        }
        if (tool === 'table-csv') return a.download(tableCSV(this.table), 'logic-lab-table.csv', 'text/csv;charset=utf-8');
        if (tool === 'table-row') { const row = this.table.rows[Number(el.dataset.row)]; a.mutate(() => { for (const n of p.nodes) if (Object.hasOwn(row.values, n.id)) n.config.value = row.values[n.id]; }); a.say('선택한 행의 입력만 적용했습니다. 저장된 초기 Q와 타이밍은 유지합니다.'); return; }
        if (tool === 'issues') { this.refresh(); this.open('회로 검사와 수정 안내', this.issues.length ? this.issues.map((issue, i) => `<article class="ll-issue-card"><strong>${esc(issue.message)}</strong><p>${esc(issue.fix)}</p><button data-tool="focus" data-index="${i}">해당 장치·핀 보기</button></article>`).join('') : '<p>연결 누락·버스 폭·조합 피드백·현재 타이밍 위반이 발견되지 않았습니다.</p>'); return; }
        if (tool === 'focus') { const issue = this.issues[Number(el.dataset.index)], n = p.nodes.find(n => n.id === issue?.node); if (!n) return; this.focused = issue; this.$('[data-tools-dialog]').close(); a.selection = { node: n.id }; a.zoom = Math.max(a.zoom, 0.8); a.view = { x: Math.max(0, n.x - 180), y: Math.max(0, n.y - 80) }; a.live(); a.say(`${issue.message} ${issue.fix}`, true); return; }
        if (tool === 'practice') return this.practice();
        if (tool === 'start-task') {
            if (!this.practiceReturn) { this.practiceReturn = structuredClone(p); try { localStorage.setItem(`${a.storageKey}:practice-return`, JSON.stringify(p)); } catch { a.say('원래 회로는 현재 창에서만 복원할 수 있습니다. 브라우저 저장 공간을 사용할 수 없습니다.', true); } }
            const next = exerciseProject(this.$('[data-task-choice]').value); next.settings = { ...p.settings }; next.components = structuredClone(p.components || []);
            this.taskId = null; a.replace(next); this.practice(); return;
        }
        if (tool === 'return-task') { if (!this.practiceReturn) return; a.replace(structuredClone(this.practiceReturn)); this.practiceReturn = null; try { localStorage.removeItem(`${a.storageKey}:practice-return`); } catch {} this.$('[data-tools-dialog]').close(); a.say('실습 과제를 열기 전 회로와 설정을 복원했습니다.'); return; }
        if (tool === 'hint') { this.hintCount = Math.min(3, (this.hintCount || 0) + 1); return this.practice(); }
        if (tool === 'grade') { this.grade = gradeExercise(p); a.mutate(() => { p.exercise.attempts++; p.exercise.solved = this.grade.passed; }, false); return this.practice(); }
        if (tool === 'component') {
            const ports = output => p.nodes.filter(n => (output ? ['LED', 'PROBE', 'BUS_OUT'] : ['SWITCH', 'BUS_IN', 'CLOCK']).includes(n.type)).map(n => `<label class="ll-port-choice"><input type="checkbox" data-component-port="${output ? 'out' : 'in'}" value="${n.id}" checked><span>${esc(n.label)} · ${pinWidth(n, output ? 'A' : 'Q', !output)}비트</span><input data-port-label="${n.id}" aria-label="${esc(n.label)} 포트 이름" maxlength="20" value="${esc(n.label)}"></label>`).join('');
            this.open('현재 회로를 재사용 부품으로 저장', `<p>현재 회로 전체를 복사합니다. 입력 스위치·버스 입력·클록을 외부 입력으로, LED·프로브·버스 출력을 외부 출력으로 지정하세요. 선택하지 않은 입력 장치는 내부 설정을 유지합니다.</p><label>부품 이름<input data-component-name maxlength="40" value="${esc(p.title)}"></label><h3>입력 포트</h3>${ports(false) || '<p>입력 장치가 없습니다. 상수 회로로 저장할 수 있습니다.</p>'}<h3>출력 포트</h3>${ports(true) || '<p>LED·프로브·버스 출력을 추가하세요.</p>'}<button data-tool="save-component">이 브라우저의 부품함에 저장</button>`); return;
        }
        if (tool === 'save-component') {
            const choose = output => [...this.root.querySelectorAll(`[data-component-port="${output ? 'out' : 'in'}"]:checked`)].map(input => ({ node: input.value, label: this.$(`[data-port-label="${input.value}"]`).value }));
            const d = makeComponent(p, this.$('[data-component-name]').value, { inputs: choose(false), outputs: choose(true) });
            const canonical = validateProject({ ...emptyProject(), components: [...(p.components || []), d] }).components;
            const lib = new Map([...this.library, ...canonical].map(d => [d.id, d])); if (lib.size > 24) throw new Error('부품함은 최대 24종입니다. JSON으로 보관하고 사용하지 않는 부품을 정리하세요.');
            localStorage.setItem(`${a.storageKey}:components`, JSON.stringify([...lib.values()])); this.library = [...lib.values()]; a.mutate(() => { p.components = canonical; }, false); this.open('부품 저장 완료', '<p>현재 회로와 설정은 유지했습니다. 내 부품함에서 추가하거나 캔버스로 드래그할 수 있습니다.</p><button data-tool="library">내 부품함 열기</button>'); return;
        }
        if (tool === 'library') {
            const defs = [...new Map([...this.library, ...(p.components || [])].map(d => [d.id, d])).values()];
            this.open('내 부품함', `<p>현재 브라우저·사이트·계정별 부품입니다. 회로 JSON에는 사용 중인 부품 정의가 함께 포함됩니다. 부품함에서 삭제해도 현재 회로에서 사용 중인 정의는 유지합니다.</p>${defs.map(d => `<article class="ll-issue-card"><strong>${esc(d.name)}</strong><p>입력 ${d.inputs.length}개 · 출력 ${d.outputs.length}개 · 내부 장치 ${d.nodes.length}개</p><button data-tool="add-component" data-component-id="${d.id}">캔버스에 추가</button>${this.library.some(o => o.id === d.id) ? `<button data-tool="remove-library" data-component-id="${d.id}">부품함에서 삭제</button>` : '<small>현재 회로에 포함된 정의</small>'}</article>`).join('') || '<p>저장한 부품이 없습니다. 회로를 만든 후 ‘현재 회로를 부품으로’를 누르세요.</p>'}`); return;
        }
        if (tool === 'remove-library') {
            const id = el.dataset.componentId;
            if (this.library.some(d => d.id !== id && d.nodes.some(n => n.type === 'CUSTOM' && n.config.componentId === id))) throw new Error('이 부품을 사용하는 상위 부품을 부품함에서 먼저 정리하세요.');
            const library = this.library.filter(d => d.id !== id); localStorage.setItem(`${a.storageKey}:components`, JSON.stringify(library)); this.library = library;
            const used = p.nodes.some(n => n.type === 'CUSTOM' && n.config.componentId === id) || (p.components || []).some(d => d.id !== id && d.nodes.some(n => n.type === 'CUSTOM' && n.config.componentId === id));
            if (!used) a.mutate(() => { p.components = (p.components || []).filter(d => d.id !== id); }, false);
            return this.handle('library', el);
        }
        if (tool === 'add-component') { this.$('[data-tools-dialog]').close(); a.addVisible('CUSTOM', el.dataset.componentId); return; }
        if (tool === 'scenario') return this.scenario();
        if (tool === 'add-stimulus') {
            const node = this.$('[data-stimulus-node]').value, n = p.nodes.find(n => n.id === node), time = Number(this.$('[data-stimulus-time]').value);
            if (!n) throw new Error('먼저 입력 스위치 또는 버스 입력을 추가하세요.');
            const event = { id: `s${crypto.randomUUID()}`, time, node, value: parseSignal(this.$('[data-stimulus-value]').value, pinWidth(n, 'Q', true)) };
            const next = validateProject({ ...p, stimuli: [...(p.stimuli || []), event] }); a.replace(next); return this.scenario();
        }
        if (tool === 'remove-stimulus') { a.mutate(() => { p.stimuli = (p.stimuli || []).filter(s => s.id !== el.dataset.id); }); return this.scenario(); }
        if (tool === 'run-scenario') { const until = Number(this.$('[data-scenario-until]').value); if (!Number.isInteger(until) || until < a.engine.now || until > 1000000) throw new Error('현재 시각 이상, 1,000,000 ns 이하의 종료 시각을 입력하세요.'); a.running = false; a.engine.advanceTo(until); this.$('[data-tools-dialog]').close(); a.live(); return; }
        if (tool === 'cursors') { this.open('파형 시간차 측정', `<p>파형을 두 번 클릭하여 A와 B를 놓거나 시각을 직접 입력하세요.</p><label>A 커서 (ns)<input type="number" min="0" max="1000000" data-cursor="0" value="${this.cursors[0] ?? 0}"></label><label>B 커서 (ns)<input type="number" min="0" max="1000000" data-cursor="1" value="${this.cursors[1] ?? a.engine.now}"></label><button data-tool="set-cursors">커서 적용</button><p>${esc(this.measurement())}</p><p class="ll-muted">최근 400 ns의 파형 범위 밖에 있는 커서는 화면에 그리지 않습니다. 시간차는 계속 표시합니다.</p>`); return; }
        if (tool === 'set-cursors') { const values = [...this.root.querySelectorAll('[data-cursor]')].map(e => Number(e.value)); if (values.some(v => !Number.isInteger(v) || v < 0 || v > 1000000)) throw new Error('커서 시각은 0~1,000,000 ns 정수로 입력하세요.'); this.cursors = values; this.$('[data-tools-dialog]').close(); a.renderWave(); a.say(this.measurement()); }
    }
    practice() {
        const p = this.app.project, task = EXERCISES.find(e => e.id === p.exercise?.id);
        if (this.taskId !== task?.id) { this.taskId = task?.id; this.hintCount = 0; this.grade = null; }
        this.open('단계별 실습 과제', `<label>과제 선택<select data-task-choice>${EXERCISES.map(t => `<option value="${t.id}" ${t.id === task?.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label><button data-tool="start-task">과제용 회로 열기</button>${this.practiceReturn ? '<button data-tool="return-task">원래 회로로 돌아가기</button>' : ''}<p class="ll-muted">현재 회로는 복원용 사본으로 보관하며 기존 저장 회로를 덮어쓰지 않습니다.</p>${task ? `<article class="ll-issue-card"><h3>${esc(task.name)}</h3><p>${esc(task.goal)}</p><p>정답 검사 ${p.exercise.attempts}회 · ${p.exercise.solved ? '통과 ✓' : '진행 중'}</p><button data-tool="grade">정답 검사</button><button data-tool="hint">힌트 ${Math.min(3, (this.hintCount || 0) + 1)} 보기</button>${task.hints.slice(0, this.hintCount || 0).map(h => `<p>💡 ${esc(h)}</p>`).join('')}${this.grade ? `<strong>${this.grade.passed ? `통과! ${this.grade.checks}개 조건을 모두 만족합니다.` : '아직 조건을 만족하지 않습니다.'}</strong>${this.grade.failures.map(f => `<p>${esc(f)}</p>`).join('')}` : ''}</article>` : '<p>1번부터 순서대로 도전하세요. 입력·출력 장치는 준비되어 있고 필요한 게이트와 배선은 직접 만듭니다.</p>'}`);
    }
    scenario() {
        const p = this.app.project, inputs = p.nodes.filter(n => ['SWITCH', 'BUS_IN'].includes(n.type));
        this.open('입력 시나리오', `<p>예약 입력은 고급 모드에서 실행합니다. 초기 입력값과 회로 설정은 유지하며, 같은 시각의 입력은 동시에 적용합니다.</p><div class="ll-scenario-form"><label>시각 (ns)<input type="number" min="0" max="1000000" data-stimulus-time value="20"></label><label>입력<select data-stimulus-node>${inputs.map(n => `<option value="${n.id}">${esc(n.label)} · ${pinWidth(n, 'Q', true)}비트</option>`).join('')}</select></label><label>값<input data-stimulus-value value="1" placeholder="0 / 1 / 0xA / 0101"></label><button data-tool="add-stimulus" ${inputs.length ? '' : 'disabled'}>변경 예약 추가</button></div><div class="ll-table-scroll"><table><thead><tr><th>시각</th><th>입력</th><th>값</th><th>삭제</th></tr></thead><tbody>${[...(p.stimuli || [])].sort((a, b) => a.time - b.time).map(s => `<tr><td>${s.time} ns</td><td>${esc(p.nodes.find(n => n.id === s.node)?.label)}</td><td>${esc(formatSignal(s.value))}</td><td><button data-tool="remove-stimulus" data-id="${s.id}">삭제</button></td></tr>`).join('')}</tbody></table></div><label>종료 시각 (ns)<input type="number" min="0" max="1000000" data-scenario-until value="${Math.max(this.app.engine.now + 100, ...((p.stimuli || []).map(s => s.time + 100)))}"></label><button data-tool="run-scenario">이 시각까지 실행</button><p class="ll-muted">시나리오를 편집하면 0 ns로 초기화합니다. 같은 입력의 같은 시각 중복 예약은 허용하지 않습니다. 예약은 회로 JSON에 포함됩니다.</p>`);
    }
    forget() { for (const suffix of [':components', ':practice-return']) localStorage.removeItem(this.app.storageKey + suffix); this.library = []; this.practiceReturn = null; }
}

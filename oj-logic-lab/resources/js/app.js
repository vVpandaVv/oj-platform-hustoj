import { TYPES, WORLD, GRID, makeNode, height, port, key, canPlace, validateProject, emptyProject, isSequential, isFF, spec, pinWidth, nodeWidth, WIDTH_TYPES, parseSignal, signal, formatSignal, signalClass } from './model.js?v=1.0.2';
import { createEngine, modeOf } from './modes.js?v=1.0.2';
import { routeCircuit } from './router.js?v=1.0.2';
import { EXAMPLES, example } from './examples.js?v=1.0.2';
import { LabTools } from './tools-ui.js?v=1.0.2';
import { previewMove } from './move.js?v=1.0.2';
import { previewWireMove } from './wire-move.js?v=1.0.2';
import { wireEditPoints, previewAddWaypoint, previewPointMove, previewDeletePoint } from './wire-points.js?v=1.0.2';
import { RecoveryStore, simulationSignature } from './project-state.js?v=1.0.2';
import { moveGroup, alignGroup, copyGroup, pasteGroup } from './editing.js?v=1.0.2';
import { waveRange, changeWave } from './waveform.js?v=1.0.2';
import { DragPlanner } from './drag-planner.js?v=1.0.2';
import { gateSymbol, sequentialSymbol } from './symbols.js?v=1.0.2';

const escape = value => String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const colors = ['#6879dc', '#cd8052', '#4b9d92', '#aa6bbb', '#4e98c3', '#bd9c47', '#cc6d86'];
const icon = (name, size = 18) => {
    const shapes = { play: '<path d="m8 5 11 7-11 7Z"/>', pause: '<path d="M8 5v14M16 5v14"/>', step: '<path d="m5 5 10 7-10 7ZM19 5v14"/>', reset: '<path d="M4 10a8 8 0 1 1 1 8M4 4v6h6"/>', plus: '<path d="M12 5v14M5 12h14"/>', minus: '<path d="M5 12h14"/>', save: '<path d="M5 3h12l4 4v14H3V3ZM7 3v7h10V3M7 21v-7h10v7"/>', folder: '<path d="M3 7V4h6l3 3h9v13H3Z"/>', download: '<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>', undo: '<path d="m8 4-5 5 5 5M3 9h10a7 7 0 0 1 0 14"/>', trash: '<path d="M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>', chip: '<rect x="5" y="5" width="14" height="14" rx="3"/><path d="M9 1v4M15 1v4M9 19v4M15 19v4M1 9h4M1 15h4M19 9h4M19 15h4M9 9h6v6H9Z"/>', fit: '<path d="M8 3H3v5M16 3h5v5M21 16v5h-5M8 21H3v-5"/>', help: '<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 5M12 17h.01"/>', chart: '<path d="M3 18h4V6h5v12h5V6h4"/>', cross: '<path d="m6 6 12 12M18 6 6 18"/>' };
    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${shapes[name] || shapes.chip}</svg>`;
};
class LogicLab {
    constructor(root) {
        this.root = root; this.storageKey = `oj-logic-lab:v1:${root.dataset.storageKey || 'local'}`;
        this.project = example('half'); this.selection = null; this.history = []; this.future = []; this.running = false;
        this.drag = null; this.connection = null; this.search = ''; this.zoom = 0.8; this.view = { x: 0, y: 0 }; this.message = '출력 핀에서 입력 핀으로 드래그하여 연결하세요.';
        this.route = routeCircuit(this.project); this.engine = createEngine(this.project); this.uid = `ll${Math.random().toString(36).slice(2, 10)}`;
        this.shell(); this.tools = new LabTools(this); let storage = null; try { storage = window.localStorage; } catch {} this.recovery = new RecoveryStore(storage, this.storageKey); this.pendingRecovery = this.recovery.draft(); if (!this.pendingRecovery && this.recovery.saved) { try { const saved = validateProject(JSON.parse(this.recovery.saved)), routing = routeCircuit(saved); if (!routing.failures.length) { this.project = saved; this.route = routing; this.engine = createEngine(saved); } } catch { this.message = '저장한 회로를 자동 복원하지 못했습니다. 원본 저장 데이터는 유지합니다.'; } } this.bind(); this.update(); this.renderRecovery(); requestAnimationFrame(() => this.fit());
        this.tick = setInterval(() => { if (!root.isConnected) { clearInterval(this.tick); this.eventsController.abort(); this.resize.disconnect(); this.recovery.flush(); this.dragPlanner?.close(); return; } if (this.running) { this.engine.step(this.project.settings.speed); if (this.engine.fault) this.running = false; this.live(); } }, 100);
        this.resize = new ResizeObserver(() => this.renderCanvas()); this.resize.observe(this.$('[data-board]'));
    }
    $(selector) { return this.root.querySelector(selector); }
    shell() {
        this.root.innerHTML = `
        <header class="ll-header"><div class="ll-brand"><span class="ll-brand-icon">${icon('chip', 24)}</span><div><strong>Logic<span>Lab</span></strong><small>논리회로 · 컴퓨터구조 실습</small></div></div>
        <div class="ll-project"><span class="ll-project-dot"></span><input aria-label="회로 이름" data-title maxlength="80"><span class="ll-local">브라우저 작업 공간</span></div>
        <div class="ll-file-actions"><button data-action="save" title="브라우저에 저장">${icon('save')}<span>저장</span></button><button data-action="export" title="회로 JSON 내보내기">${icon('download')}<span>내보내기</span></button><button data-action="import" title="회로 JSON 불러오기">${icon('folder')}<span>불러오기</span></button><button data-action="help" class="ll-icon-button" aria-label="사용 안내">${icon('help')}</button></div></header>
        <div class="ll-modebar"><div><strong data-mode-title>초보 모드</strong><span data-mode-description>입력을 바꾸면 출력이 바로 갱신됩니다.</span></div><div class="ll-mode-switch" role="group" aria-label="실습 모드"><button data-mode-choice="beginner" aria-pressed="true">초보 모드</button><button data-mode-choice="advanced" aria-pressed="false">고급 모드</button></div></div>
        <div class="ll-workspace">
        <aside class="ll-library"><div class="ll-section-heading"><h2>장치 라이브러리</h2><span>25</span></div><p class="ll-muted">장치를 캔버스로 드래그하세요</p><input class="ll-search" type="search" data-search placeholder="장치 검색…" aria-label="장치 검색"><div data-palette></div>
        <div class="ll-library-foot"><span class="ll-key">↗</span><span>핀 → 핀 드래그로 배선<br>스위치를 클릭하여 입력 전환</span></div></aside>
        <section class="ll-center" aria-label="회로 작업 공간"><div class="ll-toolbar"><div class="ll-toolbar-group"><button data-action="pulse" data-beginner-only class="ll-primary" title="모든 클록에 한 번의 상승 에지를 보냅니다">${icon('step')}<span>클록 한 번</span></button><span class="ll-auto-label" data-beginner-only>출력 자동 갱신</span><button data-action="run" data-advanced-only class="ll-primary">${icon('play')}<span>실행</span></button><button data-action="step" data-advanced-only title="설정한 시간만큼 진행">${icon('step')}<span data-step-label>10 ns</span></button><button data-action="next" data-advanced-only title="다음 이벤트 시각까지 진행">다음 이벤트</button><button data-action="reset" class="ll-icon-button" aria-label="시뮬레이션 초기화">${icon('reset')}</button></div><div class="ll-time"><span class="ll-time-dot"></span><span data-run-state>일시 정지</span><strong data-time>0 <small>ns</small></strong></div><div class="ll-toolbar-group"><button data-action="undo" class="ll-icon-button" aria-label="실행 취소">${icon('undo')}</button><button data-action="delete" class="ll-icon-button" aria-label="선택 삭제">${icon('trash')}</button><i></i><button data-action="zoomout" class="ll-icon-button" aria-label="축소">${icon('minus', 16)}</button><span data-zoom class="ll-zoom">80%</span><button data-action="zoomin" class="ll-icon-button" aria-label="확대">${icon('plus', 16)}</button><button data-action="fit" class="ll-icon-button" aria-label="회로 전체 보기">${icon('fit')}</button></div></div>
        <div class="ll-canvas-wrap" data-board><svg data-canvas role="application" aria-label="회로 편집기. 장치 선택 후 방향키로 이동, Delete로 삭제. 핀에서 Enter를 눌러 연결합니다. 배선 구간을 드래그하여 경로를 조정합니다." tabindex="0"></svg><div class="ll-canvas-label">CIRCUIT WORKSPACE <span>12 px 그리드</span></div><div class="ll-canvas-hint">배선 드래그: 경로 조정 <span>·</span> 빈 공간: 화면 이동 <span>·</span> 휠: 확대/축소</div><div class="ll-empty" data-empty hidden><strong>첫 회로를 만들어 보세요</strong><span>왼쪽의 장치를 드래그하거나 예제 회로를 불러오세요.</span></div></div>
        <div class="ll-canvas-status"><span data-count></span><div><span class="ll-legend ll-high">1 HIGH</span><span class="ll-legend ll-low">0 LOW</span><span class="ll-legend ll-unknown">X 미정</span><span class="ll-legend ll-z">Z 고임피던스</span></div></div>
        <section class="ll-basic-panel" data-beginner-only aria-label="입력과 출력"><div class="ll-wave-heading"><h2>입력을 바꾸고 결과를 확인하세요</h2><span class="ll-muted">0 = 꺼짐 · 1 = 켜짐</span></div><div class="ll-basic-io" data-basic-io></div></section>
        <section class="ll-wave-panel" data-advanced-only><div class="ll-wave-heading"><h2>${icon('chart')} 타이밍 다이어그램</h2><div><span data-wave-range></span><button data-action="csv">CSV 내보내기</button></div></div><div class="ll-wave-scroll" data-wave></div></section>
        <div class="ll-message" role="status" aria-live="polite" data-message></div></section>
        <aside class="ll-inspector"><section><div class="ll-section-heading"><h2>속성</h2><span class="ll-tag">INSPECTOR</span></div><div data-inspector></div></section>
        <section class="ll-beginner-guide" data-beginner-only><h2>세 단계로 시작하기</h2><ol><li>장치를 드래그하여 놓으세요.</li><li>출력 핀 → 입력 핀을 연결하세요.</li><li>입력의 0/1을 눌러 결과를 보세요.</li></ol><p class="ll-muted">플립플롭은 ‘클록 한 번’을 누르면 값을 기억합니다. 초보 모드는 지연 없이 계산하고 상승 에지를 사용합니다. 타이밍 실험은 고급 모드에서 할 수 있습니다.</p></section>
        <section class="ll-timing" data-advanced-only><div class="ll-section-heading"><h2>${icon('chart', 16)} 시뮬레이션 설정</h2></div><label>스텝 간격 <span><input data-setting="step" type="number" min="1" max="100000" value="10"> ns</span></label><label>재생 속도 <span><input data-setting="speed" type="number" min="1" max="10000" value="20"> ns / 0.1초</span></label><p class="ll-muted">시뮬레이션 시간은 ns 단위입니다. 재생 속도는 실제 클록 주파수와 별개입니다.</p><div data-warnings></div></section>
        <section class="ll-examples"><div class="ll-section-heading"><h2>예제 회로</h2><span class="ll-tag">LEARN</span></div>${EXAMPLES.map(([id, name, desc], i) => `<button data-example="${id}" ${['hazard', 'bus'].includes(id) ? 'data-advanced-only' : ''} title="${desc}"><span class="ll-example-number">0${i + 1}</span><span>${name}<small>${desc}</small></span><b>↗</b></button>`).join('')}<button data-action="new" class="ll-new">${icon('plus', 16)} 빈 회로 만들기</button><button data-action="restore">저장한 회로 복원</button></section></aside></div>
        <input type="file" accept=".json,application/json" data-file hidden><div class="ll-drag-ghost" data-ghost hidden></div>
        <dialog class="ll-dialog" data-help><div class="ll-section-heading"><h2>Logic Lab 사용 안내</h2><button data-action="closehelp" aria-label="닫기">${icon('cross')}</button></div><ol><li>장치를 라이브러리에서 캔버스로 드래그합니다. 버튼을 클릭하면 보이는 빈 곳에 추가됩니다.</li><li>오른쪽 출력 핀에서 왼쪽 입력 핀으로 드래그합니다. 핀 두 개를 차례로 클릭하거나 Enter로 선택해도 됩니다. Esc로 취소합니다.</li><li>같은 출력의 공통 배선은 공유하고 분기점에 점을 표시합니다. 다른 신호의 교차는 점프 곡선이며 전기적으로 연결되지 않습니다.</li><li>스위치의 숫자를 클릭하여 입력을 바꿉니다. 실행·스텝·다음 이벤트로 관찰합니다.</li><li>장치를 선택하면 지연, 초기 Q, 에지, setup/hold 또는 클록 설정을 편집합니다. 회로·타이밍 수정은 시뮬레이션을 0 ns로 되돌립니다.</li><li>미연결 입력은 X입니다. 플립플롭·래치의 미연결 RST는 비활성 0으로 처리합니다. RST는 활성 High입니다. 여러 출력을 한 입력에 연결할 수 없습니다.</li><li>게이트 지연은 관성 지연입니다. 지연보다 짧은 입력 펄스는 소거될 수 있습니다. setup/hold 위반은 Q=X로 나타내며 물리적인 메타안정성은 모델링하지 않습니다.</li><li>Delete: 선택 삭제 · Ctrl/Cmd+Z: 실행 취소 · Ctrl/Cmd+Shift+Z: 다시 실행 · 방향키: 선택 장치 이동 · Shift+클릭/빈 공간 드래그: 다중 선택 · Ctrl/Cmd+A/C/V: 전체 선택/복사/붙여넣기 · Space: 재생/정지</li><li>편집 후 0.5초에 자동 임시 저장하며 다음 접속에서 복구할 수 있습니다. 저장 버튼은 별도 확정 사본입니다. 배치·주석·파형 보기의 실행 취소는 현재 시간을 유지하고 회로·입력·타이밍 변경은 초기화합니다. 저장은 현재 브라우저·사이트·계정별 로컬 저장입니다. 공유하려면 JSON을 내보내세요. 서버에는 회로를 저장하지 않습니다.</li></ol><button data-action="forget">이 브라우저의 저장 회로 삭제</button></dialog>`;
        this.$('[data-help] ol').insertAdjacentHTML('afterbegin', '<li><strong>초보 모드:</strong> 입력 변경 시 지연 없이 출력을 계산하며 클록은 한 번씩 수동으로 진행합니다. <strong>고급 모드:</strong> 장치별 타이밍·파형·시간 진행을 설정합니다. 모드 전환은 실행 상태를 초기화하지만 회로와 고급 설정을 유지합니다.</li>');
        this.renderPalette();
        this.$('.ll-modebar').insertAdjacentHTML('beforebegin', '<div class="ll-recovery" data-recovery hidden role="status"><span>이전 작업의 임시 사본이 있습니다.</span><button data-action="recover">이전 작업 복구</button><button data-action="dismiss-recovery">사본 보관하고 닫기</button></div>');
        this.$('.ll-center').insertAdjacentHTML('afterbegin', '<nav class="ll-editbar" aria-label="회로 편집 도구"><button data-action="selectall">전체 선택</button><button data-action="copy">복사</button><button data-action="paste">붙여넣기</button><button data-action="alignleft">왼쪽 정렬</button><button data-action="aligntop">위쪽 정렬</button><button data-action="aligncenter">가로 중심 정렬</button><button data-action="addnote">주석 추가</button><button data-action="backups">최근 작업 복구</button><button data-action="redo">다시 실행</button></nav>');
        this.$('.ll-wave-panel .ll-wave-heading').insertAdjacentHTML('afterend', '<div class="ll-wave-controls"><button data-action="waveback" aria-label="파형 이전 구간">◀</button><button data-action="waveforward" aria-label="파형 다음 구간">▶</button><button data-action="wavein">파형 확대</button><button data-action="waveout">파형 축소</button><button data-action="wavefollow">최신 시각 따라가기</button><button data-action="watch">관찰 신호 선택</button><span>전이 기록은 신호별 최대 3,000개 · 전체 10만개</span></div>');
    }
    bind() {
        this.eventsController = new AbortController(); const signal = this.eventsController.signal;
        document.addEventListener('pointerdown', e => { this.active = this.root.contains(e.target); }, { signal });
        this.root.addEventListener('click', e => {
            const action = e.target.closest('[data-action]')?.dataset.action;
            if (action) this.action(action);
            const mode = e.target.closest('[data-mode-choice]'); if (mode) this.setMode(mode.dataset.modeChoice);
            const input = e.target.closest('[data-toggle-input]'); if (input) this.toggle(input.dataset.toggleInput);
            const ex = e.target.closest('[data-example]'); if (ex) { const p = example(ex.dataset.example); p.settings.mode = modeOf(this.project); this.replace(p); }
            const remove = e.target.closest('[data-remove-wire]'); if (remove) this.mutate(() => { this.project.wires = this.project.wires.filter(w => w.id !== remove.dataset.removeWire); });
            const lib = e.target.closest('[data-type]'); if (lib && !this.suppressClick) this.addVisible(lib.dataset.type);
            const component = e.target.closest('[data-component]'); if (component && !this.suppressClick) this.addVisible('CUSTOM', component.dataset.component);
        });
        this.$('[data-search]').addEventListener('input', e => { this.search = e.target.value; this.renderPalette(); });
        this.root.addEventListener('input', e => { if (e.target.matches('[data-note-text],[data-net-label]')) this.editPresentationText(e.target); });
        this.root.addEventListener('focusout', e => { if (e.target.matches('[data-note-text],[data-net-label]')) this.textEdit = null; });
        this.$('[data-title]').addEventListener('change', e => this.mutate(() => { this.project.title = e.target.value.trim() || '새 회로'; }, false));
        this.root.addEventListener('change', e => {
            if (e.target.dataset.setting) this.mutate(() => { this.project.settings[e.target.dataset.setting] = this.number(e.target); }, false);
            if (e.target.dataset.config) this.changeConfig(e.target);
            if (e.target.matches('[data-label]')) this.mutate(() => { this.selectedNode().label = e.target.value.slice(0, 40); }, false);
            if (e.target.matches('[data-net-label],[data-note-text]')) this.changed();
            if (e.target.dataset.busInput) {
                try { const n = this.project.nodes.find(n => n.id === e.target.dataset.busInput), value = parseSignal(e.target.value, pinWidth(n, 'Q', true)); this.checkpoint(); this.engine.setInput(n.id, value); this.live(); this.changed(); }
                catch (error) { this.say(error.message, true); }
            }
        });
        this.$('[data-file]').addEventListener('change', async e => {
            const file = e.target.files[0]; if (!file) return;
            try { if (file.size > 8 * 1024 * 1024) throw new Error('8 MB 이하 JSON 파일을 선택하세요.'); this.replace(validateProject(JSON.parse(await file.text()))); this.say('회로를 불러왔습니다.'); }
            catch (error) { this.say(`불러오기 실패: ${error.message}`, true); } e.target.value = '';
        });
        this.root.addEventListener('pointerdown', e => this.pointerDown(e));
        window.addEventListener('pointermove', e => this.pointerMove(e), { signal });
        window.addEventListener('pointerup', e => this.pointerUp(e), { signal });
        window.addEventListener('pointercancel', () => this.cancel(), { signal });
        this.$('[data-canvas]').addEventListener('wheel', e => { e.preventDefault(); this.setZoom(this.zoom * (e.deltaY < 0 ? 1.1 : 1 / 1.1), e); }, { passive: false });
        window.addEventListener('keydown', e => { if (this.root.contains(e.target) || this.active && e.target === document.body) this.keyboard(e); }, { signal });
        window.addEventListener('pagehide', () => this.recovery.flush(), { signal });
        document.addEventListener('visibilitychange', () => { if (document.hidden) this.recovery.flush(); }, { signal });
        window.addEventListener('beforeunload', e => { if (!this.recovery.flush() && this.recovery.dirty(this.project)) { e.preventDefault(); e.returnValue = ''; } }, { signal });
    }
    number(input) { const value = Number(input.value); return Math.max(Number(input.min), Math.min(Number(input.max), Math.round(Number.isFinite(value) ? value : Number(input.min)))); }
    selectedNode() { return this.project.nodes.find(n => n.id === this.selection?.node || this.selection?.nodes?.length === 1 && n.id === this.selection.nodes[0]); }
    editPresentationText(input) { const note = input.matches('[data-note-text]'), id = note ? this.selection?.note : this.selection?.net; if (!id) return; const edit = `${note ? 'note' : 'net'}:${id}`; if (this.textEdit !== edit) { this.checkpoint(); this.textEdit = edit; } if (note) this.project.notes.find(n => n.id === id).text = input.value.slice(0, 300); else { this.project.netNames ||= {}; this.project.netNames[id] = input.value.slice(0, 40); } this.renderCanvas(); this.changed(); }
    notePosition() {
        const left = Math.max(12, Math.ceil((this.view.x + 24) / GRID) * GRID), top = Math.max(12, Math.ceil((this.view.y + 24) / GRID) * GRID), right = Math.min(WORLD.width - 216, this.view.x + this.$('[data-board]').clientWidth / this.zoom - 216), bottom = Math.min(WORLD.height - 180, this.view.y + this.$('[data-board]').clientHeight / this.zoom - 72);
        const occupied = [...this.project.nodes.map(n => ({ x: n.x - 12, y: n.y - 12, width: 144, height: height(n) + 24 })), ...(this.project.notes || []).map(n => ({ ...n, width: 216, height: 72 }))];
        for (let y = top; y <= bottom; y += 36) for (let x = left; x <= right; x += 36) if (!occupied.some(r => x < r.x + r.width && x + 204 > r.x && y < r.y + r.height && y + 60 > r.y)) return { x, y };
        return { x: Math.min(WORLD.width - 216, left), y: Math.min(WORLD.height - 180, top) };
    }
    selectedIds() { return this.selection?.nodes || (this.selection?.node ? [this.selection.node] : []); }
    renderRecovery() { if (!this.recovery) return; this.$('[data-recovery]').hidden = !this.pendingRecovery; const status = this.$('.ll-local'); status.textContent = this.recovery.error || (this.recovery.dirty(this.project) ? this.recovery.pending ? '미저장 · 임시 저장 중' : this.recovery.last ? '미저장 · 임시 사본 저장됨' : '미저장' : '저장됨'); let compact = this.$('[data-save-status]'); if (!compact) { this.$('.ll-editbar').insertAdjacentHTML('beforeend', '<span data-save-status role="status"></span>'); compact = this.$('[data-save-status]'); } compact.textContent = status.textContent; }
    changed() { if (!this.recovery) return; if (this.pendingRecovery && !this.recoveryArchived) { this.recovery.archive(this.pendingRecovery.project); this.recoveryArchived = true; } this.recovery.schedule(this.project, () => this.renderRecovery()); this.renderRecovery(); }
    trimHistory(stack = this.history) { this.snapshotSizes ||= new WeakMap(); let bytes = 0; for (let i = stack.length - 1; i >= 0; i--) { let size = this.snapshotSizes.get(stack[i]); if (size === undefined) { size = JSON.stringify(stack[i]).length * 2; this.snapshotSizes.set(stack[i], size); } bytes += size; if ((bytes > 12 * 1024 * 1024 || stack.length - i > 60) && i < stack.length - 1) { stack.splice(0, i + 1); break; } } }
    checkpoint() { this.history.push(structuredClone(this.project)); this.trimHistory(); this.future = []; }
    mutate(fn, reset = true) {
        const before = structuredClone(this.project), future = this.future, runtime = this.engine, running = this.running; this.checkpoint();
        try { fn(); const routing = routeCircuit(this.project); if (routing.failures.length && this.project.wires.some(w => w.path)) throw new Error('수동 배선과 충돌하거나 통로가 없습니다. 배선을 조정하거나 자동 경로로 되돌린 뒤 다시 시도하세요.'); if (reset || simulationSignature(before) !== simulationSignature(this.project)) { this.running = false; if (this.project.exercise) this.project.exercise.solved = false; if (this.tools) this.tools.grade = null; this.engine = createEngine(this.project); } else this.engine.rebind(this.project); this.route = routing; this.update(); this.changed(); }
        catch (error) { this.history.pop(); this.future = future; this.project = before; this.engine = runtime; this.running = running; this.engine.rebind(before); this.route = routeCircuit(before); this.update(); this.say(error.message, true); throw error; }
    }
    replace(project) { const routing = routeCircuit(project); if (routing.failures.length && project.wires.some(w => w.path)) throw new Error('수동 배선이 장치·다른 신호선과 충돌하거나 통로가 없습니다.'); this.cancel(); if (this.recovery?.dirty(this.project)) this.recovery.archive(this.project); this.checkpoint(); this.project = project; this.running = false; this.selection = null; this.connection = null; this.engine = createEngine(project); this.route = routing; this.pendingRecovery = null; this.update(); this.fit(); this.changed(); }
    setMode(mode) {
        if (!['beginner', 'advanced'].includes(mode) || mode === modeOf(this.project)) return;
        this.cancel(); this.mutate(() => { this.project.settings.mode = mode; });
        this.say(`${mode === 'beginner' ? '초보' : '고급'} 모드로 전환했습니다. 회로와 세부 설정은 유지하고 실행 상태를 초기화했습니다.`);
        requestAnimationFrame(() => this.fit());
    }
    say(message, error = false) { this.message = message; this.$('[data-message]').textContent = message; this.$('[data-message]').classList.toggle('ll-error', error); }
    download(data, name, type) { const url = URL.createObjectURL(new Blob([data], { type })); const a = document.createElement('a'); a.href = url; a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }
    action(name) {
        if (name === 'watch-apply') { const signals = [...this.root.querySelectorAll('[data-watch]:checked')].map(el => el.value); this.mutate(() => { this.project.settings.wave = { span: 400, start: null, ...this.project.settings.wave, signals }; }, false); this.$('[data-tools-dialog]').close(); this.$('[data-canvas]').focus(); return; }
        if (['copy', 'paste', 'selectall', 'alignleft', 'aligntop', 'aligncenter', 'addnote', 'backups', 'recover', 'dismiss-recovery', 'watch'].includes(name) || name.startsWith('backup-') || name.startsWith('wave')) {
            this.cancel(); return this.editAction(name);
        }
        if (this.drag && ['undo', 'redo', 'delete', 'deletepoint', 'new', 'restore', 'import', 'reset', 'autoroute', 'addwaypoint'].includes(name)) this.cancel();
        if (name === 'addwaypoint' && this.selection?.net) {
            this.addingWaypoint = this.addingWaypoint === this.selection.net ? null : this.selection.net; this.live(); this.$('[data-canvas]').focus({ preventScroll: true }); this.say(this.addingWaypoint ? '점을 추가할 배선 구간을 클릭하세요. Esc로 취소합니다.' : '경유점 추가를 취소했습니다.'); return;
        }
        if ((name === 'deletepoint' || name === 'delete') && this.selection?.point) {
            const net = this.selection.net; this.commitWireEdit(previewDeletePoint(this.project, this.route, net, this.selection.point), net, '선택한 경로점을 삭제하고 직각으로 다시 연결했습니다.'); return;
        }
        if (['undo', 'redo', 'delete', 'new', 'restore', 'import', 'reset', 'autoroute'].includes(name)) this.addingWaypoint = null;
        if (name === 'pulse') { if (modeOf(this.project) === 'beginner') { this.engine.pulse(); this.live(); } return; }
        if (modeOf(this.project) === 'beginner' && ['run', 'step', 'next', 'csv'].includes(name)) return;
        if (name === 'run') { if (this.engine.fault) this.say(this.engine.fault, true); else { this.running = !this.running; this.live(); } }
        else if (name === 'step' || name === 'next') { this.running = false; name === 'step' ? this.engine.step(this.project.settings.step) : this.engine.next(); this.live(); }
        else if (name === 'reset') { this.running = false; this.engine = createEngine(this.project); this.live(); }
        else if (name === 'new') { const p = emptyProject(); p.settings.mode = modeOf(this.project); this.replace(p); }
        else if (name === 'save') { try { this.recovery.save(this.project); this.renderRecovery(); this.say('이 브라우저에 회로를 저장했습니다.'); } catch { this.say('브라우저 저장 공간을 사용할 수 없습니다. JSON으로 내보내세요.', true); } }
        else if (name === 'restore') { try { const raw = localStorage.getItem(this.storageKey); if (!raw) return this.say('이 브라우저에 저장한 회로가 없습니다.'); this.replace(validateProject(JSON.parse(raw))); this.say('저장한 회로를 복원했습니다.'); } catch (error) { this.say(`복원 실패: ${error.message}`, true); } }
        else if (name === 'forget') { try { clearTimeout(this.recovery.timer); this.recovery.pending = null; this.recovery.last = null; this.recovery.saved = ''; this.recovery.memoryBackups = []; this.recovery.error = ''; for (const suffix of ['', ':draft', ':backups']) localStorage.removeItem(this.storageKey + suffix); this.pendingRecovery = null; this.tools.forget(); this.renderRecovery(); this.say('이 브라우저의 저장 회로·자동 임시 사본·부품함·실습 복원 사본을 삭제했습니다. 현재 열린 회로는 유지합니다.'); } catch { this.say('브라우저 저장 공간에 접근할 수 없습니다.', true); } }
        else if (name === 'export') this.download(JSON.stringify(this.project, null, 2), 'logic-lab.json', 'application/json');
        else if (name === 'import') this.$('[data-file]').click();
        else if (name === 'csv') {
            const rows = ['signal,time_ns,value']; for (const n of this.project.nodes) for (const p of (spec(n).outputs.length ? spec(n).outputs : spec(n).inputs)) for (const event of this.engine.records.get(key(n.id, p)) || []) rows.push(`"${`${n.label}.${p}`.replaceAll('"', '""')}",${event.time},${event.value}`);
            this.download(rows.join('\r\n'), 'logic-lab-waveform.csv', 'text/csv;charset=utf-8');
        } else if (name === 'undo' || name === 'redo') {
            const from = name === 'undo' ? this.history : this.future, to = name === 'undo' ? this.future : this.history;
            if (from.length) { const current = this.project, target = from.pop(), same = simulationSignature(current) === simulationSignature(target); to.push(structuredClone(current)); this.trimHistory(to); this.project = target; this.selection = null; if (same) this.engine.rebind(target); else { this.running = false; this.engine = createEngine(target); } this.route = routeCircuit(target); this.update(); this.changed(); this.say(same ? '편집 이력을 적용했습니다. 실행 시간과 상태는 유지합니다.' : '회로·입력·타이밍 이력을 적용하고 시뮬레이션을 초기화했습니다.'); }
        } else if (name === 'delete') {
            if (this.selection?.note) this.mutate(() => { this.project.notes = this.project.notes.filter(n => n.id !== this.selection.note); this.selection = null; }, false);
            else if (this.selectedIds().length) this.mutate(() => { const ids = new Set(this.selectedIds()); this.project.nodes = this.project.nodes.filter(n => !ids.has(n.id)); this.project.wires = this.project.wires.filter(w => !ids.has(w.from.node) && !ids.has(w.to.node)); this.project.stimuli = (this.project.stimuli || []).filter(s => !ids.has(s.node)); this.project.netNames = Object.fromEntries(Object.entries(this.project.netNames || {}).filter(([k]) => ![...ids].some(id => k.startsWith(id + ':')))); this.selection = null; });
            else if (this.selection?.net) this.mutate(() => { this.project.wires = this.project.wires.filter(w => key(w.from.node, w.from.pin) !== this.selection.net); this.selection = null; });
        } else if (name === 'autoroute' && this.selection?.net) {
            const net = this.selection.net, candidate = { ...this.project, wires: this.project.wires.map(w => { if (key(w.from.node, w.from.pin) !== net) return w; const { path, ...automatic } = w; return automatic; }) }, routing = routeCircuit(candidate);
            if (routing.failures.length) return this.say('자동 배선 통로가 없습니다. 현재 경로를 유지합니다.', true);
            this.checkpoint(); this.project.wires = candidate.wires; this.route = routing; this.selection = { net }; this.$('[data-action="undo"]').disabled = false; this.live(); this.changed(); this.say('선택한 신호의 수동 경로를 해제하고 자동으로 배선했습니다.');
        } else if (name === 'fit') this.fit();
        else if (name === 'zoomin' || name === 'zoomout') this.setZoom(this.zoom * (name === 'zoomin' ? 1.2 : 1 / 1.2));
        else if (name === 'help') this.$('[data-help]').showModal();
        else if (name === 'closehelp') this.$('[data-help]').close();
    }
    editAction(name) {
        try {
            const ids = this.selectedIds();
            if (name === 'selectall') { this.selection = { nodes: this.project.nodes.map(n => n.id) }; this.live(); }
            else if (name === 'copy') { if (!ids.length) return this.say('복사할 장치를 선택하세요. Shift+클릭 또는 Shift+빈 공간 드래그로 여러 장치를 선택합니다.'); this.clipboard = copyGroup(this.project, ids); this.say(`${ids.length}개 장치와 내부 연결을 복사했습니다.`); }
            else if (name === 'paste') { const result = pasteGroup(this.project, this.clipboard); if (!result) return this.say('붙여넣을 공간이 없거나 장치 100개·배선 240개 제한을 넘습니다.', true); this.checkpoint(); this.project = result.project; this.route = result.routing; this.selection = { nodes: result.ids }; this.running = false; this.engine = createEngine(this.project); this.update(); this.changed(); this.say('장치와 내부 연결을 붙여넣었습니다. 외부 장치와의 연결은 복사하지 않습니다.'); }
            else if (name.startsWith('align')) { const result = alignGroup(this.project, ids, name === 'alignleft' ? 'left' : name === 'aligncenter' ? 'center' : 'top'); if (!result) return this.say('장치·배선이 겹치지 않도록 두 개 이상의 장치와 공간을 확보하세요.', true); this.checkpoint(); this.project = result.project; this.route = result.routing; this.engine.rebind(this.project); this.live(); this.changed(); }
            else if (name === 'addnote') { if ((this.project.notes || []).length >= 100) return this.say('주석은 최대 100개입니다.', true); this.mutate(() => { const note = { id: `note${crypto.randomUUID()}`, ...this.notePosition(), text: '주석을 선택하여 내용을 편집하세요.' }; (this.project.notes ||= []).push(note); this.selection = { note: note.id }; }, false); }
            else if (name === 'recover') { if (this.pendingRecovery) { const p = this.pendingRecovery.project; this.pendingRecovery = null; this.replace(p); this.say('이전 작업의 임시 사본을 복구했습니다.'); } }
            else if (name === 'dismiss-recovery') { if (this.pendingRecovery) this.recovery.archive(this.pendingRecovery.project); this.pendingRecovery = null; this.renderRecovery(); }
            else if (name === 'backups') { this.backupChoices = this.recovery.backups(); this.tools.open('전환 전 최근 작업 사본', this.backupChoices.length ? this.backupChoices.map((b, i) => `<p>${escape(b.project.title)} · ${new Date(b.time).toLocaleString()} <button data-action="backup-${i}">이 사본 복원</button></p>`).join('') : '<p>새 회로·예제·파일을 열기 전의 작업 사본이 여기에 보관됩니다.</p>'); }
            else if (name.startsWith('backup-')) { const b = this.backupChoices?.[Number(name.slice(7))]; if (b) { this.replace(structuredClone(b.project)); this.$('[data-tools-dialog]').close(); } }
            else if (name.startsWith('wave')) this.mutate(() => { this.project.settings.wave = changeWave(this.project, this.engine.now, name.slice(4)); }, false);
            else if (name === 'watch') { const chosen = this.project.settings.wave?.signals; this.tools.open('관찰할 신호 선택', this.project.nodes.flatMap(n => (spec(n).outputs.length ? spec(n).outputs : spec(n).inputs).map(p => `<label class="ll-watch-choice"><input type="checkbox" data-watch value="${key(n.id, p)}" ${chosen === null || chosen === undefined || chosen.includes(key(n.id, p)) ? 'checked' : ''}>${escape(n.label)} · ${p}</label>`)).join('') + '<button data-action="watch-apply">선택 적용</button>'); }
        } catch (error) { this.say(error.message, true); }
        if (!this.$('[data-tools-dialog]').open) this.$('[data-canvas]').focus({ preventScroll: true });
    }
    changeConfig(input) {
        const n = this.selectedNode(); if (!n) return;
        try { this.mutate(() => {
            const field = input.dataset.config;
            if (field === 'width') {
                const changed = { ...n, config: { ...n.config, width: Number(input.value) } };
                if (!canPlace(this.project.nodes, changed)) throw new Error('분리·결합 장치의 핀 수가 늘어납니다. 장치 아래에 공간을 확보하세요.');
                const storedField = n.type === 'BUS_IN' ? 'value' : n.type === 'WREG' ? 'initial' : null;
                if (storedField) { const old = signal(n.config[storedField], nodeWidth(n)), value = /^[01]+$/.test(String(old)) ? typeof old === 'number' ? old : parseInt(old, 2) : 'X'; if (typeof value === 'number' && value >= 2 ** changed.config.width) throw new Error('저장된 값이 새 비트 수의 범위를 넘습니다. 값을 먼저 낮추세요.'); n.config[storedField] = signal(value, changed.config.width); }
                n.config.width = changed.config.width;
            } else if (['value', 'initial'].includes(field)) n.config[field] = parseSignal(input.value, n.type === 'BUS_IN' || n.type === 'WREG' ? nodeWidth(n) : 1);
            else n.config[field] = input.type === 'number' ? this.number(input) : input.value === '0' ? 0 : input.value === '1' ? 1 : input.value;
        }); } catch { return; }
        this.say('장치 설정을 적용했습니다. 시뮬레이션이 0 ns부터 시작합니다.');
    }
    renderPalette() {
        const groups = [...new Set(Object.values(TYPES).map(t => t.group))];
        const common = ['SWITCH', 'CLOCK', 'LED', 'AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR', 'DFF', 'TFF'];
        const render = allowed => groups.map(group => {
            const entries = Object.entries(TYPES).filter(([type, d]) => type !== 'CUSTOM' && allowed(type) && d.group === group && `${type} ${d.name} ${d.description}`.toLowerCase().includes(this.search.toLowerCase()));
            return entries.length ? `<div class="ll-palette-group"><h3>${group}</h3><div class="ll-palette-grid">${entries.map(([type, d]) => `<button data-type="${type}" title="${d.description}" aria-label="${d.name} 추가"><span class="ll-gate-icon">${this.symbol(type)}</span><span>${d.name}</span></button>`).join('')}</div></div>` : '';
        }).join('');
        const simple = modeOf(this.project) === 'beginner' && !this.search;
        this.moreDevicesOpen = this.$('.ll-more-devices')?.open ?? this.moreDevicesOpen ?? false;
        this.$('[data-palette]').innerHTML = (simple ? render(type => common.includes(type)) + `<details class="ll-more-devices"${this.moreDevicesOpen ? ' open' : ''}><summary>다른 장치도 보기</summary>${render(type => !common.includes(type))}</details>` : render(() => true)) || '<p class="ll-muted">검색 결과가 없습니다.</p>';
    }
    symbol(type, node = null) {
        const drawing = gateSymbol(type) || sequentialSymbol(node || { type, config: {} }, { compact: true, ideal: modeOf(this.project) === 'beginner' }); if (drawing) return drawing;
        if (['JOIN', 'SPLIT', 'WADD', 'WREG', 'CUSTOM'].includes(type)) return { JOIN: '⇶', SPLIT: '⇉', WADD: 'Σ[n]', WREG: 'D[n] ▷', CUSTOM: '▣' }[type];
        return ({ CLOCK: '┌┐', SWITCH: '0/1', ZERO: '0', ONE: '1', LED: '◉', PROBE: '⌁', MUX: '2:1', DEMUX: '1:2', HA: '½Σ', FA: 'Σ' })[type] || type;
    }
    update() {
        const beginner = modeOf(this.project) === 'beginner';
        this.root.dataset.mode = modeOf(this.project);
        this.root.querySelectorAll('[data-advanced-only]').forEach(el => { el.hidden = beginner; });
        this.root.querySelectorAll('[data-beginner-only]').forEach(el => { el.hidden = !beginner; });
        this.root.querySelectorAll('[data-mode-choice]').forEach(el => el.setAttribute('aria-pressed', String(el.dataset.modeChoice === modeOf(this.project))));
        this.$('[data-mode-title]').textContent = beginner ? '초보 모드' : '고급 모드';
        this.$('[data-mode-description]').textContent = beginner ? '입력을 바꾸면 출력이 바로 갱신됩니다.' : '장치별 타이밍을 설정하고 시간에 따른 변화를 관찰합니다.';
        this.renderPalette();
        this.$('[data-title]').value = this.project.title;
        this.$('[data-setting="step"]').value = this.project.settings.step; this.$('[data-setting="speed"]').value = this.project.settings.speed;
        this.$('[data-step-label]').textContent = `${this.project.settings.step} ns`; this.$('[data-action="undo"]').disabled = !this.history.length;
        this.$('[data-count]').textContent = `${this.project.nodes.length} 장치  ·  ${this.project.wires.length} 연결`;
        this.$('[data-empty]').hidden = !!this.project.nodes.length; this.live();
        if (this.route.failures.length) this.say(`배선 ${this.route.failures.length}개에 통로가 없습니다. 장치 사이 간격을 넓혀 주세요. 해당 선은 그리지 않았습니다.`, true);
        else this.say(this.message);
    }
    live() {
        if (this.addingWaypoint && this.selection?.net !== this.addingWaypoint) this.addingWaypoint = null;
        const beginner = modeOf(this.project) === 'beginner';
        this.$('[data-time]').innerHTML = beginner ? `${this.engine.cycles} <small>회</small>` : `${this.engine.now.toLocaleString()} <small>ns</small>`;
        this.$('[data-run-state]').textContent = beginner ? '클록' : this.running ? '실행 중' : '일시 정지';
        this.$('[data-action="pulse"]').disabled = !this.engine.clockIds?.length || !!this.engine.fault;
        this.$('[data-action="run"]').innerHTML = `${icon(this.running ? 'pause' : 'play')}<span>${this.running ? '정지' : '실행'}</span>`;
        this.tools?.refresh();
        this.root.classList.toggle('ll-running', this.running); this.renderCanvas(); this.renderInspector(); beginner ? this.renderIO() : this.renderWave();
        this.$('[data-warnings]').innerHTML = this.engine.warnings.slice(-4).map(w => `<p class="ll-warning">${escape(w.label)} · ${w.time} ns<br>${w.kind} 시간 위반 → X</p>`).join('');
        if (this.engine.fault) this.say(this.engine.fault, true);
    }
    renderInspector() {
        const n = this.selectedNode();
        if (this.$('[data-inspector]').contains(document.activeElement) && document.activeElement.matches('input,select,textarea')) return;
        const inspected = n?.id || this.selection?.net || '';
        if (this.inspected !== inspected) { this.inspected = inspected; this.$('.ll-inspector').scrollTop = 0; }
        if (!n) {
            if (this.selection?.note) { const note = this.project.notes?.find(n => n.id === this.selection.note); if (note) this.$('[data-inspector]').innerHTML = `<div class="ll-selected-title">회로 주석</div><label>내용<textarea data-note-text maxlength="300" rows="6">${escape(note.text)}</textarea></label><p class="ll-muted">주석을 드래그해 이동하세요. 회로의 동작에 영향을 주지 않습니다.</p><button data-action="delete">주석 삭제</button>`; return; }
            if (this.selectedIds().length > 1) { this.$('[data-inspector]').innerHTML = `<div class="ll-selected-title">${this.selectedIds().length}개 장치 선택</div><p class="ll-muted">함께 드래그하거나 복사·정렬할 수 있습니다. Shift+클릭으로 선택을 바꾸세요. 정렬이 장치·배선과 충돌하면 적용하지 않습니다.</p><button data-action="copy">선택 장치 복사</button><button data-action="delete">선택 장치 삭제</button>`; return; }
            if (this.selection?.net) {
                const wires = this.project.wires.filter(w => key(w.from.node, w.from.pin) === this.selection.net);
                const point = this.selection.point;
                this.$('[data-inspector]').innerHTML = `<div class="ll-selected-title">공통 신호 배선</div><p class="ll-muted">동일한 출력에서 분기한 ${wires.length}개 연결입니다. 선 구간 또는 표시된 점을 드래그하세요. ◇ 꺾임점 · ● 경유점. 핀 앞과 다른 신호의 교차점은 고정됩니다.</p><div class="ll-route-actions"><button data-action="addwaypoint" aria-pressed="${this.addingWaypoint === this.selection.net}">${this.addingWaypoint === this.selection.net ? '추가 취소' : '경유점 추가'}</button><button data-action="deletepoint" ${point ? '' : 'disabled'}>선택한 점 삭제</button></div><p class="ll-muted">${point ? `선택한 점 (${point.x}, ${point.y}) · Delete로 삭제` : '점 추가 → 배선 클릭 · Esc로 취소'}</p><button data-action="autoroute" ${wires.some(w => w.path) ? '' : 'disabled'}>자동 경로로 되돌리기</button>${wires.map(w => `<div class="ll-wire-row"><span>${escape(this.project.nodes.find(n => n.id === w.to.node)?.label)} · ${w.to.pin}${w.path ? ' · 수동' : ''}</span><button data-remove-wire="${w.id}" aria-label="이 연결 삭제">${icon('trash', 15)}</button></div>`).join('')}`;
                this.$('[data-inspector]').insertAdjacentHTML('afterbegin', `<label>신호 이름<input data-net-label maxlength="40" value="${escape(this.project.netNames?.[this.selection.net] || '')}"></label>`);
            } else this.$('[data-inspector]').innerHTML = '<div class="ll-selection-empty">⌖<strong>장치를 선택하세요</strong><p>핀 상태와 타이밍 설정을<br>여기에서 확인할 수 있습니다.</p></div>';
            return;
        }
        // Keep focused controls stable while the simulation is playing.
        if (this.$('[data-inspector]').contains(document.activeElement) && document.activeElement.matches('input,select')) return;
        const pins = [...spec(n).inputs.map(p => [p, this.engine.readInputs(n)[p], false]), ...spec(n).outputs.map(p => [p, this.engine.value(n.id, p), true])];
        const pinValues = `<div class="ll-pin-values">${pins.map(([p, v, out]) => `<span title="${escape(formatSignal(v, true))}">${out ? '↗' : '↘'} ${p}${pinWidth(n, p, out) > 1 ? `[${pinWidth(n, p, out)}]` : ''}<b class="ll-bit ll-bit-${signalClass(v)}">${escape(formatSignal(v))}</b></span>`).join('')}</div>`;
        if (modeOf(this.project) === 'beginner') {
            const hint = n.type === 'SWITCH' ? '0/1을 바꾸면 연결된 출력이 바로 바뀝니다.' : n.type === 'CLOCK' ? '‘클록 한 번’을 눌러 연결된 기억 장치를 한 단계 진행하세요.' : isFF(n.type) ? '입력을 바꾼 뒤 ‘클록 한 번’을 누르면 값을 기억합니다.' : '입력 핀과 출력 핀의 0/1 상태를 확인하세요.';
            this.$('[data-inspector]').innerHTML = `<div class="ll-selected-title"><span class="ll-gate-icon">${this.symbol(n.type, n)}</span>${spec(n).name}</div><p class="ll-muted">${escape(hint)}</p><label>이름<input data-label value="${escape(n.label)}" maxlength="40"></label>${pinValues}${n.type === 'SWITCH' ? `<button data-toggle-input="${n.id}">입력 ${this.engine.value(n.id)} → ${this.engine.value(n.id) === 1 ? 0 : 1} 바꾸기</button>` : ''}<button class="ll-remove" data-action="delete">${icon('trash', 15)} 장치 삭제</button>`;
            return;
        }
        const num = (id, label, max = 1000000, min = 0) => `<label>${label}<span><input type="number" data-config="${id}" min="${min}" max="${max}" value="${n.config[id]}"> ${id === 'duty' ? '%' : 'ns'}</span></label>`;
        let timing = '';
        if (n.type === 'CLOCK') timing = num('period', '클록 주기', 1000000, 2) + num('duty', '듀티비', 99, 1) + num('phase', '첫 상승 시각') + `<p class="ll-muted">High ${Math.max(1, Math.min(n.config.period - 1, Math.round(n.config.period * n.config.duty / 100)))} ns · ${ (1000 / n.config.period).toFixed(2)} MHz<br>High/Low는 각각 최소 1 ns입니다.</p>`;
        else if (spec(n).inputs.length && spec(n).outputs.length) timing = num('delay', isSequential(n.type) ? '출력 지연 (tCQ)' : '전파 지연');
        if (isSequential(n.type)) timing += `<label>초기 Q<select data-config="initial">${[0, 1, 'X'].map(v => `<option ${n.config.initial === v ? 'selected' : ''}>${v}</option>`).join('')}</select></label>`;
        if (isFF(n.type)) timing += `<label>클록 에지<select data-config="edge"><option value="rising" ${n.config.edge === 'rising' ? 'selected' : ''}>상승 ↑</option><option value="falling" ${n.config.edge === 'falling' ? 'selected' : ''}>하강 ↓</option></select></label>` + num('setup', 'Setup 시간') + num('hold', 'Hold 시간');
        if (n.type === 'SWITCH') timing = `<label>시작 입력<select data-config="value">${[0, 1, 'X', 'Z'].map(v => `<option ${n.config.value === v ? 'selected' : ''}>${v}</option>`).join('')}</select></label>`;
        if (WIDTH_TYPES.includes(n.type)) timing += `<label>비트 수<select data-config="width">${(spec(n).group === '버스 · 다중 비트' ? [2, 4, 8, 16] : [1, 2, 4, 8, 16]).map(w => `<option value="${w}" ${nodeWidth(n) === w ? 'selected' : ''}>${w}비트</option>`).join('')}</select></label>`;
        if (n.type === 'BUS_IN') timing += `<label>시작 값<input data-config="value" aria-label="버스 시작 값" value="${escape(formatSignal(signal(n.config.value, nodeWidth(n))))}"></label><p class="ll-muted">2진수 ${nodeWidth(n)}자리 · 10진수 · 0x16진수를 입력하세요.</p>`;
        if (n.type === 'WREG') timing = timing.replace(/<label>초기 Q<select[\s\S]*?<\/select><\/label>/, `<label>초기 Q<input data-config="initial" aria-label="레지스터 초기 값" value="${escape(formatSignal(signal(n.config.initial, nodeWidth(n))))}"></label>`);
        this.$('[data-inspector]').innerHTML = `<div class="ll-selected-title"><span class="ll-gate-icon">${this.symbol(n.type, n)}</span>${spec(n).name}</div><p class="ll-muted">${spec(n).description}</p><label>이름<input data-label value="${escape(n.label)}" maxlength="40"></label>${pinValues}${timing ? `<h3 class="ll-property-heading">장치 설정</h3>${timing}` : ''}<button class="ll-remove" data-action="delete">${icon('trash', 15)} 장치 삭제</button>`;
    }
    renderIO() {
        const focused = document.activeElement?.closest('[data-toggle-input]')?.dataset.toggleInput;
        const inputs = this.project.nodes.filter(n => ['SWITCH', 'BUS_IN'].includes(n.type));
        const outputs = this.project.nodes.filter(n => ['LED', 'PROBE', 'BUS_OUT'].includes(n.type));
        const describe = value => ({ 0: '꺼짐', 1: '켜짐', X: '미정 · 연결 확인', Z: '고임피던스' })[value];
        const inputCards = inputs.map(n => { const v = this.engine.value(n.id), target = v === 1 ? '0으로' : '1로'; return n.type === 'BUS_IN' ? `<label class="ll-bus-value">${escape(n.label)}<input data-bus-input="${n.id}" aria-label="${escape(n.label)} 버스 입력" value="${escape(formatSignal(v))}"></label>` : `<button class="ll-io-card ll-io-${v}" data-toggle-input="${n.id}" aria-label="${escape(n.label)} 입력 ${v}, ${target} 바꾸기"><span>${escape(n.label)}</span><b>${v}</b><small>눌러서 ${target} 변경</small></button>`; }).join('');
        const outputCards = outputs.map(n => { const v = this.engine.input(n.id, 'A'); return `<div class="ll-io-card ll-io-${signalClass(v)}" data-output-card="${n.id}"><span>${escape(n.label)}</span><b>${escape(formatSignal(v))}</b><small>${String(v).length > 1 ? escape(formatSignal(v, true)) : describe(v)}</small></div>`; }).join('');
        this.$('[data-basic-io]').innerHTML = `<div><h3>입력</h3><div class="ll-io-cards">${inputCards || '<p class="ll-muted">입력 스위치를 추가하세요.</p>'}</div></div><div><h3>출력</h3><div class="ll-io-cards" aria-live="polite">${outputCards || '<p class="ll-muted">회로의 출력에 LED 또는 프로브를 연결하세요.</p>'}</div></div>`;
        if (focused) this.$(`[data-basic-io] [data-toggle-input="${focused}"]`)?.focus({ preventScroll: true });
    }
    renderCanvas() {
        const svg = this.$('[data-canvas]'); if (!svg) return;
        const box = svg.getBoundingClientRect(), width = Math.max(1, box.width) / this.zoom, h = Math.max(1, box.height) / this.zoom;
        svg.setAttribute('viewBox', `${this.view.x} ${this.view.y} ${width} ${h}`);
        const nets = [...new Set(this.project.wires.map(w => key(w.from.node, w.from.pin)))];
        const color = net => colors[Math.max(0, nets.indexOf(net)) % colors.length];
        const wireValue = net => { const w = this.project.wires.find(w => key(w.from.node, w.from.pin) === net); return w ? this.engine.value(w.from.node, w.from.pin) : 'X'; };
        let wires = '';
        {
            wires = this.route.segments.map((s, i) => `<g data-net="${s.net}" data-segment="${i}" data-direction="${s.dir}" class="ll-wire ll-wire-${signalClass(wireValue(s.net))} ${this.selection?.net === s.net ? 'll-wire-selected' : ''}" style="--ll-net:${color(s.net)}"><title>${s.dir === 'h' ? '위아래' : '좌우'}로 드래그하여 경로 조정</title><path class="ll-wire-under" d="${s.d}"/><path class="ll-wire-line" d="${s.d}"/><path class="ll-wire-hit" d="${s.d}"/></g>`).join('');
            wires += this.route.junctions.map(p => `<circle class="ll-junction" cx="${p.x}" cy="${p.y}" r="4" fill="${color(p.net)}"/>`).join('');
            const named = new Set(); for (const s of this.route.segments) { const name = this.project.netNames?.[s.net]; if (!name || named.has(s.net) || s.end - s.start < 48) continue; named.add(s.net); wires += `<text class="ll-net-name" x="${s.dir === 'h' ? (s.start + s.end) / 2 : s.fixed + 8}" y="${s.dir === 'h' ? s.fixed - 8 : (s.start + s.end) / 2}" fill="${color(s.net)}">${escape(name)}</text>`; }
        }
        const nodes = this.project.nodes.map(n => {
            const y = height(n), selected = this.selectedIds().includes(n.id), sequential = isSequential(n.type);
            const pins = (output) => spec(n)[output ? 'outputs' : 'inputs'].map(p => {
                const at = port(n, p, output), value = output ? this.engine.value(n.id, p) : this.engine.input(n.id, p);
                const label = n.config.ports?.[output ? 'outputs' : 'inputs']?.find(o => o.name === p)?.label || (p === 'QB' ? 'Q̅' : p), issue = this.tools?.issues.some(i => i.node === n.id && i.pin === p);
                return `<g data-pin="${p}" data-node="${n.id}" data-output="${output}" tabindex="0" role="button" aria-label="${escape(n.label)} ${output ? '출력' : '입력'} ${p}"><title>${escape(label)} · ${pinWidth(n, p, output)}비트</title><circle class="ll-pin-hit" cx="${at.x - n.x}" cy="${at.y - n.y}" r="12"/><circle class="ll-pin ll-pin-${signalClass(value)} ${issue ? 'll-pin-error' : ''} ${this.connection?.node === n.id && this.connection?.pin === p ? 'll-pin-active' : ''}" cx="${at.x - n.x}" cy="${at.y - n.y}" r="5"/>${sequential ? '' : `<text class="ll-pin-label" x="${output ? 109 : 11}" y="${at.y - n.y + 4}" text-anchor="${output ? 'end' : 'start'}">${escape(label.slice(0, 8))}</text>`}</g>`;
            }).join('');
            const value = spec(n).outputs.length ? this.engine.value(n.id) : this.engine.input(n.id, 'A');
            const display = ['SWITCH', 'LED', 'PROBE', 'ZERO', 'ONE', 'CLOCK', 'BUS_IN', 'BUS_OUT'].includes(n.type);
            const vector = String(value).length > 1, issue = this.tools?.focused?.node === n.id;
            return `<g data-device="${n.id}" class="ll-device ${selected ? 'll-device-selected' : ''} ${issue ? 'll-device-error' : ''}" transform="translate(${n.x},${n.y})" tabindex="0" role="group" aria-label="${escape(n.label)} ${spec(n).name}"><rect class="ll-node-shadow" x="1" y="3" width="120" height="${y}" rx="10"/><rect class="ll-node-body" width="120" height="${y}" rx="10"/><text class="ll-node-label" x="0" y="-12">${escape(n.label)}</text><text class="ll-node-kind" x="60" y="${y - (sequential ? 3 : 12)}" text-anchor="middle">${escape(n.type)}</text>${display ? `<g ${n.type === 'SWITCH' ? 'data-toggle="' + n.id + '" role="button" tabindex="0" aria-label="입력 전환"' : ''}>${vector ? `<rect class="ll-indicator" x="32" y="${(y - 12) / 2 - 15}" width="56" height="30" rx="6"/>` : `<circle class="ll-indicator ll-indicator-${signalClass(value)}" cx="60" cy="${(y - 12) / 2}" r="17"/>`}<text class="ll-indicator-text ${vector ? 'll-indicator-bus' : ''}" x="60" y="${(y - 12) / 2 + 5}" text-anchor="middle">${escape(formatSignal(value))}</text></g>` : n.type === 'CUSTOM' ? '' : (sequentialSymbol(n, { ideal: modeOf(this.project) === 'beginner' }) || gateSymbol(n.type, { x: 26, y: y / 2 - 25, width: 68, height: 46 }) || `<text class="ll-node-symbol" x="60" y="${y / 2 + 4}" text-anchor="middle">${escape(this.symbol(n.type, n))}</text>`)}${pins(false)}${pins(true)}</g>`;
        }).join('');
        let preview = '';
        if (this.connection && this.pointer) { const n = this.project.nodes.find(n => n.id === this.connection.node), a = port(n, this.connection.pin, this.connection.output), p = this.pointer; preview = `<path class="ll-wire-preview" d="M${a.x},${a.y}H${(a.x + p.x) / 2}V${p.y}H${p.x}"/>`; }
        const handles = this.selection?.net ? wireEditPoints(this.route, this.selection.net).map(p => `<g data-route-point data-point-net="${this.selection.net}" data-x="${p.x}" data-y="${p.y}" class="ll-route-point ${p.waypoint ? 'll-route-waypoint' : ''} ${this.selection.point?.x === p.x && this.selection.point?.y === p.y ? 'll-route-point-selected' : ''}" transform="translate(${p.x},${p.y})" tabindex="0" role="button" aria-label="${p.waypoint ? '경유점' : '꺾임점'} ${p.x}, ${p.y}"><title>드래그하여 이동 · 선택 후 Delete로 삭제</title><circle class="ll-route-point-hit" r="10"/>${p.waypoint ? '<circle class="ll-route-point-shape" r="4.5"/>' : '<rect class="ll-route-point-shape" x="-4" y="-4" width="8" height="8" transform="rotate(45)"/>'}</g>`).join('') : '';
        svg.classList.toggle('ll-adding-waypoint', !!this.addingWaypoint);
        const notes = (this.project.notes || []).map(n => { const lines = n.text.split('\n').flatMap(line => line.match(/.{1,24}/gu) || ['']).slice(0, 12); return `<g data-note="${n.id}" class="ll-note ${this.selection?.note === n.id ? 'll-note-selected' : ''}" transform="translate(${n.x},${n.y})" role="button" tabindex="0" aria-label="주석 ${escape(n.text.slice(0, 30))}"><rect width="204" height="${Math.max(36, lines.length * 16 + 16)}" rx="5"/>${lines.map((line, i) => `<text x="8" y="${21 + i * 16}">${escape(line)}</text>`).join('')}</g>`; }).join('');
        const d = this.drag, boxSelection = d?.kind === 'box' ? `<rect class="ll-selection-box" x="${Math.min(d.start.x, d.end.x)}" y="${Math.min(d.start.y, d.end.y)}" width="${Math.abs(d.end.x - d.start.x)}" height="${Math.abs(d.end.y - d.start.y)}"/>` : '';
        svg.innerHTML = `<defs><pattern id="${this.uid}-grid" width="12" height="12" patternUnits="userSpaceOnUse"><circle cx="0" cy="0" r="0.8" fill="#d9dee9"/></pattern></defs><rect x="0" y="0" width="${WORLD.width}" height="${WORLD.height}" fill="url(#${this.uid}-grid)"/>${wires}${nodes}${notes}${handles}${preview}${boxSelection}`;
        this.$('[data-zoom]').textContent = `${Math.round(this.zoom * 100)}%`;
    }
    renderWave() {
        const { end, start, span } = waveRange(this.project, this.engine.now);
        this.$('[data-wave-range]').textContent = `${start}–${end} ns`;
        const signals = [];
        const watched = this.project.settings.wave?.signals;
        for (const n of this.project.nodes) for (const p of (spec(n).outputs.length ? spec(n).outputs : spec(n).inputs)) if (watched === null || watched === undefined || watched.includes(key(n.id, p))) signals.push({ n, p, records: this.engine.records.get(key(n.id, p)) || [] });
        const width = 760, label = 158, waveW = width - label - 16;
        let drawing = `<rect width="${width}" height="${30 + signals.length * 28}" fill="#fff"/>`;
        for (let i = 0; i <= 8; i++) { const x = label + waveW * i / 8; drawing += `<path d="M${x} 24V${30 + signals.length * 28}" stroke="#edf0f5"/><text x="${x}" y="15" class="ll-wave-tick">${Math.round(start + span * i / 8)}</text>`; }
        signals.forEach(({ n, p, records }, i) => {
            const y = 36 + i * 28, selected = n.id === this.selection?.node;
            const value = spec(n).outputs.length ? this.engine.value(n.id, p) : this.engine.input(n.id, p);
            const name = this.project.netNames?.[key(n.id, p)] || `${n.label} · ${p}`;
            drawing += `<text x="12" y="${y + 6}" class="ll-wave-name ${selected ? 'll-wave-selected' : ''}">${escape(name.slice(0, 19))}</text><text x="138" y="${y + 6}" class="ll-wave-value">${String(value).length > 1 ? 'bus' : value}</text>`;
            for (let j = 0; j < records.length; j++) {
                const event = records[j], next = records[j + 1]?.time ?? end; if (next < start || event.time > end) continue;
                const x1 = label + Math.max(0, event.time - start) / span * waveW, x2 = label + Math.min(span, next - start) / span * waveW;
                if (String(event.value).length > 1) drawing += `<rect x="${x1}" y="${y - 8}" width="${Math.max(0, x2 - x1)}" height="17" fill="#f0edfc" stroke="#a89dcc" stroke-width="0.6"/>${x2 - x1 > 34 ? `<text x="${x1 + 4}" y="${y + 4}" class="ll-wave-name">${escape(formatSignal(event.value))}</text>` : ''}`;
                else if (event.value === 'X' || event.value === 'Z') drawing += `<rect x="${x1}" y="${y - 7}" width="${Math.max(0, x2 - x1)}" height="15" fill="${event.value === 'X' ? '#fff2df' : '#eeedf7'}"/><path d="M${x1},${y}H${x2}" stroke="${event.value === 'X' ? '#c48a31' : '#8b79ac'}" stroke-dasharray="3 3"/>`;
                else { const high = event.value === 1 ? y - 7 : y + 7; drawing += `<path d="M${x1},${j && event.time >= start ? (records[j - 1].value === 1 ? y - 7 : y + 7) : high}V${high}H${x2}" fill="none" stroke="${selected ? '#5948c9' : '#419d80'}" stroke-width="1.7"/>`; }
            }
        });
        for (const [i, time] of (this.tools?.cursors || []).entries()) if (time !== null && time >= start && time <= end) { const x = label + (time - start) / span * waveW; drawing += `<path d="M${x},22V${30 + signals.length * 28}" stroke="${i ? '#d17c46' : '#6453d4'}" stroke-width="1.5"/><text x="${x + 3}" y="24" class="ll-wave-name">${i ? 'B' : 'A'} ${time} ns</text>`; }
        this.$('[data-wave-range]').textContent += this.tools?.cursors.every(v => v !== null) ? ` · Δt=${Math.abs(this.tools.cursors[1] - this.tools.cursors[0])} ns` : ' · 클릭: A/B 커서';
        this.$('[data-wave]').innerHTML = signals.length ? `<svg role="img" aria-label="논리 신호 파형. 가로축 ns, 세로축 장치별 핀" width="100%" style="min-width:700px" height="${30 + signals.length * 28}" viewBox="0 0 ${width} ${30 + signals.length * 28}">${drawing}</svg>` : '<p class="ll-muted">장치를 추가하면 신호 파형이 나타납니다.</p>';
    }
    world(e) { const r = this.$('[data-canvas]').getBoundingClientRect(); return { x: this.view.x + (e.clientX - r.left) / this.zoom, y: this.view.y + (e.clientY - r.top) / this.zoom }; }
    inside(e) { const r = this.$('[data-canvas]').getBoundingClientRect(); return e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom; }
    fit() {
        const r = this.$('[data-canvas]').getBoundingClientRect(), ns = this.project.nodes;
        const bounds = { left: Math.min(...ns.map(n => n.x)), top: Math.min(...ns.map(n => n.y)), right: Math.max(...ns.map(n => n.x + 120)), bottom: Math.max(...ns.map(n => n.y + height(n))) };
        for (const route of this.route.routes) for (const p of route.points) { bounds.left = Math.min(bounds.left, p.x); bounds.top = Math.min(bounds.top, p.y); bounds.right = Math.max(bounds.right, p.x); bounds.bottom = Math.max(bounds.bottom, p.y); }
        for (const note of this.project.notes || []) { bounds.left = Math.min(bounds.left, note.x); bounds.top = Math.min(bounds.top, note.y); bounds.right = Math.max(bounds.right, note.x + 204); bounds.bottom = Math.max(bounds.bottom, note.y + 180); }
        const minX = ns.length ? bounds.left - 70 : 0, minY = ns.length ? bounds.top - 60 : 0;
        const maxX = ns.length ? bounds.right + 70 : 1000, maxY = ns.length ? bounds.bottom + 50 : 500;
        this.zoom = Math.max(0.1, Math.min(1, r.width / (maxX - minX), r.height / (maxY - minY))); this.view = { x: minX, y: minY }; this.renderCanvas();
    }
    setZoom(value, event) { const r = this.$('[data-canvas]').getBoundingClientRect(); const e = event || { clientX: r.left + r.width / 2, clientY: r.top + r.height / 2 }; const p = this.world(e); this.zoom = Math.max(0.1, Math.min(2, value)); this.view.x = p.x - (e.clientX - r.left) / this.zoom; this.view.y = p.y - (e.clientY - r.top) / this.zoom; this.renderCanvas(); }
    addVisible(type, componentId = null) {
        const r = this.$('[data-canvas]').getBoundingClientRect();
        for (let y = Math.max(36, Math.ceil((this.view.y + 60) / GRID) * GRID); y < Math.min(WORLD.height - 180, this.view.y + r.height / this.zoom); y += 132) for (let x = Math.max(36, Math.ceil((this.view.x + 48) / GRID) * GRID); x < Math.min(WORLD.width - 160, this.view.x + r.width / this.zoom); x += 168) { const n = componentId ? this.tools.newComponentNode(componentId, x, y) : makeNode(type, x, y); if (canPlace(this.project.nodes, n)) return this.addNode(n); }
        this.say('보이는 공간이 부족합니다. 화면을 이동하거나 축소한 뒤 장치를 드래그하세요.', true);
    }
    addNode(n) { if (this.project.nodes.length >= 100) return this.say('장치는 최대 100개까지 사용할 수 있습니다.', true); if (!canPlace(this.project.nodes, n)) return this.say('장치 사이에 여유 공간을 두고 배치해 주세요.', true); try { this.mutate(() => { if (n.type === 'CUSTOM') this.project.components = this.tools.withComponent(this.project, n.config.componentId); this.project.nodes.push(n); this.selection = { node: n.id }; }); this.say(`${n.label} 장치를 추가했습니다.`); } catch {} }
    pinInfo(el) { return { node: el.dataset.node, pin: el.dataset.pin, output: el.dataset.output === 'true' }; }
    connect(a, b) {
        this.connection = null; if (a.node === b.node && a.pin === b.pin && a.output === b.output) { this.connection = a; this.renderCanvas(); return; }
        if (a.output === b.output) return this.say('출력 핀과 입력 핀을 연결하세요.', true);
        const from = a.output ? a : b, to = a.output ? b : a;
        const fromNode = this.project.nodes.find(n => n.id === from.node), toNode = this.project.nodes.find(n => n.id === to.node);
        if (pinWidth(fromNode, from.pin, true) !== pinWidth(toNode, to.pin, false)) return this.say('비트 수가 다릅니다. 같은 폭으로 맞추거나 버스 분리·결합 장치를 사용하세요.', true);
        if (this.project.wires.some(w => w.to.node === to.node && w.to.pin === to.pin)) return this.say('이미 연결된 입력입니다. 기존 연결을 삭제한 뒤 연결하세요.', true);
        if (this.project.wires.length >= 240) return this.say('배선은 최대 240개까지 사용할 수 있습니다.', true);
        const w = { id: `w${crypto.randomUUID()}`, from: { node: from.node, pin: from.pin }, to: { node: to.node, pin: to.pin } };
        const candidate = routeCircuit({ ...this.project, wires: [...this.project.wires, w] });
        if (candidate.failures.length) { this.renderCanvas(); return this.say('배선 통로가 부족합니다. 장치 간격을 넓힌 뒤 연결하세요.', true); }
        this.mutate(() => { this.project.wires.push(w); }); this.say('배선을 연결했습니다.');
    }
    pointerDown(e) {
        if (e.button !== 0 || e.target.closest('dialog')) return;
        const lib = e.target.closest('[data-type],[data-component]');
        if (lib) { e.preventDefault(); this.drag = { kind: 'library', type: lib.dataset.component ? 'CUSTOM' : lib.dataset.type, component: lib.dataset.component || null, x: e.clientX, y: e.clientY, moved: false }; return; }
        const svg = e.target.closest('[data-canvas]'); if (!svg) return;
        e.preventDefault(); svg.focus();
        if (this.addingWaypoint) {
            const net = e.target.closest('[data-net]'), handle = e.target.closest('[data-route-point]');
            if (handle && handle.dataset.pointNet === this.addingWaypoint) { this.addingWaypoint = null; this.selection = { net: handle.dataset.pointNet, point: { x: Number(handle.dataset.x), y: Number(handle.dataset.y) } }; this.live(); this.say('이미 있는 점을 선택했습니다. 드래그하거나 삭제할 수 있습니다.'); return; }
            if (!net || net.dataset.net !== this.addingWaypoint) return this.say('선택한 신호의 배선 구간을 클릭하세요. Esc로 취소합니다.');
            const segment = this.route.segments[Number(net.dataset.segment)], p = this.world(e);
            const point = segment.dir === 'h' ? { x: Math.max(segment.start, Math.min(segment.end, p.x)), y: segment.fixed } : { x: segment.fixed, y: Math.max(segment.start, Math.min(segment.end, p.y)) };
            this.commitWireEdit(previewAddWaypoint(this.project, this.route, this.addingWaypoint, point), this.addingWaypoint, '경유점을 추가했습니다. 점을 드래그하여 경로를 바꿀 수 있습니다.'); return;
        }
        const handle = e.target.closest('[data-route-point]');
        if (handle) { const point = { x: Number(handle.dataset.x), y: Number(handle.dataset.y) }, net = handle.dataset.pointNet; this.connection = null; this.selection = { net, point }; this.drag = { kind: 'point', point, net, before: structuredClone(this.project), routeBefore: this.route, wiresBefore: this.project.wires, start: this.world(e), moved: false, invalid: false, frame: null, latest: null }; svg.dataset.dragging = 'point'; this.live(); return; }
        const pin = e.target.closest('[data-pin]');
        if (pin) { const info = this.pinInfo(pin); if (this.connection) { this.connect(this.connection, info); return; } this.connection = info; this.pointer = this.world(e); this.drag = { kind: 'wire', x: e.clientX, y: e.clientY, moved: false }; this.renderCanvas(); return; }
        const toggle = e.target.closest('[data-toggle]');
        const note = e.target.closest('[data-note]');
        if (note) { const n = this.project.notes.find(n => n.id === note.dataset.note); this.selection = { note: n.id }; this.drag = { kind: 'note', note: n, x: n.x, y: n.y, start: this.world(e), before: structuredClone(this.project), moved: false }; this.live(); return; }
        const device = e.target.closest('[data-device]');
        if (device) {
            const n = this.project.nodes.find(n => n.id === device.dataset.device), selected = this.selectedIds(); this.connection = null;
            if (e.shiftKey) { this.selection = { nodes: selected.includes(n.id) ? selected.filter(id => id !== n.id) : [...selected, n.id] }; this.live(); return; }
            const ids = selected.includes(n.id) ? selected : [n.id]; this.selection = ids.length === 1 ? { node: n.id } : { nodes: ids };
            this.drag = { kind: 'group', ids, toggle: toggle?.dataset.toggle || null, before: structuredClone(this.project), routeBefore: this.route, start: this.world(e), moved: false, invalid: false, frame: null, latest: null }; svg.dataset.dragging = 'node'; this.live(); return;
        }
        const net = e.target.closest('[data-net]');
        if (net) { const segment = this.route.segments[Number(net.dataset.segment)]; this.connection = null; this.selection = { net: net.dataset.net }; this.drag = { kind: 'route', segment, before: structuredClone(this.project), routeBefore: this.route, wiresBefore: this.project.wires, start: this.world(e), moved: false, invalid: false, frame: null, latest: null }; svg.dataset.dragging = 'route'; this.live(); return; }
        this.connection = null; this.selection = null; this.drag = e.shiftKey ? { kind: 'box', start: this.world(e), end: this.world(e) } : { kind: 'pan', x: e.clientX, y: e.clientY, view: { ...this.view } }; this.live();
    }
    pointerMove(e) {
        if (this.connection) { this.pointer = this.world(e); this.renderCanvas(); }
        const d = this.drag; if (!d || d.finishing) return;
        if (d.kind === 'library') { d.moved ||= Math.hypot(e.clientX - d.x, e.clientY - d.y) > 4; if (d.moved) { const ghost = this.$('[data-ghost]'); ghost.hidden = false; ghost.textContent = TYPES[d.type].name; ghost.style.left = `${e.clientX + 12}px`; ghost.style.top = `${e.clientY + 12}px`; } }
        else if (d.kind === 'node') { const p = this.world(e); d.moved ||= Math.hypot(p.x - d.start.x, p.y - d.start.y) > 3; if (d.moved) { d.latest = { x: d.x + p.x - d.start.x, y: d.y + p.y - d.start.y }; if (d.frame === null) d.frame = requestAnimationFrame(() => { d.frame = null; if (this.drag === d) this.moveDraggedNode(d); }); } }
        else if (d.kind === 'route') { const p = this.world(e); d.latest = d.segment.dir === 'h' ? p.y - d.start.y : p.x - d.start.x; d.moved ||= Math.abs(d.latest) > 3; if (d.moved && d.frame === null) d.frame = requestAnimationFrame(() => { d.frame = null; if (this.drag === d) this.moveDraggedWire(d); }); }
        else if (d.kind === 'point') { const p = this.world(e); d.latest = { x: d.point.x + p.x - d.start.x, y: d.point.y + p.y - d.start.y }; d.moved ||= Math.hypot(p.x - d.start.x, p.y - d.start.y) > 3; if (d.moved && d.frame === null) d.frame = requestAnimationFrame(() => { d.frame = null; if (this.drag === d) this.moveDraggedPoint(d); }); }
        else if (d.kind === 'group') { const p = this.world(e); d.latest = { x: p.x - d.start.x, y: p.y - d.start.y }; d.moved ||= Math.hypot(d.latest.x, d.latest.y) > 3; if (d.moved && d.frame === null) d.frame = requestAnimationFrame(() => { d.frame = null; if (this.drag === d) this.moveDraggedGroup(d); }); }
        else if (d.kind === 'box') { d.end = this.world(e); this.renderCanvas(); }
        else if (d.kind === 'note') { const p = this.world(e); d.moved ||= Math.hypot(p.x - d.start.x, p.y - d.start.y) > 3; if (d.moved) { d.note.x = Math.max(12, Math.min(WORLD.width - 216, Math.round((d.x + p.x - d.start.x) / GRID) * GRID)); d.note.y = Math.max(12, Math.min(WORLD.height - 180, Math.round((d.y + p.y - d.start.y) / GRID) * GRID)); this.renderCanvas(); } }
        else if (d.kind === 'pan') { this.view = { x: d.view.x - (e.clientX - d.x) / this.zoom, y: d.view.y - (e.clientY - d.y) / this.zoom }; this.renderCanvas(); }
        else if (d.kind === 'wire') d.moved ||= Math.hypot(e.clientX - d.x, e.clientY - d.y) > 4;
    }
    async pointerUp(e) {
        const d = this.drag; if (!d) return;
        d.finishing = true;
        if (['node', 'route', 'point', 'group'].includes(d.kind)) { if (d.frame !== null) cancelAnimationFrame(d.frame); if (d.moved) { await (d.kind === 'node' ? this.moveDraggedNode(d) : d.kind === 'group' ? this.moveDraggedGroup(d) : d.kind === 'point' ? this.moveDraggedPoint(d) : this.moveDraggedWire(d)); if (this.drag !== d) return; } }
        this.drag = null; delete this.$('[data-canvas]').dataset.dragging; this.$('[data-ghost]').hidden = true; this.$('[data-board]').classList.remove('ll-drag-invalid');
        if (d.kind === 'library') { this.suppressClick = true; setTimeout(() => this.suppressClick = false, 0); if (d.moved) { if (this.inside(e)) { const p = this.world(e); this.addNode(d.component ? this.tools.newComponentNode(d.component, p.x - 60, p.y - 36) : makeNode(d.type, p.x - 60, p.y - 36)); } } else this.addVisible(d.type, d.component); }
        else if (d.kind === 'box') { const left = Math.min(d.start.x, d.end.x), top = Math.min(d.start.y, d.end.y), right = Math.max(d.start.x, d.end.x), bottom = Math.max(d.start.y, d.end.y); this.selection = { nodes: this.project.nodes.filter(n => n.x >= left && n.y >= top && n.x + 120 <= right && n.y + height(n) <= bottom).map(n => n.id) }; this.live(); }
        else if (d.kind === 'note' && d.moved) { this.history.push(d.before); this.trimHistory(); this.future = []; this.changed(); }
        else if (d.kind === 'group') { if (d.moved) { if (d.invalid) { this.project = d.before; this.route = d.routeBefore; this.say('장치·배선 통로가 겹치는 위치여서 이동을 복원했습니다.', true); } else if (JSON.stringify(this.project.nodes) !== JSON.stringify(d.before.nodes)) { this.history.push(d.before); this.trimHistory(); this.future = []; this.changed(); } this.engine.rebind(this.project); } else if (d.toggle) this.toggle(d.toggle); }
        else if (d.kind === 'node' && d.moved) {
            if (d.invalid) { d.node.x = d.x; d.node.y = d.y; this.route = d.routeBefore; this.say('이 위치에는 장치 또는 배선 통로가 겹칩니다. 원래 위치로 되돌렸습니다.', true); }
            else if (d.node.x !== d.x || d.node.y !== d.y) { this.history.push(d.before); this.trimHistory(); this.future = []; }
        } else if ((d.kind === 'route' || d.kind === 'point') && d.moved) {
            if (d.invalid) { this.project.wires = d.wiresBefore; this.route = d.routeBefore; if (d.kind === 'point') this.selection = { net: d.net, point: d.point }; this.say('장치·다른 신호선과 겹치거나 통로가 없는 위치입니다. 이전 배선 경로로 복원했습니다.', true); }
            else if (JSON.stringify(this.project.wires) !== JSON.stringify(d.wiresBefore)) { this.history.push(d.before); this.trimHistory(); this.future = []; this.say('배선 경로를 조정했습니다. 회로 저장 시 함께 저장됩니다.'); }
            this.renderInspector();
        } else if (d.kind === 'node' && d.toggle) this.toggle(d.toggle);
        else if (d.kind === 'wire' && d.moved && this.connection) {
            const pin = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-pin]'); if (pin && this.root.contains(pin)) this.connect(this.connection, this.pinInfo(pin)); else { this.connection = null; this.say('핀 위에 놓아 연결하세요.'); }
        }
        if (['node', 'route', 'point'].includes(d.kind) && d.moved && !d.invalid) this.changed();
        this.$('[data-action="undo"]').disabled = !this.history.length; this.renderCanvas();
    }
    toggle(id) { const n = this.project.nodes.find(n => n.id === id); if (!n) return; this.checkpoint(); this.engine.setInput(id, this.engine.value(id) === 1 ? 0 : 1); this.selection = { node: id }; this.live(); this.changed(); }
    commitWireEdit(result, net, message) {
        if (!result) { this.say('장치·다른 신호선과 충돌하거나 편집할 수 없는 점입니다. 핀 앞·교차점을 피하고 경로 좌표 64개 제한을 확인하세요.', true); return false; }
        this.checkpoint(); this.project.wires = result.wires; this.route = result.routing; this.addingWaypoint = null; this.selection = { net, ...(result.point ? { point: result.point } : {}) }; this.$('[data-action="undo"]').disabled = false; this.live(); this.changed(); this.$('[data-canvas]').focus({ preventScroll: true }); this.say(message); return true;
    }
    moveDraggedNode(d) {
        if (!d.latest) return;
        const x = Math.round(d.latest.x / GRID) * GRID, y = Math.round(d.latest.y / GRID) * GRID, attempt = `${x}:${y}`;
        if (d.attempt === attempt) return; d.attempt = attempt;
        const preview = previewMove(this.project, d.node.id, x, y); d.invalid = !preview;
        if (preview) { d.node.x = preview.node.x; d.node.y = preview.node.y; this.route = preview.routing; }
        this.$('[data-board]').classList.toggle('ll-drag-invalid', d.invalid); this.renderCanvas();
    }
    moveDraggedWire(d) {
        const shift = Math.round(d.latest / GRID) * GRID; if (d.attempt === shift) return d.pending; d.attempt = shift;
        return this.planDrag(d, { kind: 'route', project: { ...this.project, wires: d.wiresBefore }, routing: d.routeBefore, segment: d.segment, delta: shift }, () => previewWireMove({ ...this.project, wires: d.wiresBefore }, d.routeBefore, d.segment, shift), result => { this.project.wires = result.wires; this.route = result.routing; });
    }
    moveDraggedPoint(d) {
        const position = { x: Math.round(d.latest.x / GRID) * GRID, y: Math.round(d.latest.y / GRID) * GRID }, attempt = `${position.x},${position.y}`; if (d.attempt === attempt) return d.pending; d.attempt = attempt;
        return this.planDrag(d, { kind: 'point', project: { ...this.project, wires: d.wiresBefore }, routing: d.routeBefore, net: d.net, point: d.point, position }, () => previewPointMove({ ...this.project, wires: d.wiresBefore }, d.routeBefore, d.net, d.point, position), result => { this.project.wires = result.wires; this.route = result.routing; this.selection = { net: d.net, point: result.point }; });
    }
    planDrag(d, payload, fallback, apply) { const revision = (d.revision || 0) + 1; d.revision = revision; const accept = result => { if (this.drag !== d || d.revision !== revision) return; d.invalid = !result; if (result) apply(result); this.$('[data-board]').classList.toggle('ll-drag-invalid', d.invalid); this.$('[data-board]').removeAttribute('aria-busy'); this.renderCanvas(); }; if (this.project.nodes.length > 40 || this.project.wires.length > 60) { try { this.dragPlanner ||= new DragPlanner(); this.$('[data-board]').setAttribute('aria-busy', 'true'); d.pending = this.dragPlanner.request(payload).then(result => accept(this.dragPlanner?.failed ? fallback() : result)); return d.pending; } catch {} } accept(fallback()); }
    moveDraggedGroup(d) { const x = Math.round(d.latest.x / GRID) * GRID, y = Math.round(d.latest.y / GRID) * GRID, attempt = `${x},${y}`; if (d.attempt === attempt) return d.pending; d.attempt = attempt; return this.planDrag(d, { kind: 'group', project: d.before, ids: d.ids, x, y }, () => moveGroup(d.before, d.ids, x, y), result => { this.project = result.project; this.route = result.routing; }); }
    cancel() { if (['node', 'route', 'point', 'group', 'note'].includes(this.drag?.kind)) { const d = this.drag; if (d.frame !== null && d.frame !== undefined) cancelAnimationFrame(d.frame); if (d.kind === 'node') { d.node.x = d.x; d.node.y = d.y; } else if (d.kind === 'group' || d.kind === 'note') { this.project = d.before; this.engine.rebind(this.project); } else { this.project.wires = d.wiresBefore; if (d.kind === 'point') this.selection = { net: d.net, point: d.point }; } if (d.routeBefore) this.route = d.routeBefore; } this.drag = null; this.addingWaypoint = null; delete this.$('[data-canvas]').dataset.dragging; this.connection = null; this.pointer = null; this.$('[data-ghost]').hidden = true; this.$('[data-board]').classList.remove('ll-drag-invalid'); this.$('[data-board]').removeAttribute('aria-busy'); this.renderCanvas(); this.renderInspector(); }
    keyboard(e) {
        if (e.target.matches('input,select,textarea') || e.target.closest('dialog')) return;
        if (e.key === 'Escape') { this.cancel(); return; }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); this.action(e.shiftKey ? 'redo' : 'undo'); return; }
        if ((e.ctrlKey || e.metaKey) && ['a', 'c', 'v'].includes(e.key.toLowerCase())) { e.preventDefault(); this.action({ a: 'selectall', c: 'copy', v: 'paste' }[e.key.toLowerCase()]); return; }
        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); this.action('delete'); }
        if (e.key === 'Enter' && e.target.closest('[data-route-point]')) { e.preventDefault(); const handle = e.target.closest('[data-route-point]'); this.selection = { net: handle.dataset.pointNet, point: { x: Number(handle.dataset.x), y: Number(handle.dataset.y) } }; this.live(); this.$('[data-canvas]').focus({ preventScroll: true }); return; }
        if (e.key === ' ' && !e.target.closest('button')) { e.preventDefault(); this.action(modeOf(this.project) === 'beginner' ? 'pulse' : 'run'); }
        if (e.key === 'Enter') { const pin = e.target.closest('[data-pin]'), toggle = e.target.closest('[data-toggle]'); if (pin) { e.preventDefault(); const info = this.pinInfo(pin); if (this.connection) this.connect(this.connection, info); else { this.connection = info; this.say('연결할 핀을 선택한 뒤 Enter를 누르세요.'); } } else if (toggle) this.toggle(toggle.dataset.toggle); }
        const delta = { ArrowLeft: [-GRID, 0], ArrowRight: [GRID, 0], ArrowUp: [0, -GRID], ArrowDown: [0, GRID] }[e.key], n = this.selectedNode();
        if (delta && this.selectedIds().length) { e.preventDefault(); const result = moveGroup(this.project, this.selectedIds(), delta[0], delta[1]); if (result) { this.checkpoint(); this.project = result.project; this.route = result.routing; this.engine.rebind(this.project); this.changed(); this.renderCanvas(); } }
    }
}
document.querySelectorAll('[data-logic-lab]').forEach(root => { if (!root.dataset.mounted) { root.dataset.mounted = 'true'; new LogicLab(root); } });

// All simulated times are integer nanoseconds. X = unknown, Z = high impedance.
export const GRID = 12;
export const WORLD = { width: 2400, height: 1440 };
const def = (name, group, inputs, outputs, description) => ({ name, group, inputs, outputs, description });
export const TYPES = {
    SWITCH: def('입력 스위치', '입력 · 출력', [], ['Q'], '0과 1을 직접 전환합니다.'),
    CLOCK: def('클록', '입력 · 출력', [], ['Q'], '주기·듀티비·위상을 설정하는 클록입니다.'),
    ZERO: def('상수 0', '입력 · 출력', [], ['Q'], '항상 0을 출력합니다.'),
    ONE: def('상수 1', '입력 · 출력', [], ['Q'], '항상 1을 출력합니다.'),
    LED: def('LED', '입력 · 출력', ['A'], [], '입력 신호를 표시합니다.'),
    PROBE: def('프로브', '입력 · 출력', ['A'], [], '파형에서 신호를 관찰합니다.'),
    AND: def('AND', '논리게이트', ['A', 'B'], ['Q'], '모든 입력이 1일 때 1'),
    OR: def('OR', '논리게이트', ['A', 'B'], ['Q'], '하나 이상의 입력이 1일 때 1'),
    NOT: def('NOT', '논리게이트', ['A'], ['Q'], '입력을 반전합니다.'),
    NAND: def('NAND', '논리게이트', ['A', 'B'], ['Q'], 'AND의 출력을 반전합니다.'),
    NOR: def('NOR', '논리게이트', ['A', 'B'], ['Q'], 'OR의 출력을 반전합니다.'),
    XOR: def('XOR', '논리게이트', ['A', 'B'], ['Q'], '입력의 1 개수가 홀수일 때 1'),
    XNOR: def('XNOR', '논리게이트', ['A', 'B'], ['Q'], 'XOR의 출력을 반전합니다.'),
    BUF: def('버퍼', '논리게이트', ['A'], ['Q'], '신호를 지연시켜 전달합니다.'),
    TRI: def('3상 버퍼', '논리게이트', ['A', 'EN'], ['Q'], 'EN=0이면 Z, EN=1이면 A'),
    MUX: def('2:1 MUX', '조합회로', ['A', 'B', 'S'], ['Q'], 'S=0이면 A, S=1이면 B'),
    DEMUX: def('1:2 DEMUX', '조합회로', ['A', 'S'], ['Y0', 'Y1'], '선택한 출력에 A를 전달합니다.'),
    HA: def('반가산기', '조합회로', ['A', 'B'], ['S', 'C'], '두 비트의 합과 자리올림'),
    FA: def('전가산기', '조합회로', ['A', 'B', 'CI'], ['S', 'CO'], '입력 자리올림을 포함하는 가산기'),
    DFF: def('D 플립플롭', '플립플롭 · 래치', ['D', 'CLK', 'RST'], ['Q', 'QB'], '지정한 클록 에지에서 D를 저장합니다.'),
    JKFF: def('JK 플립플롭', '플립플롭 · 래치', ['J', 'K', 'CLK', 'RST'], ['Q', 'QB'], '00 유지 · 01 리셋 · 10 세트 · 11 토글'),
    TFF: def('T 플립플롭', '플립플롭 · 래치', ['T', 'CLK', 'RST'], ['Q', 'QB'], 'T=1일 때 클록 에지마다 토글합니다.'),
    SRFF: def('SR 플립플롭', '플립플롭 · 래치', ['S', 'R', 'CLK', 'RST'], ['Q', 'QB'], 'S=R=1은 금지 입력으로 X가 됩니다.'),
    DLATCH: def('D 래치', '플립플롭 · 래치', ['D', 'EN', 'RST'], ['Q', 'QB'], 'EN=1일 때 투명하고 EN=0일 때 유지합니다.'),
    SRLATCH: def('SR 래치', '플립플롭 · 래치', ['S', 'R', 'RST'], ['Q', 'QB'], '활성 High SR 래치 · 11은 X'),
    BUS_IN: def('버스 입력', '버스 · 다중 비트', [], ['Q'], '2·4·8·16비트 값을 입력합니다.'),
    BUS_OUT: def('버스 출력', '버스 · 다중 비트', ['A'], [], '다중 비트 신호를 2진수와 16진수로 표시합니다.'),
    JOIN: def('버스 결합', '버스 · 다중 비트', [], ['Q'], 'B0가 최하위 비트입니다.'),
    SPLIT: def('버스 분리', '버스 · 다중 비트', ['A'], [], '버스를 비트별로 분리합니다. B0는 최하위 비트입니다.'),
    WADD: def('버스 가산기', '버스 · 다중 비트', ['A', 'B', 'CI'], ['Q', 'CO'], '다중 비트 덧셈과 자리올림'),
    WREG: def('버스 레지스터', '버스 · 다중 비트', ['D', 'CLK', 'RST'], ['Q', 'QB'], '다중 비트 D 레지스터'),
    CUSTOM: def('내 부품', '내 부품', [], [], '직접 저장한 회로 부품'),
};
export const isSequential = type => TYPES[type]?.group === '플립플롭 · 래치' || type === 'WREG';
export const isFF = type => type.endsWith('FF') || type === 'WREG';
export const WIDTH_TYPES = ['BUS_IN', 'BUS_OUT', 'JOIN', 'SPLIT', 'WADD', 'WREG', 'AND', 'OR', 'NOT', 'NAND', 'NOR', 'XOR', 'XNOR', 'BUF', 'TRI', 'MUX'];
export const nodeWidth = n => WIDTH_TYPES.includes(n.type) ? Number(n.config.width || 1) : 1;
export function spec(n) {
    if (n.type === 'CUSTOM') return { ...TYPES.CUSTOM, inputs: (n.config.ports?.inputs || []).map(p => p.name), outputs: (n.config.ports?.outputs || []).map(p => p.name) };
    if (n.type === 'JOIN') return { ...TYPES.JOIN, inputs: Array.from({ length: nodeWidth(n) }, (_, i) => `B${i}`) };
    if (n.type === 'SPLIT') return { ...TYPES.SPLIT, outputs: Array.from({ length: nodeWidth(n) }, (_, i) => `B${i}`) };
    return TYPES[n.type];
}
export function pinWidth(n, pin, output = false) {
    if (n.type === 'CUSTOM') return n.config.ports?.[output ? 'outputs' : 'inputs']?.find(p => p.name === pin)?.width || 1;
    if (['CLK', 'RST', 'EN', 'S', 'CI', 'CO'].includes(pin) || n.type === 'JOIN' && !output || n.type === 'SPLIT' && output) return 1;
    return nodeWidth(n);
}
export function signal(value, width = 1) {
    if (width === 1) return bit(value);
    if (typeof value === 'string' && /^[01XZ]+$/i.test(value) && value.length === width) return value.toUpperCase();
    if (value === 'X' || value === 'Z') return value.repeat(width);
    if (Number.isInteger(value) && value >= 0 && value < 2 ** width) return value.toString(2).padStart(width, '0');
    return 'X'.repeat(width);
}
export function parseSignal(text, width = 1) {
    const s = String(text).trim().toUpperCase();
    if (width === 1 && ['0', '1', 'X', 'Z'].includes(s)) return s === '0' ? 0 : s === '1' ? 1 : s;
    if (width > 1 && /^[01XZ]+$/.test(s) && s.length === width) return s;
    if (width > 1 && ['X', 'Z'].includes(s)) return s.repeat(width);
    const number = /^0X[0-9A-F]+$/.test(s) ? Number.parseInt(s.slice(2), 16) : /^0B[01]+$/.test(s) ? Number.parseInt(s.slice(2), 2) : /^\d+$/.test(s) ? Number(s) : NaN;
    if (!Number.isSafeInteger(number) || number < 0 || number >= 2 ** width) throw new Error(`${width}비트 범위의 2진수·10진수·0x16진수 또는 X/Z를 입력하세요.`);
    return width === 1 ? number : number.toString(2).padStart(width, '0');
}
export const formatSignal = (value, binary = false) => typeof value === 'string' && value.length > 1 && !binary && /^[01]+$/.test(value) ? `0x${Number.parseInt(value, 2).toString(16).toUpperCase().padStart(Math.ceil(value.length / 4), '0')}` : String(value);
export const signalClass = value => String(value).includes('X') ? 'X' : String(value).includes('Z') ? 'Z' : String(value).length > 1 ? 'bus' : String(value);
export const bit = v => [0, 1, 'X', 'Z'].includes(v) ? v : 'X';
export const inv = a => typeof a === 'string' && a.length > 1 ? [...a].map(v => v === '0' ? '1' : v === '1' ? '0' : 'X').join('') : a === 0 ? 1 : a === 1 ? 0 : 'X';
const and = values => values.includes(0) ? 0 : values.every(v => v === 1) ? 1 : 'X';
const or = values => values.includes(1) ? 1 : values.every(v => v === 0) ? 0 : 'X';
const xor = values => values.every(v => v === 0 || v === 1) ? values.reduce((a, b) => a ^ b, 0) : 'X';
export function combinational(type, v, width = 1) {
    const { A, B, S, CI, EN } = v;
    if (type === 'JOIN') return { Q: Array.from({ length: width }, (_, i) => bit(v[`B${width - i - 1}`])).join('') };
    if (type === 'SPLIT') return Object.fromEntries(Array.from({ length: width }, (_, i) => { const b = signal(A, width)[width - i - 1]; return [`B${i}`, b === '0' ? 0 : b === '1' ? 1 : b]; }));
    if (type === 'WADD') { const a = signal(A, width), b = signal(B, width); if (!/^[01]+$/.test(a + b) || ![0, 1].includes(CI)) return { Q: 'X'.repeat(width), CO: 'X' }; const sum = parseInt(a, 2) + parseInt(b, 2) + CI; return { Q: (sum % 2 ** width).toString(2).padStart(width, '0'), CO: sum >= 2 ** width ? 1 : 0 }; }
    if (width > 1 && WIDTH_TYPES.includes(type) && !['BUS_IN', 'BUS_OUT', 'WREG'].includes(type)) {
        const words = {};
        for (let i = 0; i < width; i++) {
            const scalar = Object.fromEntries(Object.entries(v).map(([p, value]) => [p, ['S', 'EN'].includes(p) ? value : signal(value, width)[i] === '0' ? 0 : signal(value, width)[i] === '1' ? 1 : signal(value, width)[i]]));
            const result = combinational(type, scalar, 1); if (!result) return null;
            for (const [p, value] of Object.entries(result)) words[p] = (words[p] || '') + value;
        }
        return words;
    }
    switch (type) {
    case 'AND': return { Q: and([A, B]) };
    case 'NAND': return { Q: inv(and([A, B])) };
    case 'OR': return { Q: or([A, B]) };
    case 'NOR': return { Q: inv(or([A, B])) };
    case 'XOR': return { Q: xor([A, B]) };
    case 'XNOR': return { Q: inv(xor([A, B])) };
    case 'NOT': return { Q: inv(A) };
    case 'BUF': return { Q: A === 'Z' ? 'X' : A };
    case 'TRI': return { Q: EN === 0 ? 'Z' : EN === 1 ? A : 'X' };
    case 'MUX': return { Q: S === 0 ? A : S === 1 ? B : A === B ? A : 'X' };
    case 'DEMUX': return { Y0: and([A, inv(S)]), Y1: and([A, S]) };
    case 'HA': return { S: xor([A, B]), C: and([A, B]) };
    case 'FA': return { S: xor([A, B, CI]), CO: or([and([A, B]), and([A, CI]), and([B, CI])]) };
    default: return null;
    }
}
export function makeNode(type, x, y, id = globalThis.crypto?.randomUUID?.() || `n${Date.now()}${Math.random()}`) {
    if (!Object.hasOwn(TYPES, type)) throw new Error('지원하지 않는 장치입니다.');
    return { id, type, x: Math.round(x / GRID) * GRID, y: Math.round(y / GRID) * GRID,
        label: TYPES[type].name, config: { delay: 2, initial: 0, value: 0, period: 40, duty: 50, phase: 0, edge: 'rising', setup: 1, hold: 1, width: TYPES[type].group === '버스 · 다중 비트' ? 4 : 1 } };
}
export const height = n => Math.max(72, (Math.max(spec(n).inputs.length, spec(n).outputs.length) + 1) * GRID * 2);
export const port = (n, name, output) => ({ x: n.x + (output ? 120 : 0), y: n.y + (spec(n)[output ? 'outputs' : 'inputs'].indexOf(name) + 1) * GRID * 2 });
export const key = (id, pin) => `${id}:${pin}`;
export function canPlace(nodes, n, except = n.id) {
    return n.x >= 36 && n.y >= 36 && n.x + 120 <= WORLD.width - 36 && n.y + height(n) <= WORLD.height - 36
        && nodes.every(o => o.id === except || n.x + 144 <= o.x || o.x + 144 <= n.x || n.y + height(n) + 24 <= o.y || o.y + height(o) + 24 <= n.y);
}
export function validateProject(raw) {
    if (raw?.schema > 1 || raw?.formatVersion > 1) throw new Error('더 새로운 회로 파일 형식입니다. 이 파일을 지원하는 버전에서 여세요. 현재 회로는 유지합니다.');
    const defs = raw?.components || [];
    if (!Array.isArray(defs) || defs.length > 24) throw new Error('내 부품은 최대 24종입니다.');
    const idOK = id => typeof id === 'string' && /^[\w-]{1,80}$/.test(id);
    if (defs.some((d, i) => !d || !idOK(d.id) || defs.findIndex(o => o?.id === d.id) !== i)) throw new Error('부품 ID가 중복되거나 올바르지 않습니다.');
    const readPorts = (ports, output) => {
        if (!Array.isArray(ports) || ports.length > 16) throw new Error('부품의 입력·출력은 각각 최대 16개입니다.');
        return ports.map((p, i) => { if (!p || p.name !== `${output ? 'O' : 'I'}${i}` || !idOK(p.node) || ![1, 2, 4, 8, 16].includes(p.width)) throw new Error('부품 포트가 올바르지 않습니다.'); return { name: p.name, node: p.node, label: String(p.label || p.name).slice(0, 20), width: p.width, clock: !output && !!p.clock }; });
    };
    const portsById = new Map(defs.map(d => [d.id, { inputs: readPorts(d.inputs, false), outputs: readPorts(d.outputs, true) }]));
    const readCircuit = raw => {
    if (!raw || raw.schema !== 1 || !Array.isArray(raw.nodes) || !Array.isArray(raw.wires) || raw.nodes.length > 100 || raw.wires.length > 240) throw new Error('회로 형식이 잘못되었거나 장치 100개·배선 240개 제한을 넘었습니다.');
    const nodes = [], ids = new Set(), occupied = new Set();
    for (const rawNode of raw.nodes) {
        if (!rawNode || typeof rawNode.id !== 'string' || !/^[\w-]{1,80}$/.test(rawNode.id) || ids.has(rawNode.id)
            || !Object.hasOwn(TYPES, rawNode.type) || !Number.isFinite(rawNode.x) || !Number.isFinite(rawNode.y)) throw new Error('장치 정보가 올바르지 않습니다.');
        const n = makeNode(rawNode.type, rawNode.x, rawNode.y, rawNode.id);
        n.label = String(rawNode.label ?? TYPES[n.type].name).slice(0, 40);
        const c = rawNode.config || {};
        for (const k of ['delay', 'setup', 'hold', 'phase']) n.config[k] = Math.max(0, Math.min(1000000, Math.round(Number(c[k] ?? n.config[k]) || 0)));
        n.config.period = Math.max(2, Math.min(1000000, Math.round(Number(c.period) || 40)));
        n.config.duty = Math.max(1, Math.min(99, Math.round(Number(c.duty) || 50)));
        n.config.edge = c.edge === 'falling' ? 'falling' : 'rising';
        n.config.width = WIDTH_TYPES.includes(n.type) && (TYPES[n.type].group === '버스 · 다중 비트' ? [2, 4, 8, 16] : [1, 2, 4, 8, 16]).includes(c.width) ? c.width : n.config.width;
        n.config.initial = signal(c.initial ?? 0, n.type === 'WREG' ? nodeWidth(n) : 1); n.config.value = signal(c.value ?? 0, n.type === 'BUS_IN' ? nodeWidth(n) : 1);
        if (n.type === 'CUSTOM') { if (!portsById.has(c.componentId)) throw new Error('참조한 내 부품이 없습니다.'); n.config.componentId = c.componentId; n.config.ports = structuredClone(portsById.get(c.componentId)); }
        if (!canPlace(nodes, n)) throw new Error('장치가 겹치거나 작업 영역을 벗어났습니다.');
        nodes.push(n); ids.add(n.id);
    }
    const wires = raw.wires.map((w, i) => {
        const from = nodes.find(n => n.id === w?.from?.node), to = nodes.find(n => n.id === w?.to?.node);
        if (!from || !to || !spec(from).outputs.includes(w.from.pin) || !spec(to).inputs.includes(w.to.pin)
            || occupied.has(key(to.id, w.to.pin))) throw new Error('배선은 출력 → 입력이어야 하며 입력에는 한 출력만 연결할 수 있습니다.');
        occupied.add(key(to.id, w.to.pin));
        const result = { id: `w${i}`, from: { node: from.id, pin: w.from.pin }, to: { node: to.id, pin: w.to.pin } };
        if (w.path !== undefined) {
            if (!Array.isArray(w.path) || w.path.length < 2 || w.path.length > 64 || w.path.some((p, j) => !Number.isInteger(p?.x) || !Number.isInteger(p?.y) || p.x % GRID || p.y % GRID || p.x < GRID || p.y < GRID || p.x > WORLD.width - GRID || p.y > WORLD.height - GRID || j > 0 && (p.x !== w.path[j - 1].x && p.y !== w.path[j - 1].y || p.x === w.path[j - 1].x && p.y === w.path[j - 1].y))) throw new Error('수동 배선 경로는 작업 영역 안의 직각 그리드 좌표 2~64개여야 합니다.');
            result.path = w.path.map(p => ({ x: p.x, y: p.y, ...(p.waypoint === true ? { waypoint: true } : {}) }));
        }
        return result;
    });
    return { nodes, wires };
    };
    const components = defs.map(d => {
        const graph = readCircuit({ ...d, schema: 1 }), ports = portsById.get(d.id);
        if (!ports.outputs.length) throw new Error('부품에는 출력 포트가 필요합니다.');
        for (const output of [false, true]) for (const p of ports[output ? 'outputs' : 'inputs']) {
            const n = graph.nodes.find(n => n.id === p.node);
            if (!n || !(output ? ['LED', 'PROBE', 'BUS_OUT'] : ['SWITCH', 'CLOCK', 'BUS_IN']).includes(n.type) || pinWidth(n, output ? 'A' : 'Q', !output) !== p.width) throw new Error('부품 포트와 내부 장치가 일치하지 않습니다.');
        }
        if (new Set(ports.inputs.map(p => p.node)).size !== ports.inputs.length || new Set(ports.outputs.map(p => p.node)).size !== ports.outputs.length) throw new Error('부품 포트가 중복되었습니다.');
        return { id: d.id, name: String(d.name || '내 부품').slice(0, 40), ...graph, ...ports };
    });
    const cost = (nodes, stack = []) => nodes.reduce((total, n) => {
        if (n.type !== 'CUSTOM') return total + 1;
        if (stack.includes(n.config.componentId) || stack.length >= 4) throw new Error('부품은 자기 자신을 참조할 수 없고 중첩은 최대 4단계입니다.');
        const d = components.find(d => d.id === n.config.componentId), count = total + cost(d.nodes, [...stack, d.id]);
        if (count > 600) throw new Error('펼친 회로는 최대 600개 장치입니다.'); return count;
    }, 0);
    for (const d of components) cost(d.nodes, [d.id]);
    const graph = readCircuit(raw); if (cost(graph.nodes) > 600) throw new Error('펼친 회로는 최대 600개 장치입니다.');
    const wireCost = graph => { let total = graph.wires.length; for (const n of graph.nodes) if (n.type === 'CUSTOM') { total += wireCost(components.find(d => d.id === n.config.componentId)); if (total > 1800) throw new Error('펼친 회로는 최대 1,800개 연결입니다.'); } return total; };
    for (const d of components) wireCost(d); wireCost(graph);
    const stimuli = raw.stimuli || [], times = new Set();
    if (!Array.isArray(stimuli) || stimuli.length > 256) throw new Error('입력 시나리오는 최대 256개입니다.');
    const scheduled = stimuli.map((s, i) => {
        const n = graph.nodes.find(n => n.id === s?.node), k = `${s?.time}:${s?.node}`;
        if (!n || !['SWITCH', 'BUS_IN'].includes(n.type) || !Number.isInteger(s.time) || s.time < 0 || s.time > 1000000 || times.has(k)) throw new Error('시나리오의 시각·입력이 잘못되었거나 같은 시각에 입력이 중복되었습니다.');
        times.add(k); return { id: `s${i}`, time: s.time, node: n.id, value: parseSignal(s.value, pinWidth(n, 'Q', true)) };
    });
    const c = raw.settings || {}, names = raw.netNames || {}, notes = raw.notes || [];
    if (!names || typeof names !== 'object' || Array.isArray(names) || Object.keys(names).length > 240 || !Array.isArray(notes) || notes.length > 100) throw new Error('신호 이름 또는 주석 형식이 올바르지 않습니다.');
    const outputKeys = new Set(graph.nodes.flatMap(n => spec(n).outputs.map(p => key(n.id, p))));
    const netNames = Object.fromEntries(Object.entries(names).filter(([k]) => outputKeys.has(k)).map(([k, v]) => [k, String(v).slice(0, 40)]));
    const noteIds = new Set(), annotations = notes.map((n, i) => { if (!n || typeof n.text !== 'string' || !Number.isFinite(n.x) || !Number.isFinite(n.y)) throw new Error('주석 좌표와 내용이 올바르지 않습니다.'); let id = n.id, suffix = i; if (!idOK(id) || noteIds.has(id)) { do { id = `note${suffix++}`; } while (noteIds.has(id)); } noteIds.add(id); return { id, text: n.text.slice(0, 300), x: Math.max(12, Math.min(WORLD.width - 216, Math.round(n.x / GRID) * GRID)), y: Math.max(12, Math.min(WORLD.height - 180, Math.round(n.y / GRID) * GRID)) }; });
    const wave = c.wave || {}, signals = wave.signals === null || wave.signals === undefined ? null : Array.isArray(wave.signals) ? [...new Set(wave.signals)].filter(k => graph.nodes.some(n => (spec(n).outputs.length ? spec(n).outputs : spec(n).inputs).some(p => key(n.id, p) === k))).slice(0, 600) : null;
    return { schema: 1, formatVersion: 1, title: String(raw.title || '새 회로').slice(0, 80), ...graph, components, stimuli: scheduled, netNames, notes: annotations,
        exercise: raw.exercise && ['and', 'xor', 'half', 'full', 'dff', 'counter'].includes(raw.exercise.id) ? { id: raw.exercise.id, attempts: Math.max(0, Math.min(9999, Number(raw.exercise.attempts) || 0)), solved: !!raw.exercise.solved } : null,
        settings: { mode: c.mode === 'beginner' ? 'beginner' : 'advanced', step: Math.max(1, Math.min(100000, Math.round(Number(c.step) || 10))), speed: Math.max(1, Math.min(10000, Math.round(Number(c.speed) || 20))), wave: { span: Math.max(10, Math.min(1000000, Math.round(Number(wave.span) || 400))), start: wave.start === null || wave.start === undefined ? null : Math.max(0, Math.min(Number.MAX_SAFE_INTEGER - 1000000, Math.round(Number(wave.start) || 0))), signals } } };
}
export const emptyProject = () => ({ schema: 1, formatVersion: 1, title: '새 회로', nodes: [], wires: [], components: [], stimuli: [], netNames: {}, notes: [], exercise: null, settings: { mode: 'beginner', step: 10, speed: 20, wave: { span: 400, start: null, signals: null } } });

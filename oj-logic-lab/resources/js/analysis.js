import { compileProject } from './compile.js?v=1.0.2';
import { BeginnerEngine } from './modes.js?v=1.0.2';
import { spec, pinWidth, key, isSequential, signal, formatSignal } from './model.js?v=1.0.2';

export function feedbackNodes(project) {
    const graph = new Map(project.nodes.map(n => [n.id, []])), sequential = new Set(project.nodes.filter(n => isSequential(n.type)).map(n => n.id));
    for (const w of project.wires) if (!sequential.has(w.to.node)) graph.get(w.from.node)?.push(w.to.node);
    const visited = new Set(), path = [], onPath = new Set(), cycles = new Set();
    const walk = id => {
        if (onPath.has(id)) { for (const node of path.slice(path.indexOf(id))) cycles.add(node); return; }
        if (visited.has(id)) return;
        visited.add(id); path.push(id); onPath.add(id);
        for (const next of graph.get(id) || []) walk(next);
        path.pop(); onPath.delete(id);
    };
    for (const n of project.nodes) walk(n.id);
    return cycles;
}

export function diagnose(project, engine, routing = null) {
    const result = [], add = (code, node, pin, message, fix, severity = 'warning', detail = '') => result.push({ id: `${code}:${detail || node}:${pin}`, code, node, pin, message, fix, severity });
    const { project: flat, owners, aliases } = compileProject(project), connected = new Set(flat.wires.map(w => key(w.to.node, w.to.pin)));
    const exposedPin = (id, pin) => { const top = owners.get(id); if (top === id) return pin; const prefix = `${top}:false:`; const entry = [...aliases].find(([k, p]) => k.startsWith(prefix) && p.node === id && p.pin === pin); return entry ? entry[0].slice(prefix.length) : ''; };
    const nodes = new Map(flat.nodes.map(n => [n.id, n]));
    for (const n of flat.nodes) {
        for (const pin of spec(n).inputs) if (pin !== 'RST' && !connected.has(key(n.id, pin))) {
            const top = owners.get(n.id), exposed = exposedPin(n.id, pin), outer = project.nodes.find(o => o.id === top), descriptor = outer?.config.ports?.inputs.find(p => p.name === exposed), control = pin === 'CLK' || descriptor?.clock;
            add(control ? 'clock' : 'missing', top, exposed, descriptor ? `${outer.label}의 ${descriptor.label} 입력이 연결되지 않았습니다.` : `${n.label}의 ${pin} 입력이 연결되지 않았습니다.`, control ? '클록 장치의 Q를 클록 입력에 연결하세요.' : '스위치·상수 또는 다른 장치의 출력을 이 입력에 연결하세요.', 'warning', `${n.id}:${pin}`);
        }
        if (['SRFF', 'SRLATCH'].includes(n.type) && engine?.input(n.id, 'S') === 1 && engine?.input(n.id, 'R') === 1) add('sr', owners.get(n.id), '', `${n.label}: S=R=1은 금지 입력입니다.`, 'S와 R 중 하나 이상을 0으로 바꾸세요.', 'error');
    }
    for (const w of flat.wires) {
        const a = nodes.get(w.from.node), b = nodes.get(w.to.node), x = pinWidth(a, w.from.pin, true), y = pinWidth(b, w.to.pin, false);
        if (x !== y) add('width', owners.get(b.id), owners.get(b.id) === b.id ? w.to.pin : '', `${a.label} ${x}비트 → ${b.label} ${y}비트: 폭이 다릅니다.`, '비트 수를 같게 맞추거나 버스 분리·결합 장치를 사용하세요.', 'error');
    }
    for (const id of feedbackNodes(flat)) add('feedback', owners.get(id), '', `${nodes.get(id).label}: 조합회로 피드백이 있습니다.`, '플립플롭·래치로 상태를 저장하거나 순환 연결을 끊으세요.');
    for (const id of routing?.failures || []) { const w = project.wires.find(w => w.id === id); if (w) add('route', w.to.node, w.to.pin, '자동 배선 통로가 없습니다.', '장치 사이 간격을 넓히세요.', 'error'); }
    for (const warning of engine?.warnings?.slice(-10) || []) add(warning.kind, warning.node, '', `${warning.label}: ${warning.time} ns에 ${warning.kind} 시간 위반`, '데이터 변경 시각, 클록 에지, setup/hold 설정을 확인하세요.', 'error');
    return [...new Map(result.map(issue => [issue.id, issue])).values()];
}

export function truthTable(source) {
    const project = structuredClone(source); project.stimuli = [];
    const compiled = compileProject(project).project;
    if (feedbackNodes(compiled).size) throw new Error('조합 피드백을 해소한 뒤 표를 생성하세요.');
    const inputs = project.nodes.filter(n => ['SWITCH', 'BUS_IN'].includes(n.type));
    const states = compiled.nodes.filter(n => isSequential(n.type));
    const inputBits = inputs.reduce((a, n) => a + pinWidth(n, 'Q', true), 0), stateBits = states.reduce((a, n) => a + pinWidth(n, 'Q', true), 0);
    if (inputBits + stateBits > 8) throw new Error('자동 표는 입력 비트 + 상태 비트 합계 8개(최대 256행)까지 지원합니다. 회로를 부품으로 나눠 검사하세요.');
    if (states.some(n => n.type.endsWith('FF') || n.type === 'WREG') && !compiled.nodes.some(n => n.type === 'CLOCK')) throw new Error('상태 전이표에는 클록 장치를 연결하세요. CLK를 직접 스위치로 조작하는 회로는 클록 장치로 바꾼 사본에서 검사하세요.');
    let outputs = project.nodes.filter(n => ['LED', 'PROBE', 'BUS_OUT'].includes(n.type)).map(n => ({ node: n.id, pin: 'A', output: false, label: n.label }));
    if (!outputs.length) outputs = project.nodes.flatMap(n => spec(n).outputs.filter(p => !project.wires.some(w => w.from.node === n.id && w.from.pin === p)).map(p => ({ node: n.id, pin: p, output: true, label: `${n.label}.${p}` })));
    if (!outputs.length) throw new Error('관찰할 LED·프로브·버스 출력 또는 연결되지 않은 출력 핀이 필요합니다.');
    const rows = [];
    const assignments = (items, number) => { let offset = 0; return Object.fromEntries([...items].reverse().map(n => { const width = pinWidth(n, 'Q', true), v = signal(Math.floor(number / 2 ** offset) % 2 ** width, width); offset += width; return [n.id, v]; }).reverse()); };
    for (let state = 0; state < 2 ** stateBits; state++) for (let input = 0; input < 2 ** inputBits; input++) {
        const values = assignments(inputs, input), initial = assignments(states, state), copy = structuredClone(project);
        for (const n of copy.nodes) if (Object.hasOwn(values, n.id)) n.config.value = values[n.id];
        const engine = new BeginnerEngine(copy, { initialState: initial });
        if (states.length && engine.clockIds.length) engine.pulse();
        if (engine.fault) throw new Error(engine.fault);
        rows.push({ values, initial, next: Object.fromEntries(states.map(n => [n.id, engine.value(n.id)])), outputs: outputs.map(o => o.output ? engine.value(o.node, o.pin) : engine.input(o.node, o.pin)) });
    }
    return { kind: states.length ? 'state' : 'combinational', inputs: inputs.map(n => ({ id: n.id, label: n.label, width: pinWidth(n, 'Q', true) })), states: states.map(n => ({ id: n.id, label: n.label })), outputs, rows };
}

export function tableCSV(table) {
    const cell = v => `"${String(v).replaceAll('"', '""')}"`;
    const header = [...table.inputs.map(n => n.label), ...table.states.map(n => `${n.label} current Q`), ...table.states.map(n => `${n.label} next Q`), ...table.outputs.map(n => n.label)];
    return [header, ...table.rows.map(r => [...table.inputs.map(n => r.values[n.id]), ...table.states.map(n => r.initial[n.id]), ...table.states.map(n => r.next[n.id]), ...r.outputs])].map(row => row.map(cell).join(',')).join('\r\n');
}

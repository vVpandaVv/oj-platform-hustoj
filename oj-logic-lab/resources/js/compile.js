import { spec, pinWidth, key } from './model.js?v=1.0.2';

export function compileProject(source) {
    const nodes = [], wires = [], aliases = new Map(), owners = new Map();
    const definitions = new Map((source.components || []).map(d => [d.id, d]));
    const expand = (graph, prefix = '', stack = [], owner = '', labelPrefix = '') => {
        const endpoints = new Map();
        for (const n of graph.nodes) {
            const id = prefix + n.id, top = owner || n.id;
            if (n.type !== 'CUSTOM') {
                const copy = prefix ? { ...structuredClone(n), id, label: `${labelPrefix}/${n.label}` } : n;
                nodes.push(copy); owners.set(id, top);
                if (nodes.length > 600) throw new Error('펼친 회로는 최대 600개 장치입니다.');
                for (const output of [false, true]) for (const pin of spec(n)[output ? 'outputs' : 'inputs']) endpoints.set(`${n.id}:${output}:${pin}`, { node: id, pin });
                continue;
            }
            const d = definitions.get(n.config.componentId);
            if (!d || stack.includes(d.id) || stack.length >= 4) throw new Error('내 부품 참조 또는 중첩 깊이가 올바르지 않습니다.');
            const inner = expand(d, `${prefix}@${n.id.length}_${n.id}@`, [...stack, d.id], top, labelPrefix ? `${labelPrefix}/${n.label}` : n.label);
            for (const output of [false, true]) for (const p of d[output ? 'outputs' : 'inputs']) {
                const endpoint = inner.get(`${p.node}:${output ? false : true}:${output ? 'A' : 'Q'}`);
                const internal = nodes.find(n => n.id === endpoint.node);
                internal.type = 'BUF'; internal.config = { ...internal.config, delay: 0, width: p.width };
                const exposed = { node: internal.id, pin: output ? 'Q' : 'A' };
                endpoints.set(`${n.id}:${output}:${p.name}`, exposed);
                aliases.set(`${id}:${output}:${p.name}`, exposed);
            }
        }
        for (const w of graph.wires) {
            const from = endpoints.get(`${w.from.node}:true:${w.from.pin}`), to = endpoints.get(`${w.to.node}:false:${w.to.pin}`);
            if (!from || !to) throw new Error('내부 배선이 없는 포트를 참조합니다.');
            wires.push({ id: prefix + w.id, from, to });
            if (wires.length > 1800) throw new Error('펼친 회로는 최대 1,800개 연결입니다.');
        }
        return endpoints;
    };
    expand(source);
    // A custom output was a sink inside its definition. It is now a buffer;
    // its original incoming wire remains attached to A and its Q is exposed.
    return { project: { ...source, nodes, wires }, aliases, owners };
}

export function makeComponent(source, name, chosen = null) {
    if (!source.nodes.length) throw new Error('저장할 회로가 없습니다.');
    const ports = (output) => source.nodes.filter(n => (output ? ['LED', 'PROBE', 'BUS_OUT'] : ['SWITCH', 'BUS_IN', 'CLOCK']).includes(n.type))
        .filter(n => !chosen || chosen[output ? 'outputs' : 'inputs'].some(p => p.node === n.id))
        .map((n, i) => ({ name: `${output ? 'O' : 'I'}${i}`, node: n.id, label: chosen?.[output ? 'outputs' : 'inputs'].find(p => p.node === n.id)?.label || n.label, width: pinWidth(n, output ? 'A' : 'Q', !output), clock: !output && n.type === 'CLOCK' }));
    const inputs = ports(false), outputs = ports(true);
    if (!outputs.length || inputs.length > 16 || outputs.length > 16) throw new Error('입력·출력을 각각 최대 16개 지정하고 LED·프로브·버스 출력 중 한 개 이상을 출력으로 지정하세요.');
    return { id: `c${crypto.randomUUID()}`, name: String(name || source.title || '내 부품').slice(0, 40), nodes: structuredClone(source.nodes), wires: structuredClone(source.wires), inputs, outputs };
}

export function componentNode(definition, node) {
    node.type = 'CUSTOM'; node.label = definition.name;
    node.config.componentId = definition.id;
    node.config.ports = { inputs: structuredClone(definition.inputs), outputs: structuredClone(definition.outputs) };
    return node;
}

import { GRID, canPlace, validateProject, key } from './model.js?v=1.0.2';
import { routeCircuit } from './router.js?v=1.0.2';
export function moveGroup(project, ids, dx, dy) {
    dx = Math.round(dx / GRID) * GRID; dy = Math.round(dy / GRID) * GRID; const selected = new Set(ids);
    const nodes = project.nodes.map(n => selected.has(n.id) ? { ...n, x: n.x + dx, y: n.y + dy } : n);
    if (nodes.some(n => selected.has(n.id) && !canPlace(nodes, n))) return null;
    const wires = project.wires.map(w => w.path && selected.has(w.from.node) && selected.has(w.to.node) ? { ...w, path: w.path.map(p => ({ ...p, x: p.x + dx, y: p.y + dy })) } : w);
    const candidate = { ...project, nodes, wires }, routing = routeCircuit(candidate); return routing.failures.length ? null : { project: candidate, routing };
}
export function alignGroup(project, ids, mode) {
    const selected = project.nodes.filter(n => ids.includes(n.id)); if (selected.length < 2) return null;
    const left = Math.min(...selected.map(n => n.x)), top = Math.min(...selected.map(n => n.y));
    const center = Math.round(selected.reduce((v, n) => v + n.x, 0) / selected.length / GRID) * GRID;
    const nodes = project.nodes.map(n => ids.includes(n.id) ? { ...n, ...(mode === 'left' ? { x: left } : mode === 'center' ? { x: center } : { y: top }) } : n);
    if (nodes.some(n => ids.includes(n.id) && !canPlace(nodes, n))) return null;
    // Manual spines stay anchored; invalid alignment is refused atomically.
    const candidate = { ...project, nodes }, routing = routeCircuit(candidate); return routing.failures.length ? null : { project: candidate, routing };
}
export function copyGroup(project, ids) {
    const selected = new Set(ids); return structuredClone({ nodes: project.nodes.filter(n => selected.has(n.id)), wires: project.wires.filter(w => selected.has(w.from.node) && selected.has(w.to.node)), components: project.components || [], netNames: Object.fromEntries(Object.entries(project.netNames || {}).filter(([k]) => ids.some(id => k.startsWith(id + ':')))), stimuli: (project.stimuli || []).filter(s => selected.has(s.node)) });
}
export function pasteGroup(project, clipboard, dx = 36, dy = 36) {
    if (!clipboard?.nodes.length || project.nodes.length + clipboard.nodes.length > 100 || project.wires.length + clipboard.wires.length > 240) return null;
    const ids = new Map(clipboard.nodes.map(n => [n.id, `n${crypto.randomUUID()}`]));
    const definitions = new Map((project.components || []).map(d => [d.id, d]));
    const include = id => { const d = clipboard.components.find(d => d.id === id); if (!d) throw new Error('복사한 부품 정의가 없습니다.'); if (definitions.has(id)) { if (JSON.stringify(definitions.get(id)) !== JSON.stringify(d)) throw new Error('같은 ID의 부품 정의가 달라 붙여넣을 수 없습니다.'); return; } definitions.set(id, d); for (const n of d.nodes) if (n.type === 'CUSTOM') include(n.config.componentId); };
    for (const n of clipboard.nodes) if (n.type === 'CUSTOM') include(n.config.componentId);
    for (let attempt = 0; attempt < 60; attempt++) {
        const x = Math.round(dx / GRID) * GRID + (attempt % 10) * GRID * 3, y = Math.round(dy / GRID) * GRID + Math.floor(attempt / 10) * GRID * 3;
        const nodes = clipboard.nodes.map(n => ({ ...structuredClone(n), id: ids.get(n.id), x: n.x + x, y: n.y + y }));
        const combined = [...project.nodes, ...nodes]; if (nodes.some(n => !canPlace(combined, n))) continue;
        const wires = clipboard.wires.map(w => ({ ...structuredClone(w), id: `w${crypto.randomUUID()}`, from: { ...w.from, node: ids.get(w.from.node) }, to: { ...w.to, node: ids.get(w.to.node) }, ...(w.path ? { path: w.path.map(p => ({ ...p, x: p.x + x, y: p.y + y })) } : {}) }));
        const netNames = { ...(project.netNames || {}) }; for (const w of clipboard.nodes) for (const [k, name] of Object.entries(clipboard.netNames || {})) if (k.startsWith(w.id + ':')) netNames[key(ids.get(w.id), k.slice(w.id.length + 1))] = name;
        const candidate = { ...project, nodes: combined, wires: [...project.wires, ...wires], components: [...definitions.values()], netNames, stimuli: [...(project.stimuli || []), ...clipboard.stimuli.map(s => ({ ...s, id: `s${crypto.randomUUID()}`, node: ids.get(s.node) }))] };
        validateProject(candidate); const routing = routeCircuit(candidate); if (!routing.failures.length) return { project: candidate, routing, ids: [...ids.values()] };
    }
    return null;
}

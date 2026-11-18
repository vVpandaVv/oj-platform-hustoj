import { GRID, WORLD, port, height, key } from './model.js?v=1.0.2';
const pointKey = p => `${p.x},${p.y}`;
const edgeKey = (a, b) => a.x < b.x || (a.x === b.x && a.y < b.y) ? `${pointKey(a)}|${pointKey(b)}` : `${pointKey(b)}|${pointKey(a)}`;
const axis = (a, b) => a.y === b.y ? 'h' : 'v';
class Heap {
    constructor() { this.a = []; }
    push(v) { let i = this.a.length; this.a.push(v); while (i > 0) { const p = (i - 1) >> 1; if (this.a[p].f <= v.f) break; this.a[i] = this.a[p]; i = p; } this.a[i] = v; }
    pop() { const first = this.a[0], last = this.a.pop(); if (this.a.length) { let i = 0; while (i * 2 + 1 < this.a.length) { let c = i * 2 + 1; if (c + 1 < this.a.length && this.a[c + 1].f < this.a[c].f) c++; if (this.a[c].f >= last.f) break; this.a[i] = this.a[c]; i = c; } this.a[i] = last; } return first; }
}
export function routeCircuit(project) {
    const edgeOwners = new Map(), vertices = new Map(), routes = [], failures = [];
    const nodes = new Map(project.nodes.map(n => [n.id, n]));
    const blocked = new Set();
    for (const n of project.nodes) {
        for (let x = n.x; x <= n.x + 120; x += GRID) for (let y = n.y; y <= n.y + height(n); y += GRID) blocked.add(`${x},${y}`);
    }
    const mark = (a, b, net) => {
        edgeOwners.set(edgeKey(a, b), net);
        const direction = axis(a, b);
        for (const p of [a, b]) {
            const k = pointKey(p); if (!vertices.has(k)) vertices.set(k, new Map());
            const nets = vertices.get(k); if (!nets.has(net)) nets.set(net, new Set());
            nets.get(net).add(direction);
        }
    };
    // Reserve every terminal stub first. Other nets may not occupy a terminal.
    const terminals = new Map();
    for (const w of project.wires) {
        const net = key(w.from.node, w.from.pin), a = port(nodes.get(w.from.node), w.from.pin, true), b = port(nodes.get(w.to.node), w.to.pin, false);
        const start = { x: a.x + GRID, y: a.y }, end = { x: b.x - GRID, y: b.y };
        for (const p of [a, b, start, end]) terminals.set(pointKey(p), net);
        mark(a, start, net); mark(end, b, net);
    }
    // Manual spines are reserved before automatic tails. They must obey the
    // same edge ownership and straight perpendicular crossing rules as A*.
    // Explicit waypoints are also protected from foreign crossings so their
    // editing handles cannot be mistaken for electrical junctions.
    for (const w of project.wires) for (const p of (w.path || []).filter(p => p.waypoint)) {
        const net = key(w.from.node, w.from.pin), k = pointKey(p);
        if (terminals.has(k) && terminals.get(k) !== net) { if (!failures.includes(w.id)) failures.push(w.id); }
        else terminals.set(k, net);
    }
    const spines = new Map();
    for (const w of project.wires.filter(w => w.path?.length)) {
        if (failures.includes(w.id)) continue;
        const net = key(w.from.node, w.from.pin), points = [], ownAxes = new Map();
        let valid = Array.isArray(w.path) && w.path.length <= 64;
        for (const p of w.path) if (!Number.isInteger(p?.x) || !Number.isInteger(p?.y) || p.x % GRID || p.y % GRID || p.x < GRID || p.y < GRID || p.x > WORLD.width - GRID || p.y > WORLD.height - GRID || blocked.has(pointKey(p))) valid = false;
        for (let i = 1; valid && i < w.path.length; i++) {
            const a = w.path[i - 1], b = w.path[i];
            if (a.x !== b.x && a.y !== b.y || a.x === b.x && a.y === b.y) { valid = false; break; }
            const dx = Math.sign(b.x - a.x) * GRID, dy = Math.sign(b.y - a.y) * GRID;
            if (!points.length) points.push(a);
            for (let p = a; p.x !== b.x || p.y !== b.y;) {
                const next = { x: p.x + dx, y: p.y + dy }, dir = axis(p, next), owner = edgeOwners.get(edgeKey(p, next));
                if (owner && owner !== net) valid = false;
                for (const v of [p, next]) {
                    const pk = pointKey(v), foreign = vertices.get(pk);
                    if (blocked.has(pk) || terminals.has(pk) && terminals.get(pk) !== net || foreign && [...foreign].some(([n, axes]) => n !== net && axes.has(dir))) valid = false;
                    if (!ownAxes.has(pk)) ownAxes.set(pk, new Set()); ownAxes.get(pk).add(dir);
                }
                points.push(next); p = next;
            }
        }
        for (const [pk, axes] of ownAxes) if (axes.size > 1 && vertices.has(pk) && [...vertices.get(pk).keys()].some(n => n !== net)) valid = false;
        if (!valid || !points.length) { failures.push(w.id); continue; }
        spines.set(w.id, points);
        for (let i = 1; i < points.length; i++) mark(points[i - 1], points[i], net);
    }
    const findPath = (start, end, net, startDir = 'h', endDir = null) => {
        const heap = new Heap(), costs = new Map(), parents = new Map();
        const stateKey = (p, dir) => `${pointKey(p)}:${dir}`;
        const initial = { ...start, dir: startDir, cost: 0, f: 0, k: stateKey(start, startDir) };
        heap.push(initial); costs.set(initial.k, 0); let found = null, visited = 0;
        while (heap.a.length && visited++ < 180000) {
            const current = heap.pop(); if (current.cost > costs.get(current.k)) continue;
            if (current.x === end.x && current.y === end.y && (!endDir || current.dir === endDir || ![...(vertices.get(pointKey(end))?.keys() || [])].some(n => n !== net))) { found = current; break; }
            for (const [dx, dy] of [[GRID, 0], [0, GRID], [-GRID, 0], [0, -GRID]]) {
                const p = { x: current.x + dx, y: current.y + dy }, dir = dx ? 'h' : 'v', pk = pointKey(p);
                if (p.x < GRID || p.y < GRID || p.x > WORLD.width - GRID || p.y > WORLD.height - GRID || blocked.has(pk)) continue;
                if (terminals.has(pk) && terminals.get(pk) !== net) continue;
                const owner = edgeOwners.get(edgeKey(current, p)); if (owner && owner !== net) continue;
                const here = vertices.get(pointKey(current)), there = vertices.get(pk);
                // At another signal, only a straight perpendicular crossing is legal.
                if (here && [...here].some(([n, axes]) => n !== net && (axes.has(dir) || dir !== current.dir))) continue;
                if (there && [...there].some(([n, axes]) => n !== net && axes.has(dir))) continue;
                const k = stateKey(p, dir), crossing = there && [...there.keys()].some(n => n !== net);
                const cost = current.cost + (owner === net ? 0.35 : 1) + (dir !== current.dir ? 0.7 : 0) + (crossing ? 3 : 0);
                if (cost >= (costs.get(k) ?? Infinity)) continue;
                costs.set(k, cost); parents.set(k, current);
                heap.push({ ...p, dir, cost, k, f: cost + (Math.abs(p.x - end.x) + Math.abs(p.y - end.y)) / GRID * 0.35 });
            }
        }
        if (!found) return null;
        const points = []; for (let cur = found; cur; cur = parents.get(cur.k)) points.push({ x: cur.x, y: cur.y });
        return points.reverse();
    };
    const wires = [...project.wires].sort((a, b) => key(a.from.node, a.from.pin).localeCompare(key(b.from.node, b.from.pin)));
    for (const w of wires) {
        if (failures.includes(w.id)) continue;
        const net = key(w.from.node, w.from.pin), a = port(nodes.get(w.from.node), w.from.pin, true), b = port(nodes.get(w.to.node), w.to.pin, false);
        const start = { x: a.x + GRID, y: a.y }, end = { x: b.x - GRID, y: b.y }, spine = spines.get(w.id);
        let points;
        if (spine) {
            const head = findPath(start, spine[0], net, 'h', axis(spine[0], spine[1]));
            const tail = findPath(spine.at(-1), end, net, axis(spine.at(-2), spine.at(-1)), 'h');
            if (head && tail) points = [...head, ...spine.slice(1), ...tail.slice(1)];
        } else points = findPath(start, end, net);
        if (!points) { failures.push(w.id); continue; }
        const attached = [a, ...points, b];
        for (let i = 1; i < attached.length; i++) mark(attached[i - 1], attached[i], net);
        routes.push({ wire: w, net, points: attached });
    }
    // Render each physical edge only once, including shared fan-out trunks.
    const groups = new Map(), junctions = [];
    const physicalEdges = new Map();
    for (const r of routes) for (let i = 1; i < r.points.length; i++) physicalEdges.set(edgeKey(r.points[i - 1], r.points[i]), r.net);
    for (const [edge, net] of physicalEdges) {
        const [a, b] = edge.split('|').map(s => { const [x, y] = s.split(',').map(Number); return { x, y }; });
        const dir = axis(a, b), fixed = dir === 'h' ? a.y : a.x, start = dir === 'h' ? a.x : a.y;
        const k = `${net}|${dir}|${fixed}`; if (!groups.has(k)) groups.set(k, { net, dir, fixed, starts: [] }); groups.get(k).starts.push(start);
    }
    const segments = [];
    for (const g of groups.values()) {
        g.starts.sort((a, b) => a - b); let start = g.starts[0], end = start + GRID;
        const emit = () => segments.push({ net: g.net, dir: g.dir, fixed: g.fixed, start, end });
        for (const v of g.starts.slice(1)) { if (v === end) end += GRID; else { emit(); start = v; end = v + GRID; } } emit();
    }
    const crosses = new Map();
    for (const [pk, nets] of vertices) {
        const [x, y] = pk.split(',').map(Number), p = { x, y };
        for (const [net, axes] of nets) {
            const degree = [[GRID, 0], [-GRID, 0], [0, GRID], [0, -GRID]].filter(([dx, dy]) => physicalEdges.get(edgeKey(p, { x: x + dx, y: y + dy })) === net).length;
            if (degree >= 3) junctions.push({ ...p, net });
            if (axes.has('h') && [...nets].some(([other, directions]) => other !== net && directions.has('v'))) {
                if (!crosses.has(net)) crosses.set(net, []); crosses.get(net).push(p);
            }
        }
    }
    for (const s of segments) {
        s.d = s.dir === 'v' ? `M${s.fixed},${s.start}V${s.end}` : `M${s.start},${s.fixed}`;
        if (s.dir === 'h') {
            const crossings = (crosses.get(s.net) || []).filter(p => p.y === s.fixed && p.x > s.start && p.x < s.end).sort((a, b) => a.x - b.x);
            for (const p of crossings) s.d += `H${p.x - 5}Q${p.x},${p.y - 10} ${p.x + 5},${p.y}`;
            s.d += `H${s.end}`;
        }
    }
    segments.sort((a, b) => (a.dir === 'h') - (b.dir === 'h'));
    return { routes, segments, junctions, failures };
}

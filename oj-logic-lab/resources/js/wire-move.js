import { GRID } from './model.js?v=1.0.2';
import { routeCircuit } from './router.js?v=1.0.2';

// Store the orthogonal interior spine, leaving both pin stubs attached to
// their devices. Every branch using a shared physical segment moves with it.
export function compactPath(points) {
    const result = [];
    for (const p of points) {
        if (result.length && p.x === result.at(-1).x && p.y === result.at(-1).y) { if (p.waypoint) result.at(-1).waypoint = true; continue; }
        while (result.length >= 2) {
            const a = result.at(-2), b = result.at(-1);
            if (b.waypoint || !(a.x === b.x && b.x === p.x && (b.y - a.y) * (p.y - b.y) > 0 || a.y === b.y && b.y === p.y && (b.x - a.x) * (p.x - b.x) > 0)) break;
            result.pop();
        }
        result.push({ x: p.x, y: p.y, ...(p.waypoint ? { waypoint: true } : {}) });
    }
    return result;
}

export function routeEditPath(route, extra = []) {
    const pinned = new Set([...(route.wire.path || []).filter(p => p.waypoint), ...extra].map(p => `${p.x},${p.y}`));
    return compactPath(route.points.slice(1, -1).map(p => ({ ...p, ...(pinned.has(`${p.x},${p.y}`) ? { waypoint: true } : {}) })));
}

export function previewWireMove(project, routing, segment, delta) {
    const shift = Math.round(delta / GRID) * GRID;
    if (!shift) return { wires: project.wires, routing, changed: false };
    const paths = new Map(), horizontal = segment.dir === 'h';
    for (const route of routing.routes.filter(r => r.net === segment.net)) {
        const points = routeEditPath(route), moving = new Set();
        for (let i = 1; i < points.length; i++) {
            const a = points[i - 1], b = points[i], fixed = horizontal ? a.y : a.x;
            if ((horizontal ? a.y === b.y : a.x === b.x) && fixed === segment.fixed && Math.min(horizontal ? a.x : a.y, horizontal ? b.x : b.y) >= segment.start && Math.max(horizontal ? a.x : a.y, horizontal ? b.x : b.y) <= segment.end) { moving.add(i - 1); moving.add(i); }
        }
        if (!moving.size) continue;
        const path = compactPath(points.map((p, i) => moving.has(i) ? { ...p, x: p.x + (horizontal ? 0 : shift), y: p.y + (horizontal ? shift : 0) } : p));
        if (path.length < 2 || path.length > 64) return null;
        paths.set(route.wire.id, path);
    }
    if (!paths.size) return null;
    const wires = project.wires.map(w => paths.has(w.id) ? { ...w, path: paths.get(w.id) } : w);
    const candidate = { ...project, wires }, result = routeCircuit(candidate);
    if (result.failures.length) return null;
    return { wires, routing: result, changed: true };
}

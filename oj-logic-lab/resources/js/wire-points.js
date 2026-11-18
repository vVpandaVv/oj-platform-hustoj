import { GRID } from './model.js?v=1.0.2';
import { routeCircuit } from './router.js?v=1.0.2';
import { compactPath, routeEditPath } from './wire-move.js?v=1.0.2';

const pk = p => `${p.x},${p.y}`;
const same = (a, b) => a.x === b.x && a.y === b.y;
const snap = p => ({ x: Math.round(p.x / GRID) * GRID, y: Math.round(p.y / GRID) * GRID });
function fixedPoints(routing, net) {
    const fixed = new Set();
    for (const r of routing.routes) {
        const points = r.net === net ? [r.points[0], r.points[1], r.points.at(-2), r.points.at(-1)] : r.points;
        for (const p of points) fixed.add(pk(p));
    }
    return fixed;
}

// Corners on an automatic tail are editable too. Shared coordinates produce
// one handle and one atomic edit for every branch using that coordinate.
export function wireEditPoints(routing, net) {
    const points = new Map(), fixed = fixedPoints(routing, net);
    for (const r of routing.routes.filter(r => r.net === net)) {
        const path = routeEditPath(r);
        for (let i = 1; i < path.length - 1; i++) {
            const p = path[i], k = pk(p); if (fixed.has(k)) continue;
            points.set(k, { x: p.x, y: p.y, waypoint: !!(p.waypoint || points.get(k)?.waypoint) });
        }
    }
    return [...points.values()];
}

function applyPaths(project, paths, point = null) {
    if (!paths.size || [...paths.values()].some(path => path.length < 2 || path.length > 64)) return null;
    const wires = project.wires.map(w => paths.has(w.id) ? { ...w, path: paths.get(w.id) } : w);
    const routing = routeCircuit({ ...project, wires });
    return routing.failures.length ? null : { wires, routing, point };
}

export function previewAddWaypoint(project, routing, net, position) {
    const point = snap(position); if (fixedPoints(routing, net).has(pk(point))) return null;
    const paths = new Map();
    for (const r of routing.routes.filter(r => r.net === net && r.points.some(p => same(p, point)))) paths.set(r.wire.id, routeEditPath(r, [point]));
    if ([...paths].every(([id, path]) => JSON.stringify(project.wires.find(w => w.id === id)?.path) === JSON.stringify(path))) return null;
    return applyPaths(project, paths, point);
}

export function previewPointMove(project, routing, net, source, position) {
    const point = snap(position); if (!wireEditPoints(routing, net).some(p => same(p, source))) return null;
    if (same(point, source)) return { wires: project.wires, routing, point: source };
    if (fixedPoints(routing, net).has(pk(point))) return null;
    const paths = new Map();
    for (const r of routing.routes.filter(r => r.net === net && r.points.some(p => same(p, source)))) {
        const path = routeEditPath(r, [source]), next = [];
        for (let i = 0; i < path.length; i++) {
            const p = path[i]; if (!same(p, source)) { next.push(p); continue; }
            const before = path[i - 1], after = path[i + 1]; if (!before || !after) return null;
            // Keep both incident directions at the dragged point, inserting
            // orthogonal elbows to the unchanged neighboring points.
            next.push(before.y === p.y ? { x: before.x, y: point.y } : { x: point.x, y: before.y });
            next.push({ ...point, waypoint: true });
            next.push(after.y === p.y ? { x: after.x, y: point.y } : { x: point.x, y: after.y });
        }
        paths.set(r.wire.id, compactPath(next));
    }
    return applyPaths(project, paths, point);
}

export function previewDeletePoint(project, routing, net, point) {
    if (!wireEditPoints(routing, net).some(p => same(p, point))) return null;
    const paths = new Map();
    for (const r of routing.routes.filter(r => r.net === net && r.points.some(p => same(p, point)))) {
        const path = routeEditPath(r, [point]), next = [];
        for (let i = 0; i < path.length; i++) {
            const p = path[i]; if (!same(p, point)) { next.push(p); continue; }
            const a = path[i - 1], b = path[i + 1]; if (!a || !b) return null;
            if (a.x !== b.x && a.y !== b.y) {
                const elbow = { x: a.x, y: b.y };
                next.push(same(elbow, point) ? { x: b.x, y: a.y } : elbow);
            }
        }
        paths.set(r.wire.id, compactPath(next));
    }
    return applyPaths(project, paths);
}

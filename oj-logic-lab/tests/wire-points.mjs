import assert from 'node:assert/strict';
import { emptyProject, makeNode, GRID, height, port, validateProject } from '../resources/js/model.js?v=1.0.2';
import { routeCircuit } from '../resources/js/router.js?v=1.0.2';
import { previewWireMove } from '../resources/js/wire-move.js?v=1.0.2';
import { previewMove } from '../resources/js/move.js?v=1.0.2';
import { wireEditPoints, previewAddWaypoint, previewPointMove, previewDeletePoint } from '../resources/js/wire-points.js?v=1.0.2';
import { schematicSVG } from '../resources/js/schematic.js?v=1.0.2';
import { Engine } from '../resources/js/engine.js?v=1.0.2';
import { example } from '../resources/js/examples.js?v=1.0.2';

function check(project, routing = routeCircuit(project)) {
    assert.deepEqual(routing.failures, []); assert.equal(routing.routes.length, project.wires.length);
    const edges = new Map(), vertices = new Map();
    for (const r of routing.routes) {
        assert.deepEqual(r.points[0], port(project.nodes.find(n => n.id === r.wire.from.node), r.wire.from.pin, true));
        assert.deepEqual(r.points.at(-1), port(project.nodes.find(n => n.id === r.wire.to.node), r.wire.to.pin, false));
        for (let i = 1; i < r.points.length; i++) {
            const a = r.points[i - 1], b = r.points[i], dir = a.y === b.y ? 'h' : 'v';
            assert.equal(Math.abs(a.x - b.x) + Math.abs(a.y - b.y), GRID, 'all edges remain orthogonal grid steps');
            const k = [`${a.x},${a.y}`, `${b.x},${b.y}`].sort().join('|');
            if (edges.has(k)) assert.equal(edges.get(k), r.net, 'different signals cannot overlap'); edges.set(k, r.net);
            for (const n of project.nodes) assert.ok(!((a.x + b.x) / 2 > n.x && (a.x + b.x) / 2 < n.x + 120 && (a.y + b.y) / 2 > n.y && (a.y + b.y) / 2 < n.y + height(n)), 'wire cannot enter a device');
            for (const p of [a, b]) { const pk = `${p.x},${p.y}`; if (!vertices.has(pk)) vertices.set(pk, new Map()); const nets = vertices.get(pk); if (!nets.has(r.net)) nets.set(r.net, new Set()); nets.get(r.net).add(dir); }
        }
    }
    for (const nets of vertices.values()) if (nets.size > 1) { assert.equal(nets.size, 2); const axes = [...nets.values()]; assert.equal(axes[0].size, 1, 'no bend at a crossing'); assert.equal(axes[1].size, 1); assert.notEqual([...axes[0]][0], [...axes[1]][0]); }
    return routing;
}
const same = (a, b) => a.x === b.x && a.y === b.y;
const nextProject = (p, result) => { assert.ok(result, 'edit is valid'); const candidate = { ...p, wires: result.wires }; check(candidate, result.routing); return candidate; };
const p = emptyProject(); p.nodes = [makeNode('SWITCH', 60, 120, 'a'), makeNode('LED', 600, 120, 'y')];
p.wires = [{ id: 'w', from: { node: 'a', pin: 'Q' }, to: { node: 'y', pin: 'A' } }];
const original = structuredClone(p), engine = new Engine(p); engine.advanceTo(40); const values = [...engine.outputs];
const r = check(p), center = { x: 360, y: 144 };
const added = previewAddWaypoint(p, r, 'a:Q', center), withPoint = nextProject(p, added);
assert.ok(wireEditPoints(added.routing, 'a:Q').some(v => same(v, center) && v.waypoint), 'collinear waypoint remains visible');
assert.deepEqual(validateProject(JSON.parse(JSON.stringify(withPoint))).wires[0].path, withPoint.wires[0].path, 'waypoint flags survive JSON roundtrip');
const moved = previewPointMove(withPoint, added.routing, 'a:Q', center, { x: 396, y: 240 }), withMovedPoint = nextProject(withPoint, moved);
assert.ok(wireEditPoints(moved.routing, 'a:Q').some(v => same(v, { x: 396, y: 240 }) && v.waypoint), 'waypoint moves in both axes');
assert.ok(schematicSVG(withMovedPoint, engine).includes(',240'), 'SVG reflects waypoint route');
const shifted = previewWireMove(withMovedPoint, moved.routing, moved.routing.segments.find(s => s.dir === 'h' && s.fixed === 240), 24), withShiftedLine = nextProject(withMovedPoint, shifted);
assert.ok(withShiftedLine.wires[0].path.some(v => v.waypoint && v.x === 396 && v.y === 264), 'line drag preserves explicit waypoint');
assert.ok(previewMove(withPoint, 'a', 36, 96), 'device movement retains waypoint routing');
const removed = previewDeletePoint(withPoint, added.routing, 'a:Q', center), withoutPoint = nextProject(withPoint, removed);
assert.equal(withoutPoint.wires[0].path.some(v => v.waypoint), false, 'deletion removes the waypoint marker');
assert.deepEqual(wireEditPoints(removed.routing, 'a:Q'), [], 'straight line has no unnecessary handles');
const corner = wireEditPoints(moved.routing, 'a:Q').find(v => !v.waypoint); assert.ok(corner);
nextProject(withMovedPoint, previewPointMove(withMovedPoint, moved.routing, 'a:Q', corner, { x: corner.x + 24, y: corner.y + 24 }));
nextProject(withMovedPoint, previewDeletePoint(withMovedPoint, moved.routing, 'a:Q', corner));
for (const target of [{ x: -24, y: 240 }, { x: 120, y: 144 }, { x: 612, y: 144 }]) assert.equal(previewPointMove(withPoint, added.routing, 'a:Q', center, target), null, 'invalid point movement is rejected');
assert.equal(previewAddWaypoint(p, r, 'a:Q', { x: 192, y: 144 }), null, 'terminal stub is fixed');
assert.equal(previewDeletePoint(withPoint, added.routing, 'a:Q', { x: 192, y: 144 }), null);
const obstacle = { ...withPoint, nodes: [...p.nodes, makeNode('AND', 300, 216, 'obstacle')] };
assert.equal(previewPointMove(obstacle, check(obstacle), 'a:Q', center, { x: 360, y: 240 }), null, 'point cannot enter an obstacle');

// A shared corner/point is edited atomically across all fan-out branches.
const half = example('half'), hr = check(half), shared = wireEditPoints(hr, 'a:Q').find(v => v.x === 432 && v.y === 144); assert.ok(shared);
const commonMove = previewPointMove(half, hr, 'a:Q', shared, { x: 408, y: 120 }), commonProject = nextProject(half, commonMove);
assert.equal(commonProject.wires.filter(w => w.path?.some(v => v.waypoint && v.x === 408 && v.y === 120)).length, 2);
const commonAdd = previewAddWaypoint(half, hr, 'a:Q', { x: 336, y: 144 }), commonAdded = nextProject(half, commonAdd);
assert.equal(commonAdded.wires.filter(w => w.path?.some(v => v.waypoint)).length, 2);
nextProject(commonAdded, previewDeletePoint(commonAdded, commonAdd.routing, 'a:Q', { x: 336, y: 144 }));

const cross = emptyProject(); cross.nodes = [makeNode('SWITCH', 60, 60, 'a'), makeNode('LED', 804, 60, 'y'), makeNode('SWITCH', 60, 420, 'b'), makeNode('LED', 804, 420, 'z')];
cross.wires = [{ id: 'a', from: { node: 'a', pin: 'Q' }, to: { node: 'y', pin: 'A' }, path: [{ x: 240, y: 240 }, { x: 720, y: 240 }] }, { id: 'b', from: { node: 'b', pin: 'Q' }, to: { node: 'z', pin: 'A' }, path: [{ x: 480, y: 144 }, { x: 480, y: 360 }] }];
const cr = check(cross); assert.ok(cr.segments.some(s => s.d.includes('Q')));
assert.equal(previewAddWaypoint(cross, cr, 'a:Q', { x: 480, y: 240 }), null, 'foreign crossing cannot become an editing junction');
const cp = previewAddWaypoint(cross, cr, 'a:Q', { x: 360, y: 240 }), cpa = nextProject(cross, cp);
assert.equal(previewPointMove(cpa, cp.routing, 'a:Q', { x: 360, y: 240 }, { x: 480, y: 240 }), null);
assert.equal(previewPointMove(cpa, cp.routing, 'a:Q', { x: 360, y: 240 }, { x: 480, y: 192 }), null, 'foreign signal overlap is rejected');
assert.deepEqual(p, original, 'editing previews never mutate the source'); assert.equal(engine.now, 40); assert.deepEqual([...engine.outputs], values, 'editing does not reset simulation');
console.log('Wire point checks passed: 2-axis corner/waypoint movement, insertion/deletion, shared branches, orthogonal reconnection, terminal/crossing/obstacle/overlap protection, save/import, SVG, line/device movement and pure previews.');

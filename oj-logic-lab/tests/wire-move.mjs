import assert from 'node:assert/strict';
import { emptyProject, makeNode, GRID, height, port, validateProject } from '../resources/js/model.js?v=1.0.2';
import { routeCircuit } from '../resources/js/router.js?v=1.0.2';
import { previewWireMove } from '../resources/js/wire-move.js?v=1.0.2';
import { previewMove } from '../resources/js/move.js?v=1.0.2';
import { Engine } from '../resources/js/engine.js?v=1.0.2';
import { schematicSVG } from '../resources/js/schematic.js?v=1.0.2';
import { example, EXAMPLES } from '../resources/js/examples.js?v=1.0.2';

function check(project, routing = routeCircuit(project)) {
    assert.deepEqual(routing.failures, []);
    assert.equal(routing.routes.length, project.wires.length);
    const edges = new Map(), vertices = new Map();
    for (const route of routing.routes) {
        assert.deepEqual(route.points[0], port(project.nodes.find(n => n.id === route.wire.from.node), route.wire.from.pin, true));
        assert.deepEqual(route.points.at(-1), port(project.nodes.find(n => n.id === route.wire.to.node), route.wire.to.pin, false));
        for (let i = 1; i < route.points.length; i++) {
            const a = route.points[i - 1], b = route.points[i], dir = a.y === b.y ? 'h' : 'v';
            assert.equal(Math.abs(a.x - b.x) + Math.abs(a.y - b.y), GRID);
            const k = [`${a.x},${a.y}`, `${b.x},${b.y}`].sort().join('|');
            if (edges.has(k)) assert.equal(edges.get(k), route.net, 'unrelated signals overlap'); edges.set(k, route.net);
            for (const n of project.nodes) assert.ok(!((a.x + b.x) / 2 > n.x && (a.x + b.x) / 2 < n.x + 120 && (a.y + b.y) / 2 > n.y && (a.y + b.y) / 2 < n.y + height(n)), 'wire enters a device');
            for (const p of [a, b]) { const pk = `${p.x},${p.y}`; if (!vertices.has(pk)) vertices.set(pk, new Map()); const nets = vertices.get(pk); if (!nets.has(route.net)) nets.set(route.net, new Set()); nets.get(route.net).add(dir); }
        }
    }
    for (const nets of vertices.values()) if (nets.size > 1) {
        assert.equal(nets.size, 2, 'only two perpendicular signals may cross');
        const axes = [...nets.values()]; assert.equal(axes[0].size, 1); assert.equal(axes[1].size, 1); assert.notEqual([...axes[0]][0], [...axes[1]][0]);
    }
    return routing;
}
const p = emptyProject();
p.nodes = [makeNode('SWITCH', 60, 120, 'a'), makeNode('LED', 600, 120, 'y')];
p.wires = [{ id: 'w', from: { node: 'a', pin: 'Q' }, to: { node: 'y', pin: 'A' } }];
const original = structuredClone(p), engine = new Engine(p); engine.advanceTo(30);
const signals = [...engine.outputs], time = engine.now;
const route = check(p), horizontal = route.segments.find(s => s.dir === 'h');
const first = previewWireMove(p, route, horizontal, 96); assert.ok(first);
const manual = { ...p, wires: first.wires }; check(manual, first.routing);
assert.ok(first.routing.segments.some(s => s.dir === 'h' && s.fixed === horizontal.fixed + 96 && s.end - s.start >= 300));
assert.deepEqual(p, original, 'preview mutates the original circuit'); assert.equal(engine.now, time); assert.deepEqual([...engine.outputs], signals);
assert.deepEqual(validateProject(JSON.parse(JSON.stringify(manual))).wires[0].path, manual.wires[0].path, 'manual routing survives save/import');
assert.ok(schematicSVG(manual, engine).includes(`,${horizontal.fixed + 96}`), 'SVG honors manual routing');
const vertical = first.routing.segments.find(s => s.dir === 'v');
const second = previewWireMove(manual, first.routing, vertical, 36); assert.ok(second); check({ ...manual, wires: second.wires }, second.routing);
assert.ok(second.routing.segments.some(s => s.dir === 'v' && s.fixed === vertical.fixed + 36), 'vertical drag moves sideways');
assert.equal(previewWireMove(p, route, horizontal, -10000), null, 'world boundary enforced');
assert.deepEqual(previewWireMove(manual, first.routing, vertical, 0).wires, manual.wires, 'zero movement preserves the path');
assert.ok(previewMove(manual, 'a', 36, 96), 'pins follow a moved device with a manual spine');
const obstacle = { ...p, nodes: [...p.nodes, makeNode('AND', 300, 216, 'obstacle')] };
assert.equal(previewWireMove(obstacle, check(obstacle), horizontal, 96), null, 'manual drag through a device is rejected');
for (const path of [[{ x: 192, y: 240 }, { x: 588, y: 241 }], [{ x: -12, y: 240 }, { x: 588, y: 240 }], Array.from({ length: 65 }, (_, i) => ({ x: 192 + i * GRID, y: 240 }))]) assert.throws(() => validateProject({ ...p, wires: [{ ...p.wires[0], path }] }), /수동 배선/);

// Forced unrelated collinear spines are rejected; a straight crossing has a bridge.
const cross = emptyProject();
cross.nodes = [makeNode('SWITCH', 60, 60, 'a'), makeNode('LED', 804, 60, 'y'), makeNode('SWITCH', 60, 420, 'b'), makeNode('LED', 804, 420, 'z')];
cross.wires = [
    { id: 'a', from: { node: 'a', pin: 'Q' }, to: { node: 'y', pin: 'A' }, path: [{ x: 240, y: 240 }, { x: 720, y: 240 }] },
    { id: 'b', from: { node: 'b', pin: 'Q' }, to: { node: 'z', pin: 'A' }, path: [{ x: 480, y: 144 }, { x: 480, y: 360 }] },
];
const crossed = check(cross); assert.ok(crossed.segments.some(s => s.d.includes('Q')));
const illegal = structuredClone(cross); illegal.wires[1].path = [{ x: 300, y: 240 }, { x: 600, y: 240 }];
assert.ok(routeCircuit(illegal).failures.includes('b'));
illegal.wires[1].path = [{ x: 480, y: 144 }, { x: 480, y: 240 }, { x: 600, y: 240 }];
assert.ok(routeCircuit(illegal).failures.includes('b'), 'a bend on a foreign signal is rejected');
const parallel = structuredClone(cross); parallel.wires[1].path = [{ x: 300, y: 360 }, { x: 600, y: 360 }];
const parallelRouting = check(parallel), lane = parallelRouting.segments.find(s => s.net === 'a:Q' && s.dir === 'h' && s.fixed === 240);
assert.ok(lane); assert.equal(previewWireMove(parallel, parallelRouting, lane, 120), null, 'dragging onto another manual signal is rejected');

const half = example('half'), hr = check(half);
const shared = hr.segments.find(s => s.dir === 'h' && s.net === 'a:Q' && s.end - s.start > GRID); assert.ok(shared);
const fanout = previewWireMove(half, hr, shared, -24); assert.ok(fanout); check({ ...half, wires: fanout.wires }, fanout.routing);
assert.equal(fanout.wires.filter(w => w.path).length, 2, 'all branches on the common trunk move together');
assert.ok(fanout.routing.junctions.some(j => j.net === shared.net), 'shared trunks keep junction dots');
for (const [name] of EXAMPLES) {
    const project = example(name), routing = check(project); let moved = false;
    for (const s of routing.segments) {
        for (const delta of [24, -24]) {
            const result = previewWireMove(project, routing, s, delta); if (!result) continue;
            check({ ...project, wires: result.wires }, result.routing); moved = true; break;
        }
        if (moved) break;
    }
    assert.ok(moved, `${name}: at least one manual segment can be moved`);
}
console.log('Manual wire checks passed: horizontal/vertical dragging, shared trunks, device obstacles, crossing bridges, unrelated overlap rejection, save/import, SVG, device following, pure previews and all 6 examples.');

import assert from 'node:assert/strict';
import { routeCircuit } from '../resources/js/router.js';
import { makeNode, height, port, GRID, emptyProject } from '../resources/js/model.js';
import { example, EXAMPLES } from '../resources/js/examples.js';
const edge = (a, b) => [[a.x, a.y].join(','), [b.x, b.y].join(',')].sort().join('|');
function check(p) {
    const result = routeCircuit(p), owners = new Map();
    assert.deepEqual(result.failures, [], p.title);
    assert.equal(result.routes.length, p.wires.length);
    for (const route of result.routes) {
        const from = p.nodes.find(n => n.id === route.wire.from.node), to = p.nodes.find(n => n.id === route.wire.to.node);
        assert.deepEqual(route.points[0], port(from, route.wire.from.pin, true)); assert.deepEqual(route.points.at(-1), port(to, route.wire.to.pin, false));
        for (let i = 1; i < route.points.length; i++) {
            const a = route.points[i - 1], b = route.points[i]; assert.equal(Math.abs(a.x - b.x) + Math.abs(a.y - b.y), GRID);
            const k = edge(a, b); if (owners.has(k)) assert.equal(owners.get(k), route.net, 'different signals must never share a segment'); owners.set(k, route.net);
            const x = (a.x + b.x) / 2, y = (a.y + b.y) / 2;
            for (const n of p.nodes) assert.ok(!(x > n.x && x < n.x + 120 && y > n.y && y < n.y + height(n)), `wire crosses ${n.id}`);
        }
    }
    return result;
}
for (const [id] of EXAMPLES) check(example(id));
{
    const p = emptyProject(); p.title = 'crossing and obstacle';
    p.nodes = [makeNode('SWITCH', 60, 60, 'a'), makeNode('SWITCH', 60, 420, 'b'), makeNode('AND', 420, 216, 'obstacle'), makeNode('LED', 804, 60, 'y'), makeNode('LED', 804, 420, 'z')];
    p.wires = [{ id: 'w1', from: { node: 'a', pin: 'Q' }, to: { node: 'z', pin: 'A' } }, { id: 'w2', from: { node: 'b', pin: 'Q' }, to: { node: 'y', pin: 'A' } }];
    const r = check(p); assert.ok(r.segments.some(s => s.d.includes('Q')), 'crossing receives a bridge');
}
{
    const r = check(example('half')); assert.ok(r.junctions.length >= 2, 'shared fanout receives junction dots');
}
{
    const p = emptyProject(); p.title = 'feedback'; p.nodes = [makeNode('NOT', 300, 300, 'n')]; p.wires = [{ id: 'loop', from: { node: 'n', pin: 'Q' }, to: { node: 'n', pin: 'A' } }]; check(p);
}
// Deterministic dense layouts include backwards connections and multi-output FFs.
for (let seed = 0; seed < 8; seed++) {
    const p = emptyProject(); p.title = `dense ${seed}`;
    for (let i = 0; i < 12; i++) p.nodes.push(makeNode(i < 4 ? 'SWITCH' : 'AND', 60 + (i % 4) * 264, 60 + Math.floor(i / 4) * 252, `n${i}`));
    for (let i = 4; i < 12; i++) for (const [j, pin] of ['A', 'B'].entries()) p.wires.push({ id: `${i}-${j}`, from: { node: `n${(i + seed + j * 3) % 4}`, pin: 'Q' }, to: { node: `n${i}`, pin } });
    check(p);
}
console.log('Router checks passed: all examples, shared trunks, bridges, obstacles, feedback, 8 dense layouts; no unrelated collinear overlap or device intersections.');

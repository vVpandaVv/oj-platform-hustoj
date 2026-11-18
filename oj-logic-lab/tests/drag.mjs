import assert from 'node:assert/strict';
import { previewMove } from '../resources/js/move.js?v=1.0.2';
import { EXAMPLES, example } from '../resources/js/examples.js?v=1.0.2';
import { port, height, GRID } from '../resources/js/model.js?v=1.0.2';
import { Engine } from '../resources/js/engine.js?v=1.0.2';
for (const [name] of EXAMPLES) {
    const p = example(name), snapshot = structuredClone(p), source = p.nodes[0], engine = new Engine(p); engine.advanceTo(20);
    const beforeSignals = [...engine.outputs], time = engine.now;
    for (let step = 1; step <= 3; step++) {
        const result = previewMove(p, source.id, source.x + GRID * step, source.y + GRID * Math.min(step, 2));
        assert.ok(result, `${name} preview ${step}`); assert.equal(result.routing.routes.length, p.wires.length);
        const candidate = { ...p, nodes: p.nodes.map(n => n.id === source.id ? result.node : n) }, owners = new Map();
        for (const r of result.routing.routes) {
            const from = candidate.nodes.find(n => n.id === r.wire.from.node), to = candidate.nodes.find(n => n.id === r.wire.to.node);
            assert.deepEqual(r.points[0], port(from, r.wire.from.pin, true)); assert.deepEqual(r.points.at(-1), port(to, r.wire.to.pin, false));
            for (let i = 1; i < r.points.length; i++) {
                const a = r.points[i - 1], b = r.points[i], x = (a.x + b.x) / 2, y = (a.y + b.y) / 2;
                for (const n of candidate.nodes) assert.ok(!(x > n.x && x < n.x + 120 && y > n.y && y < n.y + height(n)), `${name}: live wire crosses a device`);
                const k = [`${a.x},${a.y}`, `${b.x},${b.y}`].sort().join('|'); if (owners.has(k)) assert.equal(owners.get(k), r.net, 'different nets share a live wire segment'); owners.set(k, r.net);
            }
        }
        assert.deepEqual(p, snapshot, 'preview must not alter the saved circuit'); assert.equal(engine.now, time); assert.deepEqual([...engine.outputs], beforeSignals, 'moving a node must not reset signals');
    }
    const other = p.nodes[1]; assert.equal(previewMove(p, source.id, other.x, other.y), null, 'overlapping placement is rejected');
    assert.equal(previewMove(p, source.id, -120, -120), null, 'out-of-bounds placement is rejected');
}
console.log('Live drag checks passed: pins follow all 6 examples across grid moves; wires avoid devices and unrelated overlap, invalid positions are rejected, circuit/time/signals remain unchanged.');

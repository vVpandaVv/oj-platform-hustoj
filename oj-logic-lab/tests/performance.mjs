import assert from 'node:assert/strict';
import { emptyProject, makeNode } from '../resources/js/model.js';
import { routeCircuit } from '../resources/js/router.js';
import { Engine } from '../resources/js/engine.js';
import { example } from '../resources/js/examples.js';
import { writeFile } from 'node:fs/promises';
const project = emptyProject(); project.settings.mode = 'advanced';
for (let i = 0; i < 100; i++) project.nodes.push(makeNode(i < 20 ? 'SWITCH' : 'FA', 60 + i % 10 * 216, 60 + Math.floor(i / 10) * 132, `n${i}`));
for (let i = 20; i < 100; i++) for (const [j, pin] of ['A', 'B', 'CI'].entries()) project.wires.push({ id: `w${i}-${j}`, from: { node: `n${(i - 20) % 20}`, pin: 'Q' }, to: { node: `n${i}`, pin } });
const started = performance.now(), route = routeCircuit(project), routingMs = performance.now() - started;
assert.equal(route.routes.length + route.failures.length, 240);
assert.equal(route.failures.length, 0, 'the supported maximum circuit must fully route');
const owners = new Map();
for (const r of route.routes) for (let i = 1; i < r.points.length; i++) { const a = r.points[i - 1], b = r.points[i], k = [`${a.x},${a.y}`, `${b.x},${b.y}`].sort().join('|'); if (owners.has(k)) assert.equal(owners.get(k), r.net); owners.set(k, r.net); }
const engine = new Engine(project), initial = performance.now(); for (let t = 0; t < 100; t++) { engine.setInput(`n${t % 20}`, t % 2); engine.step(1000); }
assert.equal(engine.fault, ''); for (const history of engine.records.values()) assert.ok(history.length <= 3000);
const continuous = new Engine(example('counter')); for (let i = 0; i < 100; i++) continuous.step(10000); assert.equal(continuous.fault, ''); assert.ok(continuous.events.length < 20000); assert.ok([...continuous.records.values()].reduce((total, history) => total + history.length, 0) <= 100000); assert.ok([...continuous.records.values()].every(history => history.length <= 3000));
if (process.argv[2]) await writeFile(process.argv[2], JSON.stringify(project, null, 2) + '\n', 'utf8');
console.log(JSON.stringify({ nodes: 100, wires: 240, routed: route.routes.length, failures: route.failures.length, routingMs: Math.round(routingMs), simulationMs: Math.round(performance.now() - initial), simulatedNs: engine.now, continuousClockNs: continuous.now, recordLimit: 3000, totalRecordLimit: 100000 }));

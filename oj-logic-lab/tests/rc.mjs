import assert from 'node:assert/strict';
import { emptyProject, makeNode, validateProject } from '../resources/js/model.js';
import { Engine } from '../resources/js/engine.js';
import { createEngine } from '../resources/js/modes.js';
import { RecoveryStore, simulationSignature } from '../resources/js/project-state.js';
import { moveGroup, copyGroup, pasteGroup, alignGroup } from '../resources/js/editing.js';
import { waveRange, changeWave } from '../resources/js/waveform.js';
import { EXAMPLES, example } from '../resources/js/examples.js';
import { schematicSVG } from '../resources/js/schematic.js';
import { DragPlanner } from '../resources/js/drag-planner.js';

for (const type of ['TFF', 'JKFF']) {
    const p = emptyProject(), ff = makeNode(type, 360, 120, 'ff'), clk = makeNode('SWITCH', 60, 120, 'clk'), one = makeNode('ONE', 60, 336, 'one');
    Object.assign(ff.config, { delay: 10, setup: 0, hold: 0 }); p.nodes = [ff, clk, one]; p.wires = [{ id: 'clk', from: { node: 'clk', pin: 'Q' }, to: { node: 'ff', pin: 'CLK' } }, ... (type === 'TFF' ? ['T'] : ['J', 'K']).map(pin => ({ id: pin, from: { node: 'one', pin: 'Q' }, to: { node: 'ff', pin } }))];
    const engine = new Engine(p); engine.advanceTo(1); engine.setInput('clk', 1); engine.advanceTo(2); engine.setInput('clk', 0); engine.advanceTo(3); engine.setInput('clk', 1); engine.advanceTo(11); assert.equal(engine.value('ff'), 1); engine.advanceTo(13); assert.equal(engine.value('ff'), 0); assert.equal(engine.state.get('ff'), 0);
    assert.deepEqual(engine.records.get('ff:Q'), [{ time: 0, value: 0 }, { time: 11, value: 1 }, { time: 13, value: 0 }]);
}
{
    const p = example('dff'); p.nodes = p.nodes.filter(n => n.id !== 'clk'); const clk = makeNode('SWITCH', 60, 204, 'clk'); p.nodes.push(clk); const ff = p.nodes.find(n => n.id === 'ff'); Object.assign(ff.config, { delay: 10, setup: 0, hold: 0 }); const e = new Engine(p);
    e.setInput('d', 1); e.advanceTo(1); e.setInput('clk', 1); e.advanceTo(2); e.setInput('rst', 1); e.advanceTo(3); e.setInput('rst', 0); e.advanceTo(11); assert.equal(e.value('ff'), 0, 'reset invalidates an earlier delayed capture'); e.advanceTo(12); assert.equal(e.value('ff'), 0);
    const pending = new Engine(example('half')); for (let i = 0; i < 20001; i++) pending.push({ time: i, kind: 'test' }); assert.match(pending.fault, /대기열/); assert.equal(pending.events.length, 20000);
}
{
    class FakeWorker { constructor() { this.sent = []; } postMessage(value) { this.sent.push(value); } terminate() {} }
    globalThis.Worker = FakeWorker; const planner = new DragPlanner(), worker = planner.worker, replies = [];
    for (let i = 0; i < 100; i++) replies.push(planner.request({ position: i }));
    assert.equal(worker.sent.length, 1, 'rapid pointer movement only sends one in-flight route'); worker.onmessage({ data: { result: 'obsolete' } }); assert.equal(worker.sent.length, 2); assert.equal(worker.sent[1].position, 99); worker.onmessage({ data: { result: 'final' } }); const results = await Promise.all(replies); assert.equal(results.filter(Boolean).length, 1); assert.equal(results.at(-1), 'final'); planner.close(); delete globalThis.Worker;
}
const map = new Map(), storage = { getItem: k => map.get(k) ?? null, setItem: (k, v) => map.set(k, v) };
const p = example('half'), store = new RecoveryStore(storage, 'account1'); store.schedule(p); assert.ok(store.flush()); assert.equal(store.draft().project.title, p.title);
store.save(p); assert.equal(store.draft(), null); assert.equal(store.dirty(p), false);
for (let i = 0; i < 5; i++) store.archive({ ...p, title: `backup${i}` }); assert.equal(store.backups().length, 3); assert.equal(store.backups()[0].project.title, 'backup4');
assert.equal(new RecoveryStore(storage, 'account2').draft(), null, 'account keys stay separate');
const blocked = new RecoveryStore({ getItem() { throw Error('blocked'); }, setItem() { throw Error('quota'); } }, 'blocked'); blocked.schedule(p); assert.equal(blocked.flush(), false); assert.equal(blocked.archive(p), false); assert.equal(blocked.backups().length, 1, 'in-memory fallback preserves a transition copy');
const moved = moveGroup(p, ['a', 'b'], 12, 12); assert.ok(moved); assert.equal(simulationSignature(p), simulationSignature(moved.project));
assert.equal(moveGroup(p, ['a', 'b'], -1000, 0), null);
const clipboard = copyGroup(p, ['a', 'b', 'xor', 'and', 'sum', 'carry']), pasted = pasteGroup(emptyProject(), clipboard, 36, 36); assert.ok(pasted); assert.equal(pasted.project.nodes.length, 6); assert.equal(pasted.project.wires.length, 6); assert.notEqual(pasted.project.nodes[0].id, 'a'); validateProject(pasted.project);
assert.ok(alignGroup(p, ['a', 'xor', 'sum'], 'top')); assert.equal(alignGroup(p, ['a', 'b'], 'top'), null, 'alignment cannot overlap devices');
for (const mode of ['beginner', 'advanced']) {
    const circuit = example('counter'); circuit.settings.mode = mode; const runtime = createEngine(circuit); if (mode === 'beginner') runtime.pulse(); else runtime.advanceTo(52);
    const now = runtime.now, outputs = [...runtime.outputs], events = structuredClone(runtime.events), geometry = moveGroup(circuit, ['q0', 'q1'], 12, 0); assert.ok(geometry); runtime.rebind(geometry.project);
    assert.equal(runtime.now, now); assert.deepEqual([...runtime.outputs], outputs); assert.deepEqual(runtime.events, events); if (mode === 'beginner') assert.ok(runtime.clockIds.every(id => runtime.nodes.get(id).config.manualClock));
}
p.notes = [{ id: 'n', x: 24, y: 24, text: '<script>unsafe</script>' }]; p.netNames = { 'a:Q': '<A & B>' }; p.settings.wave = { span: 200, start: 20, signals: ['a:Q'] };
assert.deepEqual(validateProject(JSON.parse(JSON.stringify(p))).notes, p.notes); assert.deepEqual(waveRange(p, 1000), { start: 20, end: 220, span: 200 });
assert.equal(changeWave(p, 1000, 'in').span, 100); assert.equal(changeWave(p, 1000, 'follow').start, null);
const svg = schematicSVG(p); assert.ok(svg.includes('&lt;script&gt;')); assert.ok(svg.includes('&lt;A &amp; B&gt;')); assert.ok(!svg.includes('<script>'));
assert.throws(() => validateProject({ ...p, formatVersion: 2 }), /더 새로운/);
for (const [id] of EXAMPLES) { const canonical = validateProject(example(id)); assert.deepEqual(validateProject(JSON.parse(JSON.stringify(canonical))), canonical); }
const alu = new Engine(example('alu')); for (const [s0, s1, expected] of [[0, 0, 1], [1, 0, 7], [0, 1, 6], [1, 1, 8]]) { alu.setInput('s0', s0); alu.setInput('s1', s1); alu.step(20); assert.equal(parseInt(alu.input('y', 'A'), 2), expected); }
const transfer = new Engine(example('transfer')); transfer.advanceTo(12); assert.equal(transfer.value('r1'), '1001'); assert.equal(transfer.value('r2'), '0011'); transfer.advanceTo(52); assert.equal(transfer.value('r2'), '1001');
const datapath = new Engine(example('datapath')); datapath.advanceTo(12); assert.equal(datapath.value('reg'), '1000'); datapath.setInput('load', 0); datapath.setInput('a', '0000'); datapath.advanceTo(52); assert.equal(datapath.value('reg'), '1000');
console.log('RC checks passed: fast-clock FF transport, local recovery/quota/account isolation, group editing, runtime-preserving rebind, format roundtrip, labels/notes escaping, wave navigation and three architecture examples.');

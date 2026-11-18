import assert from 'node:assert/strict';
import { emptyProject, makeNode, validateProject } from '../resources/js/model.js';
import { example } from '../resources/js/examples.js';
import { createEngine, modeOf, BeginnerEngine } from '../resources/js/modes.js';

assert.equal(modeOf(emptyProject()), 'beginner', 'new projects start simple');
const legacy = example('half'); delete legacy.settings.mode;
assert.equal(modeOf(validateProject(legacy)), 'advanced', 'legacy files keep their original timing behavior');
assert.equal(modeOf(validateProject({ ...legacy, settings: { mode: 'unknown' } })), 'advanced');
for (const mode of ['beginner', 'advanced']) {
    const p = example('dff'); p.settings.mode = mode;
    assert.equal(modeOf(validateProject(JSON.parse(JSON.stringify(p)))), mode, 'mode survives export/import');
}
{
    const p = example('half'), before = structuredClone(p), e = createEngine(p);
    assert.ok(e instanceof BeginnerEngine); assert.equal(e.value('sum', 'A'), 'X');
    assert.equal(e.input('sum', 'A'), 1, 'no run/step required to see combinational outputs');
    assert.equal(e.input('carry', 'A'), 0); e.setInput('b', 1);
    assert.equal(e.input('sum', 'A'), 0); assert.equal(e.input('carry', 'A'), 1);
    assert.equal(p.nodes.find(n => n.id === 'b').config.value, 1, 'input changes are saved to the source circuit');
    before.nodes.find(n => n.id === 'b').config.value = 1;
    assert.deepEqual(p, before, 'beginner runtime leaves all other circuit data intact');
    assert.equal(e.pulse(), false, 'no clock is a no-op');
}
{
    const p = example('dff'), ff = p.nodes.find(n => n.id === 'ff'), clock = p.nodes.find(n => n.id === 'clk');
    Object.assign(ff.config, { delay: 25, edge: 'falling', setup: 12, hold: 13, initial: 0 });
    Object.assign(clock.config, { period: 91, duty: 23, phase: 17 });
    const config = structuredClone(p.nodes.map(n => n.config)), e = createEngine(p);
    assert.equal(e.value('ff'), 0, 'clock is idle until requested');
    e.pulse(); assert.equal(e.value('ff'), 1); assert.equal(e.value('clk'), 0); assert.equal(e.cycles, 1);
    e.setInput('d', 0); assert.equal(e.value('ff'), 1, 'data changes do not silently pulse the clock');
    e.pulse(); assert.equal(e.value('ff'), 0); assert.equal(e.cycles, 2); assert.deepEqual(e.warnings, []);
    assert.deepEqual(ff.config, config[p.nodes.indexOf(ff)]); assert.deepEqual(clock.config, config[p.nodes.indexOf(clock)]);
    p.settings.mode = 'advanced'; const advanced = createEngine(p);
    advanced.advanceTo(17); assert.equal(advanced.value('clk'), 1, 'stored phase is restored');
    assert.equal(advanced.nodes.get('ff').config.edge, 'falling'); assert.equal(advanced.nodes.get('ff').config.delay, 25);
    assert.equal(advanced.nodes.get('ff').config.setup, 12); assert.equal(advanced.nodes.get('ff').config.hold, 13);
}
{
    const p = example('counter'), e = createEngine(p), states = [];
    for (let i = 0; i < 4; i++) { assert.ok(e.pulse()); states.push(`${e.value('t1')}${e.value('t0')}`); }
    assert.deepEqual(states, ['01', '10', '11', '00'], 'synchronous counters work in ideal mode');
}
{
    const p = emptyProject();
    p.nodes.push(makeNode('CLOCK', 60, 60, 'clk1'), makeNode('CLOCK', 60, 240, 'clk2'), makeNode('ONE', 60, 420, 'one'), makeNode('DFF', 360, 60, 'ff1'), makeNode('DFF', 660, 240, 'ff2'));
    p.wires = [['one', 'Q', 'ff1', 'D'], ['ff1', 'Q', 'ff2', 'D'], ['clk1', 'Q', 'ff1', 'CLK'], ['clk2', 'Q', 'ff2', 'CLK']].map(([from, pin, to, input], i) => ({ id: `w${i}`, from: { node: from, pin }, to: { node: to, pin: input } }));
    const e = createEngine(p); e.pulse(); assert.equal(e.value('ff1'), 1); assert.equal(e.value('ff2'), 0, 'all clocks sample the pre-edge state together');
    e.pulse(); assert.equal(e.value('ff2'), 1);
}
{
    const p = example('half'); p.settings.mode = 'advanced'; p.nodes.find(n => n.id === 'xor').config.delay = 19;
    const e = createEngine(p); assert.equal(e.input('sum', 'A'), 'X'); e.advanceTo(18); assert.equal(e.input('sum', 'A'), 'X'); e.advanceTo(19); assert.equal(e.input('sum', 'A'), 1, 'advanced mode retains actual configured delay');
}
console.log('Mode checks passed: immediate I/O, manual clock, synchronous sampling, saved mode, legacy files, unchanged advanced timing and restored advanced simulation.');

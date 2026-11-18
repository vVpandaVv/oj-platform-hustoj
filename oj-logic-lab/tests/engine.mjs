import assert from 'node:assert/strict';
import { makeNode, emptyProject, validateProject, combinational, TYPES } from '../resources/js/model.js';
import { Engine } from '../resources/js/engine.js';
import { example, EXAMPLES } from '../resources/js/examples.js';
const circuit = (type, config = {}) => {
    const p = emptyProject(), n = makeNode(type, 360, 120, 'dut'); Object.assign(n.config, config); p.nodes.push(n);
    TYPES[type].inputs.forEach((pin, i) => { const s = makeNode('SWITCH', 60, 60 + i * 144, pin); p.nodes.push(s); p.wires.push({ id: pin, from: { node: pin, pin: 'Q' }, to: { node: 'dut', pin } }); });
    return new Engine(p);
};
let cases = 0;
for (const A of [0, 1]) for (const B of [0, 1]) {
    for (const [type, expected] of [['AND', A & B], ['OR', A | B], ['NAND', 1 - (A & B)], ['NOR', 1 - (A | B)], ['XOR', A ^ B], ['XNOR', 1 - (A ^ B)]]) { assert.equal(combinational(type, { A, B }).Q, expected); cases++; }
    assert.deepEqual(combinational('HA', { A, B }), { S: A ^ B, C: A & B });
    for (const CI of [0, 1]) { const sum = A + B + CI; assert.deepEqual(combinational('FA', { A, B, CI }), { S: sum % 2, CO: sum > 1 ? 1 : 0 }); cases++; }
}
assert.equal(combinational('AND', { A: 0, B: 'X' }).Q, 0);
assert.equal(combinational('OR', { A: 1, B: 'Z' }).Q, 1);
assert.equal(combinational('XOR', { A: 0, B: 'X' }).Q, 'X');
assert.equal(combinational('TRI', { A: 1, EN: 0 }).Q, 'Z');
assert.equal(combinational('MUX', { A: 1, B: 1, S: 'X' }).Q, 1);
assert.deepEqual(combinational('DEMUX', { A: 1, S: 0 }), { Y0: 1, Y1: 0 });
{
    const e = circuit('AND', { delay: 5 }); e.advanceTo(5); assert.equal(e.value('dut'), 0);
    e.setInput('A', 1); e.setInput('B', 1); e.step(4); assert.equal(e.value('dut'), 0); e.step(1); assert.equal(e.value('dut'), 1);
    e.setInput('A', 0); e.step(2); e.setInput('A', 1); e.step(5); assert.equal(e.value('dut'), 1, 'inertial filter cancels short pulse');
}
{
    const e = circuit('DFF', { delay: 3, setup: 2, hold: 2 });
    e.setInput('D', 1); e.step(5); e.setInput('CLK', 1); e.step(2); assert.equal(e.value('dut'), 0); e.step(1); assert.equal(e.value('dut'), 1); assert.equal(e.value('dut', 'QB'), 0);
    e.setInput('D', 0); e.step(3); assert.equal(e.value('dut'), 1, 'holds without an edge');
    e.setInput('RST', 1); e.step(3); assert.equal(e.value('dut'), 0, 'asynchronous high reset');
}
{
    const e = circuit('DFF', { delay: 2, setup: 3, hold: 0 }); e.step(5); e.setInput('D', 1); e.step(2); e.setInput('CLK', 1); e.step(2);
    assert.equal(e.value('dut'), 'X'); assert.equal(e.warnings.at(-1).kind, 'setup');
}
{
    const e = circuit('DFF', { delay: 2, setup: 0, hold: 4 }); e.step(5); e.setInput('CLK', 1); e.step(1); e.setInput('D', 1); e.step(3);
    assert.equal(e.value('dut'), 'X'); assert.equal(e.warnings.at(-1).kind, 'hold');
}
{
    const e = circuit('DFF', { edge: 'falling', setup: 0, hold: 0 }); e.setInput('D', 1); e.setInput('CLK', 1); e.step(4); assert.equal(e.value('dut'), 0); e.setInput('CLK', 0); e.step(2); assert.equal(e.value('dut'), 1);
}
for (const type of ['TFF', 'JKFF', 'SRFF', 'DLATCH', 'SRLATCH']) {
    const e = circuit(type, { setup: 0, hold: 0 });
    for (const p of type === 'TFF' ? ['T'] : type === 'JKFF' ? ['J'] : type === 'DLATCH' ? ['D', 'EN'] : ['S']) e.setInput(p, 1);
    if (type.endsWith('FF')) e.setInput('CLK', 1); e.step(2); assert.equal(e.value('dut'), 1, type);
    if (type === 'TFF' || type === 'JKFF') { if (type === 'JKFF') e.setInput('K', 1); e.setInput('CLK', 0); e.step(3); e.setInput('CLK', 1); e.step(2); assert.equal(e.value('dut'), 0); }
    if (type.startsWith('SR')) { e.setInput('R', 1); if (type.endsWith('FF')) { e.setInput('CLK', 0); e.setInput('CLK', 1); } e.step(2); assert.equal(e.value('dut'), 'X'); }
    if (type === 'DLATCH') { e.setInput('EN', 0); e.setInput('D', 0); e.step(4); assert.equal(e.value('dut'), 1); }
}
{
    const p = emptyProject(), c = makeNode('CLOCK', 60, 60, 'c'); Object.assign(c.config, { period: 10, duty: 30, phase: 4 }); p.nodes.push(c); const e = new Engine(p);
    e.advanceTo(3); assert.equal(e.value('c'), 0); e.advanceTo(4); assert.equal(e.value('c'), 1); e.advanceTo(7); assert.equal(e.value('c'), 0); e.advanceTo(14); assert.equal(e.value('c'), 1);
}
for (const [id] of EXAMPLES) { const p = validateProject(example(id)), e = new Engine(p); e.advanceTo(160); assert.equal(e.fault, '', id); }
{
    const e = new Engine(example('counter')); const states = [];
    for (const t of [12, 52, 92, 132]) { e.advanceTo(t); states.push(`${e.value('t1')}${e.value('t0')}`); }
    assert.deepEqual(states, ['01', '10', '11', '00']);
}
{
    const e = new Engine(example('hazard')); e.advanceTo(20); e.setInput('a', 1); e.advanceTo(40);
    assert.deepEqual(e.records.get('y:A').slice(-2), [{ time: 21, value: 1 }, { time: 29, value: 0 }]);
}
{
    const p = emptyProject(), n = makeNode('NOT', 120, 120, 'n'); n.config.delay = 0; p.nodes.push(n); p.wires.push({ id: 'w', from: { node: 'n', pin: 'Q' }, to: { node: 'n', pin: 'A' } });
    // Starting from X is a stable unknown, rather than an invented oscillation.
    const e = new Engine(p); e.step(100); assert.equal(e.value('n'), 'X');
}
{
    const p = emptyProject(), gate = makeNode('NAND', 360, 120, 'g'), enable = makeNode('SWITCH', 60, 120, 'enable');
    gate.config.delay = 0; p.nodes.push(gate, enable);
    p.wires.push({ id: 'feedback', from: { node: 'g', pin: 'Q' }, to: { node: 'g', pin: 'A' } }, { id: 'enable', from: { node: 'enable', pin: 'Q' }, to: { node: 'g', pin: 'B' } });
    const e = new Engine(p); assert.equal(e.value('g'), 1); e.setInput('enable', 1); assert.ok(e.fault.includes('이벤트 한도'), 'zero-delay oscillation stops instead of hanging');
}
assert.throws(() => validateProject({ ...emptyProject(), nodes: [makeNode('AND', 0, 0)] }));
const invalid = example('half'); invalid.wires.push({ ...invalid.wires[0], id: 'duplicate' }); assert.throws(() => validateProject(invalid));
console.log(`Engine checks passed: ${cases} binary combinations, four-state logic, delays, FF/latch behavior, setup/hold, clock, examples, counter, glitch, import validation.`);

import assert from 'node:assert/strict';
import { emptyProject, makeNode, validateProject, pinWidth, parseSignal, formatSignal, combinational, spec } from '../resources/js/model.js?v=1.0.2';
import { example } from '../resources/js/examples.js?v=1.0.2';
import { Engine } from '../resources/js/engine.js?v=1.0.2';
import { BeginnerEngine } from '../resources/js/modes.js?v=1.0.2';
import { makeComponent, componentNode, compileProject } from '../resources/js/compile.js?v=1.0.2';
import { truthTable, diagnose, tableCSV } from '../resources/js/analysis.js?v=1.0.2';
import { EXERCISES, exerciseProject, gradeExercise } from '../resources/js/exercises.js?v=1.0.2';
import { schematicSVG } from '../resources/js/schematic.js?v=1.0.2';
const wire = (p, a, pin, b, input) => p.wires.push({ id: `w${p.wires.length}`, from: { node: a, pin }, to: { node: b, pin: input } });
{
    const p = example('half'), before = structuredClone(p), t = truthTable(p);
    assert.equal(t.kind, 'combinational'); assert.deepEqual(t.rows.map(r => r.outputs), [[0, 0], [1, 0], [1, 0], [0, 1]]);
    assert.deepEqual(p, before, 'table generation must be pure'); assert.ok(tableCSV(t).includes('SUM'));
    const sequential = truthTable(example('dff')); assert.equal(sequential.kind, 'state'); assert.equal(sequential.rows.length, 8);
    for (const r of sequential.rows) assert.equal(r.next.ff, r.values.rst === 1 ? 0 : r.values.d);
    const loop = emptyProject(); loop.nodes.push(makeNode('NOT', 120, 120, 'n')); wire(loop, 'n', 'Q', 'n', 'A'); assert.throws(() => truthTable(loop), /피드백/);
    assert.throws(() => truthTable(example('bus')), /8개/);
}
for (const width of [2, 4, 8, 16]) {
    const max = 2 ** width - 1, binary = max.toString(2);
    assert.equal(parseSignal(`0x${max.toString(16)}`, width), binary); assert.equal(parseSignal(`0b${binary}`, width), binary); assert.equal(formatSignal(binary).slice(0, 2), '0x');
    assert.throws(() => parseSignal(2 ** width, width));
    assert.deepEqual(combinational('WADD', { A: binary, B: '0'.repeat(width - 1) + '1', CI: 0 }, width), { Q: '0'.repeat(width), CO: 1 });
    assert.equal(combinational('AND', { A: binary, B: '0'.repeat(width) }, width).Q, '0'.repeat(width));
    const split = combinational('SPLIT', { A: binary }, width); assert.equal(split.B0, 1); assert.equal(split[`B${width - 1}`], 1);
    assert.equal(combinational('JOIN', split, width).Q, binary);
}
{
    const p = example('bus'), e = new Engine(p); e.advanceTo(2); assert.equal(e.input('sum', 'A'), '10000000'); assert.equal(e.input('co', 'A'), 0);
    e.setInput('a', '11111111'); e.step(2); assert.equal(e.input('sum', 'A'), '00000000'); assert.equal(e.input('co', 'A'), 1);
    const roundtrip = validateProject(JSON.parse(JSON.stringify(p))); assert.equal(roundtrip.nodes.find(n => n.id === 'a').config.width, 8); assert.equal(roundtrip.nodes.find(n => n.id === 'a').config.value, '11111111');
    p.nodes.find(n => n.id === 'sum').config.width = 4; const mismatch = new Engine(p); assert.ok(diagnose(p, mismatch).some(i => i.code === 'width')); assert.equal(mismatch.input('sum', 'A'), 'XXXX');
}
{
    const p = emptyProject(); p.nodes.push(makeNode('BUS_IN', 60, 60, 'data'), makeNode('CLOCK', 60, 240, 'clock'), makeNode('WREG', 360, 60, 'reg'), makeNode('BUS_OUT', 720, 60, 'out'));
    wire(p, 'data', 'Q', 'reg', 'D'); wire(p, 'clock', 'Q', 'reg', 'CLK'); wire(p, 'reg', 'Q', 'out', 'A');
    const e = new BeginnerEngine(p); e.setInput('data', '1010'); assert.equal(e.value('reg'), '0000'); e.pulse(); assert.equal(e.input('out', 'A'), '1010'); assert.equal(e.value('reg', 'QB'), '0101');
}
{
    const source = example('half'), before = structuredClone(source), d = makeComponent(source, 'My half adder'); assert.deepEqual(source, before);
    const p = emptyProject(); p.components.push(d); p.nodes.push(makeNode('SWITCH', 60, 60, 'a'), makeNode('SWITCH', 60, 240, 'b'), componentNode(d, makeNode('CUSTOM', 360, 60, 'custom')), makeNode('LED', 720, 60, 's'), makeNode('LED', 720, 240, 'c'));
    wire(p, 'a', 'Q', 'custom', 'I0'); wire(p, 'b', 'Q', 'custom', 'I1'); wire(p, 'custom', 'O0', 's', 'A'); wire(p, 'custom', 'O1', 'c', 'A');
    const validated = validateProject(JSON.parse(JSON.stringify(p))), e = new BeginnerEngine(validated);
    e.setInput('a', 1); e.setInput('b', 1); assert.equal(e.value('custom', 'O0'), 0); assert.equal(e.value('custom', 'O1'), 1); assert.equal(e.input('c', 'A'), 1); assert.equal(e.input('custom', 'I0'), 1);
    assert.equal(truthTable(validated).rows.length, 4); assert.ok(!diagnose(validated, e).length);
    const nested = makeComponent(validated, 'Nested'), outer = emptyProject(); outer.components = [...validated.components, nested]; outer.nodes.push(componentNode(nested, makeNode('CUSTOM', 360, 360, 'outer'))); validateProject(outer); assert.ok(compileProject(outer).project.nodes.length > 5);
    const broken = structuredClone(p); broken.components = []; assert.throws(() => validateProject(broken), /부품/);
    const cyclic = structuredClone(p); cyclic.components[0].nodes.push(componentNode(cyclic.components[0], makeNode('CUSTOM', 1200, 720, 'cycle'))); assert.throws(() => validateProject(cyclic), /자기 자신/);
    const svg = schematicSVG(p, e); assert.ok(svg.includes('<svg')); assert.ok(svg.includes('MODULE')); assert.ok(svg.includes('My half adder')); assert.ok(!svg.includes('<script'));
    assert.deepEqual(source, before, 'component instantiation must not mutate its source circuit');
}
{
    const p = example('dff'); p.components = []; const d = makeComponent(p, 'D register');
    const outer = emptyProject(); outer.components.push(d); outer.nodes.push(componentNode(d, makeNode('CUSTOM', 360, 120, 'ff')));
    const compiled = compileProject(outer); assert.equal(compiled.project.nodes.filter(n => n.type === 'CLOCK').length, 0, 'exposed clocks become ports rather than internal oscillators');
    assert.ok(diagnose(outer, new BeginnerEngine(outer)).length >= 3);
    outer.nodes.push(makeNode('SWITCH', 60, 60, 'data1'), makeNode('SWITCH', 60, 240, 'data2'), makeNode('CLOCK', 60, 420, 'clock'), makeNode('ZERO', 60, 600, 'zero'), componentNode(d, makeNode('CUSTOM', 720, 120, 'second')));
    for (const [instance, data] of [['ff', 'data1'], ['second', 'data2']]) { wire(outer, data, 'Q', instance, 'I0'); wire(outer, 'clock', 'Q', instance, 'I1'); wire(outer, 'zero', 'Q', instance, 'I2'); }
    const independent = new BeginnerEngine(outer); independent.setInput('data1', 1); independent.pulse(); assert.equal(independent.value('ff', 'O0'), 1); assert.equal(independent.value('second', 'O0'), 0);
    independent.setInput('data1', 0); independent.setInput('data2', 1); independent.pulse(); assert.equal(independent.value('ff', 'O0'), 0); assert.equal(independent.value('second', 'O0'), 1);
    const flat = compileProject(outer).project; assert.equal(new Set(flat.nodes.map(n => n.id)).size, flat.nodes.length);
}
{
    const p = example('half'); p.settings.mode = 'advanced'; p.nodes.find(n => n.id === 'a').config.value = 0;
    p.stimuli = [{ id: 'x', time: 20, node: 'a', value: 1 }, { id: 'y', time: 20, node: 'b', value: 1 }];
    const before = structuredClone(p), e = new Engine(validateProject(p)); e.advanceTo(19); assert.equal(e.value('a'), 0); e.advanceTo(22); assert.equal(e.input('sum', 'A'), 0); assert.equal(e.input('carry', 'A'), 1); assert.deepEqual(p, before);
    assert.equal(e.nodes.get('a').config.value, 0, 'scheduled transitions must not change the saved initial input');
    const ideal = new BeginnerEngine(p); assert.equal(ideal.events.length, 0, 'beginner mode must ignore saved timed stimuli');
    assert.throws(() => validateProject({ ...p, stimuli: [...p.stimuli, p.stimuli[0]] }), /중복/);
    const bad = structuredClone(p); bad.stimuli[0].node = 'missing'; assert.throws(() => validateProject(bad), /시나리오/);
}
{
    const p = emptyProject(); p.nodes.push(makeNode('DFF', 360, 120, 'ff')); const e = new BeginnerEngine(p);
    assert.ok(diagnose(p, e).some(i => i.code === 'clock' && i.pin === 'CLK'));
    assert.ok(!diagnose(p, e).some(i => i.pin === 'RST'));
}
for (const task of EXERCISES) assert.equal(gradeExercise(exerciseProject(task.id)).passed, false, `unwired ${task.id} cannot pass`);
for (const id of ['and', 'xor', 'half', 'full', 'dff', 'counter']) {
    const p = exerciseProject(id), add = (type, name, x, y) => p.nodes.push(makeNode(type, x, y, name));
    if (['and', 'xor', 'half', 'full'].includes(id)) {
        add(id === 'and' ? 'AND' : id === 'xor' ? 'XOR' : id === 'half' ? 'HA' : 'FA', 'g', 420, 180);
        wire(p, 'inA', 'Q', 'g', 'A'); wire(p, 'inB', 'Q', 'g', 'B'); if (id === 'full') wire(p, 'inCI', 'Q', 'g', 'CI');
        wire(p, 'g', ['half', 'full'].includes(id) ? 'S' : 'Q', 'outY', 'A'); if (['half', 'full'].includes(id)) wire(p, 'g', id === 'full' ? 'CO' : 'C', 'outC', 'A');
    } else if (id === 'dff') { add('DFF', 'g', 420, 180); for (const [from, pin] of [['data', 'D'], ['clock', 'CLK'], ['reset', 'RST']]) wire(p, from, 'Q', 'g', pin); wire(p, 'g', 'Q', 'outY', 'A'); }
    else { add('TFF', 't0', 420, 96); add('TFF', 't1', 696, 336); add('ONE', 'one', 96, 96); wire(p, 'one', 'Q', 't0', 'T'); wire(p, 't0', 'Q', 't1', 'T'); for (const to of ['t0', 't1']) { wire(p, 'clock', 'Q', to, 'CLK'); wire(p, 'reset', 'Q', to, 'RST'); } wire(p, 't0', 'Q', 'outY', 'A'); wire(p, 't1', 'Q', 'outC', 'A'); }
    const before = structuredClone(p), grade = gradeExercise(p); assert.equal(grade.passed, true, `${id}: ${grade.failures}`); assert.deepEqual(p, before, 'grading must preserve the circuit');
}
{
    const p = example('half'); p.title = '<script>alert(1)</script>'; p.nodes[0].label = '<img onerror="bad">'; const svg = schematicSVG(p); assert.ok(!svg.includes('<script>')); assert.ok(!svg.includes('<img')); assert.ok(svg.includes('&lt;script&gt;'));
}
console.log('Feature checks passed: tables/state transitions, pure grading of 6 tasks, diagnostics, 2/4/8/16-bit buses, registers, custom/nested parts, validation, timed stimuli, safe SVG export and old-circuit preservation.');

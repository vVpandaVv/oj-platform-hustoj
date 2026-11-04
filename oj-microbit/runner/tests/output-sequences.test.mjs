import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluateOutputSequence } from '../src/assertions.mjs';
import { validateRequest } from '../src/validation.mjs';
import { request } from './fixtures.mjs';

const write = (atMs, value, signal = 'digital') => ({ atMs, pin: 1, signal, value, kind: 'write' });
const sequence = () => ({ pin: 1, signal: 'digital', afterMs: 0, steps: [
  { expected: 1, withinMs: 500, holdMs: 1000, tolerance: 0 },
  { expected: 0, withinMs: 500, holdMs: 100, tolerance: 0 },
] });
test('later actions begin at actual preceding completion, including delayed startup', () => {
  const result = evaluateOutputSequence([write(200, 1), write(1200, 0)], sequence());
  assert.equal(result.passed, true);
  assert.deepEqual(result.steps.map(step => [step.matchedAtMs, step.completedAtMs]), [[200, 1200], [1200, 1300]]);
});
test('transition exactly at hold completion is allowed by half-open intervals', () => {
  assert.equal(evaluateOutputSequence([write(0, 1), write(1000, 0)], sequence()).passed, true);
});
test('turning off one millisecond early fails and skips later actions', () => {
  const result = evaluateOutputSequence([write(0, 1), write(999, 0)], sequence());
  assert.equal(result.passed, false);
  assert.equal(result.steps[0].matchedAtMs, null);
  assert.deepEqual(result.steps[0].observed, { atMs: 999, signal: 'digital', value: 0 });
  assert.deepEqual(result.steps[1], { expected: 0, withinMs: 500, holdMs: 100, tolerance: 0, passed: false, matchedAtMs: null, completedAtMs: null, observed: null });
});
test('the first matching span cannot be retried after a glitch', () => {
  const result = evaluateOutputSequence([write(0, 1), write(500, 0), write(510, 1), write(1510, 0)], sequence());
  assert.equal(result.steps[0].passed, false);
  assert.equal(result.steps[0].observed.atMs, 500);
});
test('initial zero without an actual output write is not a matching state', () => {
  const input = sequence(); input.steps = [{ expected: 0, withinMs: 500, holdMs: 100, tolerance: 0 }];
  assert.equal(evaluateOutputSequence([], input).passed, false);
});
test('late startup and late final transition fail their own response deadlines', () => {
  assert.equal(evaluateOutputSequence([write(501, 1), write(1501, 0)], sequence()).steps[0].passed, false);
  const lateOff = evaluateOutputSequence([write(0, 1), write(1501, 0)], sequence());
  assert.equal(lateOff.steps[0].passed, true);
  assert.equal(lateOff.steps[1].passed, false);
});
test('wrong order and wrong signal do not satisfy output actions', () => {
  const wrongOrder = evaluateOutputSequence([write(0, 0), write(500, 1)], sequence());
  assert.deepEqual(wrongOrder.steps.map(step => step.passed), [true, false]);
  assert.equal(evaluateOutputSequence([write(0, 1, 'pwm')], sequence()).passed, false);
});
test('real output already held before sequence start remains observable', () => {
  const input = sequence(); input.afterMs = 200;
  const result = evaluateOutputSequence([write(100, 1), write(1200, 0)], input);
  assert.equal(result.passed, true);
  assert.equal(result.steps[0].matchedAtMs, 200);
  assert.equal(result.steps[0].observed.atMs, 100);
});
test('a write at the sequence boundary replaces prior state before matching begins', () => {
  const input = sequence(); input.afterMs = 100;
  input.steps = [{ expected: 1, withinMs: 100, holdMs: 100, tolerance: 0 }];
  const result = evaluateOutputSequence([write(0, 1), write(100, 0), write(150, 1), write(250, 0)], input);
  assert.equal(result.passed, true);
  assert.equal(result.steps[0].matchedAtMs, 150);
  assert.equal(result.steps[0].completedAtMs, 250);
});
test('adjacent identical actions can continue a single actual output state', () => {
  const input = sequence(); input.steps[1] = { expected: 1, withinMs: 500, holdMs: 1000, tolerance: 0 };
  const result = evaluateOutputSequence([write(0, 1), write(2000, 0)], input);
  assert.equal(result.passed, true);
  assert.equal(result.steps[1].matchedAtMs, 1000);
  assert.equal(result.steps[1].observed.atMs, 0);
});
test('same-timestamp write order cannot hide an early glitch', () => {
  assert.equal(evaluateOutputSequence([write(0, 1), write(0, 0), write(0, 1)], sequence()).steps[0].passed, false);
});
test('sequence numeric ranges, counts, channel types and worst-case duration are bounded', () => {
  const base = request(); base.exercise.scenarios[0].durationMs = 3000;
  base.exercise.scenarios[0].outputSequences = [sequence()];
  assert.doesNotThrow(() => validateRequest(structuredClone(base)));
  const mutations = [
    p => { p.exercise.scenarios[0].outputSequences = null; },
    p => { p.exercise.scenarios[0].outputSequences = Array.from({ length: 65 }, sequence); },
    p => { p.exercise.scenarios[0].outputSequences = Array.from({ length: 13 }, () => ({ ...sequence(), steps: Array.from({ length: 20 }, () => ({ expected: 1, withinMs: 1, holdMs: 1, tolerance: 0 })) })); },
    p => { p.exercise.scenarios[0].outputSequences[0].steps = []; },
    p => { p.exercise.scenarios[0].outputSequences[0].steps = Array.from({ length: 21 }, () => ({ expected: 1, withinMs: 1, holdMs: 1, tolerance: 0 })); },
    p => { p.exercise.scenarios[0].outputSequences[0].steps[0].withinMs = 0; },
    p => { p.exercise.scenarios[0].outputSequences[0].steps[0].holdMs = 0; },
    p => { p.exercise.scenarios[0].outputSequences[0].steps[0].tolerance = 1; },
    p => { p.exercise.scenarios[0].outputSequences[0].steps[0].expected = 2; },
    p => { p.exercise.scenarios[0].outputSequences[0].pin = 0; },
    p => { p.exercise.scenarios[0].outputSequences[0].afterMs = 1000; },
  ];
  for (const mutate of mutations) { const payload = structuredClone(base); mutate(payload); assert.throws(() => validateRequest(payload), TypeError); }
});

export { sequence };

// Timing explanation examples from the guided authoring UI, on the unchanged evaluator.
test('holding an immediate output for half a second satisfies a 0.2-second minimum',()=>{
  const single={pin:1,signal:'digital',afterMs:0,steps:[{expected:1,withinMs:100,holdMs:200,tolerance:0}]};
  const immediate=evaluateOutputSequence([write(0,1),write(500,0)],single);assert.equal(immediate.passed,true);assert.equal(immediate.steps[0].completedAtMs,200);
  assert.equal(evaluateOutputSequence([write(500,1)],single).passed,false);
});
test('a later off condition has its own deadline after the preceding hold completes',()=>{
  const two={pin:1,signal:'digital',afterMs:0,steps:[{expected:1,withinMs:100,holdMs:200,tolerance:0},{expected:0,withinMs:100,holdMs:200,tolerance:0}]};
  const trace=[write(0,1),write(500,0)];const result=evaluateOutputSequence(trace,two);assert.deepEqual(result.steps.map(x=>x.passed),[true,false]);
  two.steps[0].holdMs=500;assert.equal(evaluateOutputSequence(trace,two).passed,true);
});
test('parallel pin conditions share the reference time without chaining completion between pins',()=>{
  const first={pin:1,signal:'digital',afterMs:0,steps:[{expected:1,withinMs:100,holdMs:500,tolerance:0}]};const second={pin:2,signal:'pwm',afterMs:0,steps:[{expected:800,withinMs:100,holdMs:200,tolerance:0}]};const trace=[write(20,1),{...write(40,800,'pwm'),pin:2},write(520,0)];
  const led=evaluateOutputSequence(trace,first),motor=evaluateOutputSequence(trace,second);assert(led.passed&&motor.passed);assert.equal(led.steps[0].completedAtMs,520);assert.equal(motor.steps[0].matchedAtMs,40);assert.equal(motor.steps[0].completedAtMs,240);
});

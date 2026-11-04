import test from 'node:test';
import assert from 'node:assert/strict';
import { grade } from '../src/grade.mjs';
import { request, assertion } from './fixtures.mjs';

test('real PXT compilation and simulation react to pin inputs and PWM outputs', async () => {
  const result = await grade(request());
  assert.equal(result.status, 'graded');
  assert.equal(result.passed, true);
  assert.equal(result.score, 100);
  assert.equal(result.runtimeVersion, 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/3');
  assert.match(result.projectHash, /^[a-f0-9]{64}$/);
  assert.deepEqual(result.trace.filter(event => event.pin === 1).map(event => [event.atMs, event.value]), [[0, 0], [320, 1], [600, 0]]);
  assert.deepEqual(result.trace.filter(event => event.pin === 2).map(event => event.value), [0, 800, 100]);
});
test('incorrect constant output fails and zero without a write fails', async () => {
  const result = await grade(request('pins.digitalWritePin(DigitalPin.P1, 0)'));
  assert.equal(result.passed, false);
  assert.equal(result.score, 50);
  const empty = await grade(request('let unused = 1'));
  assert.equal(empty.score, 0);
});
test('a noncompiling MakeCode program never passes', async () => {
  const result = await grade(request('pins.nonexistentApi(123)'));
  assert.equal(result.status, 'compile_error');
  assert.equal(result.passed, false);
  assert.equal(result.score, 0);
  assert.ok(result.diagnostics.length > 0);
});
test('each scenario starts with fresh variables and outputs', async () => {
  const input = request('let counter = 0; basic.forever(function () { counter += 1; pins.analogWritePin(AnalogPin.P2, counter); basic.pause(1000) })');
  const scenario = { id: 'one', visible: true, durationMs: 500, inputs: [{ atMs: 0, pin: 0, signal: 'analog', value: 0 }], assertions: [assertion({ pin: 2, signal: 'pwm', expected: 1, afterMs: 0, withinMs: 20, holdMs: 100 })] };
  input.exercise.scenarios = [scenario, { ...structuredClone(scenario), id: 'two' }];
  assert.equal((await grade(input)).score, 100);
});
test('digital input fixtures use logical 0/1 while official state stores 0/1023', async () => {
  const input = request('basic.forever(function () { pins.digitalWritePin(DigitalPin.P1, pins.digitalReadPin(DigitalPin.P0)); basic.pause(20) })');
  input.exercise.connections[0].signal = 'digital';
  const scenario = input.exercise.scenarios[0];
  scenario.inputs = [{ atMs: 0, pin: 0, signal: 'digital', value: 1 }];
  scenario.assertions = [assertion({ afterMs: 0 })];
  const result = await grade(input);
  assert.equal(result.passed, true);
  assert.equal(result.trace[0].value, 1);
});
test('input signal values are never mistaken for output evidence', async () => {
  const input = request('let value = pins.analogReadPin(AnalogPin.P0)');
  const result = await grade(input);
  assert.equal(result.score, 0);
  assert.equal(result.trace.length, 0);
});
test('writing an input pin fails the runtime rather than corrupting fixture state', async () => {
  const result = await grade(request('pins.digitalWritePin(DigitalPin.P0, 1)'));
  assert.equal(result.status, 'runtime_error');
  assert.equal(result.score, 0);
});
test('reading an output pin cannot change direction and retain a stale correct output', async () => {
  const result = await grade(request('pins.digitalWritePin(DigitalPin.P1, 1); let value = pins.digitalReadPin(DigitalPin.P1)'));
  assert.equal(result.status, 'runtime_error');
  assert.equal(result.score, 0);
});
test('CPU-bound infinite loops terminate with a runtime failure', async () => {
  const result = await grade(request('while (true) {}'));
  assert.equal(result.status, 'runtime_error');
  assert.equal(result.passed, false);
});
test('preview without assertions returns actual outputs without inventing a score', async () => {
  const input = request(); input.exercise.scenarios[0].assertions = [];
  const result = await grade(input);
  assert.equal(result.status, 'preview');
  assert.equal(result.passed, null);
  assert.equal(result.score, null);
  assert.ok(result.trace.length > 0);
});
test('hidden scenarios do not supply a top-level device trace', async () => {
  const input = request(); input.exercise.scenarios[0].visible = false;
  const result = await grade(input);
  assert.equal(result.trace.length, 0);
  assert.equal(result.traceScenarioId, null);
});

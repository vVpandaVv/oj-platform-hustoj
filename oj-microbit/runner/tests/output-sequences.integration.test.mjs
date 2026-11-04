import test from 'node:test';
import assert from 'node:assert/strict';
import { grade } from '../src/grade.mjs';
import { peripheralRequest, servoConnection, tx, device } from './peripherals-fixtures.mjs';
import { assertion } from './fixtures.mjs';

function sequence(overrides = {}) {
  return { pin: 1, signal: 'digital', afterMs: 0, steps: [
    { expected: 1, withinMs: 500, holdMs: 1000, tolerance: 0 },
    { expected: 0, withinMs: 500, holdMs: 100, tolerance: 0 },
  ], ...overrides };
}
function sequenceRequest(source, outputSequence = sequence()) {
  const payload = peripheralRequest(source, [{ ...servoConnection, signal: outputSequence.signal, device: outputSequence.signal.startsWith('servo') ? 'servo' : 'led' }]);
  Object.assign(payload.exercise.scenarios[0], { durationMs: 3000, outputSequences: [outputSequence] });
  return payload;
}
test('real PXT delayed on, pause one second, off passes at actual completion boundary', async () => {
  const result = await grade(sequenceRequest('basic.pause(200); pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(1000); pins.digitalWritePin(DigitalPin.P1, 0)'));
  assert.equal(result.score, 100);
  assert.equal(result.runtimeVersion, 'pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/3');
  assert.deepEqual(result.scenarios[0].outputSequences[0].steps.map(step => [step.matchedAtMs, step.completedAtMs]), [[200, 1200], [1200, 1300]]);
});
test('real PXT early off and mid-hold glitches fail without later retries', async () => {
  for (const source of [
    'pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(999); pins.digitalWritePin(DigitalPin.P1, 0)',
    'pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(400); pins.digitalWritePin(DigitalPin.P1, 0); basic.pause(20); pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(1000); pins.digitalWritePin(DigitalPin.P1, 0)',
  ]) {
    const result = await grade(sequenceRequest(source));
    assert.equal(result.score, 0);
    assert.equal(result.scenarios[0].outputSequences[0].steps[1].observed, null);
  }
});
test('real PXT never-written zero and late startup never pass', async () => {
  const never = sequence({ steps: [{ expected: 0, withinMs: 500, holdMs: 100, tolerance: 0 }] });
  assert.equal((await grade(sequenceRequest('let unused = 1', never))).score, 0);
  const late = await grade(sequenceRequest('basic.pause(501); pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(1000); pins.digitalWritePin(DigitalPin.P1, 0)'));
  assert.equal(late.score, 0);
});
test('real PXT late off or wrong order awards only genuinely completed actions', async () => {
  for (const source of [
    'pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(1501); pins.digitalWritePin(DigitalPin.P1, 0)',
    'pins.digitalWritePin(DigitalPin.P1, 0); basic.pause(200); pins.digitalWritePin(DigitalPin.P1, 1)',
  ]) {
    const result = await grade(sequenceRequest(source));
    assert.equal(result.passed, false);
    assert.equal(result.score, 50);
  }
});
test('real PXT servo actions follow angle output completion rather than fixed times', async () => {
  const actions = sequence({ signal: 'servo', steps: [
    { expected: 45, withinMs: 200, holdMs: 300, tolerance: 0 },
    { expected: 135, withinMs: 100, holdMs: 250, tolerance: 0 },
    { expected: 90, withinMs: 100, holdMs: 100, tolerance: 0 },
  ] });
  const result = await grade(sequenceRequest('basic.pause(150); pins.servoWritePin(AnalogPin.P1, 45); basic.pause(300); pins.servoWritePin(AnalogPin.P1, 135); basic.pause(250); pins.servoWritePin(AnalogPin.P1, 90)', actions));
  assert.equal(result.score, 100);
  assert.deepEqual(result.scenarios[0].outputSequences[0].steps.map(step => step.matchedAtMs), [150, 450, 700]);
});
test('real PXT boundary writes supersede a matching state from before sequence start', async () => {
  const actions = sequence({ afterMs: 100, steps: [
    { expected: 1, withinMs: 100, holdMs: 100, tolerance: 0 },
    { expected: 0, withinMs: 100, holdMs: 100, tolerance: 0 },
  ] });
  const result = await grade(sequenceRequest('pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(100); pins.digitalWritePin(DigitalPin.P1, 0); basic.pause(50); pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(100); pins.digitalWritePin(DigitalPin.P1, 0)', actions));
  assert.equal(result.score, 100);
  assert.deepEqual(result.scenarios[0].outputSequences[0].steps.map(step => step.matchedAtMs), [150, 250]);
});
test('real PXT runtime errors clear every action result and all completion fields', async () => {
  const result = await grade(sequenceRequest('pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(1000); pins.digitalWritePin(DigitalPin.P1, 0); control.panic(1)'));
  assert.equal(result.status, 'runtime_error');
  assert.equal(result.score, 0);
  for (const step of result.scenarios[0].outputSequences[0].steps) {
    assert.equal(step.passed, false); assert.equal(step.matchedAtMs, null); assert.equal(step.completedAtMs, null); assert.equal(step.observed, null);
  }
});
test('sequence actions share the score denominator with GPIO and I2C checks', async () => {
  const payload = sequenceRequest('pins.digitalWritePin(DigitalPin.P1, 1); pins.i2cReadBuffer(32, 1, false); basic.pause(1000); pins.digitalWritePin(DigitalPin.P1, 0)');
  payload.exercise.i2cDevices = [device()];
  payload.exercise.scenarios[0].assertions = [assertion({ expected: 1, afterMs: 0 })];
  payload.exercise.scenarios[0].i2cAssertions = [tx('read', [31])];
  const result = await grade(payload);
  assert.equal(result.score, 75); // 1 GPIO + 2 action steps pass; one I2C check fails.
});
test('every independent scenario starts its sequence with fresh pin state', async () => {
  const payload = sequenceRequest('pins.digitalWritePin(DigitalPin.P1, 1); basic.pause(1000); pins.digitalWritePin(DigitalPin.P1, 0)');
  payload.exercise.scenarios.push({ ...structuredClone(payload.exercise.scenarios[0]), id: 'independent-second' });
  assert.equal((await grade(payload)).score, 100);
});

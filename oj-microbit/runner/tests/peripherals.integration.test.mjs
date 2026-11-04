import test from 'node:test';
import assert from 'node:assert/strict';
import { grade } from '../src/grade.mjs';
import { assertion } from './fixtures.mjs';
import { combinedRequest, peripheralRequest, servoConnection, tx, device } from './peripherals-fixtures.mjs';

test('real PXT servo angle sequence includes 0, 90 and 180 degrees', async () => {
  const payload = peripheralRequest('pins.servoWritePin(AnalogPin.P1, 0); basic.pause(200); pins.servoWritePin(AnalogPin.P1, 90); basic.pause(200); pins.servoWritePin(AnalogPin.P1, 180)', [servoConnection]);
  payload.exercise.scenarios[0].assertions = [0, 90, 180].map((expected, index) => assertion({ signal: 'servo', expected, afterMs: index * 200 }));
  const result = await grade(payload);
  assert.equal(result.score, 100);
  assert.deepEqual(result.trace.map(event => [event.atMs, event.signal, event.value]), [[0, 'servo', 0], [200, 'servo', 90], [400, 'servo', 180]]);
});
test('real PXT servo pulse sequence records microseconds and inclusive policy limits', async () => {
  const payload = peripheralRequest('pins.servoSetPulse(AnalogPin.P1, 1); basic.pause(200); pins.servoSetPulse(AnalogPin.P1, 1500); basic.pause(200); pins.servoSetPulse(AnalogPin.P1, 20000)', [{ ...servoConnection, signal: 'servo_pulse' }]);
  payload.exercise.scenarios[0].assertions = [1, 1500, 20000].map((expected, index) => assertion({ signal: 'servo_pulse', expected, afterMs: index * 200 }));
  const result = await grade(payload);
  assert.equal(result.score, 100);
  assert.deepEqual(result.trace.map(event => event.value), [1, 1500, 20000]);
});
test('changing a pin from angle to pulse mode invalidates the angle hold interval', async () => {
  const payload = peripheralRequest('pins.servoWritePin(AnalogPin.P1, 90); basic.pause(50); pins.servoSetPulse(AnalogPin.P1, 1500)', [servoConnection]);
  payload.exercise.scenarios[0].assertions = [assertion({ signal: 'servo', expected: 90, afterMs: 0, withinMs: 10, holdMs: 100 })];
  const result = await grade(payload);
  assert.equal(result.score, 0);
  assert.deepEqual(result.trace.map(event => event.signal), ['servo', 'servo_pulse']);
});
test('servo range violations and continuous rotation APIs fail closed', async () => {
  for (const source of ['pins.servoWritePin(AnalogPin.P1, 181)', 'pins.servoSetPulse(AnalogPin.P1, 0)', 'pins.servoSetPulse(AnalogPin.P1, 20001)', 'pins.servoSetContinuous(AnalogPin.P1, true)']) {
    const result = await grade(peripheralRequest(source, [servoConnection]));
    assert.equal(result.status, 'runtime_error', source);
  }
});
test('selecting positional servo mode is compatible and does not invent output evidence', async () => {
  const good = peripheralRequest('pins.servoSetContinuous(AnalogPin.P1, false); pins.servoWritePin(AnalogPin.P1, 90)', [servoConnection]);
  good.exercise.scenarios[0].assertions = [assertion({ signal: 'servo', expected: 90, afterMs: 0 })];
  assert.equal((await grade(good)).score, 100);
  good.project.files['main.ts'] = 'pins.servoSetContinuous(AnalogPin.P1, false)';
  good.exercise.scenarios[0].assertions[0].expected = 0;
  const unwritten = await grade(good);
  assert.equal(unwritten.score, 0);
  assert.deepEqual(unwritten.trace, []);
});
test('bundled servo package routes positional blocks through real PXT shims', async () => {
  for (const [source, signal, expected] of [
    ['servos.P1.setAngle(90)', 'servo', 90],
    ['servos.P1.setPulse(1500)', 'servo_pulse', 1500],
  ]) {
    const payload = peripheralRequest(source, [{ ...servoConnection, signal }]);
    const config = JSON.parse(payload.project.files['pxt.json']);
    config.dependencies.servo = '*';
    payload.project.files['pxt.json'] = JSON.stringify(config);
    payload.exercise.scenarios[0].assertions = [assertion({ signal, expected, afterMs: 0 })];
    assert.equal((await grade(payload)).score, 100, source);
    payload.project.files['main.ts'] = 'servos.P1.run(100)';
    assert.equal((await grade(payload)).status, 'runtime_error');
  }
});
test('real PXT combined I2C sensor, servo and actuator react to timed register inputs', async () => {
  const result = await grade(combinedRequest());
  assert.equal(result.score, 100);
  assert.equal(result.passed, true);
  assert.deepEqual(result.trace.map(event => [event.atMs, event.value]), [[0, 30], [520, 90], [1040, 150]]);
  assert.equal(result.i2cTrace.length, 9);
  assert.deepEqual(result.i2cTrace.filter(event => event.operation === 'read').map(event => event.data), [[30], [90], [150]]);
  assert.ok(result.scenarios[0].i2cAssertions.every(item => item.passed && item.observed.atMs === item.matchedAtMs));
});
test('real PXT buffer transactions update registers and wrap from 255 to 0', async () => {
  const source = `let bytes = pins.createBuffer(3); bytes[0] = 255; bytes[1] = 7; bytes[2] = 9; pins.i2cWriteBuffer(32, bytes, false); pins.i2cWriteNumber(32, 255, NumberFormat.UInt8LE, true); let read = pins.i2cReadBuffer(32, 2, false); pins.digitalWritePin(DigitalPin.P1, read[0] === 7 && read[1] === 9 ? 1 : 0)`;
  const payload = peripheralRequest(source, [{ ...servoConnection, signal: 'digital', device: 'led' }], [device([{ register: 255, value: 0, writable: true }, { register: 0, value: 0, writable: true }])]);
  payload.exercise.scenarios[0].assertions = [assertion({ expected: 1, afterMs: 0 })];
  payload.exercise.scenarios[0].i2cAssertions = [tx('write', [255, 7, 9]), tx('write', [255], { repeated: true }), tx('read', [7, 9])];
  const result = await grade(payload);
  assert.equal(result.score, 100);
  assert.deepEqual(result.i2cTrace.map(event => event.data), [[255, 7, 9], [255], [7, 9]]);
});
test('real PXT writeNumber preserves little and big endian encoding', async () => {
  const source = `pins.i2cWriteNumber(32, 0x3412, NumberFormat.UInt16LE, false); pins.i2cWriteNumber(32, 0x1256, NumberFormat.UInt16BE, false); pins.i2cWriteNumber(32, 18, NumberFormat.UInt8BE, true); let value = pins.i2cReadNumber(32, NumberFormat.UInt8BE, false); pins.digitalWritePin(DigitalPin.P1, value === 86 ? 1 : 0)`;
  const payload = peripheralRequest(source, [{ ...servoConnection, signal: 'digital', device: 'led' }], [device([{ register: 18, value: 0, writable: true }])]);
  payload.exercise.scenarios[0].assertions = [assertion({ afterMs: 0 })];
  payload.exercise.scenarios[0].i2cAssertions = [tx('write', [18, 52]), tx('write', [18, 86]), tx('write', [18], { repeated: true }), tx('read', [86])];
  assert.equal((await grade(payload)).score, 100);
});
test('real PXT readNumber uses endian and signed number formats', async () => {
  const source = `pins.i2cWriteNumber(32, 0, NumberFormat.UInt8LE, true); let le = pins.i2cReadNumber(32, NumberFormat.UInt16LE, false); pins.i2cWriteNumber(32, 0, NumberFormat.UInt8LE, true); let be = pins.i2cReadNumber(32, NumberFormat.UInt16BE, false); pins.i2cWriteNumber(32, 2, NumberFormat.UInt8LE, true); let signed = pins.i2cReadNumber(32, NumberFormat.Int8LE, false); pins.digitalWritePin(DigitalPin.P1, le === 4660 && be === 13330 && signed === -1 ? 1 : 0)`;
  const payload = peripheralRequest(source, [{ ...servoConnection, signal: 'digital', device: 'led' }], [device([{ register: 0, value: 52 }, { register: 1, value: 18 }, { register: 2, value: 255 }])]);
  payload.exercise.scenarios[0].assertions = [assertion({ afterMs: 0 })];
  payload.exercise.scenarios[0].i2cAssertions = [tx('write', [0], { repeated: true }), tx('read', [52, 18]), tx('write', [0], { repeated: true }), tx('read', [52, 18]), tx('write', [2], { repeated: true }), tx('read', [255])];
  assert.equal((await grade(payload)).score, 100);
});
test('I2C-only exercises work without GPIO connections', async () => {
  const payload = peripheralRequest('pins.i2cReadBuffer(32, 1, false)', [], [device()]);
  payload.exercise.scenarios[0].i2cAssertions = [tx('read', [30])];
  const result = await grade(payload);
  assert.equal(result.score, 100);
  assert.equal(result.trace.length, 0);
  assert.equal(result.i2cTrace.length, 1);
});
test('wrong address, bytes and repeated flag each fail transaction matching', async () => {
  for (const changes of [{ address: 33 }, { data: [31] }, { repeated: true }]) {
    const payload = peripheralRequest('pins.i2cReadBuffer(32, 1, false)', [], [device(), device(undefined, 33)]);
    payload.exercise.scenarios[0].i2cAssertions = [tx('read', [30], changes)];
    assert.equal((await grade(payload)).score, 0, JSON.stringify(changes));
  }
});
test('transaction order matters and a single event cannot satisfy two assertions', async () => {
  const wrongOrder = peripheralRequest('pins.i2cReadBuffer(32, 1, false); pins.i2cWriteNumber(32, 0, NumberFormat.UInt8LE, true)', [], [device()]);
  wrongOrder.exercise.scenarios[0].i2cAssertions = [tx('write', [0], { repeated: true }), tx('read', [30])];
  assert.equal((await grade(wrongOrder)).score, 50);
  const duplicate = peripheralRequest('pins.i2cReadBuffer(32, 1, false)', [], [device()]);
  duplicate.exercise.scenarios[0].i2cAssertions = [tx('read', [30]), tx('read', [30])];
  assert.equal((await grade(duplicate)).score, 50);
});
test('late I2C responses fail the configured deadline', async () => {
  const payload = peripheralRequest('basic.pause(200); pins.i2cReadBuffer(32, 1, false)', [], [device()]);
  payload.exercise.scenarios[0].i2cAssertions = [tx('read', [30])];
  assert.equal((await grade(payload)).score, 0);
});
test('undeclared devices/registers and invalid transfer sizes fail closed', async () => {
  for (const source of ['pins.i2cReadBuffer(33, 1, false)', 'pins.i2cReadBuffer(32, 3, false)', 'pins.i2cReadBuffer(32, 65, false)', 'pins.i2cReadBuffer(32, 0, false)', 'pins.i2cWriteNumber(32, 5, NumberFormat.UInt8LE, false)', 'let bytes = pins.createBuffer(65); pins.i2cWriteBuffer(32, bytes, false)']) {
    const result = await grade(peripheralRequest(source, [], [device()]));
    assert.equal(result.status, 'runtime_error', source);
  }
});
test('read-only sensor registers reject program writes but allow pointer selection and environment updates', async () => {
  const bad = peripheralRequest('pins.i2cWriteNumber(32, 99, NumberFormat.UInt16BE, false)', [], [device()]);
  assert.equal((await grade(bad)).status, 'runtime_error');
  const good = peripheralRequest('pins.i2cWriteNumber(32, 0, NumberFormat.UInt8LE, true); pins.i2cReadBuffer(32, 1, false)', [], [device()]);
  good.exercise.scenarios[0].i2cInputs = [{ atMs: 0, address: 32, register: 0, value: 60 }];
  good.exercise.scenarios[0].i2cAssertions = [tx('write', [0], { repeated: true }), tx('read', [60])];
  assert.equal((await grade(good)).score, 100);
});
test('each scenario resets I2C pointer and written register values', async () => {
  const payload = peripheralRequest('let value = pins.i2cReadNumber(32, NumberFormat.UInt8LE, false); pins.digitalWritePin(DigitalPin.P1, value === 30 ? 1 : 0); pins.i2cWriteNumber(32, 99, NumberFormat.UInt16BE, false)', [{ ...servoConnection, signal: 'digital', device: 'led' }], [device([{ register: 0, value: 30, writable: true }])]);
  const scenario = payload.exercise.scenarios[0];
  scenario.assertions = [assertion({ afterMs: 0 })];
  scenario.i2cAssertions = [tx('read', [30]), tx('write', [0, 99])];
  payload.exercise.scenarios.push({ ...structuredClone(scenario), id: 'fresh' });
  assert.equal((await grade(payload)).score, 100);
});
test('I2C bus pins cannot be repurposed by undeclared GPIO operations', async () => {
  for (const source of ['pins.digitalReadPin(DigitalPin.P19)', 'pins.digitalWritePin(DigitalPin.P20, 1)', 'pins.analogReadPin(AnalogPin.P19)', 'pins.analogWritePin(AnalogPin.P20, 1)', 'pins.servoWritePin(AnalogPin.P19, 90)', 'pins.servoSetPulse(AnalogPin.P20, 1500)', 'pins.setPull(DigitalPin.P19, PinPullMode.PullUp)', 'pins.analogSetPeriod(AnalogPin.P20, 20000)']) {
    assert.equal((await grade(peripheralRequest(source, [], [device()]))).status, 'runtime_error', source);
  }
});
test('I2C preview has real traces without a score and hidden traces stay private', async () => {
  const payload = combinedRequest();
  payload.exercise.scenarios[0].assertions = [];
  payload.exercise.scenarios[0].i2cAssertions = [];
  const preview = await grade(payload);
  assert.equal(preview.status, 'preview');
  assert.equal(preview.score, null);
  assert.equal(preview.passed, null);
  assert.ok(preview.i2cTrace.length > 0 && preview.trace.length > 0);
  payload.exercise.scenarios[0].visible = false;
  const hidden = await grade(payload);
  assert.deepEqual(hidden.i2cTrace, []);
  assert.deepEqual(hidden.trace, []);
  assert.equal(hidden.traceScenarioId, null);
});

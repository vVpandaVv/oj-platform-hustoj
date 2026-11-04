import test from 'node:test';
import assert from 'node:assert/strict';
import { validateRequest } from '../src/validation.mjs';
import { request } from './fixtures.mjs';
import { combinedRequest } from './peripherals-fixtures.mjs';

test('canonical GPIO request is accepted', () => { assert.equal(validateRequest(request()).exercise.scenarios.length, 1); });
test('external dependencies are rejected', () => {
  const input = request();
  const config = JSON.parse(input.project.files['pxt.json']);
  config.dependencies.evil = 'github:attacker/package';
  input.project.files['pxt.json'] = JSON.stringify(config);
  assert.throws(() => validateRequest(input), /bundled/);
});
test('configuration hooks and unsafe file paths are rejected', () => {
  const input = request();
  input.project.files['../main.ts'] = 'evil';
  assert.throws(() => validateRequest(input), /file/);
  delete input.project.files['../main.ts'];
  const config = JSON.parse(input.project.files['pxt.json']);
  config.additionalFilePath = '/etc';
  input.project.files['pxt.json'] = JSON.stringify(config);
  assert.throws(() => validateRequest(input), /configuration/);
});
test('input injection into an output channel is rejected', () => {
  const input = request(); input.exercise.scenarios[0].inputs[0].pin = 1;
  assert.throws(() => validateRequest(input), /connection/);
});
test('analog input and PWM maximum is 1023', () => {
  const input = request(); input.exercise.scenarios[0].inputs[0].value = 1024;
  assert.throws(() => validateRequest(input), /range/);
});
test('timing assertion must fit its scenario', () => {
  const input = request(); input.exercise.scenarios[0].assertions[0].holdMs = 1000;
  assert.throws(() => validateRequest(input), /exceeds/);
});
test('I2C rejects invalid addresses, register bytes, permissions, transfer bounds and reserved GPIO pins', () => {
  const mutations = [
    p => { p.exercise.i2cDevices[0].address = 128; },
    p => { p.exercise.i2cDevices[0].registers[0].register = 256; },
    p => { p.exercise.i2cDevices[0].registers[0].value = 256; },
    p => { p.exercise.i2cDevices[0].registers[0].writable = 'true'; },
    p => { p.exercise.i2cDevices.push(structuredClone(p.exercise.i2cDevices[0])); },
    p => { p.exercise.i2cDevices[0].registers.push(structuredClone(p.exercise.i2cDevices[0].registers[0])); },
    p => { p.exercise.scenarios[0].i2cInputs[0].register = 99; },
    p => { p.exercise.scenarios[0].i2cAssertions[0].data = []; },
    p => { p.exercise.scenarios[0].i2cAssertions[0].data = Array(65).fill(0); },
    p => { p.exercise.scenarios[0].i2cAssertions[0].data = [256]; },
    p => { p.exercise.scenarios[0].i2cAssertions[0].repeated = 1; },
    p => { p.exercise.scenarios[0].i2cAssertions[0].withinMs = 1401; },
    p => { p.exercise.connections[0].pin = 19; },
  ];
  for (const mutate of mutations) {
    const payload = combinedRequest(); mutate(payload);
    assert.throws(() => validateRequest(payload), TypeError);
  }
});
test('servo assertion ranges stay distinct from PWM and digital values', () => {
  for (const [signal, value] of [['servo', 181], ['servo_pulse', 0], ['servo_pulse', 20001]]) {
    const payload = combinedRequest();
    payload.exercise.connections[0].signal = signal;
    payload.exercise.scenarios[0].assertions = [{ ...payload.exercise.scenarios[0].assertions[0], signal, expected: value }];
    assert.throws(() => validateRequest(payload), /range/);
  }
});

test('official workspace metadata is excluded and source references stay consistent', () => {
  const input = request();
  input.project.files['.simstate.json'] = '{}'; input.project.files['.git.json'] = '{}'; input.project.files._history = '{}';
  const source = input.project.files['main.ts'];
  const config = JSON.parse(input.project.files['pxt.json']);
  config.files.push('.simstate.json', '.git.json', '_history'); config.testFiles = ['_history'];
  input.project.files['pxt.json'] = JSON.stringify(config);
  validateRequest(input);
  assert.deepEqual(Object.keys(input.project.files).sort(), ['main.ts', 'pxt.json']);
  assert.equal(input.project.files['main.ts'], source);
  assert.deepEqual(JSON.parse(input.project.files['pxt.json']).files, ['main.ts']);
  assert.deepEqual(JSON.parse(input.project.files['pxt.json']).testFiles, []);
});

test('Unicode basenames and MakeCode resource files are allowed without allowing paths', () => {
  const input = request();
  input.project.files['센서 도우미.ts'] = 'function sensor(): number { return 1; }';
  input.project.files['images.g.jres'] = '{}'; input.project.files['측정 자료.txt'] = '자료'; input.project.files['_onCodeStart.ts'] = '';
  input.project.files['notes..copy.txt'] = 'A flat basename.';
  const config = JSON.parse(input.project.files['pxt.json']);
  config.files.push('센서 도우미.ts', 'images.g.jres', '측정 자료.txt', '_onCodeStart.ts');
  input.project.files['pxt.json'] = JSON.stringify(config);
  assert.equal(validateRequest(input).project.files['측정 자료.txt'], '자료');
  assert.equal(input.project.files['notes..copy.txt'], 'A flat basename.');
  for (const filename of ['../.simstate.json', 'folder/.git.json', 'C:\\센서.ts', '/센서.ts', 'https://example.test/센서.ts', '.unknown.json', '센서\n.ts', 'CON .ts', 'bad:name.ts']) {
    const bad = request(); bad.project.files[filename] = '{}';
    assert.throws(() => validateRequest(bad), /file/);
  }
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { compileProject, studentSourceDiagnostics } from '../src/compiler.mjs';
import { grade } from '../src/grade.mjs';
import { project, request } from './fixtures.mjs';

test('student declarations and declaration files are outside the supported source format', () => {
  for (const source of ['declare let value: number;', 'declare function sensor(): number;', 'declare namespace External { let value: number; }', 'export declare class Device {}']) {
    const diagnostics = studentSourceDiagnostics({ 'main.ts': source });
    assert.equal(diagnostics[0]?.code, 95002);
    assert.equal(diagnostics[0].file, 'main.ts');
    assert.equal(diagnostics[0].line, 1);
  }
  assert.equal(studentSourceDiagnostics({ 'types.d.ts': '' })[0]?.code, 95001);
});

test('native linkage annotations are checked as attached TypeScript comments', () => {
  for (const metadata of ['shim', 'helper', 'shimArgument', 'indexerGet', 'indexerSet', 'autoCreate', 'callingConvention', 'async', 'promise', 'enumval', 'native', 'extern']) {
    const diagnostics = studentSourceDiagnostics({ 'main.ts': `//% ${metadata}=reserved\nfunction custom(): number { return 1; }` });
    assert.equal(diagnostics[0]?.code, 95003, metadata);
  }
  const variable = studentSourceDiagnostics({ 'main.ts': '//% helper=reserved\nlet custom = 1;' });
  assert.equal(variable[0]?.code, 95003);
  const method = studentSourceDiagnostics({ 'main.ts': 'class Device {\n//% shim=reserved\nread(): number { return 1; }\n}' });
  assert.equal(method[0]?.code, 95003);
  for (const attributes of ['block="custom label" helper=reserved', 'block="custom label" color=120 shim=reserved']) {
    assert.equal(studentSourceDiagnostics({ 'main.ts': `//% ${attributes}\nfunction custom(): number { return 1; }` })[0]?.code, 95003);
  }
});

test('ordinary functions, identifiers, strings, comments and UI labels remain allowed', () => {
  const source = [
    '// Ambient declare, shim and helper are ordinary explanatory words here.',
    'let shim = 1;',
    'let helper = "//% shim=ordinary string";',
    '//% block="a helper and shim label"',
    'function add(value: number): number { return value + shim; }',
    'let angle = add(89);',
    'pins.servoWritePin(AnalogPin.P1, angle);',
  ].join('\n');
  assert.deepEqual(studentSourceDiagnostics({ 'main.ts': source }), []);
  assert.deepEqual(studentSourceDiagnostics({ 'main.ts': 'let helper = `declare shim helper`;', 'README.md': 'declare shim helper' }), []);
  assert.deepEqual(studentSourceDiagnostics({ 'main.ts': '//% block="literal //% helper=display text" color=120\nfunction custom(): number { return 1; }' }), []);
});

test('policy checks all student TypeScript files without scanning trusted package sources', async () => {
  const input = project('pins.digitalWritePin(DigitalPin.P1, 1)');
  input.files['support.ts'] = 'declare let ambientValue: number;';
  const rejected = await compileProject(input);
  assert.equal(rejected.success, false);
  assert.equal(rejected.diagnostics[0].file, 'support.ts');
  assert.equal(rejected.js, undefined);
  const valid = project('servos.P1.setAngle(90)');
  const config = JSON.parse(valid.files['pxt.json']);
  config.dependencies.servo = '*';
  valid.files['pxt.json'] = JSON.stringify(config);
  assert.equal((await compileProject(valid)).success, true);
});

test('unsupported source returns a bounded safe compile error verdict', async () => {
  const input = request('declare function sensor(): number;');
  const result = await grade(input);
  assert.equal(result.status, 'compile_error');
  assert.equal(result.passed, false);
  assert.equal(result.score, 0);
  assert.deepEqual(result.scenarios, []);
  assert.deepEqual(result.trace, []);
  assert.deepEqual(result.i2cTrace, []);
  assert.equal(result.diagnostics[0].code, 95002);
  assert.ok(result.diagnostics.length <= 30);
  assert.ok(!result.diagnostics[0].message.includes('sensor'));
});

test('normal GPIO, servo and I2C source still compiles with user-defined functions', async () => {
  const input = project([
    'function readSensor(): number {',
    '  pins.i2cWriteNumber(64, 0, NumberFormat.UInt8BE, true);',
    '  return pins.i2cReadNumber(64, NumberFormat.UInt8BE, false);',
    '}',
    'let angle = readSensor();',
    'pins.servoWritePin(AnalogPin.P1, angle);',
    'pins.digitalWritePin(DigitalPin.P2, angle > 90 ? 1 : 0);',
  ].join('\n'));
  const result = await compileProject(input);
  assert.equal(result.success, true);
  assert.ok(result.js.length > 0);
});

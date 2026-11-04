const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const { parseHTML } = require('linkedom');
const source = fs.readFileSync(path.join(__dirname, '../js/microbit.js'), 'utf8');
const liveApps = new Set();
test.afterEach(() => { liveApps.forEach(app => app.destroy()); liveApps.clear(); });

function environment(html) {
  const listeners = new Map();
  const window = {
    crypto: { randomUUID: () => '4e874ad8-bcab-4517-a27a-20fc1853d4d2' },
    setTimeout, clearTimeout, navigator: {}, isSecureContext: true,
    addEventListener(type, callback) { if (!listeners.has(type)) listeners.set(type, new Set()); listeners.get(type).add(callback); },
    removeEventListener(type, callback) { listeners.get(type)?.delete(callback); },
    sessionStorage: { data: new Map(), getItem(key) { return this.data.get(key) || null; }, setItem(key, value) { this.data.set(key, value); }, removeItem(key) { this.data.delete(key); } },
  };
  if (html) window.document = parseHTML(html).document;
  vm.runInNewContext(source, { window, URL, Blob, console, module: { exports: {} } });
  return { window, api: window.OJMicrobit, message(data, origin, frameSource) { listeners.get('message')?.forEach(callback => callback({ data, origin, source: frameSource })); } };
}
function driverFixture() {
  const env = environment(), sent = [], handlers = {};
  const frame = { contentWindow: { postMessage(data, origin) { sent.push({ data, origin }); } }, addEventListener(type, fn) { handlers[type] = fn; }, removeEventListener(type) { delete handlers[type]; } };
  const driver = new env.api.EditorDriver(frame, { project: env.api.sampleExercise().starterProject });
  return { ...env, frame, sent, handlers, driver, receive(data, origin = 'https://makecode.microbit.org', source = frame.contentWindow) { env.message(data, origin, source); } };
}

test('native HEX validation rejects corrupt or incomplete firmware', () => {
  const { api } = environment();
  const valid = ':10010000214601360121470136007EFE09D2190140\n:00000001FF\n';
  assert.equal(api.validHex(valid), true);
  assert.equal(api.validHex(valid.replace('\n', '\n\n\n')), true);
  assert.equal(api.validHex(valid.replace('\n', '\r\n  \r\n\r\n')), true);
  assert.equal(api.validHex(valid.replace('0140', '0141')), false);
  assert.equal(api.validHex(valid.replace('0140', '0141').replace('\n', '\n\n')), false);
  assert.equal(api.validHex(valid.split('\n')[0]), false);
  assert.equal(api.validHex('<script>fake hex</script>'), false);
});

test('controller uses App mode and exact origin, ignores unrelated messages', async () => {
  const f = driverFixture();
  assert.equal(new URL(f.frame.src).searchParams.get('controller'), '2');
  const promise = f.driver.request('info', {}, 500);
  const request = f.sent.at(-1);
  assert.equal(request.origin, 'https://makecode.microbit.org');
  f.receive({ type: 'pxteditor', id: request.data.id, success: true, resp: 'evil' }, 'https://evil.example');
  f.receive({ type: 'pxteditor', id: request.data.id, success: true, resp: 'evil' }, 'https://makecode.microbit.org', {});
  assert.equal(f.driver.pending.size, 1);
  f.receive({ type: 'pxteditor', id: request.data.id, success: true, resp: 'allowed' });
  assert.equal((await promise).resp, 'allowed'); f.driver.dispose();
});

test('workspace sync and save preserve source and native blocks', async () => {
  const f = driverFixture();
  f.receive({ type: 'pxthost', id: 'sync', action: 'workspacesync', response: true });
  assert.equal(f.sent.at(-1).data.projects[0].header.id, f.driver.projectId);
  const project = f.api.sampleExercise().starterProject;
  project.files['main.ts'] = 'pins.digitalWritePin(DigitalPin.P1, 1)';
  project.files['main.blocks'] = '<xml><block type="device_set_digital_pin"/></xml>';
  project.files._history = '{"entries":[]}';
  f.receive({ type: 'pxthost', action: 'workspacesave', project: { header: { id: 'different', name: 'Wrong' }, text: project.files } });
  assert.notEqual(f.driver.project.files['main.ts'], project.files['main.ts']);
  f.receive({ type: 'pxthost', action: 'workspacesave', project: { header: { id: f.driver.projectId, name: 'Saved' }, text: project.files } });
  f.driver.loaded = true;
  const promise = f.driver.capture(), request = f.sent.at(-1).data;
  assert.equal(request.action, 'saveproject');
  f.receive({ type: 'pxteditor', id: request.id, success: true });
  const captured = await promise;
  assert.equal(captured.files['main.ts'], project.files['main.ts']);
  assert.equal(captured.files['main.blocks'], project.files['main.blocks']);
  assert.equal(captured.files._history, undefined);
  assert.equal(captured.name, 'Saved'); f.driver.dispose();
});

test('compilation waits for both controller acknowledgement and real HEX', async () => {
  const f = driverFixture(); f.driver.loaded = true;
  const promise = f.driver.compileHex();
  let request = f.sent.at(-1).data;
  f.receive({ type: 'pxteditor', id: request.id, success: true });
  await new Promise(resolve => setTimeout(resolve, 0));
  request = f.sent.at(-1).data;
  assert.equal(request.action, 'compile');
  const hex = ':10010000214601360121470136007EFE09D2190140\n:00000001FF\n';
  f.receive({ name: 'actual-firmware.hex', download: hex });
  f.receive({ type: 'pxteditor', id: request.id, success: true });
  const result = await promise;
  assert.equal(result.hex, hex); assert.equal(result.name, 'actual-firmware'); f.driver.dispose();
});

test('failed controller operations, timeout and disposal reject requests', async () => {
  const f = driverFixture();
  const failure = f.driver.request('compile'), message = f.sent.at(-1).data;
  f.receive({ type: 'pxteditor', id: message.id, success: false });
  await assert.rejects(failure, /완료하지/);
  await assert.rejects(f.driver.request('info', {}, 5), /초과/);
  const pending = f.driver.request('saveproject'); f.driver.dispose();
  await assert.rejects(pending, /닫혔/);
});

test('ready handshake does not echo indefinitely', () => {
  const f = driverFixture(), count = f.sent.length;
  f.receive({ type: 'iframeclientready' });
  assert.equal(f.sent.length, count); f.driver.dispose();
});

test('sample tests include initial inputs and all explicit timing conditions', () => {
  const { api } = environment(), exercise = api.sampleExercise();
  const scenario = exercise.scenarios[0];
  assert.ok(scenario.inputs.some(entry => entry.atMs === 0 && entry.pin === 0));
  scenario.assertions.forEach(entry => { assert.ok(entry.withinMs > 0 && entry.holdMs > 0); assert.ok(entry.afterMs + entry.withinMs + entry.holdMs <= scenario.durationMs); });
  assert.equal(api.canonicalProject(exercise.starterProject).files['main.ts'].includes('pins.analogReadPin'), true);
});

function appFixture(mode = 'student') {
  const f = environment('<html><body><div class="ojmicrobit"><p data-microbit-status></p><div data-microbit-content></div></div></body></html>');
  const root = f.window.document.querySelector('.ojmicrobit');
  root.setAttribute('data-config', JSON.stringify({ mode, restUrl: '/wp-json/oj-microbit/v1/', nonce: 'nonce-test', userId: 7, editorUrl: 'https://makecode.microbit.org/' }));
  const app = new f.api.App(root); liveApps.add(app); return { ...f, root, app };
}

test('REST saves send same-origin nonce and project revision', async () => {
  const f = appFixture(); let captured;
  f.window.fetch = async (url, options) => { captured = { url, options }; return { ok: true, json: async () => ({ revision: 4 }) }; };
  await f.app.api('exercises/3/project', { project: f.api.sampleExercise().starterProject, revision: 3 });
  assert.equal(captured.url, '/wp-json/oj-microbit/v1/exercises/3/project');
  assert.equal(captured.options.headers['X-WP-Nonce'], 'nonce-test');
  assert.equal(captured.options.credentials, 'same-origin');
  assert.equal(JSON.parse(captured.options.body).revision, 3); f.app.destroy();
});

test('compile errors display diagnostics instead of score or pass', () => {
  const f = appFixture(); f.app.results = f.window.document.createElement('div');
  f.app.renderResult({ status: 'compile_error', passed: false, score: 0, requestId: 'a'.repeat(32), scenarios: [], diagnostics: [{ file: 'main.ts', line: 2, message: '<unsafe> unknown name' }] });
  assert.match(f.app.results.textContent, /컴파일 오류/);
  assert.match(f.app.results.textContent, /main.ts:2/);
  assert.match(f.app.results.textContent, new RegExp('문의 번호 ' + 'a'.repeat(32)));
  assert.doesNotMatch(f.app.results.textContent, /0점/);
  assert.equal(f.app.results.querySelector('unsafe'), null); f.app.destroy();
});

test('actuator pane uses matching actual writes, never injected input values', async () => {
  const f = appFixture(); f.app.exercise = f.api.sampleExercise();
  const parent = f.window.document.createElement('div'); f.app.mountPeripherals(parent);
  f.app.playTrace([{ atMs: 0, pin: 1, signal: 'digital', value: 1, kind: 'input' }, { atMs: 0, pin: 2, signal: 'pwm', value: 800, kind: 'write' }], 20);
  await new Promise(resolve => setTimeout(resolve, 5));
  assert.equal(f.app.actuators.get('1').output.textContent, '출력 대기');
  assert.match(f.app.actuators.get('2').output.textContent, /^800/);
  f.app.clearTrace(); f.app.destroy();
});

test('teacher editor has editable pin, signal, input and time assertion controls', () => {
  const f = appFixture('teacher'); f.app.edit(f.api.sampleExercise());
  const connections = f.root.querySelector('[data-mb-connections]');
  assert.equal(connections.querySelectorAll('tbody tr').length, 3);
  const scenarios = f.root.querySelector('[data-mb-scenarios]');
  const advanced = scenarios.querySelector('[data-mb-advanced]'); assert.equal(advanced.querySelectorAll('tbody').length, 0); advanced.open = true; advanced.dispatchEvent(new f.window.document.defaultView.Event('toggle'));
  assert.equal(scenarios.querySelectorAll('tbody').length, 4);
  assert.equal(scenarios.querySelectorAll('tbody')[0].querySelectorAll('tr').length, 3);
  assert.equal(scenarios.querySelectorAll('tbody')[1].querySelectorAll('tr').length, 6);
  assert.match(scenarios.textContent, /응답 제한/);
  assert.match(scenarios.textContent, /유지 시간/);
  const addConnection = [...connections.querySelectorAll('button')].find(node => node.textContent === '연결 추가');
  addConnection.click();
  assert.equal(f.app.exercise.connections.length, 4); f.app.destroy();
});

test('student save retains server revision and sends fresh captured project', async () => {
  const f = appFixture(); const exercise = { ...f.api.sampleExercise(), id: 3, revision: 2 }; let sent;
  f.app.runnerReady = true;
  f.app.api = async (path, body) => {
    if (path === 'exercises/3/project' && body === undefined) return { project: exercise.starterProject, revision: 5, exerciseRevision: 2 };
    if (path === 'exercises/3/project') { sent = body; return { project: body.project, revision: 6, exerciseRevision: 2 }; }
    throw new Error('Unexpected test endpoint ' + path);
  };
  await f.app.learn(exercise);
  const changed = f.api.canonicalProject(exercise.starterProject); changed.files['main.ts'] = 'pins.digitalWritePin(DigitalPin.P1, 1)';
  f.app.driver.loaded = true; f.app.driver.capture = async () => changed; f.app.dirty = true;
  [...f.root.querySelectorAll('button')].find(node => node.textContent === '임시 저장').click();
  await new Promise(resolve => setTimeout(resolve, 5));
  assert.equal(sent.revision, 5);
  assert.equal(sent.project.files['main.ts'], changed.files['main.ts']);
  assert.equal(f.app.projectRevision, 6); assert.equal(f.app.dirty, false); assert.equal(f.app.content.inert, false); f.app.destroy();
});

test('409 save conflict keeps the edited project and its earlier revision', async () => {
  const f = appFixture(); const exercise = { ...f.api.sampleExercise(), id: 4, revision: 1 };
  f.app.api = async (path, body) => { if (body === undefined) return { project: exercise.starterProject, revision: 2, exerciseRevision: 1 }; throw new Error('다른 창에서 저장했습니다. 최신 버전을 확인하세요.'); };
  await f.app.learn(exercise); f.app.driver.loaded = true;
  const changed = f.api.canonicalProject(exercise.starterProject); changed.files['main.ts'] = 'pins.analogWritePin(AnalogPin.P2, 500)';
  f.app.driver.capture = async () => changed; f.app.dirty = true;
  [...f.root.querySelectorAll('button')].find(node => node.textContent === '임시 저장').click();
  await new Promise(resolve => setTimeout(resolve, 5));
  assert.equal(f.app.projectRevision, 2); assert.equal(f.app.dirty, true); assert.equal(f.app.project.files['main.ts'], changed.files['main.ts']);
  assert.match(f.app.status.textContent, /다른 창/); f.app.destroy();
});

test('status ready is used and nested attempt results render score correctly', async () => {
  const f = appFixture();
  f.app.api = async path => path === 'status' ? { ready: true } : { items: [] };
  await f.app.start(); assert.equal(f.app.runnerReady, true);
  f.app.results = f.window.document.createElement('div');
  f.app.renderHistory([{ id: 2, userId: 7, createdAt: '2026-10-28', result: { status: 'graded', score: 75, passed: false } }]);
  assert.match(f.app.results.textContent, /75/); assert.match(f.app.results.textContent, /실패/); f.app.destroy();
});

test('legacy GPIO activities without I2C fields remain editable and usable', async () => {
  const f = appFixture('teacher'), legacy = f.api.sampleExercise();
  delete legacy.i2cDevices; legacy.scenarios.forEach(scenario => { delete scenario.i2cInputs; delete scenario.i2cAssertions; });
  f.app.edit(legacy); assert.equal(f.app.exercise.i2cDevices.length, 0); assert.equal(f.app.exercise.scenarios[0].i2cAssertions.length, 0);
  f.app.closeEditor(); legacy.id = 3; legacy.revision = 1;
  f.app.api = async () => ({ project: legacy.starterProject, revision: 0, exerciseRevision: 1 });
  await f.app.learn(legacy); assert.equal(f.app.i2cDisplays.size, 0); assert.equal(f.app.actuators.size, 2); f.app.destroy();
});

test('teacher I2C forms preserve permission, byte arrays and explicit transaction order', () => {
  const f = appFixture('teacher'); f.app.edit(f.api.i2cExercise());
  const devices = f.root.querySelector('[data-mb-i2c-devices]'), writable = devices.querySelectorAll('input[type=checkbox]');
  assert.equal(writable.length, 2); assert.equal(writable[0].checked, false); assert.equal(writable[1].checked, true);
  writable[0].checked = true; writable[0].dispatchEvent(new f.window.document.defaultView.Event('change'));
  assert.equal(f.app.exercise.i2cDevices[0].registers[0].writable, true);
  const advanced = f.root.querySelector('[data-mb-advanced]'); advanced.open = true; advanced.dispatchEvent(new f.window.document.defaultView.Event('toggle'));
  const scenario = f.app.exercise.scenarios[0], original = scenario.i2cAssertions[0];
  [...f.root.querySelectorAll('.ojmicrobit-order button')].find(node => node.textContent === '아래').click();
  assert.equal(scenario.i2cAssertions[1], original); assert.equal(scenario.i2cAssertions[0].operation, 'read');
  const byteControl = f.root.querySelector('.ojmicrobit-i2c-scenario input[placeholder]');
  byteControl.value = '1, 90'; byteControl.dispatchEvent(new f.window.document.defaultView.Event('input'));
  assert.equal(JSON.stringify(scenario.i2cAssertions[0].data), '[1,90]');
  byteControl.value = 'not bytes'; byteControl.dispatchEvent(new f.window.document.defaultView.Event('input'));
  assert.equal(scenario.i2cAssertions[0].data.length, 0); f.app.destroy();
});

test('servo angle and pulse visualization use the actual signal and units', async () => {
  const f = appFixture(); f.app.exercise = f.api.servoExercise(); f.app.mountPeripherals(f.window.document.createElement('div'));
  f.app.playTrace([{ atMs: 0, pin: 1, signal: 'servo', value: 135, kind: 'write' }, { atMs: 0, pin: 2, signal: 'servo_pulse', value: 1800, kind: 'write' }], 20);
  await new Promise(resolve => setTimeout(resolve, 5));
  assert.equal(f.app.actuators.get('1').output.textContent, '135°');
  assert.equal(f.app.actuators.get('1').indicator.style.getPropertyValue('--mb-angle'), '45deg');
  assert.equal(f.app.actuators.get('2').output.textContent, '1800µs');
  assert.equal(f.app.actuators.get('2').indicator.style.getPropertyValue('--mb-angle'), undefined); f.app.destroy();
});

test('I2C pane shows actual ordered reads and writes without assuming sensor values', async () => {
  const f = appFixture(); f.app.exercise = f.api.i2cExercise(); f.app.mountPeripherals(f.window.document.createElement('div'));
  const device = f.app.i2cDisplays.get('32'); assert.equal(device.outputs.get(0).textContent, '대기');
  f.app.playTrace([], 20, [{ atMs: 0, address: 32, operation: 'write', data: [0], repeated: true, kind: 'i2c' },
    { atMs: 0, address: 32, operation: 'read', data: [99], repeated: false, kind: 'i2c' }, { atMs: 0, address: 32, operation: 'write', data: [1, 99], repeated: false, kind: 'i2c' }]);
  await new Promise(resolve => setTimeout(resolve, 5));
  assert.equal(device.outputs.get(0).textContent, '99'); assert.equal(device.outputs.get(1).textContent, '99');
  assert.equal(f.app.i2cTraceTable.children.length, 3); assert.match(f.app.i2cTraceTable.textContent, /0x20/); f.app.destroy();
});

test('preview sends external register overrides and does not grant student code write permission', async () => {
  const f = appFixture(); const exercise = { ...f.api.i2cExercise(), id: 5, revision: 1 }; let preview;
  f.app.runnerReady = true;
  f.app.api = async (path, body) => { if (body === undefined) return { project: exercise.starterProject, revision: 0, exerciseRevision: 1 };
    preview = body; return { status: 'preview', passed: null, score: null, trace: [], i2cTrace: [] }; };
  await f.app.learn(exercise); f.app.driver.loaded = true; f.app.driver.capture = async () => exercise.starterProject;
  const register = f.root.querySelector('input[aria-label="I²C 레지스터 센서 주소 32 레지스터 0 입력"]');
  register.value = '90'; register.dispatchEvent(new f.window.document.defaultView.Event('input'));
  [...f.root.querySelectorAll('button')].find(node => node.textContent === '센서 값으로 실행').click();
  await new Promise(resolve => setTimeout(resolve, 5));
  assert.equal(JSON.stringify(preview.i2cInputs), '[{"atMs":0,"address":32,"register":0,"value":90}]');
  assert.equal(f.app.exercise.i2cDevices[0].registers[0].writable, false); f.app.destroy();
});

test('I2C grading failures show transaction order and bytes', () => {
  const f = appFixture(); f.app.results = f.window.document.createElement('div');
  f.app.renderResult({ status: 'graded', passed: false, score: 50, scenarios: [{ id: 'case', name: '거래 순서', passed: false, assertions: [],
    i2cAssertions: [{ address: 32, operation: 'read', data: [90], repeated: false, passed: false, observed: { data: [30] } }] }] });
  assert.match(f.app.results.textContent, /0x20/); assert.match(f.app.results.textContent, /90/); assert.match(f.app.results.textContent, /관찰 \[30\]/); f.app.destroy();
});

test('canonical source excludes only exact PXT metadata and preserves user files', () => {
  const { api } = environment(), project = api.sampleExercise().starterProject;
  Object.assign(project.files, { _history: '{}', '.simstate.json': '{"P0":800}', '.git.json': '{}', '센서 실험.ts': '// 한글', '_sensor.ts': '// source', 'assets.jres': '{"image":"value"}', '설명.txt': '설명', 'a..b.ts': 'let a = 1', '.unknown.json': '{}' });
  const config = JSON.parse(project.files['pxt.json']); config.files.push('.simstate.json', '센서 실험.ts', 'assets.jres'); project.files['pxt.json'] = JSON.stringify(config);
  const normalized = api.canonicalProject(project);
  for (const key of ['_history', '.simstate.json', '.git.json']) assert.equal(normalized.files[key], undefined);
  for (const key of ['센서 실험.ts', '_sensor.ts', 'assets.jres', '설명.txt', 'a..b.ts', '.unknown.json']) assert.equal(normalized.files[key], project.files[key]);
  assert.equal(JSON.parse(normalized.files['pxt.json']).files.includes('.simstate.json'), false);
  project.files['../outside.ts'] = '// rejected by server, never silently discarded';
  assert.equal(api.canonicalProject(project).files['../outside.ts'], project.files['../outside.ts']);
});

test('step projection leaves arbitrary existing milliseconds and I2C order unchanged', () => {
  const { api } = environment();
  for (const exercise of [api.sampleExercise(), api.servoExercise(), api.i2cExercise()]) {
    const scenario = exercise.scenarios[0], before = JSON.stringify(scenario);
    const projection = api.projectScenarioSteps(scenario); assert.equal(projection.simple, true); assert.equal(JSON.stringify(scenario), before);
  }
  const scenario = { id: 'precision', name: '정밀 시간', durationMs: 427, inputs: [{ atMs: 0, pin: 0, signal: 'analog', value: 12 }, { atMs: 210, pin: 0, signal: 'analog', value: 43 }], assertions: [
    { pin: 1, signal: 'digital', expected: 0, afterMs: 0, withinMs: 73, holdMs: 19, tolerance: 0 }, { pin: 1, signal: 'digital', expected: 1, afterMs: 210, withinMs: 81, holdMs: 27, tolerance: 0 }], i2cInputs: [], i2cAssertions: [] };
  const before = JSON.stringify(scenario), projection = api.projectScenarioSteps(scenario);
  assert.equal(projection.steps[0].withinMs, 73); assert.equal(projection.steps[1].holdMs, 27); assert.equal(JSON.stringify(scenario), before);
  const changed = api.retimeScenarioStep(scenario, 0, { lengthMs: 333 });
  assert.equal(changed.inputs[1].atMs, 333); assert.equal(changed.assertions[1].afterMs, 333); assert.equal(changed.durationMs, 550);
  assert.equal(changed.assertions[0].withinMs, 73); assert.equal(changed.assertions[1].withinMs, 81); assert.equal(JSON.stringify(scenario), before);
});

test('overlapping or different deadlines fall back to unchanged detailed timeline', () => {
  const { api } = environment(), scenario = api.sampleExercise().scenarios[0];
  scenario.assertions[0].holdMs = 600; const before = JSON.stringify(scenario);
  assert.equal(api.projectScenarioSteps(scenario).simple, false); assert.equal(JSON.stringify(scenario), before);
  assert.throws(() => api.retimeScenarioStep(scenario, 0, { lengthMs: 1000 }), /상세 시간표/);
  scenario.assertions[0].holdMs = 200; scenario.assertions[1].withinMs = 101;
  assert.equal(api.projectScenarioSteps(scenario).simple, false);
});

test('new independent example and appended step use safe 1s/0.5s/0.1s defaults', () => {
  const { api } = environment(), exercise = api.sampleExercise(), scenario = api.newScenario(exercise, 4), before = JSON.stringify(scenario);
  assert.equal(scenario.durationMs, 1000); assert.equal(scenario.inputs[0].atMs, 0);
  assert(scenario.assertions.every(entry => entry.afterMs === 0 && entry.withinMs === 500 && entry.holdMs === 100));
  const appended = api.appendScenarioStep(scenario, exercise);
  assert.equal(appended.durationMs, 2000); assert(appended.inputs.some(entry => entry.atMs === 1000));
  assert(appended.assertions.filter(entry => entry.afterMs === 1000).every(entry => entry.withinMs === 500 && entry.holdMs === 100));
  assert.equal(JSON.stringify(scenario), before);
});

test('deleting first step carries all input pins into the new zero time', () => {
  const { api } = environment(), exercise = api.sampleExercise();
  const scenario = api.newScenario(exercise, 1); scenario.inputs.push({ atMs: 0, pin: 3, signal: 'analog', value: 17 });
  const connections = exercise.connections.concat([{ pin: 3, direction: 'input', signal: 'analog', device: 'potentiometer', label: '추가 센서' }]);
  scenario.durationMs = 2000; scenario.inputs.push({ atMs: 1000, pin: 0, signal: 'analog', value: 800 });
  scenario.assertions.push({ pin: 1, signal: 'digital', expected: 1, afterMs: 1000, withinMs: 500, holdMs: 100, tolerance: 0 });
  const changed = api.removeScenarioStep(scenario, 0, connections);
  assert.equal(changed.durationMs, 1000); assert(changed.inputs.some(entry => entry.pin === 0 && entry.atMs === 0 && entry.value === 800));
  assert(changed.inputs.some(entry => entry.pin === 3 && entry.atMs === 0 && entry.value === 17));
});

test('duplicating I2C step preserves pointer/read order and repeated flags', () => {
  const { api } = environment(), exercise = api.i2cExercise(), scenario = api.newScenario({ ...exercise, connections: [] }, 1);
  scenario.i2cAssertions[0].repeated = false; scenario.i2cAssertions[1].repeated = true;
  const changed = api.duplicateScenarioStep(scenario, 0);
  assert.equal(JSON.stringify(changed.i2cAssertions.map(entry => [entry.operation, entry.repeated, entry.afterMs])), '[["write",false,0],["read",true,0],["write",false,1000],["read",true,1000]]');
});

test('teacher primary cards hide raw time tables and update summary without replacing focused input', () => {
  const f = appFixture('teacher'); f.app.edit(f.api.sampleExercise());
  const box = f.root.querySelector('[data-mb-scenarios]'); assert.equal(box.querySelectorAll('[data-mb-step]').length, 3);
  assert.equal(box.querySelector('[data-mb-advanced]').open, false);
  assert.match(box.textContent, /입력을 이렇게 설정하면/); assert.match(box.textContent, /처음부터 실행/); assert.match(box.textContent, /이어서 바꾸기/);
  const first = box.querySelector('[data-mb-step]'), value = first.querySelector('.ojmicrobit-step-side input');
  value.value = '800'; value.dispatchEvent(new f.window.document.defaultView.Event('input'));
  assert.equal(f.root.querySelector('[data-mb-step] .ojmicrobit-step-side input'), value);
  assert.match(first.querySelector('.ojmicrobit-step-summary').textContent, /800/); f.app.destroy();
});

test('primary and advanced timeline views synchronize in both directions', () => {
  const f = appFixture('teacher'); f.app.edit(f.api.sampleExercise());
  let box = f.root.querySelector('[data-mb-scenarios]'), first = box.querySelector('[data-mb-step]'), value = first.querySelector('.ojmicrobit-step-side input');
  value.value = '800'; value.dispatchEvent(new f.window.document.defaultView.Event('input'));
  let advanced = box.querySelector('[data-mb-advanced]'); advanced.open = true; advanced.dispatchEvent(new f.window.document.defaultView.Event('toggle'));
  let rawValue = advanced.querySelector('tbody tr td:nth-child(4) input'); assert.equal(rawValue.value, '800');
  rawValue.value = '321'; rawValue.dispatchEvent(new f.window.document.defaultView.Event('input'));
  advanced.open = false; advanced.dispatchEvent(new f.window.document.defaultView.Event('toggle'));
  box = f.root.querySelector('[data-mb-scenarios]'); assert.equal(box.querySelector('[data-mb-step] .ojmicrobit-step-side input').value, '321');
  assert.match(box.querySelector('.ojmicrobit-step-summary').textContent, /321/); f.app.destroy();
});

test('seconds-based retiming preserves open time details and integer millisecond precision', () => {
  const f = appFixture('teacher'); f.app.edit(f.api.sampleExercise());
  let timing = f.root.querySelector('[data-mb-time]'); timing.open = true;
  const control = timing.querySelector('[data-mb-time-field="lengthMs"]'); control.value = '0.625'; control.dispatchEvent(new f.window.document.defaultView.Event('change'));
  timing = f.root.querySelector('[data-mb-time]'); assert.equal(timing.open, true);
  assert.equal(f.app.exercise.scenarios[0].inputs[1].atMs, 625);
  assert.equal(timing.querySelector('[data-mb-time-field="lengthMs"]').value, '0.625');
  assert.equal(f.app.exercise.scenarios[0].assertions[0].withinMs, 100); f.app.destroy();
});

test('sequence timing edits extend only the last stage and reject later input collisions atomically', () => {
  const { api } = environment(), scenario = api.sequenceExercise().scenarios[0], before = JSON.stringify(scenario);
  const extended = api.updateOutputSequence(scenario, 0, sequences => { sequences[0].steps[0].holdMs = 2000; }, true);
  assert.equal(extended.durationMs, 2700); assert.equal(JSON.stringify(scenario), before);
  const following = api.appendScenarioStep(scenario, api.sequenceExercise()), snapshot = JSON.stringify(following);
  assert.throws(() => api.updateOutputSequence(following, 0, sequences => { sequences[0].steps[0].holdMs = 2000; }, true), /다음 입력 단계.*단계 길이/);
  assert.equal(JSON.stringify(following), snapshot);
  assert.throws(() => api.updateOutputSequence(scenario, 0, sequences => { sequences[0].steps[0].holdMs = 30000; }, true), /30초/);
});

test('sequence projection, retime, duplicate, remove and append preserve relative output actions', () => {
  const { api } = environment(), exercise = api.sequenceExercise(), scenario = exercise.scenarios[0], before = JSON.stringify(scenario);
  assert.equal(api.projectScenarioSteps(scenario).steps[0].outputSequences.length, 1);
  const duplicate = api.duplicateScenarioStep(scenario, 0);
  assert.equal(duplicate.outputSequences[1].afterMs, 2000); assert.equal(JSON.stringify(duplicate.outputSequences[1].steps), JSON.stringify(scenario.outputSequences[0].steps));
  const moved = api.retimeScenarioStep(duplicate, 0, { lengthMs: 2500 }); assert.equal(moved.outputSequences[1].afterMs, 2500);
  assert.equal(JSON.stringify(api.removeScenarioStep(duplicate, 0, exercise.connections).outputSequences), JSON.stringify(scenario.outputSequences));
  const appended = api.appendScenarioStep(scenario, exercise); assert.equal(appended.outputSequences.length, 1);
  assert(appended.assertions.some(entry => entry.pin === 1 && entry.afterMs === 2000 && entry.expected === 0));
  assert.equal(JSON.stringify(scenario), before);
});

test('first-step removal carries explicit I2C environment overrides into the new initial state', () => {
  const { api } = environment(), scenario = api.i2cExercise().scenarios[0];
  scenario.i2cInputs.unshift({ atMs: 0, address: 32, register: 1, value: 99 });
  const changed = api.removeScenarioStep(scenario, 0, []);
  assert(changed.i2cInputs.some(entry => entry.atMs === 0 && entry.register === 1 && entry.value === 99));
  assert(changed.i2cInputs.some(entry => entry.atMs === 0 && entry.register === 0 && entry.value === 90));
});

test('teacher converts a single output, flips next digital state, and edits hold in seconds', () => {
  const f = appFixture('teacher'), exercise = f.api.sequenceExercise(); exercise.scenarios[0].assertions = [{ pin: 1, signal: 'digital', expected: 1, afterMs: 0, withinMs: 500, holdMs: 100, tolerance: 0 }]; exercise.scenarios[0].outputSequences = [];
  f.app.edit(exercise);
  function primaryButton(text) { return [...f.root.querySelector('[data-mb-step]').querySelectorAll('button')].find(node => node.textContent === text); }
  primaryButton('이어서 바꿀 동작 만들기').click(); assert.equal(f.app.exercise.scenarios[0].assertions.length, 0);
  primaryButton('다음 출력 추가').click(); assert.equal(f.app.exercise.scenarios[0].outputSequences[0].steps[1].expected, 0);
  const hold = f.root.querySelector('[data-mb-step] [data-mb-sequence-action] .ojmicrobit-sequence-main input'); hold.value = '2.125'; hold.dispatchEvent(new f.window.document.defaultView.Event('change'));
  assert.equal(f.app.exercise.scenarios[0].outputSequences[0].steps[0].holdMs, 2125); assert.equal(f.app.exercise.scenarios[0].durationMs, 3225);
  assert.match(f.root.querySelector('.ojmicrobit-step-summary').textContent, /켜짐 2.125초 유지 → 꺼짐/);
  const value = f.root.querySelector('[data-mb-step] [data-mb-sequence-action] select'); value.value = '0'; value.dispatchEvent(new f.window.document.defaultView.Event('change'));
  assert.equal(f.root.querySelector('[data-mb-step] [data-mb-sequence-action] select'), value); f.app.destroy();
});

test('complex timelines show a readable story and preserve raw data behind explicit advanced settings', () => {
  const f = appFixture('teacher'), exercise = f.api.sequenceExercise(); exercise.scenarios[0].inputs.push({ atMs: 300, pin: 0, signal: 'digital', value: 0 });
  const before = JSON.stringify(exercise.scenarios[0]); f.app.edit(exercise);
  assert.equal(f.root.querySelectorAll('[data-mb-step]').length, 0); const advanced = f.root.querySelector('[data-mb-advanced]');
  assert.equal(advanced.open, false); assert(f.root.querySelector('.ojmicrobit-story-step')); advanced.open=true;advanced.dispatchEvent(new f.window.document.defaultView.Event('toggle'));assert.equal(advanced.querySelectorAll('[data-mb-sequence-action]').length, 2);
  assert.equal(JSON.stringify(f.app.exercise.scenarios[0]), before); f.app.destroy();
});

test('sequence results show actual match and completion times with actionable failures', () => {
  const f = appFixture(); f.app.results = f.window.document.createElement('div');
  f.app.renderResult({ status: 'graded', passed: false, score: 50, scenarios: [{ name: 'LED 순서', passed: false, outputSequences: [{ pin: 1, signal: 'digital', afterMs: 0, passed: false, steps: [
    { expected: 1, holdMs: 1000, passed: true, matchedAtMs: 200, completedAtMs: 1200 }, { expected: 0, holdMs: 100, passed: false, matchedAtMs: null, completedAtMs: null, observed: null }] }] }] });
  assert.match(f.app.results.textContent, /0.2초/); assert.match(f.app.results.textContent, /1.2초/); assert.match(f.app.results.textContent, /동작 2/); assert.match(f.app.results.textContent, /유지 완료 못 함/); f.app.destroy();
});

test('exercise pages keep twenty summaries in the DOM and navigate by decreasing ID', async () => {
  const f = appFixture(), calls = [];
  f.app.api = async route => { calls.push(route); const cursor = Number(/beforeId=(\d+)/.exec(route)?.[1] || 61), rows = Array.from({ length: 60 }, (_, index) => ({ id: 60 - index, title: '과제 ' + (60 - index), status: 'published' })).filter(row => row.id < cursor), selected = rows.slice(0, 20); return { items: selected, nextBeforeId: rows.length > 20 ? selected.at(-1).id : null }; };
  await f.app.list(); assert.equal(f.root.querySelectorAll('.ojmicrobit-problem-list tbody tr').length, 20); assert.equal(calls[0], 'public-exercises?limit=20');
  [...f.root.querySelectorAll('button')].find(node => node.textContent === '다음 페이지').click(); await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(calls.at(-1), 'public-exercises?limit=20&beforeId=41'); assert.equal(f.root.querySelector('.ojmicrobit-problem-list tbody tr td:nth-child(2)').textContent, '과제 40'); assert.equal(f.root.querySelectorAll('.ojmicrobit-problem-list tbody tr').length, 20);
  [...f.root.querySelectorAll('button')].find(node => node.textContent === '이전 페이지').click(); await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(f.root.querySelector('.ojmicrobit-problem-list tbody tr td:nth-child(2)').textContent, '과제 60'); assert(calls.every(route => route.startsWith('public-exercises?'))); f.app.destroy();
});

test('history summary navigation loads full result only after an explicit detail click', async () => {
  const f = appFixture(), calls = []; f.app.results = f.window.document.createElement('div'); f.root.append(f.app.results);
  f.app.api = async route => { calls.push(route); if (route.endsWith('/24')) return { result: { status: 'graded', passed: true, score: 100, scenarios: [] } };
    const cursor = Number(/beforeId=(\d+)/.exec(route)?.[1] || 25), rows = Array.from({ length: 24 }, (_, index) => ({ id: 24 - index, result: { status: 'graded', score: 100, passed: true } })).filter(row => row.id < cursor), selected = rows.slice(0, 20); return { items: selected, nextBeforeId: rows.length > 20 ? selected.at(-1).id : null }; };
  await f.app.history(3); assert.equal(calls.length, 1); assert.equal(f.app.results.querySelectorAll('tbody tr').length, 20);
  [...f.app.results.querySelectorAll('button')].find(node => node.textContent === '다음 페이지').click(); await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(f.app.results.querySelectorAll('tbody tr').length, 4); assert.match(calls.at(-1), /beforeId=5/);
  [...f.app.results.querySelectorAll('button')].find(node => node.textContent === '이전 페이지').click(); await new Promise(resolve => setTimeout(resolve, 0));
  [...f.app.results.querySelectorAll('button')].find(node => node.textContent === '결과 보기').click(); await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(calls.at(-1), 'exercises/3/attempts/24'); assert.match(f.app.results.textContent, /테스트 통과/); assert.match(f.app.results.textContent, /제출 내역으로/); f.app.destroy();
});

test('slow runner readiness never blocks the exercise list or hides it on status failure', async () => {
  const f = appFixture(); let rejectStatus;
  f.app.api = route => route === 'status' ? new Promise((_, reject) => { rejectStatus = reject; }) : Promise.resolve({ items: [{ id: 1, title: '즉시 표시되는 과제', status: 'published' }], nextBeforeId: null });
  const starting = f.app.start(); await new Promise(resolve => setTimeout(resolve, 0)); assert.match(f.root.textContent, /즉시 표시되는 과제/); assert.equal(f.app.busy, false);
  rejectStatus(new Error('runner unavailable')); await starting; assert.match(f.root.textContent, /즉시 표시되는 과제/); assert.equal(f.app.runnerReady, false); f.app.destroy();
});

test('request deadline releases busy state and destroy cancels pending fetch without retry', async () => {
  const f = appFixture(); f.window.AbortController = AbortController; let requests = 0;
  f.window.fetch = (_, options) => { requests++; return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => { const error = new Error('aborted'); error.name = 'AbortError'; reject(error); })); };
  await f.app.action(() => f.app.api('exercises/1/run', {}, 'POST', 5)); assert.equal(f.app.busy, false); assert.match(f.app.status.textContent, /처리되었을 수/); assert.equal(f.app.requests.size, 0); assert.equal(requests, 1);
  const pending = f.app.api('exercises'); f.app.destroy(); await assert.rejects(pending, /aborted/); assert.equal(f.app.requests.size, 0); assert.equal(requests, 2);
});

test('busy guard prevents duplicate runs while a server operation is pending', async () => {
  const f = appFixture(); let finish, runs = 0;
  const pending = f.app.action(() => { runs++; return new Promise(resolve => { finish = resolve; }); });
  await f.app.action(() => { runs++; }); assert.equal(runs, 1); finish(); await pending; assert.equal(f.app.busy, false); f.app.destroy();
});

test('dense 10000-event replay uses one timer and bounded DOM rows while preserving final values', () => {
  const f = appFixture(); f.app.exercise = f.api.exerciseModel(f.api.i2cExercise()); f.app.mountPeripherals(f.root);
  let now = 0, identifier = 0, createdRows = 0; const scheduled = new Map(), createElement = f.window.document.createElement.bind(f.window.document);
  f.window.performance = { now: () => now }; f.window.setTimeout = (callback, delay) => { const id = ++identifier; scheduled.set(id, { callback, delay }); return id; }; f.window.clearTimeout = id => scheduled.delete(id);
  f.window.document.createElement = tag => { if (tag === 'tr') createdRows++; return createElement(tag); };
  const writes = Array.from({ length: 5000 }, (_, index) => ({ atMs: 0, pin: 1, signal: 'servo', value: index % 181, kind: 'write' }));
  const bus = Array.from({ length: 5000 }, (_, index) => ({ atMs: 0, address: 32, operation: index % 2 ? 'read' : 'write', data: [index % 2 ? index % 256 : 0], repeated: false, kind: 'i2c' }));
  f.app.playTrace(writes, 20, bus); assert.equal(scheduled.size, 1); assert.equal(f.app.traceTimers.length, 1);
  const first = scheduled.entries().next().value; scheduled.delete(first[0]); first[1].callback();
  assert.equal(scheduled.size, 1); assert.equal(createdRows, 160); assert.equal(f.app.traceTable.children.length, 60); assert.equal(f.app.i2cTraceTable.children.length, 100);
  assert.equal(f.app.actuators.get('1').meter.value, 4999 % 181); assert.equal(f.app.i2cDisplays.get('32').outputs.get(0).textContent, String(4999 % 256));
  now = 70; const last = scheduled.entries().next().value; scheduled.delete(last[0]); last[1].callback(); assert.equal(scheduled.size, 0); f.app.destroy();
});

test('public stories group simultaneous outputs into steps and reveal raw conditions only on demand', () => {
  const f = appFixture(); f.app.exercise = f.api.exerciseModel(f.api.sampleExercise());
  f.app.exercise.scenarios.push({ ...f.app.exercise.scenarios[0], id: 'hidden', name: '숨겨진 기대값', visible: false });
  f.app.renderPublicTests(); const disclosure = f.root.querySelector('[data-mb-public-tests]'); assert.equal(disclosure.querySelectorAll('tbody').length, 0);
  disclosure.open = true; disclosure.dispatchEvent(new f.window.document.defaultView.Event('toggle')); assert.equal(disclosure.querySelectorAll('.ojmicrobit-story-step').length,3);assert.equal(disclosure.querySelectorAll('tbody tr').length,0);assert.doesNotMatch(disclosure.textContent, /숨겨진 기대값/);const technical=disclosure.querySelector('[data-mb-technical-tests]');technical.open=true;technical.dispatchEvent(new f.window.document.defaultView.Event('toggle'));assert.equal(disclosure.querySelectorAll('tbody tr').length,9);
  disclosure.open = false; disclosure.dispatchEvent(new f.window.document.defaultView.Event('toggle')); disclosure.open = true; disclosure.dispatchEvent(new f.window.document.defaultView.Event('toggle'));
  assert.equal(disclosure.querySelectorAll('tbody tr').length, 9); f.app.destroy();
});

function namedButton(root, name) { return [...root.querySelectorAll('button')].find(node => node.textContent === name); }
function changeValue(f, control, value) { if (control.tagName === 'SELECT') { const options = [...control.querySelectorAll('option')]; options.forEach(option => { option.selected = false; }); options.find(option => option.value === value).selected = true; } else { control.value = value; } control.dispatchEvent(new f.window.document.defaultView.Event('change')); }
const nextTick = () => new Promise(resolve => setTimeout(resolve, 5));
function publicationFixture() {
  const f = appFixture('teacher'), exercise = { ...f.api.sequenceExercise(), id: 9, revision: 1, status: 'published' }, calls = [];
  exercise.scenarios.push({ ...JSON.parse(JSON.stringify(exercise.scenarios[0])), id: 'private-case', name: '비공개 전용 조건', visible: false });
  f.app.edit(exercise); f.app.driver.loaded = true; f.app.driver.capture = async () => f.app.project;
  f.app.api = async (path, body) => { calls.push({ path, body });
    if (path === 'teacher/exercises/review') { const preview = JSON.parse(JSON.stringify(body)); preview.scenarios = preview.scenarios.filter(scenario => scenario.visible); if (body.publicationReview.starterMode === 'blank') { preview.starterProject.files['main.ts'] = ''; }
      return { fingerprint: 'a'.repeat(64), summary: { title: body.title, audience: body.audience }, studentPreview: preview }; }
    if (path === 'teacher/exercises') { return { ...body, revision: 2 }; } throw new Error('Unexpected endpoint: ' + path);
  }; return { ...f, calls };
}

test('published save requires explicit starter choice and review before final confirmation', async () => {
  const f = publicationFixture(), frame = f.app.driver.frame, source = f.app.project.files['main.ts'];
  namedButton(f.root, '과제 저장').click(); await nextTick(); assert.equal(f.calls.length, 0); assert(f.root.querySelector('[data-mb-publication-review]')); assert.equal(f.app.driver.frame, frame);
  namedButton(f.root, '공개 내용 점검하기').click(); await nextTick(); assert.equal(f.calls.length, 0); assert.match(f.app.status.textContent, /시작 코드/);
  changeValue(f, f.root.querySelector('[data-mb-field="publicationReview.starterMode"]'), 'current'); namedButton(f.root, '공개 내용 점검하기').click(); await nextTick();
  assert.equal(f.calls[0].body.publicationReview.starterMode, 'current'); const preview = f.root.querySelector('[data-mb-student-preview]'); assert.doesNotMatch(preview.textContent, /비공개 전용 조건/);
  const publish = namedButton(f.root, '확인하고 공개'); assert.equal(publish.disabled, true);
  const ack = f.root.querySelector('[data-mb-publication-review] input[type=checkbox]'); ack.checked = true; ack.dispatchEvent(new f.window.document.defaultView.Event('change')); assert.equal(publish.disabled, false);
  publish.click(); await nextTick(); assert.equal(f.calls[1].body.publicationReview.confirmed, true); assert.equal(f.calls[1].body.publicationReview.fingerprint, 'a'.repeat(64)); assert.equal(f.calls[1].body.starterProject.files['main.ts'], source); f.app.destroy();
});

test('blank preview and cancelling review preserve the original editor and author source', async () => {
  const f = publicationFixture(), driver = f.app.driver, before = JSON.stringify(f.app.project);
  namedButton(f.root, '과제 저장').click(); await nextTick(); changeValue(f, f.root.querySelector('[data-mb-field="publicationReview.starterMode"]'), 'blank'); namedButton(f.root, '공개 내용 점검하기').click(); await nextTick();
  assert(f.root.querySelector('[data-mb-student-preview]'), f.app.status.textContent); assert.match(f.root.querySelector('[data-mb-student-preview]').textContent, /빈 시작 코드/); assert.equal(JSON.stringify(f.app.project), before);
  namedButton(f.root, '작성 계속하기').click(); assert.equal(f.app.driver, driver); assert.equal(f.root.querySelector('[data-mb-publication-review]'), null); assert.equal(JSON.stringify(f.app.project), before); assert.equal(f.calls.length, 1); f.app.destroy();
});

test('editing after review invalidates the snapshot instead of publishing changed content', async () => {
  const f = publicationFixture(); namedButton(f.root, '과제 저장').click(); await nextTick(); changeValue(f, f.root.querySelector('[data-mb-field="publicationReview.starterMode"]'), 'current'); namedButton(f.root, '공개 내용 점검하기').click(); await nextTick();
  f.app.exercise.title = '점검 후 바뀐 제목'; const ack = f.root.querySelector('[data-mb-publication-review] input[type=checkbox]'); ack.checked = true; ack.dispatchEvent(new f.window.document.defaultView.Event('change')); namedButton(f.root, '확인하고 공개').click(); await nextTick();
  assert.equal(f.calls.length, 1); assert.match(f.app.status.textContent, /바뀌었습니다/); assert.equal(f.app.exercise.title, '점검 후 바뀐 제목'); assert.equal(namedButton(f.root, '확인하고 공개'), undefined); f.app.destroy();
});

test('schema errors open the relevant raw timeline and mark a Korean field without changing data', async () => {
  const f = appFixture('teacher'); f.app.edit(f.api.sampleExercise()); const before = JSON.stringify(f.app.exercise);
  f.app.showError(Object.assign(new Error('exercise.scenarios.0.assertions.1.withinMs: 정수 범위를 확인해 주세요.'), { fieldPath: 'exercise.scenarios.0.assertions.1.withinMs' })); await nextTick();
  const target = f.root.querySelector('[data-mb-field="scenarios.0.assertions.1.withinMs"]'); assert.equal(target.getAttribute('aria-invalid'), 'true'); assert.equal(f.root.querySelector('[data-mb-advanced]').open, true); assert.match(f.app.status.textContent, /예시.*기대 출력.*반응 대기/); assert.doesNotMatch(f.app.status.textContent, /exercise.scenarios/); assert.equal(JSON.stringify(f.app.exercise), before);
  target.value = '123'; target.dispatchEvent(new f.window.document.defaultView.Event('input')); assert.equal(target.hasAttribute('aria-invalid'), false); f.app.destroy();
});

test('sequence hold errors map to the primary seconds field and reveal nested response controls', async () => {
  const f = appFixture('teacher'); f.app.edit(f.api.sequenceExercise());
  f.app.showError(Object.assign(new Error('유지 시간을 확인해 주세요.'), { fieldPath: 'exercise.scenarios.0.outputSequences.0.steps.1.holdMs' })); await nextTick();
  assert.equal(f.root.querySelector('[data-mb-step] [data-mb-field="scenarios.0.outputSequences.0.steps.1.holdMs"]').getAttribute('aria-invalid'), 'true'); assert.equal(f.root.querySelector('[data-mb-advanced]').open, false);
  f.app.showError(Object.assign(new Error('대기 시간을 확인해 주세요.'), { fieldPath: 'scenarios.0.outputSequences.0.steps.1.withinMs' })); await nextTick();
  assert.equal(f.root.querySelectorAll('[data-mb-sequence-details]')[1].open, true); f.app.destroy();
});

test('new problem publication is global and offers lesson application as a separate workflow', () => {
  const f = appFixture('teacher'); f.app.edit(f.api.sampleExercise());
  assert.equal(JSON.stringify(f.app.exercise.audience), '{"mode":"site","classIds":[]}'); assert.equal(f.root.querySelector('[data-mb-field="audience.mode"]'), null); assert.match(f.root.querySelector('[data-mb-audience]').textContent, /공개한 문제는 수업 소속과 관계없이/); assert.match(f.root.textContent, /수업에 적용/);
});

test('legacy lesson restriction remains explicit without requiring a live provider to edit its problem', async () => {
  const f = appFixture('teacher'), exercise = f.api.sampleExercise(); exercise.audience = { mode: 'classes', classIds: [7] }; let calls = 0; f.app.api = async () => { calls++; throw new Error('provider unavailable'); }; f.app.edit(exercise); await nextTick();
  assert.equal(JSON.stringify(f.app.exercise.audience), '{"mode":"classes","classIds":[7]}'); assert.equal(calls, 0); assert.match(f.root.querySelector('[data-mb-audience]').textContent, /이전 버전의 제한 공개/);
});

test('API error metadata survives and throttled runs preserve results without automatic retries', async () => {
  const f = appFixture(), requestId = 'f'.repeat(32); let calls = 0; f.app.results = f.window.document.createElement('div'); f.app.results.textContent = '이전 결과'; const run = f.window.document.createElement('button'); run.setAttribute('data-mb-run-action', ''); f.app.content.append(run, f.app.results);
  f.window.fetch = async () => { calls++; return { ok: false, status: 429, json: async () => ({ code: 'oj_microbit_busy', message: '실행 서비스가 사용 중입니다.', data: { status: 429, requestId, retryAfterSeconds: 1, fieldPath: 'project.files' } }) }; };
  await f.app.action(() => f.app.api('exercises/1/run', {})); assert.equal(calls, 1); assert.equal(run.disabled, true); assert.match(f.app.status.textContent, new RegExp(requestId)); assert.equal(f.app.results.textContent, '이전 결과'); assert.equal(f.app.busy, false); f.app.destroy();
});

function routingFixture(mode = 'student', href = 'https://school.example/oj-addons/oj-microbit/learn') {
  const f = appFixture(mode), changes = []; f.window.location = { href };
  f.window.history = { pushState(state, title, url) { changes.push(['push', url]); f.window.location.href = url; }, replaceState(state, title, url) { changes.push(['replace', url]); f.window.location.href = url; } }; return { ...f, changes };
}

test('problem URLs preserve unrelated parameters and reject invalid or cross-mode routes', () => {
  const { api } = environment();
  assert.equal(JSON.stringify(api.problemRoute('https://school.example/path?exerciseId=24', false)), JSON.stringify({ view: 'detail', exerciseId: 24, beforeId: 0 }));
  assert.equal(api.problemRoute('https://school.example/path?exerciseId=24&mbView=edit', false).view, 'list');
  assert.equal(api.problemRoute('https://school.example/path?exerciseId=9007199254740993', false).view, 'list');
  assert.equal(api.problemRoute('https://school.example/path?exerciseId=3&mbView=history&mbBefore=2', false).beforeId, 2);
  const url = new URL(api.problemUrl('https://school.example/path?keep=one&exerciseId=9&mbBefore=20#part', { view: 'solve', exerciseId: 3, beforeId: 0 }));
  assert.equal(url.searchParams.get('keep'), 'one'); assert.equal(url.searchParams.get('exerciseId'), '3'); assert.equal(url.searchParams.get('mbView'), 'solve'); assert.equal(url.searchParams.has('mbBefore'), false); assert.equal(url.hash, '#part');
});

test('learner deep link opens description and public inputs before loading any editor or project', async () => {
  const f = routingFixture('student', 'https://school.example/oj-addons/oj-microbit/learn?exerciseId=3'), calls = [], exercise = { ...f.api.sampleExercise(), id: 3, revision: 1 };
  exercise.scenarios.push({ ...exercise.scenarios[0], visible: false, name: '비공개 조건' });
  f.app.api = async path => { calls.push(path); return path === 'status' ? { ready: true } : exercise; };
  await f.app.start(); const tests = f.root.querySelector('[data-mb-public-tests]'); tests.dispatchEvent(new f.window.document.defaultView.Event('toggle'));
  assert(f.root.querySelector('[data-mb-problem]')); assert.equal(f.root.querySelector('iframe'), null); assert.equal(calls.some(path => path.endsWith('/project')), false); assert.match(tests.textContent, /센서 값/); assert.doesNotMatch(tests.textContent, /비공개 조건/); assert(namedButton(f.root, '문제 풀기')); assert.equal(f.app.route.view, 'detail');
});

test('solving loads saved project while temporary save and submission remain separate', async () => {
  const f = routingFixture(), exercise = { ...f.api.sampleExercise(), id: 3, revision: 2 }, calls = [];
  f.app.runnerReady = true; f.app.api = async (path, body) => { calls.push({ path, body }); if (path === 'exercises/3') return exercise; if (path.endsWith('/run')) return { id: 22, status: 'graded', score: 100, passed: true, scenarios: [] }; if (path.endsWith('/project') && body) return { revision: 6 }; return { project: exercise.starterProject, revision: 5 }; };
  await f.app.openRoute({ view: 'solve', exerciseId: 3, beforeId: 0 }, 'push'); f.app.driver.capture = async () => exercise.starterProject; f.app.driver.loaded = true;
  namedButton(f.root, '임시 저장').click(); await nextTick(); assert.equal(calls.filter(call => call.path.endsWith('/run')).length, 0); assert.match(f.app.status.textContent, /기록은 만들지/);
  namedButton(f.root, '제출하여 채점').click(); await nextTick(); const run = calls.find(call => call.path.endsWith('/run')); assert.equal(JSON.stringify(Object.keys(run.body)), '["project"]'); assert.match(f.app.status.textContent, /제출 #22/); assert.match(f.app.results.textContent, /100점/); assert.equal(new URL(f.window.location.href).searchParams.get('mbView'), 'solve');
});

test('teacher previews use owned endpoint and cannot save student projects or create submissions', async () => {
  const f = routingFixture('teacher'), exercise = { ...f.api.sampleExercise(), id: 3, revision: 2 }, calls = [];
  f.app.api = async path => { calls.push(path); return exercise; };
  await f.app.openRoute({ view: 'solve', exerciseId: 3, beforeId: 0 }, 'push');
  assert.equal(calls.join(','), 'teacher/exercises/3'); assert.equal(namedButton(f.root, '임시 저장'), undefined); assert.equal(namedButton(f.root, '제출하여 채점'), undefined); assert.match(f.root.textContent, /교사 미리보기/);
});

test('dirty navigation cancellation keeps code, route and browser address unchanged', async () => {
  const f = routingFixture(), exercise = { ...f.api.sampleExercise(), id: 3, revision: 2 }; f.app.problem(exercise); f.app.setRoute({ view: 'detail', exerciseId: 3, beforeId: 0 }, 'replace');
  const href = f.window.location.href; f.app.dirty = true; f.app.confirm = async () => false; let calls = 0; f.app.api = async () => { calls++; return { items: [] }; };
  await f.app.navigate({ view: 'list', exerciseId: 0, beforeId: 0 }); assert.equal(calls, 0); assert.equal(f.window.location.href, href); assert.equal(f.app.exercise.id, 3); assert.equal(f.app.dirty, true);
});

test('browser back cancellation restores the current address without losing unsaved work', async () => {
  const f = routingFixture(), current = { view: 'solve', exerciseId: 3, beforeId: 0 }; f.app.setRoute(current, 'replace'); const href = f.window.location.href;
  f.app.dirty = true; f.app.confirm = async () => false; f.window.location.href = 'https://school.example/oj-addons/oj-microbit/learn'; f.app.popState(); await nextTick();
  assert.equal(f.window.location.href, href); assert.equal(f.app.route.view, 'solve'); assert.equal(f.app.dirty, true);
});

test('failed detail authorization never mounts a problem or MakeCode frame', async () => {
  const f = routingFixture('teacher'); f.app.api = async path => { assert.equal(path, 'teacher/exercises/999'); throw new Error('접근할 권한이 없습니다.'); };
  await f.app.navigate({ view: 'preview', exerciseId: 999, beforeId: 0 }); assert.equal(f.root.querySelector('[data-mb-problem]'), null); assert.equal(f.root.querySelector('iframe'), null); assert.match(f.app.status.textContent, /권한/); assert.equal(f.app.route, null);
});

test('legacy restricted problem can explicitly become public and restore its original scope before saving', async () => {
  const f = appFixture('teacher'), exercise = f.api.sampleExercise(); exercise.audience = { mode: 'classes', classIds: [7] }; f.app.edit(exercise);
  const select = f.root.querySelector('[data-mb-field="audience.mode"]'); assert.equal([...select.options].some(option => option.value === 'groups'), false);
  changeValue(f, select, 'site'); assert.equal(JSON.stringify(f.app.exercise.audience), '{"mode":"site","classIds":[]}'); assert.equal(f.app.dirty, true);
  changeValue(f, f.root.querySelector('[data-mb-field="audience.mode"]'), 'classes'); assert.equal(JSON.stringify(f.app.exercise.audience), '{"mode":"classes","classIds":[7]}');
});

test('legacy group restriction cannot be silently broadened or retargeted to lessons', () => {
  const f = appFixture('teacher'), exercise = f.api.sampleExercise(); exercise.audience = { mode: 'groups', groupIds: [8] }; f.app.edit(exercise);
  assert.equal(JSON.stringify(f.app.exercise.audience), '{"mode":"groups","groupIds":[8]}'); assert.match(f.root.querySelector('[data-mb-audience]').textContent, /기존 그룹.*보존/); assert.match(f.api.fieldLabel('exercise.audience.groupIds'), /그룹\(학급\)/);
  const select = f.root.querySelector('[data-mb-field="audience.mode"]'); assert.equal([...select.options].some(option => option.value === 'classes'), false);
});

test('refreshing a cursor page resets both the rendered list and its reload address', async () => {
  const f = routingFixture('student', 'https://school.example/learn?mbBefore=18'), calls = []; f.app.api = async path => { calls.push(path); return { items: [], nextBeforeId: null }; };
  await f.app.openRoute({ view: 'list', exerciseId: 0, beforeId: 18 }, 'replace'); namedButton(f.root, '목록 새로고침').click(); await nextTick();
  assert.match(calls[0], /beforeId=18/); assert.equal(calls.at(-1), 'public-exercises?limit=20'); assert.equal(new URL(f.window.location.href).searchParams.has('mbBefore'), false); assert.equal(f.app.route.beforeId, 0);
});

test('failed saved-project fetch leaves the previous editor and current code usable', async () => {
  const f = appFixture('teacher'), exercise = { ...f.api.sampleExercise(), id: 3, revision: 2 }; f.app.edit(exercise); const driver = f.app.driver, before = JSON.stringify(f.app.project);
  f.app.api = async () => { throw new Error('프로젝트를 불러오지 못했습니다.'); };
  await assert.rejects(f.app.learn(exercise), /불러오지/); assert.equal(f.app.driver, driver); assert.equal(driver.disposed, false); assert.equal(JSON.stringify(f.app.project), before); assert(f.root.querySelector('iframe'));
});

function lessonFixture(mode = 'student') {
  const f = routingFixture(mode), exercise = { ...f.api.sampleExercise(), id: 3, revision: 2, status: 'published', audience: { mode: 'site', classIds: [] } };
  const assignments = [11, 12].map((id, index) => ({ id, classId: index + 7, exerciseId: 3, exerciseRevision: 2, title: exercise.title, revision: 1, archived: false }));
  const calls = [], projects = new Map(), histories = new Map(); let nextAttempt = 30;
  f.app.runnerReady = true;
  f.app.api = async (route, body, method) => {
    calls.push({ route, body, method });
    if (route === 'public-exercises?limit=20') return { items: [{ id: 3, title: exercise.title, status: 'published', audience: exercise.audience }], nextBeforeId: null };
    if (route === 'assignments?limit=20') return { items: assignments, nextBeforeId: null };
    const detail = /^(?:teacher\/)?assignments\/(11|12)$/.exec(route); if (detail) return { assignment: assignments.find(item => item.id === Number(detail[1])), exercise, sourceAvailable: true, workAvailable: true };
    if (route === 'exercises/3') return exercise;
    if (/\/project$/.test(route)) { if (body) { projects.set(route, body.project); return { revision: 1 }; } return { project: projects.get(route) || exercise.starterProject, revision: projects.has(route) ? 1 : 0 }; }
    if (/\/run$/.test(route)) { const id = ++nextAttempt, base = route.slice(0, -4); histories.set(base, [{ id, exerciseId: 3, exerciseRevision: 2, result: { status: 'graded', score: 100, passed: true } }]); return { id, status: 'graded', score: 100, passed: true, scenarios: [] }; }
    if (/\/attempts\?/.test(route)) return { items: histories.get(route.replace(/\/attempts\?.*/, '')) || [], nextBeforeId: null };
    if (/\/preview$/.test(route)) return { status: 'preview', scenarios: [], trace: [] };
    throw new Error('Unexpected endpoint ' + route);
  }; return { ...f, exercise, assignments, calls, projects, histories };
}

test('public problem list loads without reading group or lesson membership and legacy discovery stays separate', async () => {
  const f = lessonFixture(); await f.app.openRoute({ view: 'list', exerciseId: 0, beforeId: 0 }, 'replace');
  assert.equal(f.calls.map(call => call.route).join(','), 'public-exercises?limit=20'); assert.match(f.root.textContent, new RegExp(f.exercise.title)); assert(namedButton(f.root, '내 수업')); assert(namedButton(f.root, '기존 배포·전체 목록 보기')); assert.equal(f.app.assignment, null);
});

test('assignment URLs preserve exact lesson scope and reject malformed or mismatched context', async () => {
  const f = lessonFixture(); const route = f.api.problemRoute('https://school.example/learn?assignmentId=11&exerciseId=3&mbView=solve', false);
  assert.equal(route.assignmentId, 11); await f.app.openRoute(route, 'replace'); assert.match(f.root.querySelector('[data-mb-context]').textContent, /수업 #7/);
  assert.equal(f.api.problemRoute('https://school.example/learn?assignmentId=broken&exerciseId=3&mbView=solve', false).view, 'invalid');
  await assert.rejects(f.app.openRoute({ view: 'solve', assignmentId: 12, exerciseId: 999, beforeId: 0 }), /연결/); assert.equal(f.app.assignment.id, 11);
});

test('same public problem has separate global and two lesson project saves, grading and browser drafts', async () => {
  const f = lessonFixture(), keys = [], savedCodes = new Map();
  for (const id of [0, 11, 12]) {
    await f.app.openRoute({ view: 'solve', exerciseId: 3, beforeId: 0, ...(id ? { assignmentId: id } : {}) }, 'push');
    const project = JSON.parse(JSON.stringify(f.exercise.starterProject)); project.files['main.ts'] = '// scope ' + id;
    f.app.project = project; f.app.driver.loaded = true; f.app.driver.capture = async () => project; keys.push(f.app.backupKey()); f.app.backup();
    namedButton(f.root, '임시 저장').click(); await nextTick(); const prefix = id ? 'assignments/' + id : 'exercises/3'; savedCodes.set(prefix, project.files['main.ts']);
    namedButton(f.root, '제출하여 채점').click(); await nextTick(); assert.equal(f.calls.at(-1).route, prefix + '/run'); assert.deepEqual(Object.keys(f.calls.at(-1).body), ['project']);
    await f.app.history(3); assert.equal(f.calls.at(-1).route, prefix + '/attempts?limit=20'); assert.equal(f.app.results.querySelectorAll('tbody tr').length, 1);
  }
  assert.equal(new Set(keys).size, 3); for (const [prefix, code] of savedCodes) assert.equal(f.projects.get(prefix + '/project').files['main.ts'], code);
  assert.equal(f.histories.size, 3); assert.equal(f.calls.some(call => call.body && 'assignmentId' in call.body), false);
});

test('lesson sensor preview and history paging retain the assignment rather than falling back to general practice', async () => {
  const f = lessonFixture(); await f.app.openRoute({ view: 'solve', assignmentId: 11, exerciseId: 3, beforeId: 0 }, 'push');
  f.app.driver.loaded = true; f.app.driver.capture = async () => f.exercise.starterProject; namedButton(f.root, '센서 값으로 실행').click(); await nextTick(); assert.equal(f.calls.at(-1).route, 'assignments/11/preview');
  f.app.api = async route => { f.calls.push({ route }); return { items: [{ id: 20, exerciseRevision: 2, result: { status: 'graded', score: 0, passed: false } }], nextBeforeId: route.includes('beforeId') ? null : 20 }; };
  await f.app.history(3); namedButton(f.app.results, '다음 페이지').click(); await nextTick(); assert.equal(f.calls.at(-1).route, 'assignments/11/attempts?limit=20&beforeId=20'); assert.equal(new URL(f.window.location.href).searchParams.get('assignmentId'), '11');
});

test('revoked or missing assignment never loads a general project or submits a general grade', async () => {
  const f = lessonFixture(), original = f.app.api; f.app.api = async (route, body) => { if (route === 'assignments/11') { f.calls.push({ route }); throw new Error('수업 참여 권한이 없습니다.'); } return original(route, body); };
  await f.app.navigate({ view: 'solve', assignmentId: 11, exerciseId: 3, beforeId: 0 }); assert.equal(f.calls.length, 1); assert.equal(f.calls[0].route, 'assignments/11'); assert.equal(f.root.querySelector('iframe'), null); assert.match(f.app.status.textContent, /수업 참여 권한/);
});

test('archived or withdrawn lesson remains read-only with its own submission history', async () => {
  const f = lessonFixture(); f.app.api = async route => { f.calls.push({ route }); if (route === 'assignments/11') return { assignment: { ...f.assignments[0], archived: true }, exercise: null, sourceAvailable: false, workAvailable: false }; if (route === 'assignments/11/attempts?limit=20') return { items: [{ id: 99, exerciseRevision: 1, result: { status: 'graded', score: 0, passed: false } }], nextBeforeId: null }; throw new Error('Unexpected route ' + route); };
  await f.app.openRoute({ view: 'history', assignmentId: 11, exerciseId: 3, beforeId: 0 }, 'replace'); assert.equal(namedButton(f.root, '문제 풀기'), undefined); assert.equal(f.root.querySelector('iframe'), null); assert.match(f.app.results.textContent, /99/);
  await assert.rejects(f.app.openRoute({ view: 'solve', assignmentId: 11, exerciseId: 3, beforeId: 0 }), /중지/); assert.equal(f.calls.some(call => call.route.startsWith('exercises/')), false);
});

test('teacher selects managed lesson by opaque pages and applies another author public problem without saving its source', async () => {
  const f = routingFixture('teacher'), calls = []; let applied = false;
  f.app.api = async (route, body) => { calls.push({ route, body }); if (route.startsWith('teacher/classes?')) return { available: true, items: [{ id: route.includes('cursor=') ? 8 : 7, title: '컴퓨팅 수업' }], nextCursor: route.includes('cursor=') ? null : 'opaque/a+b=' };
    if (route.startsWith('teacher/classes/8/assignments?')) return { items: applied ? [{ id: 11, classId: 8, exerciseId: 91, exerciseRevision: 4, title: '다른 교사의 공개 문제', revision: 1 }] : [], nextBeforeId: null };
    if (route === 'teacher/public-exercises?limit=20') return { items: [{ id: 91, ownerId: 999, title: '다른 교사의 공개 문제' }], nextBeforeId: null };
    if (route === 'teacher/classes/8/assignments') { applied = true; assert.equal(JSON.stringify(body), '{"exerciseId":91,"revision":0}'); return { id: 11 }; } throw new Error(route); };
  await f.app.openRoute({ view: 'lessons', exerciseId: 0, beforeId: 0 }, 'replace'); namedButton(f.root, '다음 수업 페이지').click(); await nextTick(); assert.match(calls.at(-1).route, /cursor=opaque%2Fa%2Bb%3D/);
  namedButton(f.root, '문제 적용·결과 보기').click(); await nextTick(); namedButton(f.root, '공개 문제 선택').click(); await nextTick(); namedButton(f.root, '수업에 적용').click(); await nextTick();
  assert.equal(applied, true); assert.match(f.root.textContent, /다른 교사의 공개 문제/); assert.equal(calls.some(call => call.route === 'teacher/exercises'), false); assert.equal(new URL(f.window.location.href).searchParams.get('classId'), '8');
});

test('teacher lesson report distinguishes score zero, compile failure and never-submitted roster members', async () => {
  const f = lessonFixture('teacher'); let page = 0;
  const original = f.app.api; f.app.api = async (route, body) => { if (!route.includes('/results?')) return original(route, body); f.calls.push({ route }); page++;
    return { assignment: f.assignments[0], contextRevision: 'context-7', rosterRevision: 'roster-9', nextCursor: page === 1 ? 'opaque/a+b=' : null, items: page === 1 ? [
      { userId: 1, displayName: '영점 학생', managementName: '101', latest: { id: 81, exerciseRevision: 2, result: { status: 'graded', passed: false, score: 0 }, createdAt: '2026-11-03' } },
      { userId: 2, displayName: '컴파일 학생', latest: { id: 82, exerciseRevision: 1, result: { status: 'compile_error', passed: false, score: null }, createdAt: '2026-11-03' } },
      { userId: 3, displayName: '미제출 학생', latest: null }] : [{ userId: 4, displayName: '다음 학생', latest: null }] }; };
  await f.app.openRoute({ view: 'results', assignmentId: 11, exerciseId: 3, beforeId: 0 }, 'replace'); const rows = [...f.root.querySelectorAll('[data-mb-lesson-results] tbody tr')]; assert.match(rows[0].textContent, /미통과0/); assert.doesNotMatch(rows[0].textContent, /미제출/); assert.match(rows[1].textContent, /컴파일 오류/); assert.match(rows[2].textContent, /미제출/);
  assert.equal(namedButton(f.root, '문제 편집'), undefined); assert.equal(namedButton(f.root, '편집기 미리보기'), undefined);
  namedButton(f.root, '다음 학생 페이지').click(); await nextTick(); assert.match(f.calls.at(-1).route, /cursor=opaque%2Fa%2Bb%3D/); assert.match(f.calls.at(-1).route, /contextRevision=context-7&rosterRevision=roster-9/); assert.match(f.root.querySelector('[data-mb-lesson-results]').textContent, /다음 학생/);
});

test('teacher historical report works after public source withdrawal without fetching author-private data', async () => {
  const f = lessonFixture('teacher'); f.app.api = async route => { f.calls.push({ route }); if (route === 'teacher/assignments/11') return { assignment: { ...f.assignments[0], archived: true }, exercise: null, sourceAvailable: false, workAvailable: false }; if (route.endsWith('/results?limit=20')) return { assignment: f.assignments[0], items: [], nextCursor: null, contextRevision: 'c', rosterRevision: 'r' }; throw new Error(route); };
  await f.app.openRoute({ view: 'results', assignmentId: 11, exerciseId: 3, beforeId: 0 }, 'replace'); assert(f.root.querySelector('[data-mb-lesson-results]')); assert.equal(f.calls.some(call => call.route.includes('teacher/exercises')), false); assert.equal(f.root.querySelector('iframe'), null);
});

test('lesson navigation cancellation keeps the exact assignment route and draft key', async () => {
  const f = lessonFixture(); await f.app.openRoute({ view: 'solve', assignmentId: 11, exerciseId: 3, beforeId: 0 }, 'replace'); const href = f.window.location.href, key = f.app.backupKey(); f.app.dirty = true; f.app.confirm = async () => false;
  await f.app.navigate({ view: 'solve', assignmentId: 12, exerciseId: 3, beforeId: 0 }); assert.equal(f.app.assignment.id, 11); assert.equal(f.app.backupKey(), key); assert.equal(f.window.location.href, href);
});

test('archived teacher assignment retains result access and reattaches its original lesson record', async () => {
  const f = routingFixture('teacher'), calls = []; let active = false;
  f.app.api = async (route, body) => { calls.push({ route, body }); if (route === 'teacher/classes/7/assignments') { active = true; return { id: 11, revision: 3 }; } return { items: [{ id: 11, classId: 7, exerciseId: 3, title: '공개 문제', exerciseRevision: 2, revision: active ? 3 : 2, archived: !active }], nextBeforeId: null }; };
  await f.app.openRoute({ view: 'lesson', classId: 7, exerciseId: 0, beforeId: 0 }, 'replace'); assert.match(f.root.textContent, /적용 해제·기록 보관/); assert(namedButton(f.root, '수업 결과')); assert(namedButton(f.root, '제출 내역'));
  namedButton(f.root, '다시 적용').click(); await nextTick(); assert.equal(active, true); const call = calls.find(call => call.body); assert.equal(JSON.stringify(call.body), '{"exerciseId":3,"revision":0}'); assert.equal(namedButton(f.root, '다시 적용'), undefined); assert(namedButton(f.root, '적용 해제'));
});

test('roster change error preserves the existing lesson report without refetching all students or history', async () => {
  const f = lessonFixture('teacher'), original = f.app.api; let calls = 0;
  f.app.api = async (route, body) => { if (!route.includes('/results?')) return original(route, body); calls++; if (calls > 1) throw new Error('수업 학생 구성이 변경되었습니다. 첫 페이지부터 다시 확인해 주세요.'); return { items: [{ userId: 1, displayName: '기존 학생', latest: null }], nextCursor: 'next', contextRevision: 'c1', rosterRevision: 'r1' }; };
  await f.app.openRoute({ view: 'results', assignmentId: 11, exerciseId: 3, beforeId: 0 }, 'replace'); namedButton(f.root, '다음 학생 페이지').click(); await nextTick(); assert.equal(calls, 2); assert.match(f.root.querySelector('[data-mb-lesson-results]').textContent, /기존 학생/); assert.match(f.app.status.textContent, /변경/); assert.equal(f.calls.some(call => call.route.includes('/attempts')), false);
});

test('submission-history button on the lesson editor retains assignment scope and browser address', async () => {
  const f = lessonFixture(); await f.app.openRoute({ view: 'solve', assignmentId: 12, exerciseId: 3, beforeId: 0 }, 'replace'); namedButton(f.root, '내 제출 내역').click(); await nextTick(); assert.equal(f.calls.at(-1).route, 'assignments/12/attempts?limit=20'); assert.equal(new URL(f.window.location.href).searchParams.get('assignmentId'), '12'); assert.equal(f.app.assignment.id, 12);
});

test('unavailable roster offers explicit lesson history without silently opening general results', async () => {
  const f = lessonFixture('teacher'), original = f.app.api; f.app.api = async (route, body) => { if (route.includes('/results?')) throw new Error('현재 학생 명단을 조회할 수 없습니다.'); return original(route, body); };
  await f.app.navigate({ view: 'results', assignmentId: 11, exerciseId: 3, beforeId: 0 }); assert(f.root.querySelector('[data-mb-results-unavailable]')); namedButton(f.root, '수업 제출 내역 확인').click(); await nextTick(); assert.equal(f.calls.at(-1).route, 'teacher/assignments/11/attempts?limit=20');
});

test('withdrawn or unassigned lessons remain discoverable as read-only submission history', async () => {
  const f = lessonFixture(), original = f.app.api; f.app.api = async (route, body) => route === 'assignments?limit=20' ? { items: [{ ...f.assignments[0], workAvailable: false, archived: false }], nextBeforeId: null } : original(route, body);
  await f.app.openRoute({ view: 'lessons', exerciseId: 0, beforeId: 0 }); assert.match(f.root.textContent, /공개 중단·기록 보관/); namedButton(f.root, '수업 제출 내역').click(); await nextTick(); assert.equal(f.calls.at(-1).route, 'assignments/11/attempts?limit=20'); assert.equal(f.app.route.view, 'history');
});

test('returning to the first roster page or its detail omits the continuation-only roster revision', async () => {
  const f = lessonFixture('teacher'), original = f.app.api, queries = [];
  f.app.api = async (route, body) => {
    if (route.includes('/results?')) { const query = new URL('https://school.example/' + route).searchParams; queries.push(query); const continued = Boolean(query.get('cursor')); assert.equal(query.has('rosterRevision'), continued, 'Public roster protocol requires a revision only with a continuation cursor'); if (continued) assert.equal(query.get('rosterRevision'), 'roster-1');
      return { items: [{ userId: continued ? 21 : 20, displayName: continued ? '다음 학생' : '첫 학생', latest: continued ? null : { id: 81, exerciseRevision: 2, result: { status: 'graded', passed: false, score: 0 }, createdAt: '2026-11-03' } }], nextCursor: continued ? null : 'next/a+b=', contextRevision: 'class-1', rosterRevision: 'roster-1' }; }
    if (route === 'teacher/assignments/11/attempts/81') return { result: { status: 'graded', score: 0, passed: false, scenarios: [] } };
    return original(route, body);
  };
  await f.app.openRoute({ view: 'results', assignmentId: 11, exerciseId: 3, beforeId: 0 }, 'replace'); namedButton(f.root, '다음 학생 페이지').click(); await nextTick(); namedButton(f.root, '이전 학생 페이지').click(); await nextTick(); assert.equal(queries[2].get('contextRevision'), 'class-1'); assert.equal(queries[2].has('rosterRevision'), false); assert.match(f.root.querySelector('[data-mb-lesson-results]').textContent, /첫 학생/);
  namedButton(f.root, '결과 보기').click(); await nextTick(); namedButton(f.root, '수업 결과로').click(); await nextTick(); assert.equal(queries.at(-1).has('rosterRevision'), false); assert.equal(queries.at(-1).get('contextRevision'), 'class-1'); assert.match(f.root.querySelector('[data-mb-lesson-results]').textContent, /첫 학생/);
});


test('local navigation marks the correct problem or lesson context on every child view', () => {
  const f = routingFixture();
  for (const route of [{view:'list'},{view:'detail',exerciseId:3},{view:'solve',exerciseId:3},{view:'history',exerciseId:3},{view:'legacy'},{view:'edit',exerciseId:3}]) {
    f.app.renderNavigation(route); const current=f.root.querySelectorAll('[data-mb-navigation] [aria-current="page"]'); assert.equal(current.length,1); assert.equal(current[0].textContent,'공개 문제'); assert.equal(current[0].classList.contains('ojp-button'),false);
  }
  for(const route of [{view:'lessons'},{view:'lesson',classId:7},{view:'results',assignmentId:11},{view:'history',assignmentId:11,exerciseId:3}]) { f.app.renderNavigation(route);assert.equal(f.root.querySelector('[data-mb-navigation] [aria-current="page"]').textContent,'내 수업'); }
});

test('empty public list has an explicit title and explanation instead of an unlabeled placeholder', async () => {
  const f=routingFixture();f.app.api=async()=>({items:[],nextBeforeId:null});await f.app.openRoute({view:'list',exerciseId:0,beforeId:0});const empty=f.root.querySelector('[data-mb-empty]');assert(empty);assert.match(empty.querySelector('h3').textContent,/공개된 마이크로비트 문제가 없습니다/);assert.match(empty.querySelector('p').textContent,/문제가 공개되면/);assert.equal(f.root.querySelector('.ojp-empty-state'),null);
});

function nativeFixture(mode='student',section='problems',href=null) {
  const base=mode==='teacher'?'/school/oj-console/addons/oj-microbit':'/school/oj-addons/oj-microbit/learn',lesson=mode==='teacher'?base+'/lessons':'/school/oj-addons/oj-microbit/lessons';
  const f=routingFixture(mode,href||'https://school.example'+(section==='lessons'?lesson:base)), leaves=[];Object.assign(f.app.config,{nativeNavigation:true,initialSection:section,problemMenuUrl:base,lessonMenuUrl:lesson,problemPageUrl:base,lessonPageUrl:lesson,...(mode==='teacher'?{createPageUrl:base+'/new'}:{})});
  f.window.location.assign=url=>{leaves.push(['assign',url]);f.window.location.href=url;};f.window.location.replace=url=>{leaves.push(['replace',url]);f.window.location.href=url;};return {...f,leaves};
}

test('independent problem and lesson menu links keep their own active state and never link to core lists', () => {
  const f=nativeFixture();f.app.renderNavigation({view:'detail',exerciseId:3});assert.equal(f.root.querySelectorAll('[data-mb-navigation] button').length,0);assert.equal(f.root.querySelector('[data-mb-navigation] a').getAttribute('href'),'https://school.example/school/oj-addons/oj-microbit/learn');assert.equal(f.root.querySelector('[data-mb-navigation] [aria-current="page"]').textContent,'마이크로비트 문제');
  f.app.renderNavigation({view:'history',assignmentId:11,exerciseId:3});assert.equal(f.root.querySelector('[data-mb-navigation] [aria-current="page"]').getAttribute('href'),'https://school.example/school/oj-addons/oj-microbit/lessons');assert.equal(f.root.querySelectorAll('[data-mb-navigation] a').length,2);assert([...f.root.querySelectorAll('[data-mb-navigation] a')].every(a=>new URL(a.href).pathname.startsWith('/school/oj-addons/oj-microbit/')));assert.equal(f.root.querySelector('[data-mb-navigation] [aria-current="page"]').textContent,'마이크로비트 수업');
});

test('native lesson page starts in its section without a special view query', async () => {
  const f=nativeFixture('student','lessons','https://school.example/school/oj-addons/oj-microbit/lessons'),calls=[];f.app.api=async route=>{calls.push(route);return route==='status'?{ready:true}:{items:[],nextBeforeId:null};};await f.app.start();assert.equal(f.app.route.view,'lessons');assert(calls.includes('assignments?limit=20'));assert.equal(calls.some(r=>r.startsWith('public-exercises')),false);
});

test('legacy assignment deep link redirects to the correct native lesson page before fetching any data', async () => {
  const f=nativeFixture('student','problems','https://school.example/school/oj-addons/oj-microbit/learn?assignmentId=11&exerciseId=3&mbView=history&mbBefore=99');f.app.api=async()=>{throw new Error('Must redirect before API fetch');};await f.app.start();assert.equal(f.leaves.length,1);assert.equal(f.leaves[0][0],'replace');const url=new URL(f.leaves[0][1]);assert.equal(url.pathname,'/school/oj-addons/oj-microbit/lessons');assert.equal(url.searchParams.get('assignmentId'),'11');assert.equal(url.searchParams.get('exerciseId'),'3');assert.equal(url.searchParams.get('mbBefore'),'99');assert.equal(url.searchParams.get('mbView'),'history');assert.equal(f.app.route,null);
});

test('native cross-section navigation preserves class context and honors unsaved cancellation', async () => {
  const f=nativeFixture('teacher');f.app.dirty=true;f.app.confirm=async()=>false;await f.app.navigate({view:'lesson',classId:7,exerciseId:0,beforeId:12});assert.equal(f.leaves.length,0);assert.equal(f.app.dirty,true);
  f.app.confirm=async()=>true;await f.app.navigate({view:'lesson',classId:7,exerciseId:0,beforeId:12});assert.equal(f.leaves.length,1);const url=new URL(f.leaves[0][1]);assert.equal(url.pathname,'/school/oj-console/addons/oj-microbit/lessons');assert.equal(url.searchParams.get('classId'),'7');assert.equal(url.searchParams.get('mbBefore'),'12');assert.equal(f.app.dirty,true);assert.equal(f.app.confirmedNavigation,true);
});

test('native menu links preserve draft on cancel and avoid duplicate unload confirmation after approval', async () => {
  const f=nativeFixture();f.app.dirty=true;f.app.confirm=async()=>false;await f.app.followLink('https://school.example/school/oj-addons/oj-microbit/learn');assert.equal(f.leaves.length,0);assert.equal(f.app.dirty,true);f.app.confirm=async()=>true;await f.app.followLink('https://school.example/school/oj-addons/oj-microbit/learn');assert.equal(f.leaves.length,1);assert.equal(f.app.dirty,true);let prevented=0;f.app.beforeUnload({preventDefault(){prevented++;}});assert.equal(prevented,0);assert.equal(f.app.confirmedNavigation,false);f.app.beforeUnload({preventDefault(){prevented++;}});assert.equal(prevented,1);
});

test('native URL configuration accepts subdirectory paths and refuses missing or external destinations', () => {
  const f=nativeFixture();assert.equal(f.api.internalUrl('/school/oj-addons/oj-microbit/learn',f.window.location.href),'https://school.example/school/oj-addons/oj-microbit/learn');for(const value of [undefined,'','https://other.example/problems','javascript:alert(1)','https://user:secret@school.example/problems'])assert.equal(f.api.internalUrl(value,f.window.location.href),null);f.app.config.lessonPageUrl='https://other.example/classes';assert.equal(f.app.nativePageTarget({view:'lessons'}),null);
});


test('global problem bookmark on a native lesson page redirects before any class or problem request', async () => {
  const f=nativeFixture('student','lessons','https://school.example/school/oj-addons/oj-microbit/lessons?exerciseId=3&mbView=detail');f.app.api=async()=>{throw new Error('Must redirect before API fetch');};await f.app.start();assert.equal(f.leaves.length,1);const url=new URL(f.leaves[0][1]);assert.equal(url.pathname,'/school/oj-addons/oj-microbit/learn');assert.equal(url.searchParams.get('exerciseId'),'3');assert.equal(url.searchParams.has('assignmentId'),false);
});

test('initial load failure leaves readable navigation back to the correct native menu', async () => {
  const f=nativeFixture();f.app.api=async path=>{if(path==='status')return {ready:true};throw new Error('문제 목록을 불러오지 못했습니다.');};await f.app.start();assert.match(f.app.status.textContent,/불러오지/);assert.equal(f.root.querySelector('[data-mb-navigation] a').getAttribute('href'),'https://school.example/school/oj-addons/oj-microbit/learn');assert.equal(f.root.querySelectorAll('iframe').length,0);assert.equal(f.app.busy,false);
});


test('native navigation failure and bfcache restoration preserve unsaved work', () => {
  const f=nativeFixture();f.app.dirty=true;f.app.project={files:{'main.ts':'unsaved code'}};f.window.location.assign=()=>{throw new Error('Navigation blocked');};assert.throws(()=>f.app.leaveFor('https://school.example/school/oj-addons/oj-microbit/learn'),/blocked/);assert.equal(f.app.dirty,true);assert.equal(f.app.confirmedNavigation,false);assert.equal(f.app.project.files['main.ts'],'unsaved code');f.app.confirmedNavigation=true;f.app.pageShow();assert.equal(f.app.confirmedNavigation,false);assert.equal(f.app.dirty,true);
});

test('modified native link clicks keep current draft and allow normal new-tab behavior', () => {
  const f=nativeFixture();f.app.dirty=true;let confirmations=0;f.app.confirm=async()=>{confirmations++;return true;};const link=f.app.menuLink('수업 메뉴','https://school.example/school/oj-addons/oj-microbit/lessons');const event=new f.window.document.defaultView.Event('click',{cancelable:true,bubbles:true});Object.defineProperty(event,'ctrlKey',{value:true});link.dispatchEvent(event);assert.equal(event.defaultPrevented,false);assert.equal(confirmations,0);assert.equal(f.leaves.length,0);assert.equal(f.app.dirty,true);
});


test('teacher create is a distinct selected menu and learners never receive its link', () => {
  const f=nativeFixture('teacher');f.app.renderNavigation({view:'new',exerciseId:0});const nav=f.root.querySelector('[data-mb-navigation]');assert.equal(nav.querySelectorAll('a').length,3);assert.equal(nav.querySelectorAll('[aria-current="page"]').length,1);assert.equal(nav.querySelector('[aria-current="page"]').textContent,'문제 생성');assert.equal(new URL(nav.querySelector('[aria-current="page"]').href).pathname,'/school/oj-console/addons/oj-microbit/new');
  const student=nativeFixture();student.app.config.createPageUrl='/school/oj-console/addons/oj-microbit/new';student.app.renderNavigation({view:'list'});assert.equal(student.root.querySelectorAll('[data-mb-navigation] a').length,2);
});

test('teacher creation opens without a view query and uses the selected example after reload', async () => {
  const f=nativeFixture('teacher','problems','https://school.example/school/oj-console/addons/oj-microbit/new?mbTemplate=servo'),calls=[];f.app.config.initialView='new';f.app.api=async route=>{calls.push(route);return {ready:true};};await f.app.start();assert.equal(f.app.route.view,'new');assert.equal(f.app.route.template,'servo');assert(f.app.exercise.connections.some(c=>c.signal==='servo'));assert.deepEqual(calls,['status']);assert.equal(f.leaves.length,0);f.app.destroy();
});

test('new and example actions move to the creation route and preserve unsaved cancellation', async () => {
  const f=nativeFixture('teacher');f.app.dirty=true;f.app.confirm=async()=>false;await f.app.navigate({view:'new',template:'i2c',exerciseId:0,beforeId:0});assert.equal(f.leaves.length,0);assert.equal(f.app.dirty,true);f.app.confirm=async()=>true;await f.app.navigate({view:'new',template:'i2c',exerciseId:0,beforeId:0});assert.equal(f.leaves.length,1);const url=new URL(f.leaves[0][1]);assert.equal(url.pathname,'/school/oj-console/addons/oj-microbit/new');assert.equal(url.searchParams.get('mbTemplate'),'i2c');assert.equal(f.app.dirty,true);
});

test('saved new problems keep the editor frame and route state without another save or page load', () => {
  const f=nativeFixture('teacher','problems','https://school.example/school/oj-console/addons/oj-microbit/new');f.app.edit(f.api.sampleExercise());const driver=f.app.driver,frame=driver.frame;f.app.dirty=true;f.app.api=()=>{throw new Error('Duplicate save');};f.app.savedExercise({...f.app.exercise,id:71,revision:1},'저장 완료');assert.equal(f.leaves.length,0);assert.equal(f.app.driver,driver);assert.equal(f.app.driver.frame,frame);assert.equal(f.app.route.exerciseId,71);assert.equal(f.app.exercise.id,71);assert.equal(f.app.exercise.revision,1);assert.equal(f.app.dirty,false);f.app.destroy();
});

test('creation routing ignores untrusted templates and removes templates from later problem URLs', () => {
  const api=nativeFixture().api; assert.equal(api.problemRoute('https://school.example/new?mbTemplate=servo',false,'problems','new').view,'list');assert.equal(api.problemRoute('https://school.example/new?mbTemplate=evil',true,'problems','new').template,undefined);const selected=api.problemRoute('https://school.example/new?mbTemplate=sequence',true,'problems','new');assert.equal(selected.template,'sequence');assert.equal(new URL(api.problemUrl('https://school.example/new?mbTemplate=servo',{view:'edit',exerciseId:71,beforeId:0})).searchParams.has('mbTemplate'),false);
});


test('saving no longer depends on navigation permission and keeps the saved identifier for later edits', () => {
  const f=nativeFixture('teacher','problems','https://school.example/school/oj-console/addons/oj-microbit/new');f.app.edit(f.api.sampleExercise());f.window.location.replace=()=>{throw new Error('Navigation blocked');};f.app.savedExercise({...f.app.exercise,id:71,revision:2},'저장 완료');assert.equal(f.app.exercise.id,71);assert.equal(f.app.exercise.revision,2);assert.equal(f.app.route.exerciseId,71);assert.equal(f.app.dirty,false);f.app.destroy();
});

test('platform navigation suppresses duplicate problem lesson and creation tabs', () => {
  const f=nativeFixture('teacher');f.app.config.shellNavigation=true;for(const view of ['new','list','lessons','edit']){f.app.renderNavigation({view,exerciseId:3});assert.equal(f.root.querySelector('[data-mb-navigation]'),null);}f.app.destroy();
});

test('compact authoring loads MakeCode only when its code step opens and keeps it on later toggles', () => {
  const f=nativeFixture('teacher');f.app.config.compactEditor=true;f.app.config.shellNavigation=true;f.app.edit(f.api.sampleExercise());assert.equal(f.app.driver,null);assert.equal(f.root.querySelectorAll('.ojmicrobit-compose-section').length,4);const code=f.root.querySelector('[data-mb-code-section]');code.open=true;code.dispatchEvent(new f.window.document.defaultView.Event('toggle'));const driver=f.app.driver;assert(driver);code.open=false;code.dispatchEvent(new f.window.document.defaultView.Event('toggle'));code.open=true;code.dispatchEvent(new f.window.document.defaultView.Event('toggle'));assert.equal(f.app.driver,driver);assert.equal(f.root.querySelectorAll('iframe').length,1);f.app.destroy();
});

test('workspace initialization and view metadata do not warn about unsaved code while real edits do', () => {
  const f=appFixture('teacher');f.app.edit(f.api.sampleExercise());const driver=f.app.driver,initial=JSON.parse(JSON.stringify(driver.project));let config=JSON.parse(initial.files['pxt.json']);config.preferredEditor='blocksprj';config.targetVersions={target:'9.1.1'};initial.files['pxt.json']=JSON.stringify(config);initial.name='Synced header';driver.options.onProject(JSON.parse(JSON.stringify(initial)));assert.equal(f.app.dirty,false);let prevented=0;f.app.beforeUnload({preventDefault(){prevented++;}});assert.equal(prevented,0);initial.files['main.ts']+='\n// changed';driver.options.onProject(JSON.parse(JSON.stringify(initial)));assert.equal(f.app.dirty,true);f.app.beforeUnload({preventDefault(){prevented++;}});assert.equal(prevented,1);f.app.destroy();
});

test('late editor changes during save remain dirty and are not replaced with the older server snapshot', () => {
  const f=appFixture('teacher');f.app.edit(f.api.sampleExercise());const saved={...f.app.exercise,id:71,revision:2,starterProject:JSON.parse(JSON.stringify(f.app.project))},driver=f.app.driver;f.app.project=JSON.parse(JSON.stringify(f.app.project));f.app.project.files['main.ts']+='\n// later edit';f.app.dirty=true;f.app.savedExercise(saved,'저장 완료');assert.equal(f.app.driver,driver);assert.match(f.app.project.files['main.ts'],/later edit/);assert.equal(f.app.exercise.revision,2);assert.equal(f.app.dirty,true);assert.match(f.app.status.textContent,/한 번 더 저장/);f.app.destroy();
});

test('collapsible editing cards preserve open step and exact timeline values when controls rerender', () => {
  const f=appFixture('teacher');f.app.edit(f.api.sampleExercise());const before=JSON.stringify(f.app.exercise.scenarios);let card=f.root.querySelector('[data-mb-step="1"]');assert.equal(card.open,false);card.open=true;f.app.renderScenarios();assert.equal(f.root.querySelector('[data-mb-step="1"]').open,true);assert.equal(JSON.stringify(f.app.exercise.scenarios),before);f.app.destroy();
});

// Guided authoring projects onto the existing grading contract atomically.
const plain = value => JSON.parse(JSON.stringify(value));
function wizardDefinition(overrides = {}) { return {mode:'stage',atMs:0,pin:0,value:800,holdMs:500,withinMs:100,tolerance:0,...overrides}; }
const sensorConnection = {pin:0,direction:'input',signal:'analog',device:'potentiometer',label:'센서'};
const ledConnection = {pin:1,direction:'output',signal:'digital',device:'led',label:'LED'};
function wizardFixture(exercise) { const f=appFixture('teacher');f.app.config.compactEditor=true;f.app.edit(exercise||f.api.blankExercise());return f; }
function wizardNext(f) { namedButton(f.app.stageWizard.dialog,'다음').click(); }
function wizardApply(f) { namedButton(f.app.stageWizard.dialog,'단계에 적용').click(); }

const motorConnection = {pin:2,direction:'output',signal:'pwm',device:'motor',label:'모터'};
function batchAction(expected,holdMs=200){return {expected,holdMs,withinMs:100,tolerance:0};}
function batchDefinition(overrides={}){return {mode:'new',atMs:0,intervalMs:1000,channels:[
  {pin:0,newConnection:sensorConnection,value:800},
  {pin:1,newConnection:ledConnection,steps:[batchAction(1,500),batchAction(0)]},
  {pin:2,newConnection:motorConnection,steps:[batchAction(800)]},
],...overrides};}
function batchApply(f){namedButton(f.app.stageWizard.dialog,'핀 '+f.app.stageWizard.state.channels.filter(x=>x.selected).length+'개 함께 적용').click();}

test('a batch adds input and two independent outputs with one serial lane using the existing contract',()=>{
  const {api}=environment(),scenario=api.emptyScenario(1),before=JSON.stringify(scenario),result=api.applyStageBatch(scenario,[],batchDefinition());
  assert.equal(result.connections.length,3);assert.equal(result.scenario.inputs[0].value,800);assert.equal(result.scenario.outputSequences.length,2);assert.equal(result.scenario.outputSequences[0].steps.length,2);assert.equal(result.scenario.outputSequences[1].afterMs,0);assert.equal(result.scenario.durationMs,1000);assert.equal(JSON.stringify(scenario),before);
});
test('failure in a later batch pin leaves earlier valid new pins and scenario entirely untouched',()=>{
  const {api}=environment(),scenario=api.emptyScenario(1),connections=[],def=batchDefinition();def.channels[2].steps[0].expected=1024;const before=JSON.stringify([scenario,connections]);assert.throws(()=>api.applyStageBatch(scenario,connections,def),/P2/);assert.equal(JSON.stringify([scenario,connections]),before);
});
test('batch editing keeps fixed assertions fixed and preserves other phases and original I2C order',()=>{
  const {api}=environment(),ex=api.exerciseModel(api.sampleExercise()),scenario=ex.scenarios[0];scenario.i2cAssertions=[{address:32,operation:'write',data:[0],repeated:true,afterMs:0,withinMs:100},{address:32,operation:'read',data:[30],repeated:false,afterMs:0,withinMs:100}];
  const channels=api.stageBatchChannels(scenario,ex.connections,0).map(x=>({pin:x.pin,value:x.value,steps:x.steps}));channels[0].value=100;
  const result=api.applyStageBatch(scenario,ex.connections,{mode:'edit',atMs:0,intervalMs:500,channels});
  assert.equal(result.scenario.outputSequences.length,0);assert.equal(result.scenario.assertions.length,scenario.assertions.length);assert.deepEqual(plain(result.scenario.i2cAssertions),plain(scenario.i2cAssertions));assert.deepEqual(plain(result.scenario.inputs.slice(1)),plain(scenario.inputs.slice(1)));assert.equal(result.scenario.inputs[0].value,100);
});
test('adding a second value explicitly converts only that selected legacy output to a serial sequence',()=>{
  const {api}=environment(),ex=api.exerciseModel(api.sampleExercise()),scenario=ex.scenarios[0],channels=api.stageBatchChannels(scenario,ex.connections,0).map(x=>({pin:x.pin,value:x.value,steps:x.steps}));channels.find(x=>x.pin===1).steps.push(batchAction(1,50));
  const result=api.applyStageBatch(scenario,ex.connections,{mode:'edit',atMs:0,intervalMs:500,channels});assert.equal(result.scenario.outputSequences.length,1);assert.equal(result.scenario.outputSequences[0].pin,1);assert.equal(result.scenario.outputSequences[0].steps.length,2);assert.equal(result.scenario.assertions.filter(x=>x.pin===2).length,3);
});
test('batch selection rejects duplicate pins, reserved I2C pins, unsupported analog pins and digital tolerance',()=>{
  const {api}=environment();for(const mutate of [d=>d.channels.push(plain(d.channels[0])),d=>{d.channels[0].pin=5;d.channels[0].newConnection.pin=5;},d=>{d.channels[2].pin=19;d.channels[2].newConnection.pin=19;},d=>{d.channels[1].steps[0].tolerance=1;},d=>{d.channels[1].newConnection.device='unknown';}]){const def=plain(batchDefinition());mutate(def);assert.throws(()=>api.applyStageBatch(api.emptyScenario(1),[],def,true));}
});
test('batch retiming can grow an edited output budget and moves later input phases together',()=>{
  const {api}=environment(),ex=api.exerciseModel(api.sampleExercise()),scenario=ex.scenarios[0],channels=api.stageBatchChannels(scenario,ex.connections,0).map(x=>({pin:x.pin,value:x.value,steps:x.steps}));channels.find(x=>x.pin===1).steps[0].holdMs=700;
  const result=api.applyStageBatch(scenario,ex.connections,{mode:'edit',atMs:0,intervalMs:1000,channels});assert.deepEqual(plain(result.scenario.inputs.map(x=>x.atMs)),[0,1000,1500]);assert.equal(result.scenario.durationMs,scenario.durationMs+500);
});
test('batch budgets and action counts fail before committing a shorter interval or excessive duration',()=>{
  const {api}=environment();assert.throws(()=>api.applyStageBatch(api.emptyScenario(1),[],batchDefinition({intervalMs:500})),/간격/);
  const def=plain(batchDefinition());def.channels[1].steps=Array.from({length:21},()=>batchAction(1));assert.throws(()=>api.applyStageBatch(api.emptyScenario(1),[],def),/20/);
  const scenario=api.emptyScenario(1);scenario.durationMs=29900;scenario.inputs=[{atMs:0,pin:0,signal:'analog',value:0}];assert.throws(()=>api.applyStageBatch(scenario,[sensorConnection],batchDefinition({atMs:29900,channels:[{pin:1,newConnection:ledConnection,steps:[batchAction(1)]}]})),/30초/);
});
test('batch servo angle and pulse retain distinct ranges and reject tolerance covering the full range',()=>{
  const {api}=environment(),def={mode:'new',atMs:0,intervalMs:1000,channels:[{pin:1,newConnection:{...ledConnection,signal:'servo',device:'servo'},steps:[batchAction(90)]},{pin:2,newConnection:{...motorConnection,signal:'servo_pulse',device:'servo'},steps:[{...batchAction(1000),tolerance:1000}]}]};const result=api.applyStageBatch(api.emptyScenario(1),[],def);assert.deepEqual(plain(result.scenario.outputSequences.map(x=>x.signal)),['servo','servo_pulse']);assert.equal(result.scenario.outputSequences[1].steps[0].tolerance,1000);def.channels[1].steps[0].tolerance=20000;assert.throws(()=>api.applyStageBatch(api.emptyScenario(1),[],def),/전체 출력 범위/);
});
test('ambiguous same-pin legacy conditions are never silently merged by the batch editor',()=>{
  const {api}=environment(),ex=api.exerciseModel(api.sampleExercise());ex.scenarios[0].assertions.push(plain(ex.scenarios[0].assertions[0]));const before=JSON.stringify(ex);assert.throws(()=>api.stageBatchChannels(ex.scenarios[0],ex.connections,0),/여러 개/);assert.equal(JSON.stringify(ex),before);
});
test('focused author navigation shows one panel and preserves the same editor across later steps',()=>{
  const f=wizardFixture();assert.equal(f.root.querySelectorAll('[data-mb-author-panel]:not([hidden])').length,1);assert.equal(f.app.authorStep,1);f.app.showAuthorStep(2,false);assert.equal(f.app.driver,null);f.app.showAuthorStep(3,false);const driver=f.app.driver,frame=f.root.querySelector('iframe');f.app.showAuthorStep(4,false);f.app.showAuthorStep(3,false);assert.equal(f.app.driver,driver);assert.equal(f.root.querySelector('iframe'),frame);assert.equal(f.root.querySelector('[aria-current="step"]').getAttribute('data-mb-author-tab'),'3');
});
test('stage edit preselects all pins and cancellation preserves every original value',()=>{
  const f=wizardFixture(fixtureWithParallel()),scenario=f.app.exercise.scenarios[0],before=JSON.stringify(f.app.exercise);f.app.openStageBatchWizard(scenario,{atMs:0});wizardNext(f);assert.equal(f.app.stageWizard.state.channels.filter(x=>x.selected).length,3);assert.equal(f.app.stageWizard.dialog.querySelectorAll('[data-mb-batch-pin]').length,3);wizardNext(f);assert.equal(f.app.stageWizard.dialog.querySelectorAll('[data-mb-batch-channel]').length,3);namedButton(f.app.stageWizard.dialog,'작성 취소').click();assert.equal(JSON.stringify(f.app.exercise),before);assert.equal(Boolean(f.app.content.inert),false);
});
test('one batch applies three pins with serial values without server calls or replacing the editor',()=>{
  const f=wizardFixture();f.app.showAuthorStep(3,false);const driver=f.app.driver;f.app.api=async()=>{throw Error('Modal must not call REST');};f.app.showAuthorStep(2,false);f.app.openStageBatchWizard(null);wizardNext(f);f.app.stageWizard.state.channels.push(...batchDefinition().channels.map(x=>({pin:x.pin,selected:true,added:true,connection:plain(x.newConnection),value:x.value,steps:plain(x.steps||[])})));wizardNext(f);wizardNext(f);batchApply(f);assert.equal(f.app.stageWizard,null);assert.equal(f.app.exercise.connections.length,3);assert.equal(f.app.exercise.scenarios[0].outputSequences.length,2);assert.equal(f.app.driver,driver);assert.equal(f.app.dirty,true);
});
test('batch errors retain the dialog and all original exercise values until valid Apply',()=>{
  const f=wizardFixture(fixtureWithParallel()),scenario=f.app.exercise.scenarios[0],before=JSON.stringify(f.app.exercise);f.app.openStageBatchWizard(scenario,{atMs:0});wizardNext(f);wizardNext(f);const item=f.app.stageWizard.state.channels.find(x=>x.pin===2);item.steps[0].expected=1024;wizardNext(f);assert.equal(f.app.stageWizard.state.page,2);assert.match(f.app.stageWizard.dialog.textContent,/P2/);assert.equal(JSON.stringify(f.app.exercise),before);
});
test('batch detects changes to connection metadata while open and keeps the draft untouched',()=>{
  const f=wizardFixture(fixtureWithParallel()),scenario=f.app.exercise.scenarios[0];f.app.openStageBatchWizard(scenario,{atMs:0});wizardNext(f);wizardNext(f);wizardNext(f);f.app.exercise.connections[0].label='다른 변경';const before=JSON.stringify(f.app.exercise);batchApply(f);assert(f.app.stageWizard);assert.match(f.app.stageWizard.dialog.textContent,/원본이 바뀌/);assert.equal(JSON.stringify(f.app.exercise),before);
});
test('batch commit is atomic when another example has no room for a new initial sensor value',()=>{
  const f=wizardFixture(fixtureWithParallel());const other=plain(f.app.exercise.scenarios[0]);other.id='full';other.inputs=Array.from({length:256},(_,i)=>({atMs:i,pin:0,signal:'digital',value:0}));f.app.exercise.scenarios.push(other);const before=JSON.stringify(f.app.exercise),connection={...sensorConnection,pin:3};const applied=f.api.applyStageBatch(f.app.exercise.scenarios[0],f.app.exercise.connections,{mode:'edit',atMs:0,intervalMs:2000,channels:[{pin:3,newConnection:connection,value:800}]});assert.throws(()=>f.app.commitStageBatch(f.app.exercise.scenarios[0],applied),/공간/);assert.equal(JSON.stringify(f.app.exercise),before);
});
test('focused problem uses one readable title, public examples and scoped controls with no eager iframe',()=>{
  const f=appFixture();f.app.config.compactEditor=true;f.app.runnerNotice=()=>{};const ex=fixtureWithParallel();ex.id=3;ex.title='<script>문제 제목</script>';ex.scenarios.push({...plain(ex.scenarios[0]),id:'hidden',name:'비공개 조건 이름',visible:false});f.app.problem(ex,false);const box=f.root.querySelector('.ojmicrobit-focused-problem');assert(box);assert.equal(box.querySelectorAll('h1').length,1);assert.equal(box.querySelector('h1').textContent,ex.title);assert.equal(box.querySelectorAll('script').length,0);assert.equal(box.querySelectorAll('iframe').length,0);const tests=box.querySelector('[data-mb-public-tests]');tests.dispatchEvent(new f.window.document.defaultView.Event('toggle'));assert.doesNotMatch(tests.textContent,/비공개 조건 이름/);assert(box.querySelector('[data-mb-solve]'));assert.equal(box.querySelector('[data-mb-solve]').getAttribute('aria-label'),'문제 풀기');assert.match(box.textContent,/이번에 만들 동작/);
});
test('focused read-only lesson cannot expose a solve button or remove its assignment context',()=>{
  const f=appFixture();f.app.config.compactEditor=true;f.app.runnerNotice=()=>{};const ex={...f.api.sampleExercise(),id:3};f.app.problem(ex,false,{id:11,classId:7,classTitle:'센서 수업',exerciseId:3,workAvailable:false});assert.equal(namedButton(f.root,'문제 풀기'),undefined);assert.match(f.root.querySelector('.ojmicrobit-problem-actions').textContent,/읽기 전용/);assert.equal(f.app.assignment.id,11);assert(namedButton(f.root,'내 제출 내역'));
});
test('focused server field errors open the correct author step before marking the original control',async()=>{
  const f=wizardFixture(fixtureWithParallel());f.app.showAuthorStep(1,false);f.app.showError({message:'입력값을 확인하세요.',fieldPath:'exercise.scenarios.0.inputs.0.value'});await nextTick();assert.equal(f.app.authorStep,2);assert(f.root.querySelector('[aria-invalid="true"]'));
});
test('focused publication review opens from its final action and retains the prepared code frame',async()=>{
  const f=wizardFixture(fixtureWithParallel());f.app.showAuthorStep(3,false);const driver=f.app.driver;f.app.captureProject=async()=>f.app.project;f.app.showAuthorStep(4,false);namedButton(f.root,'공개 전 점검 →').click();await nextTick();assert.equal(f.app.exercise.status,'published');assert.equal(f.app.authorStep,4);assert(f.root.querySelector('[data-mb-author-panel="4"] [data-mb-publication-review]'));assert.equal(f.app.driver,driver);namedButton(f.root,'작성 계속하기').click();assert.equal(f.app.driver,driver);
});

test('compact creation begins empty instead of preloading several input stages', async()=>{
  const f=wizardFixture();assert.equal(f.app.exercise.connections.length,0);assert.equal(f.app.exercise.scenarios.length,0);assert.equal(f.root.querySelector('iframe'),null);namedButton(f.root,'+ 첫 단계 만들기').click();assert.match(f.app.stageWizard.dialog.textContent,/1. 단계 만들기/);assert.equal(f.app.exercise.scenarios.length,0);
});
test('stage helper stores input at explicit time and separates interval from output hold',()=>{
  const {api}=environment();const scenario=api.emptyScenario(1),before=JSON.stringify(scenario);const input=api.applyStageWizard(scenario,[],wizardDefinition({connection:sensorConnection}));assert.equal(input.scenario.durationMs,500);assert.equal(JSON.stringify(scenario),before);assert.deepEqual(plain(input.scenario.inputs),[{atMs:0,pin:0,signal:'analog',value:800}]);
  const output=api.applyStageWizard(input.scenario,input.connections,wizardDefinition({mode:'parallel',pin:1,value:1,connection:ledConnection,holdMs:200}));assert.equal(output.scenario.durationMs,500);assert.equal(output.scenario.outputSequences[0].steps[0].holdMs,200);assert.equal(output.scenario.outputSequences[0].steps[0].withinMs,100);
});
test('serial append converts only its selected fixed condition and preserves the other pin',()=>{
  const {api}=environment();const exercise=api.exerciseModel(api.sampleExercise()),scenario=exercise.scenarios[0],before=JSON.stringify(scenario);const updated=api.applyStageWizard(scenario,exercise.connections,wizardDefinition({mode:'serial',pin:1,value:1,holdMs:100}));assert.equal(updated.scenario.assertions.length,scenario.assertions.length-1);assert.equal(updated.scenario.outputSequences[0].steps.length,2);assert.deepEqual(plain(updated.scenario.outputSequences[0].steps[0]),{expected:0,withinMs:100,holdMs:200,tolerance:0});assert.equal(JSON.stringify(scenario),before);assert.deepEqual(plain(updated.scenario.assertions.filter(x=>x.pin===2)),plain(scenario.assertions.filter(x=>x.pin===2)));
});
test('new input pins receive visible initial zero and exact nonzero later values',()=>{
  const {api}=environment();const scenario=api.emptyScenario(1);scenario.outputSequences=[{pin:1,signal:'digital',afterMs:0,steps:[{expected:1,withinMs:100,holdMs:200,tolerance:0}]}];const result=api.applyStageWizard(scenario,[ledConnection],wizardDefinition({atMs:1000,connection:sensorConnection}));assert.deepEqual(plain(result.scenario.inputs),[{atMs:0,pin:0,signal:'analog',value:0},{atMs:1000,pin:0,signal:'analog',value:800}]);assert.equal(result.scenario.durationMs,1500);
});
test('invalid pin, signal, value and duration reject without changing source or connections',()=>{
  const {api}=environment();const scenario=api.emptyScenario(1),connections=[],before=JSON.stringify([scenario,connections]);for(const def of [wizardDefinition({connection:{...sensorConnection,pin:5},pin:5}),wizardDefinition({connection:sensorConnection,value:1024}),wizardDefinition({connection:sensorConnection,holdMs:0}),wizardDefinition({connection:sensorConnection,withinMs:NaN}),wizardDefinition({connection:sensorConnection,atMs:30000}),wizardDefinition({connection:{...ledConnection,pin:19},pin:19,value:1})]){assert.throws(()=>api.applyStageWizard(scenario,connections,def,true));assert.equal(JSON.stringify([scenario,connections]),before);}
});
test('output extension rejects collisions and 30-second overflow atomically',()=>{
  const {api}=environment();const exercise=api.exerciseModel(api.sampleExercise()),scenario=exercise.scenarios[0],before=JSON.stringify(scenario);assert.throws(()=>api.applyStageWizard(scenario,exercise.connections,wizardDefinition({mode:'serial',pin:1,value:1,holdMs:500})),/겹쳐/);assert.equal(JSON.stringify(scenario),before);assert.throws(()=>api.applyStageWizard(api.emptyScenario(1),[ledConnection],wizardDefinition({pin:1,value:1,atMs:29900,holdMs:200})),/30초/);
});
test('editing an input interval moves later phases together and preserves their pin values',()=>{
  const {api}=environment();const exercise=api.exerciseModel(api.sampleExercise()),scenario=exercise.scenarios[0];const result=api.applyStageWizard(scenario,exercise.connections,wizardDefinition({mode:'edit',target:{kind:'inputs',index:0},holdMs:700,value:100}));assert.deepEqual(plain(result.scenario.inputs.map(x=>x.atMs)),[0,700,1200]);assert.deepEqual(plain(result.scenario.assertions.map(x=>x.afterMs)),[0,0,700,700,1200,1200]);assert.equal(result.scenario.inputs[0].value,100);assert.equal(result.scenario.durationMs,scenario.durationMs+200);
});
test('editing one serial value preserves the other actions and rejects stale targets',()=>{
  const {api}=environment();const exercise=api.exerciseModel(api.sequenceExercise()),scenario=exercise.scenarios[0];const result=api.applyStageWizard(scenario,exercise.connections,wizardDefinition({mode:'edit',pin:1,value:0,holdMs:300,target:{kind:'outputSequences',index:0,actionIndex:1}}));assert.equal(result.scenario.outputSequences[0].steps[1].holdMs,300);assert.deepEqual(plain(result.scenario.outputSequences[0].steps[0]),plain(scenario.outputSequences[0].steps[0]));assert.throws(()=>api.applyStageWizard(scenario,exercise.connections,wizardDefinition({mode:'edit',pin:1,value:0,target:{kind:'outputSequences',index:99,actionIndex:1}})),/바뀌/);
});
test('flow view places separate pins in parallel and chains only the steps of one pin',()=>{
  const f=wizardFixture(fixtureWithParallel());assert.equal(f.root.querySelectorAll('[data-mb-parallel]').length,1);assert.equal(f.root.querySelectorAll('[data-mb-serial]').length,1);assert.equal(f.root.querySelectorAll('.ojmicrobit-flow-arrow').length,1);assert.match(f.root.textContent,/함께 시작해요/);assert.match(f.root.textContent,/직렬 · 앞 동작 유지 완료 후/);assert.equal(namedButton(f.root,'동작 순서로 검사'),undefined);
});
function fixtureWithParallel(){const {api}=environment();const ex=api.exerciseModel(api.sequenceExercise());ex.connections.push({pin:2,direction:'output',signal:'pwm',device:'motor',label:'모터'});ex.scenarios[0].outputSequences.push({pin:2,signal:'pwm',afterMs:0,steps:[{expected:800,withinMs:100,holdMs:200,tolerance:0}]});return ex;}
test('wizard has four pages, commits only at Apply and cancellation restores exact data',()=>{
  const f=wizardFixture(),before=JSON.stringify(f.app.exercise);f.app.openStageWizard(null);assert.equal(f.app.content.inert,true);wizardNext(f);assert.match(f.app.stageWizard.dialog.textContent,/2. 사용할 핀/);wizardNext(f);assert.match(f.app.stageWizard.dialog.textContent,/3. 입력값 또는 출력값/);assert.equal(JSON.stringify(f.app.exercise),before);namedButton(f.app.stageWizard.dialog,'이전').click();assert.equal(f.app.stageWizard.state.page,1);namedButton(f.app.stageWizard.dialog,'작성 취소').click();assert.equal(JSON.stringify(f.app.exercise),before);assert.equal(Boolean(f.app.content.inert),false);assert.equal(f.app.dirty,false);assert.equal(f.app.stageWizard,null);
});
test('wizard applies an input through four pages and Escape preserves the prior stage',()=>{
  const f=wizardFixture();f.app.openStageWizard(null);wizardNext(f);wizardNext(f);const value=f.app.stageWizard.dialog.querySelector('input');value.value='800';value.dispatchEvent(new f.window.document.defaultView.Event('input'));wizardNext(f);assert.match(f.app.stageWizard.dialog.textContent,/다음 입력 단계까지 간격/);wizardApply(f);assert.equal(f.app.exercise.scenarios.length,1);assert.equal(f.app.exercise.scenarios[0].inputs[0].value,800);assert.equal(f.app.exercise.scenarios[0].durationMs,500);assert.equal(f.app.stageWizard,null);const before=JSON.stringify(f.app.exercise);f.app.openStageWizard(f.app.exercise.scenarios[0]);const e=new f.window.document.defaultView.Event('keydown',{cancelable:true});e.key='Escape';f.app.stageWizard.dialog.dispatchEvent(e);assert.equal(f.app.stageWizard,null);assert.equal(JSON.stringify(f.app.exercise),before);
});
test('invalid wizard values and conflicting originals keep the dialog open without a partial pin',()=>{
  const f=wizardFixture();f.app.openStageWizard(null);wizardNext(f);wizardNext(f);f.app.stageWizard.state.value=2000;wizardNext(f);assert.equal(f.app.stageWizard.state.page,2);assert.match(f.app.stageWizard.dialog.textContent,/0~1023/);assert.equal(f.app.exercise.connections.length,0);f.app.stageWizard.close();
  const ex=f.api.exerciseModel(f.api.sampleExercise());f.app.edit(ex);const original=f.app.exercise.scenarios[0];f.app.openStageWizard(original,{mode:'edit',atMs:0,pin:0,value:800,target:{kind:'inputs',index:0}});wizardNext(f);wizardNext(f);wizardNext(f);original.name+=' edited elsewhere';wizardApply(f);assert.match(f.app.stageWizard.dialog.textContent,/원본이 바뀌/);assert.equal(original.inputs[0].value,0);
});
test('applying a pin step preserves the mounted editor frame and initial values for a separate example',()=>{
  const f=appFixture('teacher');f.app.config.compactEditor=true;f.app.edit(f.api.sampleExercise());const code=f.root.querySelector('[data-mb-code-section]');code.open=true;code.dispatchEvent(new f.window.document.defaultView.Event('toggle'));const driver=f.app.driver,frame=f.root.querySelector('iframe');f.app.openStageWizard(null);wizardNext(f);wizardNext(f);wizardNext(f);wizardApply(f);assert.equal(f.app.driver,driver);assert.equal(f.root.querySelector('iframe'),frame);assert.equal(f.app.exercise.scenarios.length,2);assert.equal(f.app.exercise.scenarios.at(-1).inputs[0].atMs,0);
});
test('student lane view omits edit actions and hidden scenarios without altering original timing',()=>{
  const f=appFixture();f.app.exercise=f.api.exerciseModel(fixtureWithParallel());f.app.exercise.scenarios.push({...plain(f.app.exercise.scenarios[0]),id:'secret',name:'숨긴 동작',visible:false});const before=JSON.stringify(f.app.exercise.scenarios);f.app.renderPublicTests();const disclosure=f.root.querySelector('[data-mb-public-tests]');disclosure.open=true;disclosure.dispatchEvent(new f.window.document.defaultView.Event('toggle'));assert(disclosure.querySelector('[data-mb-parallel]'));assert(disclosure.querySelector('[data-mb-serial]'));assert.equal(disclosure.querySelectorAll('button').length,0);assert.doesNotMatch(disclosure.textContent,/숨긴 동작/);assert.equal(JSON.stringify(f.app.exercise.scenarios),before);
});

test('adding a new sensor seeds other examples at zero without overwriting their existing values',()=>{
  const ex=environment().api.sampleExercise();ex.scenarios.push({...plain(ex.scenarios[0]),id:'second'});const f=wizardFixture(ex),oldInputs=plain(f.app.exercise.scenarios[1].inputs);f.app.openStageWizard(f.app.exercise.scenarios[0],{mode:'parallel',atMs:0});wizardNext(f);assert.equal(f.app.stageWizard.state.addNew,true);assert.equal(f.app.stageWizard.state.pin,3);wizardNext(f);f.app.stageWizard.state.value=700;wizardNext(f);wizardApply(f);assert.equal(f.app.exercise.scenarios[0].inputs.find(x=>x.pin===3).value,700);assert.deepEqual(plain(f.app.exercise.scenarios[1].inputs.filter(x=>x.pin!==3)),oldInputs);assert.deepEqual(plain(f.app.exercise.scenarios[1].inputs.find(x=>x.pin===3)),{atMs:0,pin:3,signal:'analog',value:0});
});

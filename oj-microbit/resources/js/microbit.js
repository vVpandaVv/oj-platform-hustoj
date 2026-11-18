(function (global) {
  'use strict';

  function clone(value) { return JSON.parse(JSON.stringify(value)); }
  function problemRoute(href, teacher, initialSection, initialView) {
    var url = new URL(href), raw = url.searchParams.get('exerciseId'), id = raw && /^[1-9][0-9]*$/.test(raw) ? Number(raw) : 0;
    var rawAssignment = url.searchParams.get('assignmentId'), assignment = rawAssignment && /^[1-9][0-9]*$/.test(rawAssignment) ? Number(rawAssignment) : 0;
    var rawClass = url.searchParams.get('classId'), classId = rawClass && /^[1-9][0-9]*$/.test(rawClass) ? Number(rawClass) : 0;
    if (rawAssignment !== null && (!assignment || !Number.isSafeInteger(assignment)) || rawClass !== null && (!classId || !Number.isSafeInteger(classId))) { return { view: 'invalid', exerciseId: 0, beforeId: 0 }; }
    var view = url.searchParams.get('mbView') || (assignment ? 'detail' : id ? teacher ? 'edit' : 'detail' : initialSection === 'lessons' ? teacher && classId ? 'lesson' : 'lessons' : teacher && initialView === 'new' ? 'new' : 'list');
    var allowed = teacher ? ['list', 'new', 'edit', 'preview', 'detail', 'solve', 'history', 'lessons', 'lesson', 'results'] : ['list', 'legacy', 'detail', 'solve', 'history', 'lessons'];
    if (!allowed.includes(view) || !Number.isSafeInteger(id) || (['detail', 'preview', 'solve', 'history', 'edit'].includes(view) && !id && !assignment) || (view === 'lesson' && !classId) || (view === 'results' && !assignment) || (assignment && !['detail', 'preview', 'solve', 'history', 'results'].includes(view))) { return { view: 'list', exerciseId: 0, beforeId: 0 }; }
    var rawBefore = url.searchParams.get('mbBefore'), before = rawBefore && /^[1-9][0-9]*$/.test(rawBefore) ? Number(rawBefore) : 0;
    var route = { view: view, exerciseId: ['list', 'legacy', 'new', 'lessons', 'lesson'].includes(view) ? 0 : id, beforeId: Number.isSafeInteger(before) && ['list', 'legacy', 'history', 'lessons', 'lesson'].includes(view) ? before : 0 };
    if (assignment) { route.assignmentId = assignment; } if (classId && view === 'lesson') { route.classId = classId; }
    var template = url.searchParams.get('mbTemplate'); if (teacher && view === 'new' && ['sequence', 'servo', 'i2c'].includes(template)) { route.template = template; } return route;
  }
  function problemUrl(href, route) {
    var url = new URL(href); ['exerciseId', 'assignmentId', 'classId', 'mbView', 'mbBefore', 'mbTemplate'].forEach(function (key) { url.searchParams.delete(key); });
    if (route.exerciseId) { url.searchParams.set('exerciseId', route.exerciseId); }
    if (route.assignmentId) { url.searchParams.set('assignmentId', route.assignmentId); } if (route.classId) { url.searchParams.set('classId', route.classId); }
    if (route.view !== 'list') { url.searchParams.set('mbView', route.view); }
    if (route.beforeId) { url.searchParams.set('mbBefore', route.beforeId); } if (route.view === 'new' && ['sequence', 'servo', 'i2c'].includes(route.template)) { url.searchParams.set('mbTemplate', route.template); } return url.href;
  }
  function projectSignature(value) {
    var project = editorProject(canonicalProject(value));
    function sorted(value) { if (Array.isArray(value)) { return value.map(sorted); } if (value && typeof value === 'object') { var result = {}; Object.keys(value).sort().forEach(function (key) { result[key] = sorted(value[key]); }); return result; } return value; }
    return JSON.stringify(Object.keys(project.files).sort().map(function (name) { var content = project.files[name]; if (name === 'pxt.json') { var config = JSON.parse(content); delete config.preferredEditor; delete config.targetVersions; content = JSON.stringify(sorted(config)); } return [name, content]; }));
  }
  function uid() { return global.crypto && global.crypto.randomUUID ? global.crypto.randomUUID() : 'mb-' + Date.now() + '-' + Math.random().toString(36).slice(2); }
  function canonicalProject(value) {
    var source = value && (value.files || value.text), files = Object.create(null), metadata = ['_history', '.simstate.json', '.git.json'];
    if (!source || typeof source !== 'object' || Array.isArray(source)) { throw new Error('MakeCode 프로젝트 파일을 확인해 주세요.'); }
    Object.keys(source).forEach(function (key) {
      // These three exact PXT workspace files are editor/simulator/Git state,
      // never program source. Unknown filenames are preserved for the server's
      // authoritative validation, rather than silently deleting student work.
      if (metadata.includes(key)) { return; }
      if (typeof source[key] !== 'string') { throw new Error('프로젝트 파일 내용이 올바르지 않습니다: ' + key); }
      files[key] = source[key];
    });
    if (typeof files['main.ts'] !== 'string' || typeof files['pxt.json'] !== 'string') { throw new Error('main.ts와 pxt.json이 필요합니다.'); }
    var config = JSON.parse(files['pxt.json']);
    var changed = false;
    ['files', 'testFiles'].forEach(function (key) { if (Array.isArray(config[key]) && config[key].some(function (name) { return metadata.includes(name); })) { config[key] = config[key].filter(function (name) { return !metadata.includes(name); }); changed = true; } });
    if (changed) { files['pxt.json'] = JSON.stringify(config, null, 2); }
    return { name: String(value.name || (value.header && value.header.name) || config.name || '마이크로비트 프로젝트'), files: files };
  }
  function makeHeader(project, id) {
    var config = JSON.parse(project.files['pxt.json']);
    return { id: id, name: project.name, target: 'microbit', meta: {}, editor: config.preferredEditor || (project.files['main.blocks'] && !project.files['main.ts'].trim() ? 'blocksprj' : 'tsprj'),
      pubId: '', pubCurrent: false, recentUse: Date.now() / 1000, modificationTime: Date.now() / 1000, isDeleted: false };
  }
  function editorProject(project) {
    var prepared = clone(project), config = JSON.parse(prepared.files['pxt.json']);
    // MakeCode hides its Blocks tab when the package has no main.blocks file.
    // Keep a block file in the native workspace even for a TypeScript starter.
    if (!Object.prototype.hasOwnProperty.call(prepared.files, 'main.blocks')) {
      prepared.files['main.blocks'] = '<xml xmlns="https://developers.google.com/blockly/xml"></xml>\n';
      config.files = (config.files || ['main.ts']).concat(['main.blocks']);
      config.preferredEditor = config.preferredEditor || 'tsprj';
      prepared.files['pxt.json'] = JSON.stringify(config, null, 2);
    }
    return prepared;
  }
  function validHex(text) {
    if (typeof text !== 'string' || text.length < 30 || text.length > 8000000) { return false; }
    var lines = text.trim().split(/\r?\n/), end = false;
    for (var i = 0; i < lines.length; i += 1) {
      var line = lines[i].trim();
      // MakeCode's Universal HEX can contain padding blank lines between
      // blocks. The Foundation's ihex.splitHex also removes empty lines.
      // Every non-empty record still needs its exact byte count and checksum.
      if (!line) { continue; }
      if (!/^:[0-9a-f]+$/i.test(line) || line.length < 11 || line.length % 2 !== 1) { return false; }
      var bytes = line.slice(1).match(/.{2}/g).map(function (byte) { return parseInt(byte, 16); });
      if (bytes.length !== bytes[0] + 5 || bytes.reduce(function (sum, byte) { return sum + byte; }, 0) % 256 !== 0) { return false; }
      if (bytes[3] === 1) { end = true; }
    }
    return end;
  }

  /** Protocol adapter, based on PXT's public iframe workspace/controller contract.
   * Both the exact frame window and its configured origin are checked on receipt.
   * App controller mode returns real native HEX data; USB belongs to this page.
   */
  function EditorDriver(frame, options) {
    this.frame = frame; this.options = options || {}; this.project = editorProject(canonicalProject(options.project));
    this.projectId = uid(); this.header = makeHeader(this.project, this.projectId); this.pending = new Map();
    this.sequence = 0; this.loaded = false; this.disposed = false; this.hexWaiter = null;
    var url = new URL(options.editorUrl || 'https://makecode.microbit.org/');
    if (url.protocol !== 'https:' && !(url.hostname === 'localhost' || url.hostname === '127.0.0.1')) { throw new Error('MakeCode 주소는 HTTPS여야 합니다.'); }
    url.searchParams.set('controller', '2'); url.searchParams.set('lang', 'ko'); url.hash = 'editor';
    this.origin = url.origin; this.url = url.href;
    this.listener = this.receive.bind(this); global.addEventListener('message', this.listener);
    var self = this;
    this.loadListener = function () { self.post({ type: 'iframeclientready' }); };
    frame.addEventListener('load', this.loadListener);
    frame.allow = 'autoplay; usb'; frame.title = 'MakeCode 마이크로비트 편집기'; frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.src = this.url;
    this.loadTimer = global.setTimeout(function () { if (!self.loaded) { self.notify('MakeCode 연결이 지연되고 있습니다. 인터넷 연결을 확인한 뒤 편집기를 다시 열어 주세요.', true); } }, 60000);
  }
  EditorDriver.prototype.notify = function (text, error) { if (this.options.onStatus) { this.options.onStatus(text, Boolean(error)); } };
  EditorDriver.prototype.post = function (message) {
    if (!this.disposed && this.frame.contentWindow) { this.frame.contentWindow.postMessage(message, this.origin); }
  };
  EditorDriver.prototype.request = function (action, extra, timeoutMs) {
    var self = this, id = 'oj-mb-' + (++this.sequence), message = Object.assign({ type: 'pxteditor', action: action, id: id, response: true }, extra || {});
    return new Promise(function (resolve, reject) {
      if (self.disposed) { reject(new Error('편집기가 닫혔습니다.')); return; }
      var timer = global.setTimeout(function () { self.pending.delete(id); reject(new Error('MakeCode 응답 시간이 초과되었습니다. 다시 시도해 주세요.')); }, timeoutMs || 20000);
      self.pending.set(id, { resolve: resolve, reject: reject, timer: timer }); self.post(message);
    });
  };
  EditorDriver.prototype.markLoaded = function () {
    this.loaded = true; global.clearTimeout(this.loadTimer);
    this.notify('MakeCode가 연결되었습니다. 블록이나 코드를 편집할 수 있습니다.');
    if (this.options.onReady) { this.options.onReady(); }
  };
  EditorDriver.prototype.receive = function (event) {
    if (this.disposed || event.origin !== this.origin || event.source !== this.frame.contentWindow || !event.data || typeof event.data !== 'object') { return; }
    var data = event.data;
    if (data.type === 'iframeclientready') { return; }
    if (data.type === 'pxthost') {
      if (data.action === 'workspacesync') {
        this.post({ type: 'pxthost', id: data.id, action: data.action, success: true, controllerId: 'oj-microbit',
          projects: [{ header: this.header, text: clone(this.project.files) }], editor: { searchBar: true } }); return;
      }
      if (data.action === 'workspacesave' && data.project && data.project.header && data.project.header.id === this.projectId) {
        try {
          this.project = canonicalProject(data.project); this.header = clone(data.project.header);
          if (this.options.onProject) { this.options.onProject(clone(this.project)); }
        } catch (error) { this.notify(error.message, true); }
      }
      if (data.response) { this.post({ type: 'pxthost', id: data.id, success: true }); }
      // MakeCode opens the newest project from workspacesync itself. Workspace
      // loaded is earlier than editor readiness; issuing openheader here races
      // with that initial open and can discard a user's first mode change.
      if (data.action === 'editorcontentloaded') { this.markLoaded(); }
      return;
    }
    if (data.type === 'pxteditor' && typeof data.id === 'string' && this.pending.has(data.id)) {
      var pending = this.pending.get(data.id); this.pending.delete(data.id); global.clearTimeout(pending.timer);
      if (data.success === true) { pending.resolve(data); } else { pending.reject(new Error('MakeCode 작업을 완료하지 못했습니다. 편집기의 오류를 확인해 주세요.')); }
      return;
    }
    if (typeof data.download === 'string' || typeof data.save === 'string') {
      var hex = data.download || data.save;
      if (!validHex(hex)) { if (this.hexWaiter) { this.hexWaiter.reject(new Error('컴파일 결과가 올바른 Intel HEX 파일이 아닙니다.')); } return; }
      var result = { hex: hex, name: String(data.name || this.project.name).replace(/[^a-zA-Z0-9가-힣._-]/g, '_').replace(/(?:\.hex)+$/i, '') || 'microbit' };
      if (this.hexWaiter) { this.hexWaiter.resolve(result); } else if (this.options.onHex) { this.options.onHex(result); }
    }
  };
  EditorDriver.prototype.capture = async function () {
    if (!this.loaded) { throw new Error('MakeCode 연결을 기다려 주세요. 연결되지 않으면 프로젝트 파일을 가져와 계속할 수 있습니다.'); }
    await this.request('saveproject', {}, 30000);
    return clone(this.project);
  };
  EditorDriver.prototype.compileHex = async function () {
    if (this.hexWaiter) { throw new Error('HEX 파일을 생성하고 있습니다. 잠시 기다려 주세요.'); }
    await this.capture();
    var self = this, timer;
    var output = new Promise(function (resolve, reject) {
      timer = global.setTimeout(function () { reject(new Error('HEX 생성 시간이 초과되었습니다. MakeCode의 컴파일 오류와 인터넷 연결을 확인해 주세요.')); }, 120000);
      self.hexWaiter = { resolve: resolve, reject: reject };
    });
    try {
      var results = await Promise.all([output, this.request('compile', {}, 120000)]);
      return results[0];
    } finally { global.clearTimeout(timer); self.hexWaiter = null; }
  };
  EditorDriver.prototype.dispose = function () {
    this.disposed = true; global.clearTimeout(this.loadTimer); global.removeEventListener('message', this.listener);
    this.frame.removeEventListener('load', this.loadListener);
    this.pending.forEach(function (pending) { global.clearTimeout(pending.timer); pending.reject(new Error('편집기가 닫혔습니다.')); }); this.pending.clear();
    if (this.hexWaiter) { this.hexWaiter.reject(new Error('편집기가 닫혔습니다.')); this.hexWaiter = null; }
  };

  function sampleExercise() {
    var source = 'basic.forever(function () {\n    let value = pins.analogReadPin(AnalogPin.P0)\n    if (value >= 512) {\n        pins.digitalWritePin(DigitalPin.P1, 1)\n    } else {\n        pins.digitalWritePin(DigitalPin.P1, 0)\n    }\n    pins.analogWritePin(AnalogPin.P2, value)\n    basic.pause(20)\n})\n';
    var inputs = [{ atMs: 0, pin: 0, signal: 'analog', value: 0 }, { atMs: 500, pin: 0, signal: 'analog', value: 800 }, { atMs: 1000, pin: 0, signal: 'analog', value: 200 }];
    var assertions = [];
    inputs.forEach(function (entry) {
      assertions.push({ pin: 1, signal: 'digital', expected: entry.value >= 512 ? 1 : 0, afterMs: entry.atMs, withinMs: 100, holdMs: 200, tolerance: 0 });
      assertions.push({ pin: 2, signal: 'pwm', expected: entry.value, afterMs: entry.atMs, withinMs: 100, holdMs: 200, tolerance: 0 });
    });
    return { title: '조도에 따라 LED와 모터 제어하기', description: 'P0 센서 값이 512 이상이면 P1 LED를 켜고, 미만이면 끕니다. P2 PWM 출력은 P0 입력과 같게 만드세요.', status: 'draft', i2cDevices: [],
      connections: [{ pin: 0, direction: 'input', signal: 'analog', device: 'light_sensor', label: '조도센서' },
        { pin: 1, direction: 'output', signal: 'digital', device: 'led', label: '경고 LED' }, { pin: 2, direction: 'output', signal: 'pwm', device: 'motor', label: '모터' }],
      scenarios: [{ id: 'light-threshold', name: '어두움 → 밝음 → 어두움', visible: true, durationMs: 1500, inputs: inputs, assertions: assertions, i2cInputs: [], i2cAssertions: [] }],
      starterProject: { name: '조도 제어', files: { 'main.ts': source, 'pxt.json': JSON.stringify({ name: '조도 제어', dependencies: { core: '*' }, files: ['main.ts'], preferredEditor: 'tsprj' }, null, 2) } } };
  }
  function projectWithSource(name, source) { return { name: name, files: { 'main.ts': source, 'pxt.json': JSON.stringify({ name: name, dependencies: { core: '*' }, files: ['main.ts'], preferredEditor: 'tsprj' }, null, 2) } }; }
  function servoExercise() {
    var inputs = [{ atMs: 0, pin: 0, signal: 'analog', value: 0 }, { atMs: 500, pin: 0, signal: 'analog', value: 800 }, { atMs: 1000, pin: 0, signal: 'analog', value: 200 }], assertions = [];
    inputs.forEach(function (entry) {
      assertions.push({ pin: 1, signal: 'servo', expected: entry.value >= 512 ? 135 : 45, afterMs: entry.atMs, withinMs: 100, holdMs: 200, tolerance: 0 });
      assertions.push({ pin: 2, signal: 'servo_pulse', expected: entry.value >= 512 ? 1800 : 1000, afterMs: entry.atMs, withinMs: 100, holdMs: 200, tolerance: 0 });
    });
    return { title: '센서로 서보 각도와 펄스 제어하기', description: 'P0 값이 512 미만이면 P1 서보를 45°, P2 펄스를 1000µs로 설정합니다. 512 이상이면 135°와 1800µs로 바꿉니다. 화면은 명령한 각도·펄스 값을 보여 줍니다.', status: 'draft', i2cDevices: [],
      connections: [{ pin: 0, direction: 'input', signal: 'analog', device: 'potentiometer', label: '입력 센서' }, { pin: 1, direction: 'output', signal: 'servo', device: 'servo', label: '각도 서보' }, { pin: 2, direction: 'output', signal: 'servo_pulse', device: 'servo', label: '펄스 서보' }],
      scenarios: [{ id: 'servo-threshold', name: '서보 각도·펄스 변경', visible: true, durationMs: 1500, inputs: inputs, assertions: assertions, i2cInputs: [], i2cAssertions: [] }],
      starterProject: projectWithSource('서보 제어', 'basic.forever(function () {\n    if (pins.analogReadPin(AnalogPin.P0) >= 512) {\n        pins.servoWritePin(AnalogPin.P1, 135)\n        pins.servoSetPulse(AnalogPin.P2, 1800)\n    } else {\n        pins.servoWritePin(AnalogPin.P1, 45)\n        pins.servoSetPulse(AnalogPin.P2, 1000)\n    }\n    basic.pause(20)\n})\n') };
  }
  function i2cExercise() {
    var assertions = [], transactions = [];
    [30, 90, 150].forEach(function (value, index) {
      var at = index * 500;
      assertions.push({ pin: 1, signal: 'servo', expected: value, afterMs: at, withinMs: 100, holdMs: 200, tolerance: 0 });
      transactions.push({ address: 32, operation: 'write', data: [0], repeated: true, afterMs: at, withinMs: 100 },
        { address: 32, operation: 'read', data: [value], repeated: false, afterMs: at, withinMs: 100 },
        { address: 32, operation: 'write', data: [1, value], repeated: false, afterMs: at, withinMs: 100 });
    });
    return { title: 'I²C 센서 값을 읽어 서보 제어하기', description: '주소 32(0x20)의 레지스터 0을 UInt8BE로 읽어 P1 서보 각도로 사용합니다. 읽은 값은 같은 장치의 레지스터 1에도 씁니다. 레지스터 선택 write → read → 결과 write 순서와 repeated 조건을 함께 검사합니다.', status: 'draft',
      connections: [{ pin: 1, direction: 'output', signal: 'servo', device: 'servo', label: '센서 연동 서보' }],
      i2cDevices: [{ address: 32, label: 'I²C 레지스터 센서', registers: [{ register: 0, value: 30, writable: false }, { register: 1, value: 0, writable: true }] }],
      scenarios: [{ id: 'i2c-servo', name: 'I²C 읽기·쓰기와 서보 연동', visible: true, durationMs: 1500, inputs: [], assertions: assertions,
        i2cInputs: [{ atMs: 500, address: 32, register: 0, value: 90 }, { atMs: 1000, address: 32, register: 0, value: 150 }], i2cAssertions: transactions }],
      starterProject: projectWithSource('I2C 서보 제어', 'basic.forever(function () {\n    pins.i2cWriteNumber(32, 0, NumberFormat.UInt8BE, true)\n    let angle = pins.i2cReadNumber(32, NumberFormat.UInt8BE, false)\n    pins.servoWritePin(AnalogPin.P1, angle)\n    pins.i2cWriteNumber(32, 256 + angle, NumberFormat.UInt16BE, false)\n    basic.pause(500)\n})\n') };
  }
  function exerciseModel(exercise) {
    var result = clone(exercise); if (result.i2cDevices === undefined) { result.i2cDevices = []; }
    if (result.audience === undefined) { result.audience = { mode: 'site', classIds: [] }; }
    result.scenarios.forEach(function (scenario) { if (scenario.i2cInputs === undefined) { scenario.i2cInputs = []; } if (scenario.i2cAssertions === undefined) { scenario.i2cAssertions = []; } if (scenario.outputSequences === undefined) { scenario.outputSequences = []; } }); return result;
  }
  function sequenceExercise() {
    return { title: '버튼을 누르면 LED가 켜졌다 꺼지기', description: 'P0 버튼이 눌리면 0.5초 안에 P1 LED를 켜고, 실제 켜진 뒤 1초 유지한 다음 끄세요. 예제 코드는 0.2초를 기다린 뒤 켭니다.', status: 'draft', i2cDevices: [],
      connections: [{ pin: 0, direction: 'input', signal: 'digital', device: 'button', label: '시작 버튼' }, { pin: 1, direction: 'output', signal: 'digital', device: 'led', label: '표시 LED' }],
      scenarios: [{ id: 'led-sequence', name: '켜짐 1초 → 꺼짐', visible: true, durationMs: 2000, inputs: [{ atMs: 0, pin: 0, signal: 'digital', value: 1 }], assertions: [], i2cInputs: [], i2cAssertions: [],
        outputSequences: [{ pin: 1, signal: 'digital', afterMs: 0, steps: [{ expected: 1, withinMs: 500, holdMs: 1000, tolerance: 0 }, { expected: 0, withinMs: 100, holdMs: 100, tolerance: 0 }] }] }],
      starterProject: projectWithSource('LED 동작 순서', 'if (pins.digitalReadPin(DigitalPin.P0) == 1) {\n    basic.pause(200)\n    pins.digitalWritePin(DigitalPin.P1, 1)\n    basic.pause(1000)\n    pins.digitalWritePin(DigitalPin.P1, 0)\n}\n') };
  }
  function sequenceBudget(sequence) { return sequence.steps.reduce(function (total, step) { return total + step.withinMs + step.holdMs; }, 0); }
  /** Edit atomically: never move a later input stage without an explicit stage-length edit. */
  function updateOutputSequence(scenario, index, operation, protectSteps) {
    var next = clone(scenario); next.outputSequences = next.outputSequences || []; operation(next.outputSequences, next);
    if (next.inputs.length > 256) { throw new Error('한 예시의 입력값은 256개까지 설정할 수 있어요.'); }
    if (next.outputSequences.length > 64 || next.outputSequences.reduce(function (total, entry) { return total + entry.steps.length; }, 0) > 256) { throw new Error('한 예시는 출력 순서 64개, 전체 동작 256개까지 설정할 수 있습니다.'); }
    var sequence = next.outputSequences[index]; if (!sequence) { return next; }
    if (!sequence.steps.length || sequence.steps.length > 20 || sequence.steps.some(function (step) { return !Number.isInteger(step.withinMs) || step.withinMs < 1 || !Number.isInteger(step.holdMs) || step.holdMs < 1; })) { throw new Error('출력 순서는 1~20개 동작이며, 반응 대기와 유지 시간은 각각 0.001초 이상이어야 합니다.'); }
    var required = sequence.afterMs + sequenceBudget(sequence), boundary = scenario.durationMs;
    if (protectSteps) { scenarioEntries(scenario, function (entry, time) { if (entry[time] > sequence.afterMs && entry[time] < boundary) { boundary = entry[time]; } });
      if (boundary < scenario.durationMs && required > boundary) { throw new Error('다음 입력 단계와 겹칩니다. 이 단계의 ‘시간 조건 → 단계 길이(초)’를 최소 ' + sequenceBudget(sequence) / 1000 + '초로 늘린 뒤 다시 설정하세요.'); }
    }
    if (required > 30000) { throw new Error('반응 대기와 유지 시간을 합친 실행 시간은 30초 이하여야 합니다.'); }
    next.durationMs = Math.max(next.durationMs, required); return next;
  }
  function bytesText(bytes) { return (bytes || []).map(function (byte) { return String(byte); }).join(', '); }
  function addressText(address) { return '0x' + Number(address).toString(16).toUpperCase().padStart(2, '0') + ' (' + address + ')'; }
  function stepI2CActions(records) {
    var actions = [];
    for (var index = 0; index < records.length; index += 1) {
      var entry = records[index], next = records[index + 1];
      if (entry.operation === 'write' && entry.data.length === 1 && next && next.operation === 'read' && next.address === entry.address && next.data.length === 1) {
        actions.push({ operation: 'read', address: entry.address, register: entry.data[0], expected: next.data[0], records: [entry, next] }); index += 1;
      } else if (entry.operation === 'write' && entry.data.length === 2) {
        actions.push({ operation: 'write', address: entry.address, register: entry.data[0], expected: entry.data[1], records: [entry] });
      } else { return null; }
    }
    return actions;
  }
  /** Read-only projection. A complex existing timeline remains authoritative. */
  function projectScenarioSteps(scenario) {
    var times = new Set([0]), fail = function (reason) { return { simple: false, reason: reason, steps: [] }; };
    if (!Number.isInteger(scenario.durationMs) || scenario.durationMs < 1) { return fail('실행 시간을 상세 시간표에서 확인하세요.'); }
    var groups = [['inputs', 'atMs'], ['assertions', 'afterMs'], ['outputSequences', 'afterMs'], ['i2cInputs', 'atMs'], ['i2cAssertions', 'afterMs']];
    groups.forEach(function (pair) { (scenario[pair[0]] || []).forEach(function (entry) { times.add(entry[pair[1]]); }); });
    var starts = Array.from(times).sort(function (a, b) { return a - b; });
    if (starts.length > 20 || starts.some(function (time) { return !Number.isInteger(time) || time < 0 || time >= scenario.durationMs; })) { return fail('세밀한 시각이 많은 예시입니다. 원래 상세 시간표를 그대로 편집합니다.'); }
    var bus = scenario.i2cAssertions || [];
    if (bus.some(function (entry, index) { return index && entry.afterMs < bus[index - 1].afterMs; })) { return fail('I²C 거래 순서와 기준 시각을 상세 시간표에서 그대로 편집합니다.'); }
    var steps = [];
    for (var index = 0; index < starts.length; index += 1) {
      var at = starts[index], end = starts[index + 1] === undefined ? scenario.durationMs : starts[index + 1];
      var step = { atMs: at, endMs: end, lengthMs: end - at, inputs: (scenario.inputs || []).filter(function (entry) { return entry.atMs === at; }), assertions: (scenario.assertions || []).filter(function (entry) { return entry.afterMs === at; }), outputSequences: (scenario.outputSequences || []).filter(function (entry) { return entry.afterMs === at; }), i2cInputs: (scenario.i2cInputs || []).filter(function (entry) { return entry.atMs === at; }), i2cAssertions: bus.filter(function (entry) { return entry.afterMs === at; }) };
      var deadlines = new Set(step.assertions.concat(step.i2cAssertions).map(function (entry) { return entry.withinMs; })), holds = new Set(step.assertions.map(function (entry) { return entry.holdMs; }));
      if (deadlines.size > 1 || holds.size > 1 || new Set(step.assertions.map(function (entry) { return entry.pin; })).size !== step.assertions.length) { return fail('같은 시각에 서로 다른 검사 조건이 있습니다. 원래 상세 시간표를 보존합니다.'); }
      if (step.assertions.some(function (entry) { return at + entry.withinMs + entry.holdMs > end; }) || step.outputSequences.some(function (entry) { return at + sequenceBudget(entry) > end; }) || step.i2cAssertions.some(function (entry) { return at + entry.withinMs > end; })) { return fail('시간 조건이 다음 입력 단계와 겹칩니다. 원래 상세 시간표를 보존합니다.'); }
      step.withinMs = deadlines.size ? Array.from(deadlines)[0] : Math.min(500, Math.max(1, Math.floor(step.lengthMs / 2)));
      step.holdMs = holds.size ? Array.from(holds)[0] : Math.min(100, Math.max(1, step.lengthMs - step.withinMs));
      step.i2cActions = stepI2CActions(step.i2cAssertions);
      if (step.i2cActions === null) { return fail('복합 I²C 바이트 거래는 상세 시간표에서 원래 순서 그대로 편집합니다.'); }
      steps.push(step);
    }
    return { simple: true, reason: '', steps: steps };
  }
  function scenarioEntries(scenario, callback) { [['inputs', 'atMs'], ['assertions', 'afterMs'], ['outputSequences', 'afterMs'], ['i2cInputs', 'atMs'], ['i2cAssertions', 'afterMs']].forEach(function (pair) { (scenario[pair[0]] || []).forEach(function (entry) { callback(entry, pair[1], pair[0]); }); }); }
  function retimeScenarioStep(scenario, index, changes) {
    var next = clone(scenario), projection = projectScenarioSteps(next); if (!projection.simple || !projection.steps[index]) { throw new Error('이 예시는 상세 시간표에서 편집하세요.'); }
    var step = projection.steps[index], length = changes.lengthMs === undefined ? step.lengthMs : changes.lengthMs, within = changes.withinMs === undefined ? step.withinMs : changes.withinMs, hold = changes.holdMs === undefined ? step.holdMs : changes.holdMs;
    if (![length, within, hold].every(function (value) { return Number.isInteger(value) && value >= 1; }) || (step.assertions.length && within + hold > length) || (step.i2cAssertions.length && within > length) || step.outputSequences.some(function (entry) { return sequenceBudget(entry) > length; })) { throw new Error('단계 길이는 반응 대기와 모든 출력 동작의 유지 시간을 담을 수 있어야 합니다.'); }
    var delta = length - step.lengthMs;
    if (next.durationMs + delta > 30000) { throw new Error('한 예시는 30초 이하로 설정하세요.'); }
    scenarioEntries(next, function (entry, time) { if (entry[time] >= step.endMs) { entry[time] += delta; } }); next.durationMs += delta;
    if (changes.withinMs !== undefined) { step.assertions.concat(step.i2cAssertions).forEach(function (entry) { entry.withinMs = within; }); }
    if (changes.holdMs !== undefined) { step.assertions.forEach(function (entry) { entry.holdMs = hold; }); }
    return next;
  }
  function duplicateScenarioStep(scenario, index) {
    var next = clone(scenario), projection = projectScenarioSteps(next); if (!projection.simple || !projection.steps[index]) { throw new Error('이 예시는 상세 시간표에서 편집하세요.'); }
    var step = projection.steps[index]; if (next.durationMs + step.lengthMs > 30000) { throw new Error('한 예시는 30초 이하로 설정하세요.'); }
    var copies = { inputs: clone(step.inputs), assertions: clone(step.assertions), outputSequences: clone(step.outputSequences), i2cInputs: clone(step.i2cInputs), i2cAssertions: clone(step.i2cAssertions) };
    var busIndex = step.i2cAssertions.length ? next.i2cAssertions.indexOf(step.i2cAssertions.at(-1)) + 1 : (next.i2cAssertions || []).findIndex(function (entry) { return entry.afterMs >= step.endMs; });
    scenarioEntries(next, function (entry, time) { if (entry[time] >= step.endMs) { entry[time] += step.lengthMs; } }); next.durationMs += step.lengthMs;
    Object.keys(copies).forEach(function (key) { var time = key === 'inputs' || key === 'i2cInputs' ? 'atMs' : 'afterMs'; copies[key].forEach(function (entry) { entry[time] += step.lengthMs; });
      next[key] = next[key] || []; if (key === 'i2cAssertions') { next[key].splice.apply(next[key], [busIndex < 0 ? next[key].length : busIndex, 0].concat(copies[key])); } else { next[key].push.apply(next[key], copies[key]); next[key].sort(function (a, b) { return a[time] - b[time]; }); }
    }); return next;
  }
  function removeScenarioStep(scenario, index, connections) {
    var next = clone(scenario), projection = projectScenarioSteps(next); if (!projection.simple || projection.steps.length < 2 || !projection.steps[index]) { throw new Error('마지막 단계는 삭제할 수 없습니다. 예시 전체를 삭제할 수 있습니다.'); }
    var step = projection.steps[index], held = new Map(), heldI2C = new Map(); (scenario.inputs || []).filter(function (entry) { return entry.atMs <= step.endMs; }).forEach(function (entry) { held.set(entry.pin, entry); });
    (scenario.i2cInputs || []).filter(function (entry) { return entry.atMs <= step.endMs; }).forEach(function (entry) { heldI2C.set(entry.address + ':' + entry.register, entry); });
    [['inputs', 'atMs'], ['assertions', 'afterMs'], ['outputSequences', 'afterMs'], ['i2cInputs', 'atMs'], ['i2cAssertions', 'afterMs']].forEach(function (pair) { next[pair[0]] = (next[pair[0]] || []).filter(function (entry) { return entry[pair[1]] !== step.atMs; }); });
    scenarioEntries(next, function (entry, time) { if (entry[time] >= step.endMs) { entry[time] -= step.lengthMs; } }); next.durationMs -= step.lengthMs;
    if (index === 0) { (connections || []).filter(function (connection) { return connection.direction === 'input'; }).forEach(function (connection) { if (!next.inputs.some(function (entry) { return entry.atMs === 0 && entry.pin === connection.pin; })) { next.inputs.push({ atMs: 0, pin: connection.pin, signal: connection.signal, value: held.has(connection.pin) ? held.get(connection.pin).value : 0 }); } }); next.inputs.sort(function (a, b) { return a.atMs - b.atMs || a.pin - b.pin; }); }
    if (index === 0) { heldI2C.forEach(function (entry) { if (!next.i2cInputs.some(function (item) { return item.atMs === 0 && item.address === entry.address && item.register === entry.register; })) { var restored = clone(entry); restored.atMs = 0; next.i2cInputs.push(restored); } }); next.i2cInputs.sort(function (a, b) { return a.atMs - b.atMs; }); }
    return next;
  }
  function newScenario(exercise, number) {
    var scenario = { id: 'case-' + uid().slice(0, 12), name: '예시 ' + number, visible: true, durationMs: 1000, inputs: [], assertions: [], outputSequences: [], i2cInputs: [], i2cAssertions: [] };
    exercise.connections.forEach(function (connection) { if (connection.direction === 'input') { scenario.inputs.push({ atMs: 0, pin: connection.pin, signal: connection.signal, value: 0 }); }
      else { scenario.assertions.push({ pin: connection.pin, signal: connection.signal, expected: connection.signal === 'servo' ? 90 : connection.signal === 'servo_pulse' ? 1000 : 0, afterMs: 0, withinMs: 500, holdMs: 100, tolerance: 0 }); }
    });
    if (!scenario.assertions.length && exercise.i2cDevices.length && exercise.i2cDevices[0].registers.length) { var device = exercise.i2cDevices[0], register = device.registers[0]; scenario.i2cAssertions.push({ address: device.address, operation: 'write', data: [register.register], repeated: true, afterMs: 0, withinMs: 500 }, { address: device.address, operation: 'read', data: [register.value], repeated: false, afterMs: 0, withinMs: 500 }); }
    return scenario;
  }
  function appendScenarioStep(scenario, exercise) {
    var next = clone(scenario), projection = projectScenarioSteps(next); if (!projection.simple) { throw new Error('이 예시는 상세 시간표에서 편집하세요.'); }
    if (next.durationMs + 1000 > 30000) { throw new Error('한 예시는 30초 이하로 설정하세요.'); }
    var at = next.durationMs, additions = newScenario(exercise, 1), values = new Map(); next.inputs.forEach(function (entry) { values.set(entry.pin, entry.value); });
    additions.inputs.forEach(function (entry) { entry.value = values.has(entry.pin) ? values.get(entry.pin) : entry.value; });
    var last = projection.steps.at(-1); if (last.assertions.length) { additions.assertions = clone(last.assertions); additions.assertions.forEach(function (entry) { entry.withinMs = 500; entry.holdMs = 100; }); }
    last.outputSequences.forEach(function (sequence) { additions.assertions = additions.assertions.filter(function (entry) { return entry.pin !== sequence.pin; }); var finalStep = sequence.steps.at(-1); additions.assertions.push({ pin: sequence.pin, signal: sequence.signal, expected: finalStep.expected, afterMs: 0, withinMs: 500, holdMs: 100, tolerance: finalStep.tolerance }); });
    if (last.i2cAssertions.length) { additions.i2cAssertions = clone(last.i2cAssertions); additions.i2cAssertions.forEach(function (entry) { entry.withinMs = 500; }); }
    scenarioEntries(additions, function (entry, time) { entry[time] = at; });
    ['inputs', 'assertions', 'outputSequences', 'i2cInputs', 'i2cAssertions'].forEach(function (key) { next[key] = (next[key] || []).concat(additions[key]); }); next.durationMs += 1000; return next;
  }
  function el(tag, className, text) { var node = global.document.createElement(tag); if (className) { node.className = className; } if (text !== undefined) { node.textContent = String(text); } return node; }
  function button(text, fn, primary) { var node = el('button', 'ojp-button ojmicrobit-button' + (primary ? ' ojp-button--primary' : ''), text); node.type = 'button'; node.setAttribute('data-mb-variant', primary ? 'primary' : 'secondary'); node.addEventListener('click', fn); return node; }
  function routeSection(route) { return route.assignmentId || ['lessons', 'lesson', 'results'].includes(route.view) ? 'lessons' : 'problems'; }
  function internalUrl(value, base) { if (typeof value !== 'string' || !value.trim()) { return null; } try { var url = new URL(value, base), origin = new URL(base); return ['https:', 'http:'].includes(url.protocol) && url.origin === origin.origin && !url.username && !url.password ? url.href : null; } catch (error) { return null; } }
  function emptyState(title, message) { var box = el('div', 'ojmicrobit-empty'); box.setAttribute('data-mb-empty', ''); box.append(el('h3', '', title), el('p', '', message)); return box; }
  function field(label, control) { var node = el('label', 'ojp-field'); node.append(el('span', 'ojp-label', label), control); return node; }
  function input(value, type, onChange) { var node = el('input', 'ojp-input'); node.type = type || 'text'; node.value = value === undefined ? '' : value;
    if (node.type === 'number') { node.min = '0'; node.step = '1'; }
    if (onChange) { node.addEventListener('input', function () { onChange(node.type === 'number' ? Number(node.value) : node.value); }); } return node; }
  function boundedInput(value, min, max, onChange) { var control = input(value, 'number', onChange); control.min = String(min); control.max = String(max); return control; }
  function checkInput(value, onChange) { var control = input('', 'checkbox'); control.checked = Boolean(value); control.addEventListener('change', function () { onChange(control.checked); }); return control; }
  function byteInput(value, onChange) { var control = input(bytesText(value), 'text', function (text) {
    var valid = /^\s*\d+(?:[\s,]+\d+)*\s*$/.test(text), bytes = valid ? text.trim().split(/[\s,]+/).map(Number) : [];
    valid = valid && bytes.length >= 1 && bytes.length <= 64 && bytes.every(function (byte) { return Number.isInteger(byte) && byte >= 0 && byte <= 255; });
    if (control.setCustomValidity) { control.setCustomValidity(valid ? '' : '0~255 정수 바이트 1~64개를 쉼표로 구분해 입력하세요.'); } onChange(valid ? bytes : []);
  }); control.placeholder = '예: 0, 30'; return control; }
  function select(value, options, onChange) { var node = el('select', 'ojp-select'), found = false; options.forEach(function (pair) { var item = el('option', '', pair[1]); item.value = pair[0]; item.selected = String(pair[0]) === String(value); found = found || item.selected; node.append(item); });
    if (!found) { var missing = el('option', '', '선택 필요 (' + value + ')'); missing.value = String(value); missing.selected = true; missing.disabled = true; node.prepend(missing); }
    if (onChange) { node.addEventListener('change', function () { onChange(node.value); }); } return node; }
  function textArea(value, rows, onChange) { var node = el('textarea', 'ojp-textarea'); node.value = value || ''; node.rows = rows || 4; if (onChange) { node.addEventListener('input', function () { onChange(node.value); }); } return node; }
  function table(headers) { var wrap = el('div', 'ojp-table-wrap'), node = el('table', 'ojp-table'), head = el('thead'), row = el('tr'), body = el('tbody');
    headers.forEach(function (text) { row.append(el('th', '', text)); }); head.append(row); node.append(head, body); wrap.append(node); return { wrap: wrap, body: body }; }
  function cell(row, content) { var node = el('td'); if (typeof content === 'string' || typeof content === 'number') { node.textContent = String(content); } else { node.append(content); } row.append(node); return node; }
  function markField(control, path) { control.setAttribute('data-mb-field', path); return control; }
  function blankExercise() { return { title: '새 마이크로비트 문제', description: '', status: 'draft', audience: { mode: 'site', classIds: [] }, connections: [], i2cDevices: [], scenarios: [], starterProject: projectWithSource('새 마이크로비트 프로젝트', '') }; }
  function emptyScenario(number) { return { id: 'case-' + uid().slice(0, 12), name: '예시 ' + number, visible: true, durationMs: 1000, inputs: [], assertions: [], outputSequences: [], i2cInputs: [], i2cAssertions: [] }; }
  function flowGroups(scenario) {
    var groups = new Map();
    scenarioEntries(scenario, function (entry, fieldName, kind) { var at = entry[fieldName]; if (!groups.has(at)) { groups.set(at, { atMs: at, inputs: [], assertions: [], outputSequences: [], i2cInputs: [], i2cAssertions: [] }); } groups.get(at)[kind].push(entry); });
    var list = Array.from(groups.values()).sort(function (a, b) { return a.atMs - b.atMs; }); list.forEach(function (group, index) { group.endMs = list[index + 1] ? list[index + 1].atMs : scenario.durationMs; group.lengthMs = group.endMs - group.atMs; }); return list;
  }
  /** A public-example illustration, never a synthesized rule or a grading execution. */
  function expectedPreviewModel(exercise, scenario) {
    if (!scenario || !scenario.visible) { return { controls: [], outputs: [], scenes: [] }; }
    var controls = exercise.connections.filter(function (c) { return c.direction === 'input'; }).map(function (c) { return { key: 'pin:' + c.pin, pin: c.pin, label: c.label, signal: c.signal, device: c.device, min: 0, max: c.signal === 'digital' ? 1 : 1023 }; }), known = {}, baseRegisters = new Map();
    (exercise.i2cDevices || []).forEach(function (device) { device.registers.forEach(function (register) { baseRegisters.set(device.address + ':' + register.register, { device: device, register: register }); }); });
    var busKeys = new Set((scenario.i2cInputs || []).map(function (entry) { return entry.address + ':' + entry.register; }));
    busKeys.forEach(function (key) { var initial = baseRegisters.get(key), parts = key.split(':'), control = { key: 'i2c:' + key, label: initial ? initial.device.label : 'I²C 장치', signal: 'register', device: 'i2c', address: Number(parts[0]), register: Number(parts[1]), min: 0, max: 255 }; controls.push(control); if (initial) { known[control.key] = initial.register.value; } });
    var scenes = flowGroups(scenario).map(function (group, index) {
      group.inputs.forEach(function (entry) { known['pin:' + entry.pin] = entry.value; }); group.i2cInputs.forEach(function (entry) { known['i2c:' + entry.address + ':' + entry.register] = entry.value; });
      var plans = new Map(), events = [], unresolved = [];
      function plan(pin, signal, steps) { if (!plans.has(pin)) { plans.set(pin, []); } plans.get(pin).push({ signal: signal, steps: steps }); }
      group.assertions.forEach(function (entry) { plan(entry.pin, entry.signal, [entry]); }); group.outputSequences.forEach(function (entry) { plan(entry.pin, entry.signal, entry.steps); });
      plans.forEach(function (items, pin) { if (items.length !== 1) { unresolved.push(pin); return; } var at = 0, item = items[0]; item.steps.forEach(function (step) { if (at < group.lengthMs) { events.push({ atMs: at, pin: pin, signal: item.signal, value: step.expected }); } at += step.holdMs; }); });
      events.sort(function (a, b) { return a.atMs - b.atMs || a.pin - b.pin; });
      return { index: index, atMs: group.atMs, endMs: group.endMs, inputs: Object.assign({}, known), events: events, unresolved: unresolved, signature: JSON.stringify({ events: events, unresolved: unresolved }) };
    });
    controls.forEach(function (control) { control.values = Array.from(new Set(scenes.map(function (scene) { return scene.inputs[control.key]; }).filter(Number.isInteger))).sort(function (a, b) { return a - b; }); });
    return { controls: controls, outputs: exercise.connections.filter(function (c) { return c.direction === 'output'; }), scenes: scenes };
  }
  function resolveExpectedPreview(model, values, preferredIndex) {
    if (model.controls.some(function (c) { var n = values[c.key]; return !Number.isInteger(n) || n < c.min || n > c.max; })) { return { status: 'invalid', scene: null }; }
    var candidates = model.scenes.filter(function (scene) { return model.controls.every(function (c) { return scene.inputs[c.key] !== undefined && scene.inputs[c.key] === values[c.key]; }); });
    if (!candidates.length) { return { status: 'unknown', scene: null }; }
    var preferred = candidates.find(function (scene) { return scene.index === preferredIndex; });
    if (!preferred && candidates.some(function (scene) { return scene.signature !== candidates[0].signature; })) { return { status: 'ambiguous', scene: null }; }
    return { status: 'match', scene: preferred || candidates[0] };
  }
  /** Atomic UI projection onto the existing pin/timing contract; no extra persisted fields. */
  function applyStageWizard(scenario, connections, definition, hasI2C) {
    var next = clone(scenario), nextConnections = clone(connections), groups = flowGroups(scenario), mode = definition.mode || 'stage', at = definition.atMs;
    if (!['stage', 'parallel', 'serial', 'edit'].includes(mode) || !Number.isInteger(at) || at < 0 || at > 29999) { throw new Error('단계의 시작 시점을 확인해 주세요.'); }
    var connection = nextConnections.find(function (item) { return item.pin === definition.pin; });
    if (definition.connection) {
      if (connection) { throw new Error('이미 연결한 핀이에요. 목록에서 그 장치를 선택하세요.'); }
      connection = clone(definition.connection);
      if (!pins.some(function (pair) { return Number(pair[0]) === connection.pin; }) || connection.pin !== definition.pin || hasI2C && [19, 20].includes(connection.pin)) { throw new Error('사용할 수 있는 핀을 선택하세요. I²C의 P19·P20은 따로 사용해요.'); }
      if (!['input', 'output'].includes(connection.direction) || !(connection.direction === 'input' ? ['digital', 'analog'] : ['digital', 'pwm', 'servo', 'servo_pulse']).includes(connection.signal) || connection.signal === 'analog' && ![0, 1, 2, 3, 4, 10].includes(connection.pin)) { throw new Error('이 핀에서 사용할 수 있는 입력·출력 종류를 선택하세요.'); }
      nextConnections.push(connection);
    }
    if (!connection) { throw new Error('사용할 핀을 선택하세요.'); }
    var min = connection.signal === 'servo_pulse' ? 1 : 0, max = connection.signal === 'digital' ? 1 : connection.signal === 'servo' ? 180 : connection.signal === 'servo_pulse' ? 20000 : 1023;
    if (!Number.isInteger(definition.value) || definition.value < min || definition.value > max) { throw new Error('값은 ' + min + '~' + max + ' 사이의 정수로 입력하세요.'); }
    var hold = definition.holdMs, within = definition.withinMs, tolerance = definition.tolerance === undefined ? 0 : definition.tolerance;
    if (!Number.isInteger(hold) || hold < 1 || hold > 30000 || !Number.isInteger(within) || within < 1 || within > 30000 || !Number.isInteger(tolerance) || tolerance < 0 || tolerance > (connection.signal === 'digital' ? 0 : max)) { throw new Error('시간은 0.001~30초, 허용 오차는 신호 범위 안으로 설정하세요.'); }
    if (connection.direction === 'output' && connection.signal !== 'digital' && definition.value - tolerance <= min && definition.value + tolerance >= max) { throw new Error('허용 오차가 전체 출력 범위를 포함할 수 없어요. 오차를 줄여 주세요.'); }
    var target = definition.target, selected, desiredLength = null;
    if (mode === 'edit') {
      if (!target || !['inputs', 'assertions', 'outputSequences'].includes(target.kind) || !next[target.kind] || !(selected = next[target.kind][target.index]) || selected.pin !== connection.pin || selected[target.kind === 'inputs' ? 'atMs' : 'afterMs'] !== at) { throw new Error('수정할 동작이 바뀌었어요. 창을 닫고 다시 선택하세요.'); }
      if (target.kind === 'inputs') { selected.value = definition.value; desiredLength = hold; }
      else if (target.kind === 'assertions') { Object.assign(selected, { expected: definition.value, withinMs: within, holdMs: hold, tolerance: tolerance }); }
      else { if (!selected.steps[target.actionIndex]) { throw new Error('수정할 출력 동작을 확인하세요.'); } Object.assign(selected.steps[target.actionIndex], { expected: definition.value, withinMs: within, holdMs: hold, tolerance: tolerance }); }
    } else if (mode === 'serial') {
      if (connection.direction !== 'output') { throw new Error('직렬 동작은 출력 핀에 추가할 수 있어요.'); }
      var sequences = next.outputSequences.filter(function (item) { return item.pin === connection.pin && item.afterMs === at; }), fixed = next.assertions.filter(function (item) { return item.pin === connection.pin && item.afterMs === at; });
      if (sequences.length + fixed.length !== 1) { throw new Error('이 핀의 앞 동작 하나를 먼저 선택하세요. 겹치는 조건은 세부 설정에서 확인할 수 있어요.'); }
      selected = sequences[0]; if (!selected) { var first = fixed[0]; selected = { pin: first.pin, signal: first.signal, afterMs: at, steps: [{ expected: first.expected, withinMs: first.withinMs, holdMs: first.holdMs, tolerance: first.tolerance }] }; next.assertions.splice(next.assertions.indexOf(first), 1); next.outputSequences.push(selected); }
      selected.steps.push({ expected: definition.value, withinMs: within, holdMs: hold, tolerance: tolerance });
    } else {
      if (next.inputs.some(function (entry) { return entry.pin === connection.pin && entry.atMs === at; }) || next.assertions.concat(next.outputSequences).some(function (entry) { return entry.pin === connection.pin && entry.afterMs === at; })) { throw new Error('이 시점에 이미 있는 핀이에요. 카드에서 값을 수정하거나 화살표 뒤에 다음 동작을 추가하세요.'); }
      if (connection.direction === 'input') { next.inputs.push({ atMs: at, pin: connection.pin, signal: connection.signal, value: definition.value }); if (mode === 'stage' || !groups.length) { desiredLength = hold; } }
      else { next.outputSequences.push({ pin: connection.pin, signal: connection.signal, afterMs: at, steps: [{ expected: definition.value, withinMs: within, holdMs: hold, tolerance: tolerance }] }); }
    }
    nextConnections.filter(function (entry) { return entry.direction === 'input'; }).forEach(function (entry) { if (!next.inputs.some(function (input) { return input.pin === entry.pin && input.atMs === 0; })) { next.inputs.push({ atMs: 0, pin: entry.pin, signal: entry.signal, value: 0 }); } });
    if (next.outputSequences.length > 64 || next.outputSequences.reduce(function (sum, entry) { return sum + entry.steps.length; }, 0) > 256 || next.outputSequences.some(function (entry) { return entry.steps.length > 20; })) { throw new Error('한 핀의 직렬 동작은 20개까지, 한 예시의 출력 동작은 모두 256개까지 설정할 수 있어요.'); }
    var stage = flowGroups(next).find(function (entry) { return entry.atMs === at; }), budget = Math.max(1, ...stage.assertions.map(function (entry) { return entry.withinMs + entry.holdMs; }), ...stage.outputSequences.map(sequenceBudget), ...stage.i2cAssertions.map(function (entry) { return entry.withinMs; }));
    var following = groups.find(function (entry) { return entry.atMs > at; });
    if (desiredLength !== null) {
      if (desiredLength < budget) { throw new Error('이 입력 간격에는 출력의 반응 시간과 유지 시간을 합친 ' + budget / 1000 + '초 이상이 필요해요.'); }
      if (following) { var projection = projectScenarioSteps(next), index = projection.steps.findIndex(function (entry) { return entry.atMs === at; }); if (!projection.simple || index < 0) { throw new Error('복잡한 기존 시간표는 세부 설정에서 입력 간격을 바꿔 주세요.'); } next = retimeScenarioStep(next, index, { lengthMs: desiredLength }); }
      else { next.durationMs = at + Math.max(desiredLength, budget); }
    } else if (following && at + budget > following.atMs) { throw new Error('다음 입력 시점(' + following.atMs / 1000 + '초)과 겹쳐요. 먼저 입력 단계의 간격을 늘리거나 유지 시간을 줄여 주세요.'); }
    else { next.durationMs = Math.max(next.durationMs, at + budget); if (!groups.length) { next.durationMs = at + budget; } }
    if (next.durationMs > 30000) { throw new Error('한 예시는 최대 30초예요. 단계를 줄이거나 다른 예시로 나눠 주세요.'); }
    next.inputs.sort(function (a, b) { return a.atMs - b.atMs || a.pin - b.pin; }); return { scenario: next, connections: nextConnections };
  }
  function stageBatchChannels(scenario, connections, atMs) {
    var result = [];
    [['inputs', 'atMs'], ['assertions', 'afterMs'], ['outputSequences', 'afterMs']].forEach(function (pair) {
      (scenario[pair[0]] || []).forEach(function (entry) {
        if (entry[pair[1]] !== atMs) { return; }
        if (result.some(function (item) { return item.pin === entry.pin; })) { throw new Error('같은 시점에 한 핀의 조건이 여러 개 있어요. 세부 채점 설정에서 확인해 주세요.'); }
        var connection = connections.find(function (item) { return item.pin === entry.pin; });
        if (!connection) { throw new Error('핀 연결을 먼저 확인해 주세요.'); }
        result.push({ pin: entry.pin, selected: true, added: false, connection: clone(connection), originalKind: pair[0], value: entry.value,
          steps: pair[0] === 'inputs' ? [] : clone(pair[0] === 'assertions' ? [{ expected: entry.expected, holdMs: entry.holdMs, withinMs: entry.withinMs, tolerance: entry.tolerance }] : entry.steps) });
      });
    }); return result;
  }
  /** All selected pins are validated and applied together to the existing contract. */
  function applyStageBatch(scenario, connections, definition, hasI2C) {
    var next = clone(scenario), connected = clone(connections), at = definition.atMs, groups = flowGroups(scenario), group = groups.find(function (item) { return item.atMs === at; }), editing = definition.mode === 'edit', length = definition.intervalMs, channels = definition.channels;
    if (!Number.isInteger(at) || at < 0 || at > 29999 || !['new', 'edit'].includes(definition.mode) || editing && !group || !editing && at !== (groups.length ? scenario.durationMs : 0)) { throw new Error('단계의 기준 시점이 바뀌었어요. 다시 열어 주세요.'); }
    if (!Number.isInteger(length) || length < 1 || length > 30000 || !Array.isArray(channels) || !channels.length || channels.length > pins.length) { throw new Error('핀을 하나 이상 고르고, 입력 단계 간격은 0.001~30초로 정해 주세요.'); }
    var previous = editing ? stageBatchChannels(scenario, connections, at) : [], byPin = new Map();
    channels.forEach(function (channel) {
      if (byPin.has(channel.pin)) { throw new Error('같은 핀을 두 번 선택할 수 없어요.'); }
      var connection = connected.find(function (item) { return item.pin === channel.pin; });
      if (channel.newConnection) {
        var added = channel.newConnection;
        if (connection || added.pin !== channel.pin || !pins.some(function (pair) { return Number(pair[0]) === added.pin; }) || hasI2C && [19, 20].includes(added.pin)) { throw new Error('새 장치의 핀 번호를 확인하세요. I²C를 쓰면 P19·P20은 예약돼요.'); }
        if (!['input', 'output'].includes(added.direction) || !(added.direction === 'input' ? ['digital', 'analog'] : ['digital', 'pwm', 'servo', 'servo_pulse']).includes(added.signal) || added.signal === 'analog' && ![0, 1, 2, 3, 4, 10].includes(added.pin) || !devices.some(function (pair) { return pair[0] === added.device; }) || typeof added.label !== 'string' || !added.label.trim() || added.label.length > 160) { throw new Error('새 장치의 종류와 표시 이름을 확인해 주세요.'); }
        connection = clone(added); connected.push(connection);
      }
      if (!connection) { throw new Error('선택한 핀의 연결 정보가 없어요.'); }
      var minimum = connection.signal === 'servo_pulse' ? 1 : 0, maximum = connection.signal === 'digital' ? 1 : connection.signal === 'servo' ? 180 : connection.signal === 'servo_pulse' ? 20000 : 1023;
      function validValue(value) { if (!Number.isInteger(value) || value < minimum || value > maximum) { throw new Error('P' + channel.pin + ' 값은 ' + minimum + '~' + maximum + ' 사이의 정수로 정해 주세요.'); } }
      var old = previous.find(function (item) { return item.pin === channel.pin; }), kind;
      if (connection.direction === 'input') { validValue(channel.value); kind = 'inputs'; }
      else {
        if (!Array.isArray(channel.steps) || channel.steps.length < 1 || channel.steps.length > 20) { throw new Error('P' + channel.pin + ' 출력 동작은 1~20개로 정해 주세요.'); }
        channel.steps.forEach(function (step) {
          validValue(step.expected);
          if (![step.withinMs, step.holdMs].every(function (value) { return Number.isInteger(value) && value >= 1 && value <= 30000; }) || !Number.isInteger(step.tolerance) || step.tolerance < 0 || step.tolerance > (connection.signal === 'digital' ? 0 : maximum)) { throw new Error('P' + channel.pin + ' 반응·유지 시간은 0.001~30초, 오차는 신호 범위 안으로 정해 주세요.'); }
          if (connection.signal !== 'digital' && step.expected - step.tolerance <= minimum && step.expected + step.tolerance >= maximum) { throw new Error('P' + channel.pin + ' 허용 오차가 전체 출력 범위를 포함해요. 오차를 줄여 주세요.'); }
        });
        kind = old && old.originalKind === 'assertions' && channel.steps.length === 1 ? 'assertions' : 'outputSequences';
      }
      byPin.set(channel.pin, { channel: channel, connection: connection, kind: kind, written: false });
    });
    function entryFor(item) {
      var channel = item.channel, connection = item.connection;
      if (item.kind === 'inputs') { return { atMs: at, pin: channel.pin, signal: connection.signal, value: channel.value }; }
      if (item.kind === 'assertions') { return Object.assign({ pin: channel.pin, signal: connection.signal, afterMs: at }, clone(channel.steps[0])); }
      return { pin: channel.pin, signal: connection.signal, afterMs: at, steps: clone(channel.steps) };
    }
    [['inputs', 'atMs'], ['assertions', 'afterMs'], ['outputSequences', 'afterMs']].forEach(function (pair) {
      next[pair[0]] = (next[pair[0]] || []).flatMap(function (entry) {
        if (entry[pair[1]] !== at) { return [entry]; }
        var item = byPin.get(entry.pin);
        if (!item || item.kind !== pair[0]) { return []; }
        item.written = true; return [entryFor(item)];
      });
    });
    byPin.forEach(function (item) { if (!item.written) { next[item.kind].push(entryFor(item)); } });
    connected.filter(function (item) { return item.direction === 'input'; }).forEach(function (item) { if (!next.inputs.some(function (entry) { return entry.pin === item.pin && entry.atMs === 0; })) { next.inputs.push({ atMs: 0, pin: item.pin, signal: item.signal, value: 0 }); } });
    var changed = flowGroups(next).find(function (item) { return item.atMs === at; }), budget = Math.max(1, ...changed.assertions.map(function (entry) { return entry.withinMs + entry.holdMs; }), ...changed.outputSequences.map(sequenceBudget), ...changed.i2cAssertions.map(function (entry) { return entry.withinMs; }));
    if (length < budget) { throw new Error('이 단계에는 반응 시간과 유지 시간을 합친 ' + budget / 1000 + '초 이상이 필요해요. 다음 입력 단계까지 간격을 늘려 주세요.'); }
    var following = groups.find(function (item) { return item.atMs > at; });
    if (editing && following && length !== group.lengthMs) {
      if (!projectScenarioSteps(scenario).simple) { throw new Error('복잡한 기존 시간표의 간격은 세부 채점 설정에서 바꿔 주세요.'); }
      var delta = length - group.lengthMs; scenarioEntries(next, function (entry, time) { if (entry[time] >= following.atMs) { entry[time] += delta; } }); next.durationMs += delta;
    } else if (!following) { next.durationMs = at + length; }
    if (next.durationMs > 30000 || next.inputs.length > 256 || next.assertions.length > 256 || next.outputSequences.length > 64 || next.outputSequences.reduce(function (sum, sequence) { return sum + sequence.steps.length; }, 0) > 256) { throw new Error('한 예시는 30초, 입력·출력 동작은 각각 256개 이내로 나눠 주세요.'); }
    next.inputs.sort(function (a, b) { return a.atMs - b.atMs || a.pin - b.pin; });
    return { scenario: next, connections: connected };
  }
  function fieldLabel(path) {
    var names = { title: '제목', description: '학습 목표와 설명', status: '공개 상태', audience: '배포 대상', mode: '공개 범위', classIds: '선택 학급', connections: '핀 연결', pin: '핀 번호', direction: '입력·출력 방향', signal: '신호 종류', device: '장치', label: '표시 이름', i2cDevices: 'I²C 장치', address: '장치 주소', registers: '레지스터', register: '레지스터 번호', value: '입력값', writable: '학생 코드 쓰기 허용', scenarios: '예시', name: '이름', visible: '학생 공개 여부', durationMs: '전체 실행 시간', inputs: '핀 입력', assertions: '기대 출력', i2cInputs: 'I²C 입력', i2cAssertions: '기대 I²C 거래', outputSequences: '출력 동작 순서', steps: '동작', expected: '기대 출력값', atMs: '입력 적용 시각', afterMs: '검사 시작 시각', withinMs: '반응 대기 한도', holdMs: '유지 시간', tolerance: '허용 오차', operation: '읽기·쓰기', data: '바이트 값', repeated: '버스 유지 조건', starterProject: '시작 프로젝트', project: '프로젝트', files: '프로젝트 파일', publicationReview: '공개 전 점검', starterMode: '시작 코드 선택', fingerprint: '확인한 작성 내용' };
    names.classIds = '선택 수업'; names.groupIds = '선택 그룹(학급)';
    var labels = []; String(path || '').replace(/^exercise\./, '').split('.').forEach(function (part) { if (/^\d+$/.test(part) && labels.length) { labels[labels.length - 1] += ' ' + (Number(part) + 1); } else { labels.push(names[part] || part); } }); return labels.join(' → ');
  }
  function labelTableControls(wrap, prefix, keys) {
    var headings = Array.from(wrap.querySelectorAll('thead th')).map(function (node) { return node.textContent; });
    wrap.querySelectorAll('tbody tr').forEach(function (row, index) { Array.from(row.children).forEach(function (cellNode, column) {
      cellNode.querySelectorAll('input,select').forEach(function (control) { control.setAttribute('aria-label', (headings[column] || '값') + ' · ' + (index + 1) + '행'); if (prefix && keys[column]) { markField(control, prefix + '.' + index + '.' + keys[column]); } });
    }); });
  }
  function section(title, hint) { var node = el('section', 'ojmicrobit-card'); node.append(el('h2', '', title)); if (hint) { node.append(el('p', 'ojmicrobit-muted', hint)); } return node; }
  function items(value) { return Array.isArray(value) ? value : (value && Array.isArray(value.items) ? value.items : []); }
  function download(text, name, type) { var blob = new Blob([text], { type: type || 'application/json' }), url = URL.createObjectURL(blob), a = el('a'); a.href = url; a.download = name; global.document.body.append(a); a.click(); a.remove(); global.setTimeout(function () { URL.revokeObjectURL(url); }, 30000); }
  var signals = [['analog', '아날로그 입력 0~1023'], ['digital', '디지털 0/1'], ['pwm', 'PWM 출력 0~1023'], ['servo', '서보 각도 0~180°'], ['servo_pulse', '서보 펄스 1~20000µs']];
  var devices = [['light_sensor', '조도센서'], ['potentiometer', '가변저항'], ['button', '버튼'], ['led', 'LED'], ['motor', '모터'], ['servo', '서보']];
  var pins = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 19, 20].map(function (pin) { return [String(pin), 'P' + pin]; });

  function App(root) {
    this.root = root; this.config = JSON.parse(root.getAttribute('data-config') || '{}'); this.teacher = this.config.mode === 'teacher';
    this.content = root.querySelector('[data-microbit-content]') || el('div');
    this.status = root.querySelector('[data-microbit-status]') || el('p', 'ojmicrobit-status');
    if (!this.content.parentNode) { root.append(this.status, this.content); }
    this.status.setAttribute('role', 'status'); this.status.setAttribute('aria-live', 'polite');
    this.driver = null; this.exercise = null; this.project = null; this.projectRevision = 0; this.dirty = false; this.busy = false; this.usb = null;
    this.generation = 0; this.previewSequence = 0; this.traceTimers = []; this.inputValues = {}; this.actuators = new Map(); this.destroyed = false;
    this.runnerReady = Boolean(this.config.runnerReady); this.requests = new Set();
    var self = this;
    this.confirmedNavigation = false; this.pageShow = function () { self.confirmedNavigation = false; }; global.addEventListener('pageshow', this.pageShow);
    this.beforeUnload = function (event) { if (self.confirmedNavigation) { self.confirmedNavigation = false; return; } if (self.dirty || self.stageWizard && self.stageWizard.state.touched) { event.preventDefault(); event.returnValue = ''; } };
    global.addEventListener('beforeunload', this.beforeUnload);
    this.route = null; this.routeHref = null; this.assignment = null; this.lessonTitles = new Map();
    this.popState = function () {
      if (!self.routeHref || self.destroyed) { return; }
      var target = problemRoute(global.location.href, self.teacher, self.config.initialSection, self.config.initialView), previous = self.routeHref;
      if (self.busy) { global.history.pushState(null, '', previous); self.message('앞선 작업이 끝난 뒤 다시 이동하세요.'); return; }
      self.navigate(target, 'pop', previous);
    }; global.addEventListener('popstate', this.popState);
  }
  App.prototype.message = function (text, error) {
    this.status.textContent = text; this.status.classList.toggle('is-error', Boolean(error)); this.root.classList.toggle('has-focus-surface', Boolean(this.content.querySelector('.ojmicrobit-focus-surface')));
    var local = this.content.querySelector('[data-mb-author-status], [data-mb-workspace-status]');
    this.status.hidden = Boolean(local && !error);
    if (local) { local.textContent = error ? '' : text; }
    this.refreshWorkspaceSaveState();
  };
  App.prototype.api = async function (path, body, method, timeoutMs, owner) {
    var controller = global.AbortController ? new global.AbortController() : null, timedOut = false, self = this;
    var timer = controller ? global.setTimeout(function () { timedOut = true; controller.abort(); }, timeoutMs || (/\/(run|preview)$/.test(path) ? 90000 : 20000)) : null;
    if (controller) { this.requests.add(controller); } if (owner) { owner.abort = controller ? function () { controller.abort(); } : null; }
    try {
    var response = await global.fetch(this.config.restUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, ''), {
      method: method || (body === undefined ? 'GET' : 'POST'), credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-WP-Nonce': this.config.nonce },
      body: body === undefined ? undefined : JSON.stringify(body), signal: controller ? controller.signal : undefined
    });
    var result;
    try { result = await response.json(); } catch (error) { throw new Error('서버 응답을 읽지 못했습니다. 다시 시도해 주세요.'); }
    if (!response.ok) { var failure = new Error(result.message || '요청을 처리하지 못했습니다.'), data = result.data || {}; failure.status = response.status; failure.code = result.code; failure.fieldPath = data.fieldPath; failure.requestId = data.requestId; failure.retryAfterSeconds = data.retryAfterSeconds; if (/\/(run|preview)$/.test(path) && Number.isInteger(data.retryAfterSeconds) && data.retryAfterSeconds >= 1 && data.retryAfterSeconds <= 300) { this.setRunCooldown(data.retryAfterSeconds); } throw failure; }
    return result;
    } catch (error) { if (timedOut) { throw new Error('서버 응답 시간이 초과되었습니다. 저장·채점 요청은 처리되었을 수 있으니 현재 내용이나 채점 기록을 확인한 뒤 다시 시도해 주세요.'); } throw error; }
    finally { if (timer !== null) { global.clearTimeout(timer); } if (controller) { self.requests.delete(controller); } if (owner) { owner.abort = null; } }
  };
  App.prototype.action = async function (operation, label) {
    if (this.busy) { this.message('앞선 작업을 마칠 때까지 기다려 주세요.'); return; }
    this.busy = true; if (this.liveLab) { this.liveLab.hold(); } this.root.setAttribute('aria-busy', 'true'); this.content.inert = true;
    if (label) { this.message(label); }
    try { if (this.liveExecutionFlight) { try { await this.liveExecutionFlight; } catch (ignore) {} } if (this.destroyed) { return; } return await operation(); } catch (error) { if (!this.destroyed) { this.showError(error); } }
    finally { this.busy = false; this.root.removeAttribute('aria-busy'); this.content.inert = false; if (this.liveLab) { this.liveLab.wake(); } }
  };
  App.prototype.showError = function (error) {
    var path = typeof error.fieldPath === 'string' ? error.fieldPath.replace(/^exercise\./, '') : '', requestId = /^[a-f0-9]{32}$/i.test(error.requestId || '') ? ' · 문의 번호 ' + error.requestId : '', message = String(error.message || '작업을 완료하지 못했습니다.').replace(/^(?:exercise\.)?[A-Za-z][\w.\[\]-]*:\s*/, '');
    this.message((path ? fieldLabel(path) + ': ' : '') + message + (Number.isInteger(error.retryAfterSeconds) && error.retryAfterSeconds > 0 && error.retryAfterSeconds <= 300 ? ' · ' + error.retryAfterSeconds + '초 뒤 다시 실행하세요.' : '') + requestId, true);
    this.clearFieldErrors();
    if (!path) { return; } if (this.focusedAuthor && this.focusedAuthor.isConnected) { this.showAuthorStep(/^(connections|i2cDevices|scenarios|starterProject|project)/.test(path) ? 2 : /^(status|audience|publicationReview)/.test(path) ? 3 : 1, false); if (/^(starterProject|project)/.test(path) && this.startCodeDetails) { this.startCodeDetails.open = true; } } var self = this, match = /^scenarios\.(\d+)/.exec(path);
    if (match && this.config.compactEditor && this.exercise.scenarios[Number(match[1])]) { this.showCaseGroup(this.exercise.scenarios[Number(match[1])].visible); }
    function locate() { return Array.from(self.content.querySelectorAll('[data-mb-field]')).find(function (node) { return node.getAttribute('data-mb-field') === path; }); }
    if (!locate() && match && this.exercise && this.exercise.scenarios[Number(match[1])]) { var scenario = this.exercise.scenarios[Number(match[1])], advanced = Array.from(this.content.querySelectorAll('[data-mb-advanced]')).find(function (node) { return node.getAttribute('data-mb-advanced') === scenario.id; }); if (advanced) { advanced.open = true; this.fillAdvancedScenario(advanced, scenario); } }
    global.setTimeout(function () {
      if (self.destroyed) { return; } var control = locate();
      if (!control) { control = Array.from(self.content.querySelectorAll('[data-mb-field]')).find(function (node) { var candidate = node.getAttribute('data-mb-field'); return path.startsWith(candidate + '.') || candidate.startsWith(path + '.'); }); }
      if (!control && /^(starterProject|project)/.test(path)) { control = self.content.querySelector('iframe'); }
      if (!control) { return; }
      var ancestor = control.parentElement; while (ancestor && ancestor !== self.content) { if (ancestor.tagName === 'DETAILS') { ancestor.open = true; } ancestor = ancestor.parentElement; }
      var note = el('p', 'ojmicrobit-field-error', fieldLabel(path) + ': ' + message); note.id = 'mb-error-' + uid(); note.setAttribute('data-mb-error-note', ''); note.setAttribute('role', 'alert'); control.setAttribute('aria-invalid', 'true'); control.setAttribute('aria-describedby', note.id); control.parentNode.append(note);
      if (control.scrollIntoView) { control.scrollIntoView({ block: 'center', behavior: 'smooth' }); } control.focus();
      function clear() { control.removeAttribute('aria-invalid'); control.removeAttribute('aria-describedby'); note.remove(); control.removeEventListener('input', clear); control.removeEventListener('change', clear); } control.addEventListener('input', clear); control.addEventListener('change', clear);
    }, 0);
  };
  App.prototype.clearFieldErrors = function () { this.content.querySelectorAll('[data-mb-error-note]').forEach(function (node) { node.remove(); }); this.content.querySelectorAll('[aria-invalid="true"]').forEach(function (node) { node.removeAttribute('aria-invalid'); node.removeAttribute('aria-describedby'); }); };
  App.prototype.setRunCooldown = function (seconds) {
    var self = this; this.runRetryUntil = Date.now() + seconds * 1000; if (this.runRetryTimer) { global.clearTimeout(this.runRetryTimer); }
    this.content.querySelectorAll('[data-mb-run-action]').forEach(function (control) { control.disabled = true; control.title = seconds + '초 뒤 다시 실행할 수 있습니다.'; });
    this.runRetryTimer = global.setTimeout(function () { self.runRetryTimer = null; self.runRetryUntil = 0; if (!self.destroyed) { self.content.querySelectorAll('[data-mb-run-action]').forEach(function (control) { control.disabled = false; control.removeAttribute('title'); }); } }, seconds * 1000);
  };
  App.prototype.confirm = async function (title, message) {
    if (global.OJP && global.OJP.confirm) { return global.OJP.confirm({ title: title, message: message || '저장되지 않은 변경은 사라집니다.', confirmLabel: '계속' }); }
    // Accessible inline confirmation when the platform helper is unavailable.
    var self = this;
    return new Promise(function (resolve) {
      var box = section(title, message), controls = el('div', 'ojmicrobit-toolbar'); box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-label', title);
      controls.append(button('취소', function () { box.remove(); resolve(false); }), button('계속', function () { box.remove(); resolve(true); }, true));
      box.append(controls); self.content.prepend(box); controls.querySelector('button').focus();
    });
  };
  App.prototype.clearTrace = function () { this.traceTimers.forEach(function (timer) { global.clearTimeout(timer); }); this.traceTimers = []; this.previewSequence += 1; };
  App.prototype.closeEditor = function () { this.workspaceView = null; this.startCodeDetails = null; this.clearLiveExecution(); this.clearExpectedPreview(); if (this.driver) { this.driver.dispose(); this.driver = null; } if (this.reviewDriver) { this.reviewDriver.dispose(); this.reviewDriver = null; } this.publication = null; this.clearTrace(); };
  App.prototype.start = async function () {
    var self = this, initial = global.location ? problemRoute(global.location.href, this.teacher, this.config.initialSection, this.config.initialView) : { view: this.config.initialSection === 'lessons' ? 'lessons' : 'list', exerciseId: 0, beforeId: 0 };
    var redirect = this.nativePageTarget(initial); if (this.needsPageNavigation(redirect)) { this.leaveFor(redirect, true); return; }
    this.renderNavigation(initial);
    this.runnerStatusPending = true; var readiness = this.api('status').then(function (result) { self.runnerReady = result.ready !== undefined ? result.ready : result.runnerReady !== undefined ? result.runnerReady : self.config.runnerReady; self.runnerMessage = result.message || (result.runner && result.runner.message) || ''; }, function () { self.runnerReady = false; self.runnerMessage = '실행 서비스의 연결 상태를 확인하지 못했습니다.'; });
    await this.action(function () { return self.openRoute(initial, 'replace'); }, '마이크로비트 문제를 불러오고 있습니다.');
    await readiness; this.runnerStatusPending = false; if (this.liveLab) { this.liveLab.wake(); } if (!this.destroyed && !this.exercise) { this.runnerNotice(); }
  };
  App.prototype.setRoute = function (route, mode) {
    this.route = route; this.root.setAttribute('data-mb-view', route.view); this.renderNavigation(route);
    if (global.location && global.history) { var href = problemUrl(global.location.href, route); if (mode === 'push' && href !== global.location.href) { global.history.pushState(null, '', href); } else if (mode !== 'pop') { global.history.replaceState(null, '', href); } this.routeHref = href; }
  };
  App.prototype.nativePageTarget = function (route) {
    if (!this.config.nativeNavigation || route.view === 'invalid' || !global.location) { return null; }
    var key = routeSection(route) === 'lessons' ? 'lessonPageUrl' : this.teacher && route.view === 'new' && this.config.createPageUrl ? 'createPageUrl' : 'problemPageUrl';
    var target = internalUrl(this.config[key], global.location.href);
    return target ? problemUrl(target, route) : null;
  };
  App.prototype.needsPageNavigation = function (target) { return !!(target && global.location && new URL(target).pathname !== new URL(global.location.href).pathname); };
  App.prototype.leaveFor = function (href, replace) {
    var previousConfirmation = this.confirmedNavigation; this.confirmedNavigation = true;
    try { if (replace && global.location.replace) { global.location.replace(href); } else if (global.location.assign) { global.location.assign(href); } else { global.location.href = href; } }
    catch (error) { this.confirmedNavigation = previousConfirmation; throw error; }
  };
  App.prototype.followLink = async function (href) {
    if (this.busy) { this.message('앞선 작업이 끝난 뒤 다시 이동하세요.'); return; }
    if (this.dirty && !await this.confirm('저장하지 않고 다른 화면으로 이동할까요?')) { return; }
    this.leaveFor(href, false);
  };
  App.prototype.menuLink = function (text, href) {
    var self = this, link = el('a', 'ojmicrobit-menu-link', text); link.href = href;
    link.addEventListener('click', function (event) { if (event.button && event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; } event.preventDefault(); self.followLink(href).catch(function (error) { self.showError(error); }); }); return link;
  };
  App.prototype.navigate = async function (route, mode, previousHref) {
    if (this.busy) { this.message('앞선 작업을 마칠 때까지 기다려 주세요.'); return; }
    if (this.dirty && !await this.confirm('저장하지 않고 다른 화면으로 이동할까요?')) { if (mode === 'pop' && global.history) { global.history.pushState(null, '', previousHref); } return; }
    var pageTarget = this.nativePageTarget(route); if (this.needsPageNavigation(pageTarget)) { this.leaveFor(pageTarget, mode === 'pop'); return; }
    var self = this, completed = false;
    await this.action(async function () { await self.openRoute(route, mode || 'push'); completed = true; }, '문제를 불러오고 있습니다.');
    if (!completed && mode === 'pop' && global.history) { global.history.pushState(null, '', previousHref); }
  };
  App.prototype.renderNavigation = function (route) {
    var previous = this.content.querySelector('[data-mb-navigation]'); if (previous) { previous.remove(); }
    if (this.config.shellNavigation) { return; }
    var self = this, current = route.view === 'invalid' && this.config.initialSection === 'lessons' ? 'lessons' : routeSection(route), nav = el('nav', 'ojmicrobit-navigation'); nav.setAttribute('data-mb-navigation', ''); nav.setAttribute('aria-label', '마이크로비트 탐색');
    var menu = global.location && internalUrl(this.config[current === 'lessons' ? 'lessonMenuUrl' : 'problemMenuUrl'], global.location.href);
    if (this.config.nativeNavigation && menu) {
      nav.setAttribute('aria-label', '마이크로비트 메뉴');
      var entries = [['problems', '마이크로비트 문제', this.config.problemMenuUrl], ['lessons', '마이크로비트 수업', this.config.lessonMenuUrl]];
      if (this.teacher && this.config.createPageUrl) { entries.push(['new', '문제 생성', this.config.createPageUrl]); }
      entries.forEach(function (entry) {
        var href = internalUrl(entry[2], global.location.href); if (!href) { return; }
        var link = self.menuLink(entry[1], href); link.classList.remove('ojmicrobit-menu-link'); link.classList.add('ojmicrobit-nav-item');
        if ((route.view === 'new' && self.teacher && self.config.createPageUrl ? 'new' : current) === entry[0]) { link.setAttribute('aria-current', 'page'); } nav.append(link);
      });
    } else {
      [['list', this.teacher ? '문제 관리' : '공개 문제'], ['lessons', this.teacher ? '수업 적용·결과' : '내 수업']].forEach(function (entry) {
        var control = el('button', 'ojmicrobit-nav-item', entry[1]); control.type = 'button'; control.setAttribute('data-mb-nav', entry[0]); control.addEventListener('click', function () { self.navigate({ view: entry[0], exerciseId: 0, beforeId: 0 }); });
        if ((current === 'lessons') === (entry[0] === 'lessons')) { control.setAttribute('aria-current', 'page'); } nav.append(control);
      });
    } this.content.prepend(nav);
  };
  App.prototype.scopedRoute = function (view, beforeId) {
    var route = { view: view, exerciseId: this.exercise ? this.exercise.id : 0, beforeId: beforeId || 0 };
    if (this.assignment) { route.assignmentId = this.assignment.id; } return route;
  };
  App.prototype.practicePath = function (exerciseId, assignment) { return assignment ? 'assignments/' + assignment.id : 'exercises/' + exerciseId; };
  App.prototype.contextBanner = function (parent) {
    var text = this.assignment ? '수업: ' + (this.assignment.classTitle || this.lessonTitles.get(this.assignment.classId) || '수업 #' + this.assignment.classId) + (this.assignment.workAvailable === false ? ' · 수업 기록 보관 중입니다. 현재는 제출 내역을 확인할 수 있습니다.' : ' · 이 화면의 제출은 해당 수업 결과로 기록됩니다.') : '일반 연습 · 개인 제출 기록으로 저장돼요.';
    var banner = el('p', 'ojp-notice ojp-notice--info', text); banner.setAttribute('data-mb-context', ''); parent.append(banner);
  };
  App.prototype.openRoute = async function (route, mode) {
    if (route.view === 'invalid') { throw new Error('수업 적용 링크가 올바르지 않습니다. 내 수업에서 문제를 다시 열어 주세요.'); }
    if (route.view === 'list' || route.view === 'legacy') { await this.list(route.beforeId, undefined, route.view === 'legacy'); }
    else if (route.view === 'lessons') { await this.lessons(route.beforeId); }
    else if (route.view === 'lesson') { await this.lesson(route.classId, route.beforeId); }
    else if (route.view === 'new') { this.edit(route.template === 'sequence' ? sequenceExercise() : route.template === 'servo' ? servoExercise() : route.template === 'i2c' ? i2cExercise() : this.config.compactEditor ? blankExercise() : sampleExercise()); }
    else {
      var assignment = null, exercise;
      if (route.assignmentId) {
        var detail = await this.api((this.teacher ? 'teacher/' : '') + 'assignments/' + route.assignmentId);
        assignment = detail.assignment && Object.assign({}, detail.assignment, { workAvailable: detail.workAvailable }); exercise = detail.exercise;
        if (assignment && assignment.workAvailable === false && route.view === 'solve') { throw new Error('현재 수업 적용이 중지되었거나 문제가 공개되지 않았습니다. 수업 제출 내역을 확인해 주세요.'); }
        if (assignment && !exercise && ['detail', 'preview', 'history', 'results'].includes(route.view)) { exercise = { id: assignment.exerciseId, title: assignment.title, description: '이 수업 문제는 현재 풀이를 진행할 수 없습니다. 보관된 수업 결과와 제출 내역을 확인할 수 있습니다.', connections: [], i2cDevices: [], scenarios: [], sourceUnavailable: true }; }
        if (!assignment || assignment.id !== route.assignmentId || !exercise || assignment.exerciseId !== exercise.id || route.exerciseId && route.exerciseId !== exercise.id) { throw new Error('수업과 문제 연결을 확인하지 못했습니다. 내 수업에서 다시 열어 주세요.'); }
      } else { exercise = await this.api((this.teacher ? 'teacher/exercises/' : 'exercises/') + route.exerciseId); }
      if (route.view === 'edit') { if (assignment) { throw new Error('수업에 적용한 문제의 원본은 문제 관리에서 편집하세요.'); } this.edit(exercise); }
      else if (route.view === 'solve') { if (assignment && assignment.workAvailable === false) { throw new Error('현재 수업 적용이 중지되었거나 문제가 공개되지 않았습니다. 수업 제출 내역을 확인해 주세요.'); } await this.learn(exercise, this.teacher, assignment); }
      else { if (assignment && ['history', 'results'].includes(route.view)) { this.assignmentRecordScreen(exercise, assignment, route.view); } else { this.problem(exercise, this.teacher, assignment); } if (route.view === 'history') { await this.history(exercise.id, route.beforeId); } else if (route.view === 'results') { await this.assignmentResults(); } }
      if (assignment) { route = Object.assign({}, route, { exerciseId: exercise.id }); }
    }
    this.setRoute(route, mode);
  };
  App.prototype.runnerNotice = function () { var previous = this.content.querySelector('[data-mb-runner-notice]'); if (previous) { previous.remove(); } if (!this.runnerReady) { var warning = el('p', 'ojp-notice ojp-notice--warning', '채점 서버가 연결되지 않았습니다. MakeCode 편집·HEX 다운로드·USB 업로드는 사용할 수 있으며, 센서 실행과 채점은 연결 후 사용할 수 있습니다. 관리자는 WordPress 설정 → OJ Microbit에서 설치 안내와 상세 상태를 확인하세요.'); warning.setAttribute('data-mb-runner-notice', ''); this.content.prepend(warning); } };
  App.prototype.list = async function (beforeId, cursors, legacy) {
    var self = this, cursor = beforeId || 0; this.listCursors = cursors || [0];
    var result = await this.api((this.teacher ? 'teacher/exercises' : legacy ? 'exercises' : 'public-exercises') + '?limit=20' + (cursor ? '&beforeId=' + cursor : '')); this.closeEditor(); this.exercise = null; this.assignment = null; this.project = null; this.dirty = false; this.content.replaceChildren();
    if (!this.config.shellNavigation) { this.content.append(el('h2', '', this.teacher ? '문제 관리' : legacy ? '기존 배포·전체 목록' : '공개 문제')); }
    var toolbar = el('div', 'ojmicrobit-toolbar ojmicrobit-list-tools');
    if (this.teacher && !this.config.shellNavigation) { toolbar.append(button('새 문제 작성', function () { self.navigate({ view: 'new', exerciseId: 0, beforeId: 0 }); }, true), button('켜졌다 꺼짐 예제', function () { self.navigate({ view: 'new', template: 'sequence', exerciseId: 0, beforeId: 0 }); }), button('서보 예제', function () { self.navigate({ view: 'new', template: 'servo', exerciseId: 0, beforeId: 0 }); }), button('I²C 예제', function () { self.navigate({ view: 'new', template: 'i2c', exerciseId: 0, beforeId: 0 }); })); }
    toolbar.append(button('목록 새로고침', function () { self.action(async function () { await self.list(0, undefined, legacy); self.setRoute({ view: legacy ? 'legacy' : 'list', exerciseId: 0, beforeId: 0 }, 'replace'); }); })); this.content.append(toolbar);
    var collection = items(result), cards = el('div', 'ojmicrobit-problem-list');
    if (!collection.length) { cards.append(emptyState(this.teacher ? '첫 마이크로비트 문제를 만드세요.' : legacy ? '현재 확인할 기존 배포 문제가 없습니다.' : '공개된 마이크로비트 문제가 없습니다.', this.teacher ? '문제 생성에서 새 문제를 만들어 보세요.' : '문제가 공개되면 여기서 골라 풀 수 있어요.')); }
    else {
      var rows = table(self.teacher ? ['번호', '문제', '상태', '관리'] : ['번호', '문제', '상태']); rows.wrap.classList.add('ojmicrobit-problem-table');
      collection.forEach(function (record) {
        var row = el('tr'); cell(row, '#' + record.id);
        function link(label, view) { var route = { view: view, exerciseId: record.id, beforeId: 0 }, href = problemUrl(self.nativePageTarget(route) || (global.location ? global.location.href : 'http://localhost/'), route), control = el('a', 'ojmicrobit-row-link', label); control.href = href; control.title = record.title; control.addEventListener('click', function (event) { if (event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; } event.preventDefault(); self.navigate(route); }); return control; }
        cell(row, link(record.title, self.teacher ? 'edit' : 'detail')); cell(row, el('span', 'ojp-status-badge', record.status === 'published' ? '공개' : '초안'));
        if (self.teacher) { var actions = el('div', 'ojmicrobit-row-actions'); actions.append(link('미리보기', 'preview'), link('제출', 'history')); cell(row, actions); } rows.body.append(row);
      }); cards.append(rows.wrap);
    }
    this.content.append(cards, this.pageControls(result, this.listCursors, async function (next, trail) { await self.list(next, trail, legacy); self.setRoute({ view: legacy ? 'legacy' : 'list', exerciseId: 0, beforeId: next }, 'push'); })); this.message(this.config.shellNavigation ? '' : this.teacher ? '문제를 선택해 편집하거나 새 문제를 만드세요.' : '문제를 골라 풀어보세요.');
    if (!this.teacher && !legacy) { this.content.append(button('기존 배포·전체 목록 보기', function () { self.navigate({ view: 'legacy', exerciseId: 0, beforeId: 0 }); })); }
    if (!this.teacher && legacy && result.groupAccessAvailable === false) { this.content.prepend(el('p', 'ojp-notice ojp-notice--warning', '그룹(학급) 소속을 확인하지 못했습니다. 그룹 배포 문제가 보이지 않으면 소속과 연결 상태를 확인하세요.')); }
    if (!this.teacher && legacy && result.classAccessAvailable === false) { this.content.prepend(el('p', 'ojp-notice ojp-notice--warning', '수업 연결을 확인하지 못해 기존 수업 배포 문제를 표시하지 못할 수 있습니다.')); }
    this.runnerNotice();
  };
  App.prototype.lessons = async function (beforeId, cursors, contextCursor) {
    var self = this, cursor = beforeId || 0, trail = cursors || [this.teacher ? '' : 0];
    var response = await this.api(this.teacher ? 'teacher/classes?limit=20' + (contextCursor ? '&cursor=' + encodeURIComponent(contextCursor) : '') : 'assignments?limit=20' + (cursor ? '&beforeId=' + cursor : ''));
    this.closeEditor(); this.exercise = null; this.assignment = null; this.project = null; this.dirty = false; this.generation += 1; this.content.replaceChildren();
    var box = section(this.teacher ? '수업 적용·결과' : '내 수업', this.teacher ? '공개한 문제를 수업에 연결하고 해당 수업에서 제출한 결과를 따로 확인합니다. 수업의 학생 구성은 연결된 그룹(학급)과 수업 설정을 따릅니다.' : '수업에서 문제를 열고 제출하면 해당 수업의 결과로 기록됩니다. 일반 연습 기록은 공개 문제에서 확인합니다.');
    if (response.available === false || response.classAccessAvailable === false) { box.append(el('p', 'ojp-notice ojp-notice--warning', '수업 소속과 권한을 확인하지 못했습니다. 소속과 연결 상태를 확인한 뒤 다시 열어 주세요.')); }
    var rows = table(this.teacher ? ['수업', ''] : ['수업', '문제', '']);
    items(response).forEach(function (record) {
      var row = el('tr'); if (self.teacher) { self.lessonTitles.set(record.id, record.title); cell(row, record.title); cell(row, button('문제 적용·결과 보기', function () { self.navigate({ view: 'lesson', classId: record.id, exerciseId: 0, beforeId: 0 }); }, true)); }
      else { var readOnly = record.workAvailable === false || record.archived; cell(row, record.classTitle || '수업 #' + record.classId); cell(row, record.title + (readOnly ? record.archived ? ' · 적용 해제·기록 보관' : ' · 공개 중단·기록 보관' : '')); cell(row, button(readOnly ? '수업 제출 내역' : '수업 문제 보기', function () { self.navigate({ view: readOnly ? 'history' : 'detail', assignmentId: record.id, exerciseId: record.exerciseId, beforeId: 0 }); }, true)); } rows.body.append(row);
    }); if (items(response).length) { box.append(rows.wrap); } else { box.append(el('p', 'ojp-empty-state', this.teacher ? '관리할 수 있는 활성 수업이 없습니다.' : '현재 수업에 적용된 마이크로비트 문제가 없습니다.')); }
    this.content.append(box);
    if (this.teacher) { box.append(this.contextPages(response, trail, function (next, nextTrail) { return self.lessons(0, nextTrail, next); })); }
    else { box.append(this.pageControls(response, trail, async function (next, nextTrail) { await self.lessons(next, nextTrail); self.setRoute({ view: 'lessons', exerciseId: 0, beforeId: next }, 'push'); })); }
    this.renderNavigation({ view: 'lessons' }); this.message(this.teacher ? '수업을 선택해 공개 문제를 적용하거나 수업 결과를 확인하세요.' : '내 수업에서 문제를 선택해 제출하세요.');
  };
  App.prototype.contextPages = function (response, trail, load) {
    var self = this, nav = el('nav', 'ojmicrobit-toolbar'); nav.setAttribute('aria-label', '수업 목록 페이지');
    if (trail.length > 1) { nav.append(button('이전 수업 페이지', function () { self.action(function () { return load(trail[trail.length - 2], trail.slice(0, -1)); }); })); }
    if (typeof response.nextCursor === 'string' && response.nextCursor && !trail.includes(response.nextCursor)) { nav.append(button('다음 수업 페이지', function () { self.action(function () { return load(response.nextCursor, trail.concat(response.nextCursor)); }); })); } return nav;
  };
  App.prototype.lesson = async function (classId, beforeId, cursors) {
    if (!this.teacher || !Number.isSafeInteger(classId) || classId < 1) { throw new Error('관리할 수업을 다시 선택해 주세요.'); }
    var self = this, cursor = beforeId || 0, trail = cursors || [0];
    var response = await this.api('teacher/classes/' + classId + '/assignments?limit=20' + (cursor ? '&beforeId=' + cursor : ''));
    this.closeEditor(); this.exercise = null; this.assignment = null; this.project = null; this.dirty = false; this.generation += 1; this.content.replaceChildren();
    var box = section((this.lessonTitles.get(classId) || '수업 #' + classId) + ' · 적용한 문제', '같은 공개 문제를 여러 수업에 적용할 수 있습니다. 결과는 수업별로 기록됩니다.');
    var controls = el('div', 'ojmicrobit-toolbar'); controls.append(button('수업 선택', function () { self.navigate({ view: 'lessons', exerciseId: 0, beforeId: 0 }); }), button('공개 문제 선택', function () { self.action(function () { return self.publicPicker(classId); }); }, true)); box.append(controls);
    var rows = table(['문제', '적용 당시 버전', '']);
    items(response).forEach(function (record) { var row = el('tr'), actions = el('div', 'ojmicrobit-toolbar'); cell(row, record.title + (record.archived ? ' · 적용 해제·기록 보관' : '')); cell(row, record.exerciseRevision || '—');
      actions.append(button('수업 결과', function () { self.navigate({ view: 'results', assignmentId: record.id, exerciseId: record.exerciseId, beforeId: 0 }); }, true), button('제출 내역', function () { self.navigate({ view: 'history', assignmentId: record.id, exerciseId: record.exerciseId, beforeId: 0 }); }), button('문제 보기', function () { self.navigate({ view: 'detail', assignmentId: record.id, exerciseId: record.exerciseId, beforeId: 0 }); }));
      if (record.archived) { actions.append(button('다시 적용', function () { self.action(async function () { await self.api('teacher/classes/' + classId + '/assignments', { exerciseId: record.exerciseId, revision: 0 }); await self.lesson(classId); self.setRoute({ view: 'lesson', classId: classId, exerciseId: 0, beforeId: 0 }, 'replace'); self.message('문제를 다시 적용했습니다. 기존 수업 기록을 이어서 사용합니다.'); }); })); }
      else { actions.append(button('적용 해제', async function () { if (!await self.confirm('이 수업의 문제 적용을 해제할까요?', '새로운 수업 제출을 중지하고 기존 수업 결과는 보관합니다. 다시 적용하면 같은 수업 기록을 이어서 사용합니다.')) { return; }
        self.action(async function () { await self.api('teacher/assignments/' + record.id, { revision: record.revision }, 'DELETE'); await self.lesson(classId); self.setRoute({ view: 'lesson', classId: classId, exerciseId: 0, beforeId: 0 }, 'replace'); self.message('수업 적용을 해제했습니다. 기존 기록은 보관합니다.'); }); })); } cell(row, actions); rows.body.append(row);
    });
    if (items(response).length) { box.append(rows.wrap); } else { box.append(el('p', 'ojp-empty-state', '이 수업에 적용한 마이크로비트 문제가 없습니다. 공개 문제를 선택해 적용하세요.')); }
    box.append(this.pageControls(response, trail, async function (next, nextTrail) { await self.lesson(classId, next, nextTrail); self.setRoute({ view: 'lesson', classId: classId, exerciseId: 0, beforeId: next }, 'push'); })); this.content.append(box);
    this.lessonAssignments = items(response); this.picker = el('div'); this.picker.setAttribute('data-mb-public-picker', ''); this.content.append(this.picker); this.renderNavigation({ view: 'lesson' }); this.message('공개 문제를 적용하면 이 수업의 제출과 결과를 따로 모아 볼 수 있습니다.');
  };
  App.prototype.publicPicker = async function (classId, beforeId, cursors) {
    var self = this, cursor = beforeId || 0, trail = cursors || [0], response = await this.api('teacher/public-exercises?limit=20' + (cursor ? '&beforeId=' + cursor : ''));
    var box = section('수업에 적용할 공개 문제', '모든 교사가 공개한 문제 중에서 선택합니다. 문제 원본과 일반 연습 결과는 그대로 유지됩니다.'), rows = table(['문제 번호', '문제', '']);
    items(response).forEach(function (record) { var row = el('tr'); cell(row, record.id); cell(row, record.title); var applied = (self.lessonAssignments || []).some(function (entry) { return entry.exerciseId === record.id && !entry.archived; });
      var apply = button(applied ? '적용됨' : '수업에 적용', function () { self.action(async function () { await self.api('teacher/classes/' + classId + '/assignments', { exerciseId: record.id, revision: 0 }); await self.lesson(classId); self.setRoute({ view: 'lesson', classId: classId, exerciseId: 0, beforeId: 0 }, 'replace'); self.message('공개 문제를 수업에 적용했습니다. 수업에서 제출한 결과를 별도로 기록합니다.'); }); }, !applied); apply.disabled = applied; cell(row, apply); rows.body.append(row);
    }); if (items(response).length) { box.append(rows.wrap); } else { box.append(el('p', 'ojp-empty-state', '현재 선택할 공개 문제가 없습니다. 문제를 먼저 공개한 뒤 적용하세요.')); }
    box.append(this.pageControls(response, trail, function (next, nextTrail) { return self.publicPicker(classId, next, nextTrail); })); this.picker.replaceChildren(box);
  };
  App.prototype.assignmentRecordScreen = function (exercise, assignment, view) {
    this.closeEditor(); this.assignment = assignment; this.exercise = exerciseModel(exercise); this.project = null; this.dirty = false; this.generation += 1; this.content.replaceChildren();
    var self = this, toolbar = el('div', 'ojmicrobit-toolbar'); toolbar.append(this.backButton());
    if (!exercise.sourceUnavailable) { toolbar.append(button('문제 설명', function () { self.navigate(self.scopedRoute('detail')); })); }
    if (this.teacher && view !== 'results') { toolbar.append(button('수업 결과', function () { self.navigate(self.scopedRoute('results')); })); }
    if (view !== 'history') { toolbar.append(button('수업 제출 내역', function () { self.navigate(self.scopedRoute('history')); })); }
    this.content.append(toolbar); this.contextBanner(this.content); this.content.append(section(exercise.title, '이 수업에 연결된 제출과 결과를 확인합니다.'));
    this.results = el('div'); this.content.append(this.results);
  };
  App.prototype.assignmentResults = async function (cursor, trail, revisions) {
    if (!this.teacher || !this.assignment) { throw new Error('결과를 볼 수업 문제를 선택해 주세요.'); }
    var self = this, assignment = this.assignment, query = '?limit=20', cursors = trail || [''];
    if (cursor) { query += '&cursor=' + encodeURIComponent(cursor); }
    if (revisions) { query += '&contextRevision=' + encodeURIComponent(revisions.contextRevision); if (cursor) { query += '&rosterRevision=' + encodeURIComponent(revisions.rosterRevision); } }
    var response;
    try { response = await this.api('teacher/assignments/' + assignment.id + '/results' + query); }
    catch (error) {
      if (!this.results.querySelector('[data-mb-results-unavailable]')) { var unavailable = section('수업 결과 조회 안내', '현재 학생 명단과 최신 결과를 확인하지 못했습니다. 이미 기록된 수업 제출 내역을 확인할 수 있습니다.'); unavailable.setAttribute('data-mb-results-unavailable', ''); unavailable.append(button('수업 제출 내역 확인', function () { self.navigate(self.scopedRoute('history')); })); this.results.prepend(unavailable); }
      throw error;
    }
    var pageRevisions = { contextRevision: response.contextRevision, rosterRevision: response.rosterRevision };
    var box = section('수업 결과 · 학생별 최신 제출', '현재 수업 학생의 최신 결과와 미제출 상태를 표시합니다. 이 수업에서 제출한 결과만 사용합니다.'), rows = table(['학생', '관리 이름', '제출 상태', '최근 점수', '제출 당시 버전', '제출 시각', '']); box.setAttribute('data-mb-lesson-results', '');
    items(response).forEach(function (record) { var row = el('tr'), latest = record.latest, result = latest && latest.result; cell(row, record.displayName || '학생 #' + record.userId); cell(row, record.managementName || '—');
      cell(row, !latest ? '미제출' : result.status === 'compile_error' ? '컴파일 오류' : result.status === 'runtime_error' ? '실행 오류' : result.passed ? '통과' : '미통과'); cell(row, result && Number.isFinite(result.score) ? result.score : '—'); cell(row, latest ? latest.exerciseRevision : '—'); cell(row, latest ? latest.createdAt : '—');
      if (latest) { cell(row, button('결과 보기', function () { self.action(async function () { var detail = await self.api('teacher/assignments/' + assignment.id + '/attempts/' + latest.id); self.renderResult(detail.result); self.results.prepend(button('수업 결과로', function () { self.action(function () { return self.assignmentResults(cursor, cursors, pageRevisions); }); })); }); })); }
      else { cell(row, '—'); } rows.body.append(row);
    }); if (items(response).length) { box.append(rows.wrap); } else { box.append(el('p', 'ojp-empty-state', '이 페이지에 표시할 수업 학생이 없습니다.')); }
    var pages = el('nav', 'ojmicrobit-toolbar'); pages.setAttribute('aria-label', '수업 결과 페이지');
    if (cursors.length > 1) { pages.append(button('이전 학생 페이지', function () { self.action(function () { return self.assignmentResults(cursors[cursors.length - 2], cursors.slice(0, -1), pageRevisions); }); })); }
    if (typeof response.nextCursor === 'string' && response.nextCursor && !cursors.includes(response.nextCursor)) { pages.append(button('다음 학생 페이지', function () { self.action(function () { return self.assignmentResults(response.nextCursor, cursors.concat(response.nextCursor), pageRevisions); }); })); }
    box.append(pages, button('수업 제출 내역', function () { self.navigate(self.scopedRoute('history')); })); this.results.replaceChildren(box); this.message('이 수업의 학생별 최신 제출을 확인합니다.');
  };
  App.prototype.pageControls = function (result, cursors, load) {
    var self = this, tools = el('nav', 'ojmicrobit-toolbar'); tools.setAttribute('aria-label', '목록 페이지'); tools.setAttribute('data-mb-pages', '');
    var trail = cursors.slice(); tools.append(el('span', 'ojmicrobit-muted', '최근 자료부터 · ' + trail.length + '페이지'));
    if (trail.length > 1) { tools.append(button('이전 페이지', function () { self.action(function () { return load(trail[trail.length - 2], trail.slice(0, -1)); }); })); }
    if (Number.isInteger(result.nextBeforeId) && result.nextBeforeId > 0 && (!trail.at(-1) || result.nextBeforeId < trail.at(-1))) { tools.append(button('다음 페이지', function () { self.action(function () { return load(result.nextBeforeId, trail.concat(result.nextBeforeId)); }); })); }
    return tools;
  };
  App.prototype.backButton = function () { var self = this, section = this.assignment ? 'lessons' : 'problems', menu = global.location && internalUrl(this.config[section === 'lessons' ? 'lessonMenuUrl' : 'problemMenuUrl'], global.location.href); if (this.config.nativeNavigation && menu) { return this.menuLink(section === 'lessons' ? '마이크로비트 수업 목록' : '마이크로비트 문제 목록', menu); } return button(this.assignment ? '수업 목록' : '문제 목록', function () { self.navigate({ view: self.assignment ? self.teacher ? 'lesson' : 'lessons' : 'list', exerciseId: 0, classId: self.assignment && self.assignment.classId, beforeId: 0 }); }); };
  App.prototype.edit = function (exercise) {
    this.closeEditor(); this.focusedAuthor = null; this.publicationHost = null; this.caseVisibility = true; this.assignment = null; this.exercise = exerciseModel(exercise); this.project = canonicalProject(exercise.starterProject); this.projectRevision = 0; this.dirty = false; this.generation += 1;
    this.content.replaceChildren(); var self = this, draft = this.exercise, toolbar = el('div', 'ojmicrobit-toolbar'); if (!this.config.shellNavigation) { toolbar.append(this.backButton()); this.content.append(toolbar); }
    var title = el('h2', '', draft.id ? '마이크로비트 문제 편집' : '새 마이크로비트 문제'); this.editTitle = this.config.shellNavigation ? this.root.querySelector('.ojp-page-header h1') : title; if (!this.config.shellNavigation) { this.content.append(title); }
    if (!this.config.compactEditor) { this.content.append(el('p', 'ojmicrobit-workflow', '① 문제 작성 · ② 전체 공개 · ③ 수업에 적용 · ④ 수업 결과 확인')); }
    var general = section('과제 안내'), grid = el('div', 'ojmicrobit-fields');
    grid.append(field('제목', markField(input(draft.title, 'text', function (value) { draft.title = value; self.dirty = true; }), 'title')),
      field('공개 상태', markField(select(draft.status, [['draft', '초안'], ['published', '공개 전 점검 후 학생에게 공개']], function (value) { draft.status = value; self.dirty = true; }), 'status')));
    general.append(grid, field('학습 목표와 설명', markField(textArea(draft.description, this.config.compactEditor ? 3 : 4, function (value) { draft.description = value; self.dirty = true; }), 'description')));
    this.classSelection = (draft.audience.classIds || []).slice(); this.groupSelection = (draft.audience.groupIds || []).slice(); this.classPage = null; this.groupPage = null; this.classesLoading = false; this.groupsLoading = false; this.classLoadError = ''; this.groupLoadError = ''; this.classTitles = this.classTitles || new Map(); this.groupTitles = this.groupTitles || new Map();
    this.content.append(general); this.renderAudience(); this.renderConnections(); this.renderI2CDevices(); this.renderScenarios();
    var editorBox = section('시작 프로젝트', '여기서 준비한 블록·코드가 학생의 첫 프로젝트가 됩니다. 과제 저장을 누르면 최신 편집 내용을 함께 보관합니다.');
    this.content.append(editorBox);
    if (!this.config.compactEditor) { this.mountEditor(editorBox, true); }
    var actions = el('div', 'ojmicrobit-toolbar'); actions.append(button('과제 저장', function () { self.action(async function () {
      var payload = await self.exercisePayload(); if (payload.status === 'published') { self.openPublicationReview(payload); return; }
      var saved = await self.api('teacher/exercises', payload); self.savedExercise(saved, '문제 초안을 저장했습니다. 학생에게 배포하려면 공개 상태를 선택하고 공개 전 점검을 진행하세요.');
    }, '과제와 시작 프로젝트를 확인하고 있습니다.'); }, true));
    { var remove = button('과제 삭제', async function () { if (!await self.confirm('이 과제를 삭제할까요?')) { return; } self.action(async function () {
      await self.api('teacher/exercises/' + draft.id, { revision: draft.revision }, 'DELETE'); await self.list(); self.setRoute({ view: 'list', exerciseId: 0, beforeId: 0 }, 'replace'); self.message('문제를 삭제했습니다.');
    }); }); remove.hidden = !draft.id; remove.setAttribute('data-mb-delete-exercise', ''); actions.append(remove); }
    this.content.append(actions); if (this.config.compactEditor) { this.setupFocusedAuthor(general, editorBox, actions); } this.message(this.config.compactEditor ? '' : '위에서부터 한 단계씩 설정하세요. 저장 후에도 편집을 이어갈 수 있습니다.');
  };
  App.prototype.setupFocusedAuthor = function (general, editorBox, actions) {
    if (this.editTitle && this.editTitle.isConnected) { this.editTitle.hidden = true; }
    var surface = el('section', 'ojmicrobit-focus-surface ojmicrobit-author-surface ojmicrobit-immersive'), header = el('header', 'ojmicrobit-focus-header'), headingRow = el('div', 'ojmicrobit-focus-heading-row'), title = el('h1', '', this.exercise.id ? '문제 편집' : '문제 만들기');
    surface.setAttribute('data-mb-focus-surface', 'author'); this.editTitle = title;
    header.append(el('p', 'ojmicrobit-focus-breadcrumb', '마이크로비트 · ' + (this.exercise.id ? '문제 편집' : '새 문제')));
    var draftBadge = el('span', 'ojmicrobit-author-draft', this.exercise.status === 'published' ? '공개' : '작성 중'); draftBadge.setAttribute('data-mb-focus-status', ''); headingRow.append(title, draftBadge); header.append(headingRow);
    var self = this, layout = el('div', 'ojmicrobit-author-layout'), nav = el('nav', 'ojmicrobit-author-nav'), panels = el('div', 'ojmicrobit-author-panels');
    layout.setAttribute('data-mb-focused-author', ''); nav.setAttribute('aria-label', '문제 작성 단계');
    var names = ['문제 설명', '장치·동작', '공개 설정'];
    var statusField = this.content.querySelector('[data-mb-field="status"]').closest('label'), connections = this.content.querySelector('[data-mb-connections]'), bus = this.content.querySelector('[data-mb-i2c-devices]'), scenarios = this.content.querySelector('[data-mb-scenarios]'), audience = this.content.querySelector('[data-mb-audience]');
    names.forEach(function (name, index) {
      var tab = button('', function () { self.showAuthorStep(index + 1, true); }), panel = el('section', 'ojmicrobit-compose-section'), title = el('h2', 'ojmicrobit-author-heading ojmicrobit-visually-hidden', name);
      tab.append(el('span', 'ojmicrobit-author-index', String(index + 1)), el('span', '', name)); tab.setAttribute('aria-label', name); tab.setAttribute('data-mb-author-tab', String(index + 1)); tab.id = 'mb-author-tab-' + (index + 1); panel.id = 'mb-author-panel-' + (index + 1); tab.setAttribute('aria-controls', panel.id); panel.setAttribute('aria-labelledby', tab.id); panel.setAttribute('data-mb-author-panel', String(index + 1));
      panel.append(title); title.tabIndex = -1;
      if (index === 0) { panel.append(general); general.querySelector('[data-mb-field="description"]').rows = 4; var descriptionLabel = general.querySelector('[data-mb-field="description"]').closest('label').querySelector('.ojp-label'); if (descriptionLabel) { descriptionLabel.textContent = '학생이 만들 동작'; } var titleLabel = general.querySelector('[data-mb-field="title"]').closest('label').querySelector('.ojp-label'); if (titleLabel) { titleLabel.textContent = '문제 제목'; } }
      if (index === 1) {
        panel.append(scenarios);
        var devices = el('details', 'ojmicrobit-focus-devices'); devices.append(el('summary', '', '연결한 장치·I²C 설정 (필요할 때)'), connections, bus); panel.append(devices);
        var code = el('details', 'ojmicrobit-start-code'); code.setAttribute('data-mb-code-section', ''); code.append(el('summary', '', '학생용 시작 코드 준비 · 선택 사항'), editorBox); panel.append(code); self.focusEditorHost = editorBox; self.startCodeDetails = code;
        code.addEventListener('toggle', function () { if (code.open && code.isConnected && !self.driver) { self.mountEditor(editorBox, true); } });
      }
      if (index === 2) { var publication = el('div', 'ojmicrobit-focus-publication'); publication.append(statusField, audience, el('p', 'ojmicrobit-muted', '공개 문제는 누구나 풀고 채점할 수 있어요. 수업 적용과 수업별 결과는 마이크로비트 수업 메뉴에서 관리해요.')); panel.append(publication); self.publicationHost = panel; }
      nav.append(tab); panels.append(panel);
    });
    layout.append(nav, panels); surface.append(header, layout, actions); this.content.prepend(surface); this.focusedAuthor = layout; this.authorActions = actions; actions.classList.add('ojmicrobit-author-footer'); actions.setAttribute('data-mb-author-actions', '');
    var remove = actions.querySelector('[data-mb-delete-exercise]'); if (remove) { var maintenance = el('details', 'ojmicrobit-focus-maintenance'); maintenance.append(el('summary', '', '문제 관리'), remove); panels.querySelector('[data-mb-author-panel="3"]').append(maintenance); }
    var save = actions.querySelector('button'); save.textContent = this.exercise.status === 'published' ? '변경 저장' : '초안 저장'; save.setAttribute('data-mb-save-exercise', ''); save.setAttribute('data-mb-variant', 'secondary'); save.classList.remove('ojp-button--primary');
    var next = button('', function () {
      if (self.authorStep < 3) { self.showAuthorStep(self.authorStep + 1, true); return; }
      self.action(async function () {
        self.exercise.status = 'published'; self.dirty = true; var control = self.content.querySelector('[data-mb-field="status"]'); Array.from(control.options).forEach(function (option) { option.selected = option.value === 'published'; });
        self.openPublicationReview(await self.exercisePayload());
      }, '공개할 내용을 준비하고 있습니다.');
    }, true); next.setAttribute('data-mb-author-next', ''); actions.append(next);
    var previous = button('이전', function () { self.showAuthorStep(self.authorStep - 1, true); }); previous.setAttribute('data-mb-author-previous', ''); actions.prepend(previous);
    var footerCopy = el('div', 'ojmicrobit-author-footer-copy'), note = el('span', 'ojmicrobit-author-progress'), feedback = el('span', 'ojmicrobit-author-status'); note.setAttribute('data-mb-author-progress', ''); feedback.setAttribute('data-mb-author-status', ''); feedback.setAttribute('role', 'status'); footerCopy.append(note, feedback); previous.after(footerCopy);
    this.showAuthorStep(1, false);
  };
  App.prototype.showAuthorStep = function (step, focus) {
    if (!this.focusedAuthor || !this.focusedAuthor.isConnected || ![1, 2, 3].includes(step)) { return; }
    this.switchingAuthorStep = true; this.authorStep = step;
    this.focusedAuthor.querySelectorAll('[data-mb-author-panel]').forEach(function (panel) { panel.hidden = Number(panel.getAttribute('data-mb-author-panel')) !== step; });
    this.focusedAuthor.querySelectorAll('[data-mb-author-tab]').forEach(function (tab) { if (Number(tab.getAttribute('data-mb-author-tab')) === step) { tab.setAttribute('aria-current', 'step'); } else { tab.removeAttribute('aria-current'); } });
    var names = ['문제 설명', '장치·동작', '공개 설정'], next = this.authorActions.querySelector('[data-mb-author-next]'); next.textContent = step === 3 ? '공개 전 점검 →' : '다음: ' + names[step] + ' →';
    this.authorActions.querySelector('[data-mb-author-progress]').textContent = '초안 저장으로 작성 내용을 보관하세요.';
    this.authorActions.querySelector('[data-mb-author-previous]').disabled = step === 1;
    this.switchingAuthorStep = false;
    if (focus) { this.focusedAuthor.querySelector('[data-mb-author-panel="' + step + '"] .ojmicrobit-author-heading').focus(); }
  };
  App.prototype.savedExercise = function (saved, message, replaceProject) {
    var sameCode = this.project && saved.starterProject && projectSignature(this.project) === projectSignature(saved.starterProject);
    if (this.exercise && (sameCode || !replaceProject)) {
      var draft = this.exercise; ['id', 'revision', 'ownerId', 'status', 'updatedAt', 'title', 'description'].forEach(function (key) { if (saved[key] !== undefined) { draft[key] = saved[key]; } });
      draft.starterProject = clone(saved.starterProject); if (sameCode) { this.project = canonicalProject(saved.starterProject); } this.dirty = !sameCode;
      ['title', 'description', 'status'].forEach(function (key) { var control = this.content.querySelector('[data-mb-field="' + key + '"]'); if (control && control.value !== String(draft[key])) { if (control.tagName === 'SELECT') { Array.from(control.options).forEach(function (option) { option.selected = option.value === String(draft[key]); }); } else { control.value = draft[key]; } } }, this);
      if (this.reviewDriver) { this.reviewDriver.dispose(); this.reviewDriver = null; } this.publication = null;
      var panel = this.content.querySelector('[data-mb-publication-review]'); if (panel) { panel.remove(); }
      var remove = this.content.querySelector('[data-mb-delete-exercise]'); if (remove) { remove.hidden = false; }
      if (this.editTitle) { this.editTitle.textContent = this.config.compactEditor ? '문제 편집' : '마이크로비트 문제 편집'; }
      var saveButton = this.content.querySelector('[data-mb-save-exercise]'); if (saveButton) { saveButton.textContent = draft.status === 'published' ? '변경 저장' : '초안 저장'; } var badge = this.content.querySelector('[data-mb-focus-status]'); if (badge) { badge.textContent = draft.status === 'published' ? '공개' : '초안'; }
    } else { this.edit(saved); this.dirty = false; }
    this.setRoute({ view: 'edit', exerciseId: saved.id, beforeId: 0 }, 'replace'); this.message(this.dirty ? '문제는 저장됐어요. 저장 중 바뀐 코드가 있어 한 번 더 저장해 주세요.' : message);
  };
  App.prototype.exercisePayload = async function () {
    var draft = this.exercise, payload = {}; draft.starterProject = await this.captureProject();
    ['id', 'revision', 'title', 'description', 'status', 'audience', 'connections', 'i2cDevices', 'scenarios', 'starterProject'].forEach(function (key) { if (draft[key] !== undefined) { payload[key] = draft[key]; } });
    payload = clone(payload); payload.scenarios.forEach(function (scenario) { scenario.inputs.sort(function (a, b) { return a.atMs - b.atMs; }); scenario.i2cInputs.sort(function (a, b) { return a.atMs - b.atMs; }); }); return payload;
  };
  App.prototype.renderAudience = function () {
    var self = this, previous = this.content.querySelector('[data-mb-audience]'), audience = this.exercise.audience;
    var box = section('문제 공개', '공개한 문제는 수업 소속과 관계없이 모든 실습 사용자가 풀고 채점할 수 있습니다. 수업 적용은 공개한 뒤 수업 적용·결과 메뉴에서 진행합니다.'); box.setAttribute('data-mb-audience', '');
    var restricted = audience.mode !== 'site', oldMode = this.groupSelection.length ? 'groups' : this.classSelection.length ? 'classes' : null;
    if (restricted || oldMode) {
      var mode = restricted ? audience.mode : oldMode, noun = mode === 'groups' ? '그룹(학급)' : '수업', key = mode === 'groups' ? 'groupIds' : 'classIds';
      var ids = restricted ? audience[key] : mode === 'groups' ? this.groupSelection : this.classSelection;
      box.append(el('p', 'ojp-notice ojp-notice--warning', '이전 버전의 제한 공개 문제입니다. 기존 ' + noun + ' 대상 (' + ids.join(', ') + ')을 보존합니다. 전체 공개로 바꾸면 수업 밖 사용자도 풀 수 있으므로 공개 전 점검에서 다시 확인하세요.'));
      box.append(field('기존 공개 범위', markField(select(audience.mode, [[mode, '기존 ' + noun + ' 제한 유지'], ['site', '전체 공개 문제로 변경']], function (value) {
        self.exercise.audience = value === 'site' ? { mode: 'site', classIds: [] } : value === 'groups' ? { mode: value, groupIds: self.groupSelection.slice() } : { mode: value, classIds: self.classSelection.slice() }; self.dirty = true; self.renderAudience();
      }), 'audience.mode')));
    } else { box.append(el('p', 'ojp-notice ojp-notice--info', '전체 공개 문제 · 공개 전 점검을 완료하고 공개하면 모두가 풀어볼 수 있습니다.')); }
    if (previous) { previous.replaceWith(box); } else { this.content.append(box); }
  };
  App.prototype.openPublicationReview = function (candidate) {
    if (this.reviewDriver) { this.reviewDriver.dispose(); this.reviewDriver = null; }
    if (this.focusedAuthor && this.focusedAuthor.isConnected) { this.showAuthorStep(3, false); }
    this.publication = { candidate: candidate, starterMode: '', response: null }; this.renderPublicationReview(); this.message('시작 코드와 배포 대상을 선택하고 공개 전 점검을 진행하세요.');
  };
  App.prototype.renderPublicationReview = function () {
    var self = this, state = this.publication; if (!state) { return; } if (this.reviewDriver) { this.reviewDriver.dispose(); this.reviewDriver = null; } var previous = this.content.querySelector('[data-mb-publication-review]'), box = section('공개 전 점검', '점검 중에는 작성 화면과 시작 코드를 유지합니다. 학생에게 전달할 코드와 공개 범위를 확인한 뒤 공개하세요.'); box.setAttribute('data-mb-publication-review', ''); box.setAttribute('tabindex', '-1');
    box.append(field('학생에게 제공할 시작 코드', markField(select(state.starterMode, [['', '시작 코드 배포 방식을 선택하세요'], ['blank', '학생용 빈 틀로 시작'], ['current', '현재 코드를 의도적으로 배포']], function (value) { if (self.reviewDriver) { self.reviewDriver.dispose(); self.reviewDriver = null; } state.starterMode = value; state.response = null; self.renderPublicationReview(); }), 'publicationReview.starterMode')),
      el('p', 'ojmicrobit-muted', state.starterMode === 'blank' ? '점검 중에는 작성 코드를 유지합니다. 빈 틀로 공개하면 저장된 시작 코드는 빈 틀로 바뀝니다. 참조 코드는 먼저 내보내세요.' : state.starterMode === 'current' ? '현재 시작 프로젝트의 블록·코드를 학생에게 그대로 제공합니다. 완성 예제라면 정답 코드도 공개됩니다.' : '예제의 시작 프로젝트에는 완성 코드가 들어 있을 수 있습니다. 배포 방식을 직접 선택하세요.'));
    var actions = el('div', 'ojmicrobit-toolbar'); actions.append(button('공개 내용 점검하기', function () { self.action(async function () {
      if (!state.starterMode) { var error = new Error('학생용 빈 틀 또는 현재 코드 배포를 선택하세요.'); error.fieldPath = 'publicationReview.starterMode'; throw error; }
      var candidate = await self.exercisePayload(); candidate.status = 'published'; state.candidate = candidate;
      state.response = await self.api('teacher/exercises/review', Object.assign(clone(candidate), { publicationReview: { starterMode: state.starterMode } })); state.signature = JSON.stringify(candidate); self.clearFieldErrors(); self.renderPublicationReview(); self.message('공개될 내용이 준비되었습니다. 확인 후 공개하세요.');
    }, '배포 권한과 공개할 내용을 점검하고 있습니다.'); }, true), button('교사용 현재 프로젝트 내보내기', function () { self.action(async function () { var project = await self.captureProject(); download(JSON.stringify(project, null, 2), 'microbit-teacher-reference.ojmicrobit.json'); self.message('교사용 현재 프로젝트를 내보냈습니다.'); }); }), button('작성 계속하기', function () { if (self.reviewDriver) { self.reviewDriver.dispose(); self.reviewDriver = null; } self.publication = null; box.remove(); self.message('작성 내용을 유지했습니다.'); })); box.append(actions);
    if (state.response) {
      var summary = state.response.summary || {}, audience = summary.audience || state.candidate.audience;
      var audienceText = '배포 대상: 사이트의 실습 권한이 있는 모든 로그인 학생';
      if (audience.mode === 'groups') { audienceText = '선택 그룹(학급): ' + (audience.groupNames || audience.groupIds.map(function (id) { return self.groupTitles.get(id) || '그룹(학급) #' + id; })).join(', '); }
      if (audience.mode === 'classes') { audienceText = '기존 수업 배포: ' + (audience.classNames || audience.classIds.map(function (id) { return self.classTitles.get(id) || '수업 #' + id; })).join(', '); }
      box.append(el('h3', '', '배포 요약'), el('p', '', '제목: ' + (summary.title || state.candidate.title)), el('p', '', audienceText));
      if (Number.isInteger(summary.checksCount)) { box.append(el('p', 'ojmicrobit-muted', '검사 ' + summary.checksCount + '개 · 만점 ' + (Number.isFinite(summary.maxScore) ? summary.maxScore : '—') + '점 · 이 화면은 실행 결과가 아닙니다.')); }
      var scenarios = table(['예시', '학생 공개', '실행 시간', '검사 수']);
      state.candidate.scenarios.forEach(function (scenario, index) { var row = el('tr'), checks = scenario.assertions.length + scenario.i2cAssertions.length + scenario.outputSequences.reduce(function (count, sequence) { return count + sequence.steps.length; }, 0); cell(row, (index + 1) + '. ' + scenario.name); cell(row, scenario.visible ? '공개' : '비공개'); cell(row, scenario.durationMs / 1000 + '초'); cell(row, checks + '개'); scenarios.body.append(row); }); box.append(scenarios.wrap);
      var timing = el('details', 'ojmicrobit-review-times'); timing.append(el('summary', '', '시간 조건 확인'));
      state.candidate.scenarios.forEach(function (scenario, index) { timing.append(el('h4', '', '예시 ' + (index + 1) + ' · ' + scenario.name)); scenario.assertions.forEach(function (entry) { timing.append(el('p', '', 'P' + entry.pin + ' 값 ' + entry.expected + ' · ' + entry.afterMs / 1000 + '초부터 ' + entry.withinMs / 1000 + '초 안에 도달 · ' + entry.holdMs / 1000 + '초 유지')); }); scenario.outputSequences.forEach(function (sequence) { timing.append(el('p', '', 'P' + sequence.pin + ' · ' + sequence.afterMs / 1000 + '초부터 ' + sequence.steps.map(function (entry) { return '값 ' + entry.expected + ' (' + entry.withinMs / 1000 + '초 안에 도달·' + entry.holdMs / 1000 + '초 유지)'; }).join(' → '))); }); scenario.i2cAssertions.forEach(function (entry) { timing.append(el('p', '', 'I²C ' + addressText(entry.address) + ' ' + entry.operation + ' [' + bytesText(entry.data) + '] · ' + entry.afterMs / 1000 + '초부터 ' + entry.withinMs / 1000 + '초 이내')); }); }); box.append(timing);
      var preview = el('details', 'ojmicrobit-review-preview'); preview.setAttribute('data-mb-student-preview', ''); preview.append(el('summary', '', '학생에게 보일 내용 미리보기')); var student = state.response.studentPreview;
      if (student) { preview.append(el('h3', '', student.title), el('p', 'ojmicrobit-description', student.description), el('p', 'ojmicrobit-muted', '공개 전 내용 확인 화면입니다. 저장·센서 실행·채점은 공개 후 실습 화면에서 사용할 수 있습니다.'));
        (student.scenarios || []).filter(function (scenario) { return scenario.visible; }).forEach(function (scenario) { preview.append(el('p', '', '공개 예시: ' + scenario.name + ' · ' + scenario.durationMs / 1000 + '초')); });
        var source = el('pre', 'ojmicrobit-review-source'); source.textContent = student.starterProject.files['main.ts'] || '(빈 시작 코드)'; preview.append(source, button('학생 시작 프로젝트를 MakeCode로 보기', function () { if (self.reviewDriver) { return; } var frame = el('iframe', 'ojmicrobit-editor'), status = el('p', 'ojmicrobit-muted', '학생 시작 프로젝트를 불러오고 있습니다.'); preview.append(status, frame); self.reviewDriver = new EditorDriver(frame, { project: student.starterProject, editorUrl: self.config.editorUrl, onStatus: function (text) { status.textContent = text; } }); }));
      } box.append(preview);
      var acknowledged = false, publish = button('확인하고 공개', function () { self.action(async function () {
        if (!acknowledged) { throw new Error('시작 코드와 공개 범위를 확인해 주세요.'); } var candidate = await self.exercisePayload();
        if (candidate.status !== 'published') { throw new Error('현재 공개 상태가 초안입니다. 학생에게 공개를 선택하고 다시 점검하세요.'); }
        if (JSON.stringify(candidate) !== state.signature) { state.response = null; self.renderPublicationReview(); throw new Error('점검 후 작성 내용이 바뀌었습니다. 공개 내용 점검을 다시 진행하세요.'); }
        var payload = Object.assign(clone(candidate), { publicationReview: { starterMode: state.starterMode, confirmed: true, fingerprint: state.response.fingerprint } });
        var saved = await self.api('teacher/exercises', payload); self.savedExercise(saved, '점검한 내용으로 문제를 공개하고 선택한 대상에 배포했습니다.', state.starterMode === 'blank');
      }, '확인한 내용으로 과제를 공개하고 있습니다.'); }, true); publish.disabled = true;
      var acknowledgement = field('시작 코드·공개 예시·배포 대상을 확인했습니다', checkInput(false, function (value) { acknowledged = value; publish.disabled = !value; })); acknowledgement.classList.add('ojmicrobit-review-ack'); box.append(acknowledgement, publish);
    }
    if (previous) { previous.replaceWith(box); } else { (this.publicationHost && this.publicationHost.isConnected ? this.publicationHost : this.content).prepend(box); } global.setTimeout(function () { if (box.isConnected) { box.focus(); } }, 0);
  };
  App.prototype.renderConnections = function () {
    var self = this, draft = this.exercise, previous = this.content.querySelector('[data-mb-connections]'), box = section('핀과 장치 연결', '입력: 아날로그·디지털 · 출력: 디지털·PWM·서보 각도·서보 펄스. I²C 장치를 쓰면 P19/P20은 버스에 예약됩니다.'); box.setAttribute('data-mb-connections', '');
    markField(box, 'connections'); box.tabIndex = -1;
    var rows = table(['핀', '방향', '신호 종류', '장치', '표시 이름', '']);
    draft.connections.forEach(function (connection, index) {
      var row = el('tr');
      cell(row, select(connection.pin, pins, function (value) { connection.pin = Number(value); self.dirty = true; }));
      cell(row, select(connection.direction, [['input', '입력'], ['output', '출력']], function (value) {
        connection.direction = value; connection.signal = value === 'input' ? 'analog' : 'digital'; connection.device = value === 'input' ? 'potentiometer' : 'led'; self.dirty = true; self.renderConnections();
      }));
      cell(row, select(connection.signal, signals.filter(function (pair) { return (connection.direction === 'input' ? ['analog', 'digital'] : ['digital', 'pwm', 'servo', 'servo_pulse']).includes(pair[0]); }), function (value) {
        connection.signal = value; if (connection.direction === 'input') { connection.device = value === 'digital' ? 'button' : connection.device === 'button' ? 'potentiometer' : connection.device; }
        else if (value === 'servo' || value === 'servo_pulse') { connection.device = 'servo'; } else if (connection.device === 'servo') { connection.device = 'led'; }
        self.dirty = true; self.renderConnections();
      }));
      cell(row, select(connection.device, devices.filter(function (pair) { return connection.direction === 'input' ? ['light_sensor', 'potentiometer', 'button'].includes(pair[0]) : ['led', 'motor', 'servo'].includes(pair[0]); }), function (value) {
        connection.device = value; if (connection.direction === 'input') { connection.signal = value === 'button' ? 'digital' : 'analog'; }
        else if (value === 'servo') { if (!['servo', 'servo_pulse'].includes(connection.signal)) { connection.signal = 'servo'; } } else if (['servo', 'servo_pulse'].includes(connection.signal)) { connection.signal = 'digital'; }
        self.dirty = true; self.renderConnections();
      }));
      cell(row, input(connection.label, 'text', function (value) { connection.label = value; self.dirty = true; }));
      cell(row, button('삭제', function () { draft.connections.splice(index, 1); self.dirty = true; self.renderConnections(); })); rows.body.append(row);
    });
    labelTableControls(rows.wrap, 'connections', ['pin', 'direction', 'signal', 'device', 'label']);
    box.append(rows.wrap, button('연결 추가', function () { var freePins = pins.map(function (pair) { return Number(pair[0]); }).filter(function (candidate) { return !draft.connections.some(function (connection) { return connection.pin === candidate; }); });
      var analogPin = freePins.find(function (candidate) { return [0, 1, 2, 3, 4, 10].includes(candidate); }), analog = analogPin !== undefined;
      draft.connections.push({ pin: analog ? analogPin : freePins[0] === undefined ? 0 : freePins[0], direction: 'input', signal: analog ? 'analog' : 'digital', device: analog ? 'potentiometer' : 'button', label: analog ? '센서' : '버튼' }); self.dirty = true; self.renderConnections(); }));
    if (previous) { previous.replaceWith(box); } else { this.content.append(box); }
  };
  App.prototype.renderI2CDevices = function () {
    var self = this, draft = this.exercise, previous = this.content.querySelector('[data-mb-i2c-devices]'), box = section('I²C 장치와 초기 레지스터', '7비트 주소(0~127)와 사용할 레지스터를 등록합니다. 각 레지스터·값은 0~255입니다. 선언한 레지스터만 읽고 쓸 수 있으며, 전기적 회로 대신 레지스터 장치의 동작을 검사합니다.'); box.setAttribute('data-mb-i2c-devices', '');
    markField(box, 'i2cDevices'); box.tabIndex = -1;
    draft.i2cDevices.forEach(function (device, deviceIndex) {
      var card = el('fieldset', 'ojmicrobit-scenario'); card.append(el('legend', '', 'I²C 장치 ' + (deviceIndex + 1)));
      var fields = el('div', 'ojmicrobit-fields');
      fields.append(field('7비트 주소(십진수)', boundedInput(device.address, 0, 127, function (value) { var before = device.address; device.address = value;
        draft.scenarios.forEach(function (scenario) { scenario.i2cInputs.concat(scenario.i2cAssertions).forEach(function (entry) { if (entry.address === before) { entry.address = value; } }); }); self.dirty = true; self.renderScenarios();
      })), field('장치 이름', input(device.label, 'text', function (value) { device.label = value; self.dirty = true; }))); Array.from(fields.querySelectorAll('input')).forEach(function (control, index) { markField(control, 'i2cDevices.' + deviceIndex + '.' + (index === 0 ? 'address' : 'label')); }); card.append(fields);
      var registers = table(['레지스터 번호', '초기값', '학생 코드 쓰기 허용', '']);
      device.registers.forEach(function (register, index) { var row = el('tr');
        cell(row, boundedInput(register.register, 0, 255, function (value) { register.register = value; self.dirty = true; })); cell(row, boundedInput(register.value, 0, 255, function (value) { register.value = value; self.dirty = true; }));
        cell(row, checkInput(register.writable, function (value) { register.writable = value; self.dirty = true; }));
        cell(row, button('레지스터 삭제', function () { device.registers.splice(index, 1); self.dirty = true; self.renderI2CDevices(); self.renderScenarios(); })); registers.body.append(row);
      }); labelTableControls(registers.wrap, 'i2cDevices.' + deviceIndex + '.registers', ['register', 'value', 'writable']); card.append(registers.wrap);
      var tools = el('div', 'ojmicrobit-toolbar'); tools.append(button('레지스터 추가', function () {
        var number = 0; while (number < 255 && device.registers.some(function (register) { return register.register === number; })) { number += 1; }
        device.registers.push({ register: number, value: 0, writable: false }); self.dirty = true; self.renderI2CDevices(); self.renderScenarios();
      }), button('I²C 장치 삭제', function () { draft.i2cDevices.splice(deviceIndex, 1); self.dirty = true; self.renderI2CDevices(); self.renderScenarios(); })); card.append(tools); box.append(card);
    });
    box.append(button('I²C 장치 추가', function () { var address = 32; while (address < 127 && draft.i2cDevices.some(function (device) { return device.address === address; })) { address += 1; }
      draft.i2cDevices.push({ address: address, label: 'I²C 장치', registers: [{ register: 0, value: 0, writable: false }] }); self.dirty = true; self.renderI2CDevices(); self.renderScenarios(); }));
    if (previous) { previous.replaceWith(box); } else { this.content.append(box); }
  };
  App.prototype.i2cScenarioControls = function (scenario) {
    var self = this, box = el('div', 'ojmicrobit-i2c-scenario'), addressOptions = this.exercise.i2cDevices.map(function (device) { return [String(device.address), addressText(device.address) + ' · ' + device.label]; });
    box.append(el('h3', '', 'I²C 레지스터 입력 시나리오'), el('p', 'ojmicrobit-muted', '초기 레지스터 값에서 시작하고 지정 시각에 환경이 바꿀 값을 입력합니다.'));
    var inputs = table(['시각(ms)', '장치 주소', '레지스터', '값', '']);
    scenario.i2cInputs.forEach(function (entry, index) { var row = el('tr');
      cell(row, boundedInput(entry.atMs, 0, scenario.durationMs - 1, function (value) { entry.atMs = value; self.dirty = true; }));
      cell(row, select(entry.address, addressOptions, function (value) { entry.address = Number(value); self.dirty = true; }));
      cell(row, boundedInput(entry.register, 0, 255, function (value) { entry.register = value; self.dirty = true; })); cell(row, boundedInput(entry.value, 0, 255, function (value) { entry.value = value; self.dirty = true; }));
      cell(row, button('I²C 입력 삭제', function () { scenario.i2cInputs.splice(index, 1); self.dirty = true; self.renderScenarios(); })); inputs.body.append(row);
    }); labelTableControls(inputs.wrap, 'scenarios.' + self.exercise.scenarios.indexOf(scenario) + '.i2cInputs', ['atMs', 'address', 'register', 'value']); box.append(inputs.wrap, button('I²C 입력 추가', function () {
      var device = self.exercise.i2cDevices[0]; if (!device || !device.registers.length) { self.message('먼저 I²C 장치와 레지스터를 등록해 주세요.', true); return; }
      var previous = scenario.i2cInputs.reduce(function (value, entry) { return Math.max(value, entry.atMs); }, -100);
      scenario.i2cInputs.push({ atMs: Math.min(previous + 100, scenario.durationMs - 1), address: device.address, register: device.registers[0].register, value: device.registers[0].value }); self.dirty = true; self.renderScenarios();
    }));
    box.append(el('h3', '', '기대 I²C 거래와 순서'), el('p', 'ojmicrobit-muted', '각 행은 서로 다른 실제 거래 한 번과 순서대로 대응합니다. 데이터는 0~255의 십진수 바이트를 쉼표로 구분합니다. repeated는 다음 거래까지 버스를 유지하는 조건입니다.'));
    var assertions = table(['순서', '주소', '동작', '바이트 데이터', 'repeated', '기준 시각(ms)', '응답 제한(ms)', '']);
    scenario.i2cAssertions.forEach(function (entry, index) { var row = el('tr'), order = el('div', 'ojmicrobit-order'); order.append(el('span', '', index + 1));
      if (index > 0) { order.append(button('위', function () { scenario.i2cAssertions.splice(index - 1, 0, scenario.i2cAssertions.splice(index, 1)[0]); self.dirty = true; self.renderScenarios(); })); }
      if (index < scenario.i2cAssertions.length - 1) { order.append(button('아래', function () { scenario.i2cAssertions.splice(index + 1, 0, scenario.i2cAssertions.splice(index, 1)[0]); self.dirty = true; self.renderScenarios(); })); }
      cell(row, order); cell(row, select(entry.address, addressOptions, function (value) { entry.address = Number(value); self.dirty = true; }));
      cell(row, select(entry.operation, [['read', '읽기'], ['write', '쓰기']], function (value) { entry.operation = value; self.dirty = true; }));
      cell(row, byteInput(entry.data, function (value) { entry.data = value; self.dirty = true; })); cell(row, checkInput(entry.repeated, function (value) { entry.repeated = value; self.dirty = true; }));
      cell(row, boundedInput(entry.afterMs, 0, scenario.durationMs, function (value) { entry.afterMs = value; self.dirty = true; })); cell(row, boundedInput(entry.withinMs, 1, scenario.durationMs, function (value) { entry.withinMs = value; self.dirty = true; }));
      cell(row, button('기대 거래 삭제', function () { scenario.i2cAssertions.splice(index, 1); self.dirty = true; self.renderScenarios(); })); assertions.body.append(row);
    }); labelTableControls(assertions.wrap, 'scenarios.' + self.exercise.scenarios.indexOf(scenario) + '.i2cAssertions', ['', 'address', 'operation', 'data', 'repeated', 'afterMs', 'withinMs']); box.append(assertions.wrap, button('기대 I²C 거래 추가', function () {
      var device = self.exercise.i2cDevices[0]; if (!device) { self.message('먼저 I²C 장치를 등록해 주세요.', true); return; }
      scenario.i2cAssertions.push({ address: device.address, operation: 'read', data: [device.registers[0] ? device.registers[0].value : 0], repeated: false, afterMs: 0, withinMs: 100 }); self.dirty = true; self.renderScenarios();
    })); return box;
  };
  App.prototype.renderDetailedScenarios = function (host, singleScenario) {
    host = host || this.content;
    var self = this, draft = this.exercise, previous = host.querySelector('[data-mb-scenarios]'), box = section('자동 채점 시나리오', '시나리오마다 프로그램을 초기화합니다. 같은 시나리오의 입력은 시간 순서대로 적용합니다.'); box.setAttribute('data-mb-scenarios', '');
    (singleScenario ? [singleScenario] : draft.scenarios).forEach(function (scenario, scenarioIndex) {
      var card = el('fieldset', 'ojmicrobit-scenario'), legend = el('legend', '', '시나리오 ' + (scenarioIndex + 1)), grid = el('div', 'ojmicrobit-fields'); card.append(legend);
      var publicToggle = input('', 'checkbox'); publicToggle.checked = scenario.visible; publicToggle.addEventListener('change', function () { scenario.visible = publicToggle.checked; self.dirty = true; });
      grid.append(field('이름', input(scenario.name, 'text', function (value) { scenario.name = value; self.dirty = true; })),
        field('실행 시간(ms)', input(scenario.durationMs, 'number', function (value) { scenario.durationMs = value; self.dirty = true; })), field('학생에게 테스트 공개', publicToggle)); card.append(grid);
      card.append(el('h3', '', '입력값과 적용 시각'));
      var inputTable = table(['시각(ms)', '핀', '신호', '값', '']);
      scenario.inputs.forEach(function (entry, index) { var row = el('tr');
        cell(row, input(entry.atMs, 'number', function (value) { entry.atMs = value; self.dirty = true; }));
        cell(row, select(entry.pin, pins, function (value) { entry.pin = Number(value); self.dirty = true; }));
        cell(row, select(entry.signal, signals.filter(function (pair) { return ['analog', 'digital'].includes(pair[0]); }), function (value) { entry.signal = value; self.dirty = true; }));
        cell(row, input(entry.value, 'number', function (value) { entry.value = value; self.dirty = true; }));
        cell(row, button('삭제', function () { scenario.inputs.splice(index, 1); self.dirty = true; self.renderScenarios(); })); inputTable.body.append(row);
      });
      labelTableControls(inputTable.wrap, 'scenarios.' + draft.scenarios.indexOf(scenario) + '.inputs', ['atMs', 'pin', 'signal', 'value']);
      card.append(inputTable.wrap, button('입력 단계 추가', function () {
        var first = draft.connections.find(function (connection) { return connection.direction === 'input'; });
        var lastTime = scenario.inputs.reduce(function (maximum, entry) { return Math.max(maximum, entry.atMs); }, -100);
        scenario.inputs.push({ atMs: Math.min(lastTime + 100, scenario.durationMs - 1), pin: first ? first.pin : 0, signal: first ? first.signal : 'analog', value: 0 }); self.dirty = true; self.renderScenarios();
      }));
      card.append(el('h3', '', '기대 출력과 시간 조건'));
      var outputTable = table(['핀', '신호', '기대값', '기준 시각(ms)', '응답 제한(ms)', '유지 시간(ms)', '허용 오차', '']);
      scenario.assertions.forEach(function (entry, index) { var row = el('tr');
        cell(row, select(entry.pin, pins, function (value) { entry.pin = Number(value); self.dirty = true; }));
        cell(row, select(entry.signal, signals.filter(function (pair) { return pair[0] !== 'analog'; }), function (value) { entry.signal = value; self.dirty = true; }));
        ['expected', 'afterMs', 'withinMs', 'holdMs', 'tolerance'].forEach(function (key) { cell(row, input(entry[key], 'number', function (value) { entry[key] = value; self.dirty = true; })); });
        cell(row, button('삭제', function () { scenario.assertions.splice(index, 1); self.dirty = true; self.renderScenarios(); })); outputTable.body.append(row);
      });
      labelTableControls(outputTable.wrap, 'scenarios.' + draft.scenarios.indexOf(scenario) + '.assertions', ['pin', 'signal', 'expected', 'afterMs', 'withinMs', 'holdMs', 'tolerance']);
      card.append(outputTable.wrap, button('기대 출력 추가', function () {
        var first = draft.connections.find(function (connection) { return connection.direction === 'output'; });
        scenario.assertions.push({ pin: first ? first.pin : 1, signal: first ? first.signal : 'digital', expected: first && first.signal === 'servo_pulse' ? 1000 : 0, afterMs: 0, withinMs: 100, holdMs: 100, tolerance: 0 }); self.dirty = true; self.renderScenarios();
      }));
      card.append(el('h3', '', '출력 동작 순서'));
      (scenario.outputSequences || []).forEach(function (sequence) { card.append(self.sequenceControls(scenario, sequence, true)); });
      card.append(button('출력 동작 순서 추가', function () { var connection = draft.connections.find(function (item) { return item.direction === 'output'; }); if (!connection) { self.message('먼저 출력 장치를 연결하세요.', true); return; }
        try { var changed = updateOutputSequence(scenario, (scenario.outputSequences || []).length, function (sequences) { sequences.push({ pin: connection.pin, signal: connection.signal, afterMs: 0, steps: [{ expected: connection.signal === 'servo_pulse' ? 1000 : 0, withinMs: 500, holdMs: 100, tolerance: 0 }] }); }, false); self.replaceScenario(scenario, changed); } catch (error) { self.message(error.message, true); }
      }));
      card.append(self.i2cScenarioControls(scenario));
      var remove = button('시나리오 삭제', function () { draft.scenarios.splice(scenarioIndex, 1); self.dirty = true; self.renderScenarios(); }); remove.classList.add('ojp-button--danger'); card.append(remove); box.append(card);
    });
    box.append(button('시나리오 추가', function () {
      var inputs = draft.connections.filter(function (connection) { return connection.direction === 'input'; }).map(function (connection) { return { atMs: 0, pin: connection.pin, signal: connection.signal, value: 0 }; });
      draft.scenarios.push({ id: 'case-' + uid().slice(0, 12), name: '새 시나리오', visible: true, durationMs: 2000, inputs: inputs, assertions: [], i2cInputs: [], i2cAssertions: [] }); self.dirty = true; self.renderScenarios();
    }));
    if (previous) { previous.replaceWith(box); } else { host.append(box); }
  };
  App.prototype.commitStageBatch = function (scenario, applied) {
    var existing = Boolean(scenario), draft = this.exercise;
    if (!existing && draft.scenarios.length >= 20) { throw new Error('예시는 최대 20개예요. 기존 예시에 단계를 추가해 주세요.'); }
    if (draft.scenarios.reduce(function (sum, item) { return sum + (item === scenario ? 0 : item.durationMs); }, 0) + applied.scenario.durationMs > 120000) { throw new Error('모든 예시의 실행 시간 합계는 120초 이하여야 해요.'); }
    var candidates = draft.scenarios.map(function (item) { return item === scenario ? clone(applied.scenario) : clone(item); });
    if (!existing) { candidates.push(clone(applied.scenario)); }
    candidates.forEach(function (item) {
      applied.connections.filter(function (entry) { return entry.direction === 'input'; }).forEach(function (entry) {
        if (!item.inputs.some(function (input) { return input.pin === entry.pin && input.atMs === 0; })) { item.inputs.push({ atMs: 0, pin: entry.pin, signal: entry.signal, value: 0 }); }
      });
      if (item.inputs.length > 256) { throw new Error('다른 예시에 새 핀의 처음 값을 넣을 공간이 없어요. 입력을 먼저 줄여 주세요.'); }
      item.inputs.sort(function (a, b) { return a.atMs - b.atMs || a.pin - b.pin; });
    });
    candidates.forEach(function (candidate, index) {
      var target = draft.scenarios[index];
      if (target) { Object.keys(target).forEach(function (key) { delete target[key]; }); Object.assign(target, candidate); }
      else { draft.scenarios.push(candidate); }
    });
    draft.connections = clone(applied.connections); this.dirty = true;
  };
  App.prototype.openStageBatchWizard = function (scenario, options) {
    if (this.busy || this.stageWizard) { return; } options = options || {};
    var self = this, exercise = this.exercise, working = scenario || emptyScenario(exercise.scenarios.length + 1), groups = flowGroups(working), editing = options.atMs !== undefined, at = editing ? options.atMs : groups.length ? working.durationMs : 0;
    if (!scenario && options.visible === false) { working.visible = false; working.name = '비공개 채점 ' + (exercise.scenarios.filter(function (item) { return !item.visible; }).length + 1); }
    var original = JSON.stringify({ connections: exercise.connections, scenarios: exercise.scenarios }), channels;
    try { channels = editing ? stageBatchChannels(working, exercise.connections, at) : []; } catch (error) { this.message(error.message, true); return; }
    exercise.connections.forEach(function (connection) { if (!channels.some(function (item) { return item.pin === connection.pin; })) { channels.push({ pin: connection.pin, selected: false, added: false, connection: clone(connection), value: 0, steps: [{ expected: connection.signal === 'servo' ? 90 : connection.signal === 'servo_pulse' ? 1000 : 0, holdMs: 200, withinMs: 100, tolerance: 0 }] }); } });
    var group = groups.find(function (item) { return item.atMs === at; }), state = { page: 0, channels: channels, intervalMs: group ? group.lengthMs : 1000, touched: false, adding: !channels.length, kind: 'sensor', newPin: null, label: '' };
    var kinds = { sensor: ['input', 'analog', 'potentiometer', '센서'], button: ['input', 'digital', 'button', '버튼'], led: ['output', 'digital', 'led', 'LED'], motor: ['output', 'pwm', 'motor', '모터'], servo: ['output', 'servo', 'servo', '서보'], pulse: ['output', 'servo_pulse', 'servo', '서보 펄스'] };
    var dialog = el('dialog', 'ojmicrobit-wizard ojmicrobit-batch-wizard'), opener = global.document.activeElement, wasInert = this.content.inert;
    dialog.setAttribute('data-mb-batch-wizard', ''); this.root.append(dialog); this.content.inert = true; this.stageWizard = { dialog: dialog, state: state, close: close };
    function selected() { return channels.filter(function (item) { return item.selected; }); }
    function freePins() { return pins.filter(function (pair) { var pin = Number(pair[0]); return !channels.some(function (item) { return item.pin === pin; }) && !(exercise.i2cDevices.length && [19, 20].includes(pin)) && (state.kind !== 'sensor' || [0, 1, 2, 3, 4, 10].includes(pin)); }); }
    function pickPin() { var choices = freePins(); state.newPin = choices.length ? Number(choices[0][0]) : null; }
    function close() { self.stageWizard = null; self.content.inert = wasInert; if (dialog.close && dialog.open) { dialog.close(); } dialog.remove(); var target = opener && opener.isConnected ? opener : self.content.querySelector('[data-mb-stage-edit="' + at + '"]') || self.content.querySelector('[data-mb-stage-new]'); if (target) { target.focus(); } }
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { event.preventDefault(); close(); return; }
      if (event.key !== 'Tab') { return; }
      var nodes = Array.from(dialog.querySelectorAll('button, input, select, textarea, summary')).filter(function (node) { return !node.disabled && (!node.getClientRects || node.getClientRects().length); }), active = global.document.activeElement, first = nodes[0], last = nodes.at(-1);
      if (first && (!nodes.includes(active) || event.shiftKey && active === first || !event.shiftKey && active === last)) { event.preventDefault(); (event.shiftKey ? last : first).focus(); }
    });
    function fail(message) { var note = dialog.querySelector('[data-mb-wizard-error]'); note.textContent = message; note.focus(); }
    function range(item) { return [item.connection.signal === 'servo_pulse' ? 1 : 0, item.connection.signal === 'digital' ? 1 : item.connection.signal === 'servo' ? 180 : item.connection.signal === 'servo_pulse' ? 20000 : 1023]; }
    function validateValues() {
      if (!selected().length) { throw new Error('이 단계에서 사용할 핀을 하나 이상 선택해 주세요.'); }
      selected().forEach(function (item) { var bounds = range(item); (item.connection.direction === 'input' ? [item.value] : item.steps.map(function (step) { return step.expected; })).forEach(function (value) { if (!Number.isInteger(value) || value < bounds[0] || value > bounds[1]) { throw new Error('P' + item.pin + ' 값은 ' + bounds[0] + '~' + bounds[1] + ' 사이의 정수예요.'); } }); });
    }
    function valueControl(item, value, change) {
      if (item.connection.signal === 'digital') { return select(value, [['0', item.connection.direction === 'input' ? '누르지 않음 · 0' : '꺼짐 · 0'], ['1', item.connection.direction === 'input' ? '누름 · 1' : '켜짐 · 1']], function (raw) { state.touched = true; change(Number(raw)); }); }
      var bounds = range(item); return boundedInput(value, bounds[0], bounds[1], function (raw) { state.touched = true; change(raw); });
    }
    function seconds(label, value, change) { var control = boundedInput(value / 1000, 0.001, 30, function (raw) { state.touched = true; change(Math.round(raw * 1000)); }); control.step = '0.001'; return field(label, control); }
    function render() {
      dialog.replaceChildren(); var head = el('div', 'ojmicrobit-wizard-header'), title = el('h2', '', ['1. 단계 만들기', '2. 사용할 핀 여러 개 선택', '3. 핀별 입력값과 출력값', '4. 간격과 유지시간'][state.page]); title.id = 'mb-batch-wizard-title'; title.tabIndex = -1; dialog.setAttribute('aria-labelledby', title.id);
      head.append(el('span', 'ojmicrobit-muted', (state.page + 1) + ' / 4'), button('작성 취소', close)); dialog.append(head, title); var body = el('div', 'ojmicrobit-wizard-body');
      if (state.page === 0) {
        if (!scenario) { body.append(field('검사 이름', input(working.name, 'text', function (value) { working.name = value; state.touched = true; }))); var caseKind = select(working.visible ? 'public' : 'hidden', [['public', '공개 예시 · 학생 설명과 채점'], ['hidden', '비공개 채점 · 학생에게 숨김']], function (value) { working.visible = value === 'public'; state.touched = true; }); caseKind.setAttribute('aria-label', '검사 종류'); body.append(field('검사 종류', caseKind)); } else { body.append(el('p', 'ojmicrobit-wizard-relation', working.visible ? '공개 예시 · 학생에게 보여주는 조건' : '비공개 채점 · 학생에게 숨기는 조건')); }
        body.append(el('p', 'ojmicrobit-wizard-relation', editing ? '이 단계의 여러 입력·출력을 함께 수정해요.' : '여러 핀의 입력·출력을 한 단계에 함께 만들어요.'), el('p', '', at ? '기준 시점: 예시 시작 후 ' + at / 1000 + '초' : '기준 시점: 프로그램을 시작할 때'), el('p', 'ojmicrobit-muted', '핀 여러 개 선택 → 각 핀의 값 → 시간 → 한 번에 적용 순서예요. 핀마다 도우미를 다시 열지 않아도 돼요.'));
        if (group && (group.i2cInputs.length || group.i2cAssertions.length)) { body.append(el('p', 'ojmicrobit-muted', '이 단계의 I²C 거래는 보존해요. 버스 값은 세부 채점 설정에서 수정하세요.')); }
      } else if (state.page === 1) {
        body.append(el('p', 'ojmicrobit-muted', '같은 시점에 사용할 핀을 모두 골라 주세요. 입력과 출력을 함께 선택할 수 있어요.'));
        var choices = el('div', 'ojmicrobit-batch-choices'); channels.forEach(function (item) {
          var control = checkInput(item.selected, function (value) { item.selected = value; state.touched = true; });
          control.setAttribute('data-mb-batch-pin', String(item.pin)); if (editing && at === 0 && item.originalKind === 'inputs') { control.disabled = true; }
          choices.append(field('P' + item.pin + ' · ' + item.connection.label + ' · ' + (item.connection.direction === 'input' ? '입력' : '출력') + (control.disabled ? ' · 처음 값' : ''), control));
        }); body.append(choices);
        body.append(button(state.adding ? '새 장치 연결 접기' : '+ 새 장치 연결', function () { state.adding = !state.adding; if (state.adding) { pickPin(); } render(); }));
        if (state.adding) {
          if (state.newPin === null) { pickPin(); } var builder = el('div', 'ojmicrobit-batch-connect');
          builder.append(field('장치 종류', select(state.kind, [['sensor', '센서 입력'], ['button', '버튼 입력'], ['led', 'LED 출력'], ['motor', '모터 출력'], ['servo', '서보 각도'], ['pulse', '서보 펄스']], function (value) { state.kind = value; state.label = ''; pickPin(); render(); })));
          var available = freePins();
          if (available.length) {
            builder.append(field('새 장치 핀', select(state.newPin, available, function (value) { state.newPin = Number(value); })), field('장치 이름 (선택)', input(state.label, 'text', function (value) { state.label = value; })), button('선택 목록에 추가', function () {
              var kind = kinds[state.kind]; channels.push({ pin: state.newPin, selected: true, added: true, connection: { pin: state.newPin, direction: kind[0], signal: kind[1], device: kind[2], label: state.label.trim() || kind[3] }, value: 0, steps: [{ expected: state.kind === 'servo' ? 90 : state.kind === 'pulse' ? 1000 : 0, holdMs: 200, withinMs: 100, tolerance: 0 }] }); state.touched = true; state.label = ''; state.adding = false; state.newPin = null; render();
            }));
          } else { builder.append(el('p', 'ojmicrobit-field-error', '이 장치에 사용할 빈 핀이 없어요.')); } body.append(builder);
        }
      } else if (state.page === 2) {
        selected().forEach(function (item) {
          var card = el('section', 'ojmicrobit-batch-channel'); card.setAttribute('data-mb-batch-channel', String(item.pin)); card.append(el('h3', '', 'P' + item.pin + ' · ' + item.connection.label + ' · ' + (item.connection.direction === 'input' ? '입력' : '출력')));
          if (item.connection.direction === 'input') { card.append(field('P' + item.pin + ' 입력값', valueControl(item, item.value, function (value) { item.value = value; }))); }
          else {
            item.steps.forEach(function (step, index) {
              var row = el('div', 'ojmicrobit-batch-value'); if (index) { row.append(el('span', 'ojmicrobit-flow-mode', '↓ 앞 동작 유지 완료 후')); }
              row.append(field('P' + item.pin + ' 동작 ' + (index + 1) + ' 출력값', valueControl(item, step.expected, function (value) { step.expected = value; }))); card.append(row);
            });
            var tools = el('div', 'ojmicrobit-toolbar'); tools.append(button('P' + item.pin + ' → 다음 값 추가', function () { if (item.steps.length >= 20) { fail('한 핀의 직렬 동작은 20개까지예요.'); return; } var last = item.steps.at(-1); item.steps.push({ expected: item.connection.signal === 'digital' ? 1 - last.expected : last.expected, holdMs: 200, withinMs: 100, tolerance: 0 }); state.touched = true; render(); }));
            if (item.steps.length > 1) { tools.append(button('P' + item.pin + ' 마지막 동작 삭제', function () { item.steps.pop(); state.touched = true; render(); })); } card.append(tools);
          } body.append(card);
        });
      } else {
        body.append(seconds('다음 입력 단계까지 간격 (초)', state.intervalMs, function (value) { state.intervalMs = value; }), el('p', 'ojmicrobit-muted', '입력값은 다음에 바꾸기 전까지 유지돼요. 출력 유지시간은 값이 나타난 뒤의 최소 시간이며 입력 간격과 별개예요.'));
        selected().filter(function (item) { return item.connection.direction === 'output'; }).forEach(function (item) {
          var card = el('section', 'ojmicrobit-batch-channel'); card.append(el('h3', '', 'P' + item.pin + ' · ' + item.connection.label));
          item.steps.forEach(function (step, index) {
            card.append(seconds('P' + item.pin + ' 동작 ' + (index + 1) + ' 유지시간 (초)', step.holdMs, function (value) { step.holdMs = value; }));
            var details = el('details', 'ojmicrobit-wizard-timing'); details.append(el('summary', '', '동작 ' + (index + 1) + ' 반응 시간·허용 오차'), seconds('P' + item.pin + ' 동작 ' + (index + 1) + ' 반응 한도 (초)', step.withinMs, function (value) { step.withinMs = value; }), field('P' + item.pin + ' 동작 ' + (index + 1) + ' 허용 오차', boundedInput(step.tolerance, 0, item.connection.signal === 'digital' ? 0 : range(item)[1], function (value) { step.tolerance = value; state.touched = true; }))); card.append(details);
          }); body.append(card);
        });
        body.append(el('p', 'ojmicrobit-muted', '여러 핀은 같은 기준 시점부터 병렬로 확인해요. 한 핀의 다음 값은 앞 동작의 유지가 끝난 뒤 확인해요. 새 입력 핀의 다른 예시에 처음 값이 없으면 0으로 준비해요.'));
      }
      var note = el('p', 'ojmicrobit-field-error'); note.setAttribute('data-mb-wizard-error', ''); note.setAttribute('role', 'alert'); note.tabIndex = -1; dialog.append(body, note);
      var foot = el('div', 'ojmicrobit-wizard-footer'), back = button('이전', function () { state.page--; render(); }); back.disabled = state.page === 0;
      foot.append(back, button(state.page === 3 ? '핀 ' + selected().length + '개 함께 적용' : '다음', function () {
        try {
          if (state.page === 1 && !selected().length) { throw new Error('핀을 하나 이상 선택해 주세요. 새 장치는 선택 목록에 추가한 뒤 진행해 주세요.'); }
          if (state.page === 2) { validateValues(); }
          if (state.page < 3) { state.page++; state.touched = true; render(); return; }
          validateValues(); if (self.exercise !== exercise || original !== JSON.stringify({ connections: exercise.connections, scenarios: exercise.scenarios })) { throw new Error('작성 중 원본이 바뀌었어요. 창을 닫고 다시 열어 주세요.'); }
          var applied = applyStageBatch(working, exercise.connections, { mode: editing ? 'edit' : 'new', atMs: at, intervalMs: state.intervalMs, channels: selected().map(function (item) { return { pin: item.pin, newConnection: item.added ? item.connection : null, value: item.value, steps: item.steps }; }) }, Boolean(exercise.i2cDevices.length));
          self.commitStageBatch(scenario, applied); self.caseVisibility = applied.scenario.visible; self.renderConnections(); self.renderScenarios(); close(); self.message('선택한 핀을 한 번에 적용했어요. 같은 시점의 핀과 이어지는 동작을 확인하세요.');
        } catch (error) { fail(error.message); }
      }, true)); dialog.append(foot); title.focus();
    }
    render(); if (dialog.showModal) { dialog.showModal(); } else { dialog.setAttribute('open', ''); dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); }
  };
  App.prototype.openStageWizard = function (scenario, options) {
    if (this.busy || this.stageWizard) { return; } options = options || {};
    var self = this, originalExercise = this.exercise, existing = Boolean(scenario), working = scenario || emptyScenario(this.exercise.scenarios.length + 1), expected = JSON.stringify(working), groups = flowGroups(working), mode = options.mode || 'stage', at = options.atMs === undefined ? groups.length ? working.durationMs : 0 : options.atMs;
    var used = new Set(); scenarioEntries(working, function (entry, time) { if (entry[time] === at && entry.pin !== undefined) { used.add(entry.pin); } });
    var available = this.exercise.connections.filter(function (entry) { return mode === 'edit' || mode === 'serial' ? entry.pin === options.pin : !used.has(entry.pin); });
    var kinds = { sensor: ['input', 'analog', 'potentiometer', '센서'], button: ['input', 'digital', 'button', '버튼'], led: ['output', 'digital', 'led', 'LED'], motor: ['output', 'pwm', 'motor', '모터'], servo: ['output', 'servo', 'servo', '서보'], pulse: ['output', 'servo_pulse', 'servo', '서보 펄스'] };
    var state = { page: 0, pin: available.length ? available[0].pin : null, addNew: !available.length, kind: 'sensor', label: '', value: options.value === undefined ? 0 : options.value, holdMs: options.holdMs || 500, withinMs: options.withinMs || 100, tolerance: options.tolerance || 0, touched: false };
    if (mode === 'edit' && options.target && options.target.kind === 'inputs') { var currentGroup = groups.find(function (entry) { return entry.atMs === at; }); state.holdMs = currentGroup ? currentGroup.lengthMs : 500; }
    function freePins() { return pins.filter(function (pair) { var pin = Number(pair[0]); return !self.exercise.connections.some(function (entry) { return entry.pin === pin; }) && !(self.exercise.i2cDevices.length && [19, 20].includes(pin)) && (state.kind !== 'sensor' || [0, 1, 2, 3, 4, 10].includes(pin)); }); }
    function newConnection() { var definition = kinds[state.kind]; return { pin: Number(state.pin), direction: definition[0], signal: definition[1], device: definition[2], label: state.label.trim() || definition[3] }; }
    function connection() { return state.addNew ? newConnection() : self.exercise.connections.find(function (entry) { return entry.pin === state.pin; }); }
    function pickNew() { var options = freePins(); state.pin = options.length ? Number(options[0][0]) : null; }
    if (state.addNew) { pickNew(); } else if (options.value === undefined) { var initial = connection(); state.value = initial.signal === 'servo' ? 90 : initial.signal === 'servo_pulse' ? 1000 : 0; }
    var dialog = el('dialog', 'ojmicrobit-wizard'), opener = global.document.activeElement, wasInert = this.content.inert;
    dialog.setAttribute('aria-label', '단계 작성 도우미'); this.root.append(dialog); this.content.inert = true; this.stageWizard = { dialog: dialog, state: state, close: close };
    function close() { if (self.stageWizard && self.stageWizard.dialog === dialog) { self.stageWizard = null; } self.content.inert = wasInert; if (dialog.close && dialog.open) { dialog.close(); } dialog.remove(); var focus = opener && opener.isConnected ? opener : self.content.querySelector('[data-mb-stage-new]'); if (focus && focus.focus) { focus.focus(); } }
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { event.preventDefault(); close(); return; }
      if (event.key !== 'Tab') { return; }
      var controls = Array.from(dialog.querySelectorAll('button, input, select, textarea, summary, [tabindex="0"]')).filter(function (node) { return !node.disabled && (!node.getClientRects || node.getClientRects().length); }), first = controls[0], last = controls.at(-1), active = global.document.activeElement;
      if (first && (!controls.includes(active) || event.shiftKey && active === first || !event.shiftKey && active === last)) { event.preventDefault(); (event.shiftKey ? last : first).focus(); }
    });
    function fail(message) { var note = dialog.querySelector('[data-mb-wizard-error]'); note.textContent = message; note.focus(); }
    function validValue() { var item = connection(); if (!item) { throw new Error('사용할 핀을 선택하세요.'); } var min = item.signal === 'servo_pulse' ? 1 : 0, max = item.signal === 'digital' ? 1 : item.signal === 'servo' ? 180 : item.signal === 'servo_pulse' ? 20000 : 1023; if (!Number.isInteger(state.value) || state.value < min || state.value > max) { throw new Error('값은 ' + min + '~' + max + ' 사이의 정수예요.'); } }
    function render() {
      dialog.replaceChildren(); var header = el('div', 'ojmicrobit-wizard-header'), title = el('h2', '', ['1. 단계 만들기', '2. 사용할 핀', '3. 입력값 또는 출력값', '4. 유지시간'][state.page]); title.tabIndex = -1; title.id = 'mb-stage-wizard-title'; dialog.setAttribute('aria-labelledby', title.id);
      header.append(el('span', 'ojmicrobit-muted', (state.page + 1) + ' / 4'), button('작성 취소', close)); dialog.append(header, title);
      var body = el('div', 'ojmicrobit-wizard-body');
      if (state.page === 0) {
        var relation = mode === 'serial' ? '직렬 · 앞 출력이 유지시간을 채우면 다음 값을 확인해요.' : mode === 'parallel' ? '병렬 · 같은 시점부터 다른 핀의 값을 함께 확인해요.' : mode === 'edit' ? '선택한 동작만 수정해요. 다른 동작은 그대로 두어요.' : '새 입력 단계를 만들어요. 이 단계 안에 여러 핀의 동작을 넣을 수 있어요.';
        body.append(el('p', 'ojmicrobit-wizard-relation', relation), el('p', '', at === 0 ? '시작 시점: 처음' : '입력 기준 시점: 예시 시작 후 ' + at / 1000 + '초'));
        body.append(el('p', 'ojmicrobit-muted', '핀을 고르고 → 값을 정하고 → 시간을 설정하면 화면의 동작 그림에 추가돼요.'));
      } else if (state.page === 1) {
        if (mode === 'serial' || mode === 'edit') { var fixed = connection(); body.append(el('p', 'ojmicrobit-wizard-pin', fixed ? 'P' + fixed.pin + ' · ' + fixed.label : '핀을 다시 확인해 주세요.')); }
        else {
          body.append(field('연결한 장치 또는 새 핀', select(state.addNew ? 'new' : String(state.pin), available.map(function (item) { return [String(item.pin), 'P' + item.pin + ' · ' + item.label]; }).concat([['new', '새 핀 연결']]), function (value) { state.touched = true; state.addNew = value === 'new'; if (state.addNew) { pickNew(); } else { state.pin = Number(value); } var item = connection(); state.value = item && item.signal === 'servo' ? 90 : item && item.signal === 'servo_pulse' ? 1000 : 0; render(); })));
          if (state.addNew) {
            body.append(field('무엇을 연결하나요?', select(state.kind, [['sensor','센서 값 읽기'],['button','버튼 입력'],['led','LED 켜기·끄기'],['motor','모터 세기'],['servo','서보 각도'],['pulse','서보 펄스']], function (value) { state.kind = value; state.label = ''; state.touched = true; pickNew(); state.value = value === 'servo' ? 90 : value === 'pulse' ? 1000 : 0; render(); })));
            var choices = freePins(); if (choices.length) { body.append(field('핀 번호', select(state.pin, choices, function (value) { state.pin = Number(value); state.touched = true; }))); } else { body.append(el('p', 'ojmicrobit-field-error', '이 종류에 쓸 수 있는 빈 핀이 없어요. 연결한 장치를 선택하거나 장치 연결을 확인하세요.')); }
            body.append(field('장치 이름 (선택)', input(state.label, 'text', function (value) { state.label = value; state.touched = true; })));
          }
        }
        var selected = connection(); if (selected) { body.append(el('p', 'ojmicrobit-muted', selected.direction === 'input' ? '센서·버튼에서 받을 입력값을 다음 화면에서 정해요.' : '이 핀으로 보낼 출력값을 다음 화면에서 정해요.')); }
      } else if (state.page === 2) {
        var item = connection(); body.append(el('p', 'ojmicrobit-wizard-pin', 'P' + item.pin + ' · ' + item.label));
        if (item.signal === 'digital') { var values = el('div', 'ojmicrobit-wizard-values'); [0, 1].forEach(function (value) { var control = button(item.direction === 'input' && item.device === 'button' ? value ? '누름 · 1' : '누르지 않음 · 0' : value ? '켜짐 · 1' : '꺼짐 · 0', function () { state.value = value; state.touched = true; render(); }, state.value === value); control.setAttribute('aria-pressed', String(state.value === value)); values.append(control); }); body.append(values); }
        else { var max = item.signal === 'servo' ? 180 : item.signal === 'servo_pulse' ? 20000 : 1023, min = item.signal === 'servo_pulse' ? 1 : 0; body.append(field((item.direction === 'input' ? '입력값' : '출력값') + (item.signal === 'servo' ? ' (도)' : item.signal === 'servo_pulse' ? ' (µs)' : '') + ' · ' + min + '~' + max, boundedInput(state.value, min, max, function (value) { state.value = value; state.touched = true; }))); }
      } else {
        var item = connection(), inputMode = item.direction === 'input';
        body.append(el('p', 'ojmicrobit-wizard-pin', 'P' + item.pin + ' · ' + item.label + ' = ' + state.value));
        var duration = boundedInput(state.holdMs / 1000, 0.001, 30, function (value) { state.holdMs = Math.round(value * 1000); state.touched = true; }); duration.step = '0.001';
        body.append(field(inputMode ? '다음 입력 단계까지 간격 (초)' : '이 출력 상태를 유지할 시간 (초)', duration));
        body.append(el('p', 'ojmicrobit-muted', inputMode ? '새 입력 단계는 이 간격 뒤에 시작해요. 이 핀의 값은 다음에 바꾸기 전까지 유지돼요. 다른 예시에서도 이 핀의 처음 값이 없으면 0으로 준비해요.' : '정답 출력이 나타난 뒤 이 시간만큼 유지해야 해요. 0.2초 조건이면 0.5초 유지해도 충족할 수 있어요. 다음 동작의 조건도 함께 확인해요.'));
        if (!inputMode) { var timing = el('details', 'ojmicrobit-wizard-timing'); timing.append(el('summary', '', '반응 시간·허용 오차 (필요할 때)')); var reaction = boundedInput(state.withinMs / 1000, 0.001, 30, function (value) { state.withinMs = Math.round(value * 1000); state.touched = true; }); reaction.step = '0.001'; timing.append(field('이 값이 나타나기까지 허용할 시간 (초)', reaction), field('허용 오차', boundedInput(state.tolerance, 0, item.signal === 'digital' ? 0 : item.signal === 'servo' ? 180 : item.signal === 'servo_pulse' ? 20000 : 1023, function (value) { state.tolerance = value; state.touched = true; })), el('p', 'ojmicrobit-muted', '바로 켠 뒤 기다리는 것과, 기다린 뒤 켜는 것은 달라요. 반응 시간은 켜질 때까지, 유지시간은 켜진 뒤를 확인해요.')); body.append(timing); }
      }
      var error = el('p', 'ojmicrobit-field-error'); error.setAttribute('data-mb-wizard-error', ''); error.setAttribute('role', 'alert'); error.tabIndex = -1; dialog.append(body, error);
      var footer = el('div', 'ojmicrobit-wizard-footer'), previous = button('이전', function () { state.page--; render(); }); previous.disabled = state.page === 0; footer.append(previous, button(state.page === 3 ? '단계에 적용' : '다음', function () {
        try {
          if (state.page === 1 && (state.pin === null || !connection())) { throw new Error('사용할 핀을 선택하세요.'); }
          if (state.page === 2) { validValue(); }
          if (state.page < 3) { state.touched = true; state.page++; render(); return; }
          if (self.exercise !== originalExercise || existing && JSON.stringify(scenario) !== expected) { throw new Error('작성 중 원본이 바뀌었어요. 창을 닫고 다시 선택해 주세요.'); }
          validValue(); var applied = applyStageWizard(working, self.exercise.connections, { mode: mode, atMs: at, pin: state.pin, connection: state.addNew ? newConnection() : null, value: state.value, holdMs: state.holdMs, withinMs: state.withinMs, tolerance: state.tolerance, target: options.target }, Boolean(self.exercise.i2cDevices.length));
          if (!existing && self.exercise.scenarios.length >= 20) { throw new Error('예시는 최대 20개예요. 기존 예시에 단계를 추가해 주세요.'); }
          if (self.exercise.scenarios.reduce(function (sum, item) { return sum + (item === scenario ? 0 : item.durationMs); }, 0) + applied.scenario.durationMs > 120000) { throw new Error('모든 예시의 실행 시간 합계는 120초 이하여야 해요.'); }
          if (self.exercise.scenarios.some(function (item) { if (item === scenario) { return false; } var missing = applied.connections.filter(function (entry) { return entry.direction === 'input' && !item.inputs.some(function (input) { return input.pin === entry.pin && input.atMs === 0; }); }).length; return item.inputs.length + missing > 256; })) { throw new Error('다른 예시에 새 핀의 처음 값을 넣을 공간이 없어요. 그 예시의 입력을 먼저 줄여 주세요.'); }
          if (existing) { Object.keys(scenario).forEach(function (key) { delete scenario[key]; }); Object.assign(scenario, applied.scenario); } else { self.exercise.scenarios.push(applied.scenario); }
          self.exercise.scenarios.forEach(function (item) { applied.connections.filter(function (entry) { return entry.direction === 'input'; }).forEach(function (entry) { if (!item.inputs.some(function (input) { return input.pin === entry.pin && input.atMs === 0; })) { item.inputs.push({ atMs: 0, pin: entry.pin, signal: entry.signal, value: 0 }); } }); item.inputs.sort(function (a, b) { return a.atMs - b.atMs || a.pin - b.pin; }); });
          self.exercise.connections = applied.connections; self.dirty = true; close(); self.renderConnections(); self.renderScenarios(); self.message('단계를 적용했어요. 그림에서 같이 시작하는 동작과 화살표로 이어지는 동작을 확인하세요.');
        } catch (problem) { fail(problem.message); }
      }, true)); dialog.append(footer); if (title.focus) { title.focus(); }
    }
    render(); if (dialog.showModal) { dialog.showModal(); } else { dialog.setAttribute('open', ''); dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true'); }
  };
  App.prototype.flowValue = function (connection, value) { return connection.signal === 'digital' ? connection.direction === 'input' && connection.device === 'button' ? value ? '누름' : '누르지 않음' : value ? '켜짐' : '꺼짐' : String(value) + (connection.signal === 'servo' ? '도' : connection.signal === 'servo_pulse' ? 'µs' : ''); };
  App.prototype.appendFlowLanes = function (parent, scenario, group, editable) {
    var self = this, lanes = el('div', 'ojmicrobit-flow-lanes'), count = new Set(group.assertions.concat(group.outputSequences).map(function (entry) { return entry.pin; })).size + (group.i2cAssertions.length ? 1 : 0);
    if (count > 1) { lanes.classList.add('is-parallel'); lanes.setAttribute('data-mb-parallel', ''); var together = el('p', 'ojmicrobit-flow-mode', self.config.compactEditor ? '아래 ' + count + '개 핀이 함께 시작해요' : '∥ 병렬 · 같은 시점부터 각 핀을 따로 확인'); together.setAttribute('data-mb-parallel-caption', ''); parent.append(together); }
    function pinConnection(pin, signal, direction) { return self.exercise.connections.find(function (entry) { return entry.pin === pin; }) || { pin: pin, label: '핀', signal: signal, direction: direction }; }
    function lane(pin, signal, steps, targetKind, sourceIndex) {
      var connection = pinConnection(pin, signal, 'output'), box = el('div', 'ojmicrobit-flow-lane'); box.setAttribute('data-mb-action-count', String(steps.length)); var name = el('div', 'ojmicrobit-flow-lane-name'); name.append(el('span', '', connection.label), el('small', '', 'P' + pin)); box.append(name);
      if (steps.length > 1) { box.setAttribute('data-mb-serial', ''); box.append(el('span', 'ojmicrobit-flow-mode', '직렬 · 앞 동작 유지 완료 후')); }
      var actions = el('div', 'ojmicrobit-flow-actions'); steps.forEach(function (entry, index) {
        if (index) { actions.append(el('span', 'ojmicrobit-flow-arrow', '→')); }
        var action = el('div', 'ojmicrobit-flow-action' + (signal === 'digital' && entry.expected === 1 ? ' is-on' : '')); action.append(el('strong', '', signal === 'pwm' ? '세기 ' + entry.expected : self.flowValue(connection, entry.expected)), el('span', '', entry.holdMs / 1000 + '초 유지'), el('small', 'ojmicrobit-muted', entry.withinMs / 1000 + '초 안에 이 값으로'));
        if (editable && !self.config.compactEditor) { action.append(button('수정', function () { self.openStageWizard(scenario, { mode: 'edit', atMs: group.atMs, pin: pin, value: entry.expected, holdMs: entry.holdMs, withinMs: entry.withinMs, tolerance: entry.tolerance, target: { kind: targetKind, index: sourceIndex, actionIndex: index } }); })); } actions.append(action);
      });
      if (editable && !self.config.compactEditor) { actions.append(button('→ 다음 값', function () { var last = steps.at(-1); self.openStageWizard(scenario, { mode: 'serial', atMs: group.atMs, pin: pin, value: signal === 'digital' ? 1 - last.expected : last.expected }); })); }
      box.append(actions); lanes.append(box);
    }
    group.assertions.map(function (entry) { return { entry: entry, kind: 'assertions' }; }).concat(group.outputSequences.map(function (entry) { return { entry: entry, kind: 'outputSequences' }; })).sort(function (a, b) { return a.entry.pin - b.entry.pin; }).forEach(function (item) { var entry = item.entry; lane(entry.pin, entry.signal, item.kind === 'assertions' ? [entry] : entry.steps, item.kind, scenario[item.kind].indexOf(entry)); });
    if (group.i2cAssertions.length) { var bus = el('div', 'ojmicrobit-flow-lane'); bus.append(el('strong', '', 'I²C · 직렬 버스 통신')); var actions = el('div', 'ojmicrobit-flow-actions'); group.i2cAssertions.forEach(function (entry, index) { if (index) { actions.append(el('span', 'ojmicrobit-flow-arrow', '→')); } actions.append(el('span', 'ojmicrobit-flow-action', entry.operation === 'read' ? '읽기 [' + bytesText(entry.data) + ']' : '보내기 [' + bytesText(entry.data) + ']')); }); bus.append(actions); lanes.append(bus); }
    if (!count) { lanes.append(el('p', 'ojmicrobit-muted', editable ? '입력만 정한 단계예요. 출력 동작을 추가해 보세요.' : '이 단계에서는 입력값만 바꿔요.')); } parent.append(lanes);
    if (editable && !self.config.compactEditor) { parent.append(button('+ 함께 시작할 핀 추가', function () { self.openStageBatchWizard(scenario, { atMs: group.atMs }); })); }
  };
  App.prototype.appendFocusInputs = function (parent, group, readOnly) {
    var self = this, inputs = el('div', 'ojmicrobit-focus-inputs');
    group.inputs.forEach(function (entry) {
      var connection = self.exercise.connections.find(function (item) { return item.pin === entry.pin; }) || { label: '센서', signal: entry.signal, direction: 'input' }, row = el('div', 'ojmicrobit-focus-input-row'), name = el('span', 'ojmicrobit-focus-input-name');
      name.append(el('span', '', connection.label + (readOnly ? ' 값이' : '')), el('small', '', 'P' + entry.pin)); if (readOnly) { row.classList.add('is-readonly-input'); } row.append(el('span', 'ojmicrobit-focus-chip', '입력'), name, el('strong', 'ojmicrobit-focus-input-value', self.flowValue(connection, entry.value))); if (readOnly) { row.append(el('span', '', '일 때')); } inputs.append(row);
    });
    group.i2cInputs.forEach(function (entry) { var row = el('div', 'ojmicrobit-focus-input-row'); row.append(el('span', 'ojmicrobit-focus-chip', 'I²C'), el('span', '', addressText(entry.address) + ' · 레지스터 ' + entry.register), el('strong', 'ojmicrobit-focus-input-value', entry.value)); inputs.append(row); });
    if (inputs.childNodes.length) { parent.append(inputs); }
  };
  App.prototype.appendStageFlow = function (parent, scenario) {
    var self = this, groups = flowGroups(scenario), list = el('ol', 'ojmicrobit-stage-flow');
    groups.forEach(function (group, index) {
      var card = el('li', 'ojmicrobit-story-step'); card.setAttribute('data-mb-flow-stage', String(index)); var stageHead = el('div', 'ojmicrobit-stage-heading'), editStage = button('수정', function () { self.openStageBatchWizard(scenario, { atMs: group.atMs }); }); editStage.setAttribute('aria-label', '단계 수정'); editStage.setAttribute('data-mb-stage-edit', String(group.atMs)); stageHead.append(el('h3', 'ojmicrobit-story-heading', '상황 ' + (self.exercise.scenarios.indexOf(scenario) + 1) + (groups.length > 1 ? ' · ' + (index + 1) + '단계' : '') + ' · ' + (group.atMs ? '시작 후 ' + group.atMs / 1000 + '초' : '시작할 때')), editStage); card.append(stageHead);
      self.appendFocusInputs(card, group); self.appendFlowLanes(card, scenario, group, true); list.append(card);
    });
    if (!groups.length) { parent.append(el('p', 'ojmicrobit-muted', '단계를 먼저 만들고 핀·값·시간을 차례로 정해 보세요.')); }
    parent.append(list); var add = button(groups.length ? '+ 다음 입력 단계 만들기' : '+ 첫 단계 만들기', function () { self.openStageBatchWizard(scenario); }, !groups.length); add.setAttribute('data-mb-stage-new', ''); add.classList.add('ojmicrobit-focus-add'); parent.append(add);
  };
  App.prototype.replaceScenario = function (scenario, changed) { Object.keys(scenario).forEach(function (key) { delete scenario[key]; }); Object.assign(scenario, changed); this.dirty = true; this.renderScenarios(); };
  App.prototype.sequenceControls = function (scenario, sequence, advanced, updateSummary) {
    var self = this, sequenceIndex = scenario.outputSequences.indexOf(sequence), fieldBase = 'scenarios.' + this.exercise.scenarios.indexOf(scenario) + '.outputSequences.' + sequenceIndex, box = el('section', 'ojmicrobit-output-sequence'); box.setAttribute('data-mb-output-sequence', String(sequenceIndex));
    function apply(operation) { try { self.replaceScenario(scenario, updateOutputSequence(scenario, sequenceIndex, operation, !advanced)); } catch (error) { self.message(error.message, true); return false; } return true; }
    function timeControl(value, change) { var control = boundedInput(value / 1000, 0.001, 30); control.step = '0.001'; control.addEventListener('change', function () { if (!change(Math.round(Number(control.value) * 1000))) { control.value = value / 1000; } }); return control; }
    var options = this.exercise.connections.filter(function (connection) { return connection.direction === 'output'; }).map(function (connection) { return [String(connection.pin), 'P' + connection.pin + ' · ' + connection.label]; }), title = el('div', 'ojmicrobit-sequence-heading');
    title.append(field('이어서 바꿀 출력 핀', select(sequence.pin, options, function (value) { apply(function (sequences) { var item = sequences[sequenceIndex], connection = self.exercise.connections.find(function (entry) { return entry.pin === Number(value); }); item.pin = connection.pin; item.signal = connection.signal; item.steps.forEach(function (step) { step.expected = connection.signal === 'servo_pulse' ? 1000 : connection.signal === 'servo' ? 90 : 0; step.tolerance = 0; }); }); })), button('출력 순서 삭제', function () { apply(function (sequences) { sequences.splice(sequenceIndex, 1); }); })); box.append(title);
    markField(title.querySelector('select'), fieldBase + '.pin');
    if (advanced) { var start = markField(boundedInput(sequence.afterMs, 0, 30000), fieldBase + '.afterMs'); start.addEventListener('change', function () { if (!apply(function (sequences) { sequences[sequenceIndex].afterMs = Number(start.value); })) { start.value = sequence.afterMs; } }); box.append(field('첫 동작 기준 시각(ms)', start)); }
    sequence.steps.forEach(function (step, index) {
      var row = el('div', 'ojmicrobit-sequence-action'); row.setAttribute('data-mb-sequence-action', String(index));
      var value = sequence.signal === 'digital' ? select(step.expected, [['0', '꺼짐 · 0'], ['1', '켜짐 · 1']], function (raw) { step.expected = Number(raw); self.dirty = true; if (updateSummary) { updateSummary(); } }) : boundedInput(step.expected, sequence.signal === 'servo_pulse' ? 1 : 0, sequence.signal === 'servo' ? 180 : sequence.signal === 'servo_pulse' ? 20000 : 1023, function (raw) { step.expected = raw; self.dirty = true; if (updateSummary) { updateSummary(); } });
      var main = el('div', 'ojmicrobit-sequence-main'); main.append(el('strong', 'ojmicrobit-sequence-number', String(index + 1)), field('출력 상태' + (sequence.signal === 'servo' ? '(도)' : sequence.signal === 'servo_pulse' ? '(µs)' : ''), markField(value, fieldBase + '.steps.' + index + '.expected')), field('이 상태를 유지(초)', markField(timeControl(step.holdMs, function (value) { return apply(function (sequences) { sequences[sequenceIndex].steps[index].holdMs = value; }); }), fieldBase + '.steps.' + index + '.holdMs'))); row.append(main);
      var timing = el('details', 'ojmicrobit-sequence-time'), timingKey = scenario.id + ':' + sequenceIndex + ':' + index; timing.setAttribute('data-mb-sequence-details', timingKey); timing.open = Boolean(this.sequenceDetails && this.sequenceDetails.has(timingKey)); timing.append(el('summary', '', '반응 대기·허용 오차'));
      var fields = el('div', 'ojmicrobit-fields'); fields.append(field('이 상태가 되기까지 대기(초)', timeControl(step.withinMs, function (value) { return apply(function (sequences) { sequences[sequenceIndex].steps[index].withinMs = value; }); })), field('허용 오차', boundedInput(step.tolerance, 0, sequence.signal === 'digital' ? 0 : sequence.signal === 'servo' ? 180 : sequence.signal === 'servo_pulse' ? 20000 : 1023, function (value) { step.tolerance = value; self.dirty = true; }))); timing.append(fields); row.append(timing);
      Array.from(fields.querySelectorAll('input')).forEach(function (control, fieldIndex) { markField(control, fieldBase + '.steps.' + index + '.' + (fieldIndex === 0 ? 'withinMs' : 'tolerance')); });
      var actions = el('div', 'ojmicrobit-condition-actions');
      if (index > 0) { actions.append(button('동작 위로', function () { apply(function (sequences) { var steps = sequences[sequenceIndex].steps; steps.splice(index - 1, 0, steps.splice(index, 1)[0]); }); })); }
      if (index < sequence.steps.length - 1) { actions.append(button('동작 아래로', function () { apply(function (sequences) { var steps = sequences[sequenceIndex].steps; steps.splice(index + 1, 0, steps.splice(index, 1)[0]); }); })); }
      if (sequence.steps.length > 1) { actions.append(button('동작 삭제', function () { apply(function (sequences) { sequences[sequenceIndex].steps.splice(index, 1); }); })); } row.append(actions); box.append(row);
      if (index < sequence.steps.length - 1) { box.append(el('div', 'ojmicrobit-sequence-arrow', '↓ 그다음')); }
    }, this);
    box.append(button('다음 출력 추가', function () { apply(function (sequences) { var item = sequences[sequenceIndex], last = item.steps.at(-1); item.steps.push({ expected: item.signal === 'digital' ? 1 - last.expected : last.expected, withinMs: 500, holdMs: 100, tolerance: last.tolerance }); }); }), el('p', 'ojmicrobit-muted', '기대 출력이 실제로 나타난 순간부터 유지 시간을 잽니다. 다음 동작은 앞 상태의 유지가 끝난 순간부터 반응을 기다립니다.')); return box;
  };
  App.prototype.stepSummary = function (step) {
    var exercise = this.exercise;
    function pinLabel(pin) { var connection = exercise.connections.find(function (item) { return item.pin === pin; }); return 'P' + pin + ' ' + (connection ? connection.label : '핀'); }
    function deviceLabel(address, register) { var device = exercise.i2cDevices.find(function (item) { return item.address === address; }); return (device ? device.label : addressText(address)) + ' 레지스터 ' + register; }
    function signalValue(signal, value) { return signal === 'digital' ? value === 1 ? '켜짐' : '꺼짐' : String(value) + (signal === 'servo' ? '°' : signal === 'servo_pulse' ? 'µs' : ''); }
    var before = step.inputs.map(function (entry) { return pinLabel(entry.pin) + ' ' + signalValue(entry.signal, entry.value); }).concat(step.i2cInputs.map(function (entry) { return deviceLabel(entry.address, entry.register) + ' 입력 ' + entry.value; }));
    var after = step.assertions.map(function (entry) { return pinLabel(entry.pin) + ' ' + signalValue(entry.signal, entry.expected); }).concat((step.outputSequences || []).map(function (sequence) { return pinLabel(sequence.pin) + ' ' + sequence.steps.map(function (entry) { return signalValue(sequence.signal, entry.expected) + ' ' + entry.holdMs / 1000 + '초 유지'; }).join(' → '); })).concat((step.i2cActions || []).map(function (entry) { return deviceLabel(entry.address, entry.register) + (entry.operation === 'read' ? ' 읽기값 ' : '에 쓰기 ') + entry.expected; }));
    return (before.length ? before.join(', ') : '현재 입력 유지') + ' → ' + (after.length ? after.join(', ') : '기대 출력을 추가하세요');
  };
  App.prototype.stepCard = function (scenario, step, stepIndex, count, openTimes) {
    var self = this, card = el('details', 'ojmicrobit-step-card'), header = el('div', 'ojmicrobit-step-heading'), summary = el('p', 'ojmicrobit-step-summary', this.stepSummary(step));
    summary.setAttribute('aria-live', 'polite'); card.setAttribute('data-mb-step', String(stepIndex)); card.setAttribute('data-mb-step-key', scenario.id + ':' + stepIndex); card.open = Boolean(this.openStepCards && this.openStepCards.has(scenario.id + ':' + stepIndex));
    header.append(el('strong', '', (stepIndex + 1) + '단계 · ' + (stepIndex === 0 ? '처음' : step.atMs / 1000 + '초 후')), el('span', 'ojmicrobit-muted', '눌러서 수정')); var heading = el('summary'); heading.append(header, summary); card.append(heading);
    function updateSummary() { var current = projectScenarioSteps(scenario); if (current.simple && current.steps[stepIndex]) { summary.textContent = self.stepSummary(current.steps[stepIndex]); } self.dirty = true; }
    function mutate(operation) { try { operation(); self.dirty = true; self.renderScenarios(); } catch (error) { self.message(error.message, true); } }
    function channelOptions(direction) { return self.exercise.connections.filter(function (connection) { return connection.direction === direction; }).map(function (connection) { return [String(connection.pin), 'P' + connection.pin + ' · ' + connection.label]; }); }
    function registerOptions(writableOnly) { var options = []; self.exercise.i2cDevices.forEach(function (device) { device.registers.filter(function (register) { return !writableOnly || register.writable; }).forEach(function (register) { options.push([device.address + ':' + register.register, device.label + ' · 레지스터 ' + register.register + ' (' + addressText(device.address) + ')']); }); }); return options; }
    function valueControl(signal, value, callback) { if (signal === 'digital') { return select(value, [['0', '꺼짐 · 0'], ['1', '켜짐 · 1']], function (raw) { callback(Number(raw)); }); } return boundedInput(value, signal === 'servo_pulse' ? 1 : 0, signal === 'servo' ? 180 : signal === 'servo_pulse' ? 20000 : 1023, callback); }
    var flow = el('div', 'ojmicrobit-step-flow'), before = el('div', 'ojmicrobit-step-side'), after = el('div', 'ojmicrobit-step-side'); before.append(el('h5', '', '입력을 이렇게 설정하면')); after.append(el('h5', '', '기대 출력은 이렇게'));
    step.inputs.forEach(function (entry) { var row = el('div', 'ojmicrobit-condition');
      row.append(field('입력 장치', select(entry.pin, channelOptions('input'), function (value) { var connection = self.exercise.connections.find(function (item) { return item.pin === Number(value); }); entry.pin = connection.pin; entry.signal = connection.signal; entry.value = connection.signal === 'digital' ? entry.value ? 1 : 0 : entry.value; self.dirty = true; self.renderScenarios(); })), field('입력값', valueControl(entry.signal, entry.value, function (value) { entry.value = value; updateSummary(); })));
      Array.from(row.querySelectorAll('input,select')).forEach(function (control, index) { markField(control, 'scenarios.' + self.exercise.scenarios.indexOf(scenario) + '.inputs.' + scenario.inputs.indexOf(entry) + '.' + (index === 0 ? 'pin' : 'value')); });
      if (stepIndex > 0) { row.append(button('입력 조건 삭제', function () { mutate(function () { scenario.inputs.splice(scenario.inputs.indexOf(entry), 1); }); })); } before.append(row);
    });
    step.i2cInputs.forEach(function (entry) { var row = el('div', 'ojmicrobit-condition'); row.append(field('I²C 센서 레지스터', select(entry.address + ':' + entry.register, registerOptions(false), function (value) { var parts = value.split(':').map(Number); entry.address = parts[0]; entry.register = parts[1]; updateSummary(); })), field('입력값', boundedInput(entry.value, 0, 255, function (value) { entry.value = value; updateSummary(); })), button('레지스터 입력 삭제', function () { mutate(function () { scenario.i2cInputs.splice(scenario.i2cInputs.indexOf(entry), 1); }); })); before.append(row); });
    if (!step.inputs.length && !step.i2cInputs.length) { before.append(el('p', 'ojmicrobit-muted', stepIndex === 0 ? '연결한 장치의 초기 입력값을 사용합니다.' : '앞 단계의 입력값을 그대로 유지합니다.')); }
    var inputActions = el('div', 'ojmicrobit-condition-actions'); inputActions.append(button('핀 입력 추가', function () { mutate(function () { var connection = self.exercise.connections.find(function (item) { return item.direction === 'input' && !step.inputs.some(function (entry) { return entry.pin === item.pin; }); }); if (!connection) { throw new Error('이 단계의 입력 핀을 모두 설정했습니다. 장치 연결에서 입력 핀을 추가할 수 있습니다.'); } var held = 0; scenario.inputs.filter(function (entry) { return entry.pin === connection.pin && entry.atMs <= step.atMs; }).forEach(function (entry) { held = entry.value; }); scenario.inputs.push({ atMs: step.atMs, pin: connection.pin, signal: connection.signal, value: held }); scenario.inputs.sort(function (a, b) { return a.atMs - b.atMs || a.pin - b.pin; }); }); }));
    if (this.exercise.i2cDevices.length) { inputActions.append(button('I²C 센서 입력 추가', function () { mutate(function () { var options = registerOptions(false).filter(function (option) { return !step.i2cInputs.some(function (entry) { return entry.address + ':' + entry.register === option[0]; }); }); if (!options.length) { throw new Error('등록한 레지스터의 입력값을 모두 설정했습니다.'); } var parts = options[0][0].split(':').map(Number), device = self.exercise.i2cDevices.find(function (item) { return item.address === parts[0]; }), register = device.registers.find(function (item) { return item.register === parts[1]; }); scenario.i2cInputs.push({ atMs: step.atMs, address: parts[0], register: parts[1], value: register.value }); scenario.i2cInputs.sort(function (a, b) { return a.atMs - b.atMs || a.address - b.address || a.register - b.register; }); }); })); } before.append(inputActions);
    step.assertions.forEach(function (entry) { var row = el('div', 'ojmicrobit-condition');
      row.append(field('출력 장치', select(entry.pin, channelOptions('output'), function (value) { var connection = self.exercise.connections.find(function (item) { return item.pin === Number(value); }); entry.pin = connection.pin; entry.signal = connection.signal; entry.expected = connection.signal === 'servo_pulse' ? 1000 : connection.signal === 'servo' ? 90 : 0; self.dirty = true; self.renderScenarios(); })), field('기대 출력값', valueControl(entry.signal, entry.expected, function (value) { entry.expected = value; updateSummary(); })), button('이어서 바꿀 동작 만들기', function () { mutate(function () { scenario.outputSequences.push({ pin: entry.pin, signal: entry.signal, afterMs: entry.afterMs, steps: [{ expected: entry.expected, withinMs: entry.withinMs, holdMs: entry.holdMs, tolerance: entry.tolerance }] }); scenario.assertions.splice(scenario.assertions.indexOf(entry), 1); }); }), button('기대 출력 삭제', function () { mutate(function () { scenario.assertions.splice(scenario.assertions.indexOf(entry), 1); }); })); after.append(row);
      Array.from(row.querySelectorAll('input,select')).forEach(function (control, index) { markField(control, 'scenarios.' + self.exercise.scenarios.indexOf(scenario) + '.assertions.' + scenario.assertions.indexOf(entry) + '.' + (index === 0 ? 'pin' : 'expected')); });
    });
    step.outputSequences.forEach(function (sequence) { after.append(self.sequenceControls(scenario, sequence, false, updateSummary)); });
    step.i2cActions.forEach(function (action, actionIndex) { var row = el('div', 'ojmicrobit-condition'); row.append(el('strong', 'ojmicrobit-muted', '거래 ' + (actionIndex + 1) + ' · ' + (action.operation === 'read' ? '읽기' : '쓰기')),
      field('I²C ' + (action.operation === 'read' ? '읽을' : '쓸') + ' 레지스터', select(action.address + ':' + action.register, registerOptions(action.operation === 'write'), function (value) { var parts = value.split(':').map(Number); action.records.forEach(function (entry) { entry.address = parts[0]; }); action.records[0].data[0] = parts[1]; updateSummary(); })), field(action.operation === 'read' ? '기대 읽기값' : '기대 쓰기값', boundedInput(action.expected, 0, 255, function (value) { var entry = action.records.at(-1); entry.data[action.operation === 'read' ? 0 : 1] = value; updateSummary(); })), button('기대 거래 삭제', function () { mutate(function () { scenario.i2cAssertions = scenario.i2cAssertions.filter(function (entry) { return !action.records.includes(entry); }); }); })); after.append(row);
    });
    function appendBus(operation) { mutate(function () { var options = registerOptions(operation === 'write'); if (!options.length) { throw new Error(operation === 'write' ? '장치 설정에서 쓰기를 허용한 레지스터가 필요합니다.' : '먼저 I²C 장치와 레지스터를 등록하세요.'); } var parts = options[0][0].split(':').map(Number), device = self.exercise.i2cDevices.find(function (item) { return item.address === parts[0]; }), register = device.registers.find(function (item) { return item.register === parts[1]; }), records = operation === 'read' ? [{ address: parts[0], operation: 'write', data: [parts[1]], repeated: true, afterMs: step.atMs, withinMs: step.withinMs }, { address: parts[0], operation: 'read', data: [register.value], repeated: false, afterMs: step.atMs, withinMs: step.withinMs }] : [{ address: parts[0], operation: 'write', data: [parts[1], register.value], repeated: false, afterMs: step.atMs, withinMs: step.withinMs }];
      var insert = scenario.i2cAssertions.findIndex(function (entry) { return entry.afterMs > step.atMs; }); scenario.i2cAssertions.splice.apply(scenario.i2cAssertions, [insert < 0 ? scenario.i2cAssertions.length : insert, 0].concat(records)); }); }
    var outputActions = el('div', 'ojmicrobit-condition-actions'); outputActions.append(button('핀 기대 출력 추가', function () { mutate(function () { var connection = self.exercise.connections.find(function (item) { return item.direction === 'output' && !step.assertions.concat(step.outputSequences).some(function (entry) { return entry.pin === item.pin; }); }); if (!connection) { throw new Error('이 단계의 출력 핀을 모두 설정했습니다.'); } scenario.assertions.push({ pin: connection.pin, signal: connection.signal, expected: connection.signal === 'servo' ? 90 : connection.signal === 'servo_pulse' ? 1000 : 0, afterMs: step.atMs, withinMs: step.withinMs, holdMs: step.holdMs, tolerance: 0 }); scenario.assertions.sort(function (a, b) { return a.afterMs - b.afterMs; }); }); }));
    if (this.exercise.i2cDevices.length) { outputActions.append(button('I²C 기대 읽기 추가', function () { appendBus('read'); }), button('I²C 기대 쓰기 추가', function () { appendBus('write'); })); } after.append(outputActions); flow.append(before, el('div', 'ojmicrobit-step-arrow', '→'), after); card.append(flow);
    var timing = el('details', 'ojmicrobit-step-time'), timingKey = scenario.id + ':' + stepIndex; timing.setAttribute('data-mb-time', timingKey); timing.open = openTimes.has(timingKey); timing.append(el('summary', '', '시간 조건 · ' + step.lengthMs / 1000 + '초 동안 이 단계 실행'));
    var timingFields = el('div', 'ojmicrobit-fields');
    function seconds(label, current, key) { var control = boundedInput(current / 1000, 0.001, 30); control.step = '0.001'; control.setAttribute('data-mb-time-field', key); control.addEventListener('change', function () { var changes = {}; changes[key] = Math.round(Number(control.value) * 1000); try { self.replaceScenario(scenario, retimeScenarioStep(scenario, stepIndex, changes)); var reopened = Array.from(self.content.querySelectorAll('[data-mb-time]')).find(function (node) { return node.getAttribute('data-mb-time') === timingKey; }), replacement = reopened && reopened.querySelector('[data-mb-time-field="' + key + '"]'); if (replacement) { replacement.focus(); } } catch (error) { control.value = current / 1000; self.message(error.message, true); } }); return field(label, control); }
    timingFields.append(seconds('단계 길이(초)', step.lengthMs, 'lengthMs'));
    if (step.assertions.length || step.i2cAssertions.length) { timingFields.append(seconds('반응 대기 한도(초)', step.withinMs, 'withinMs')); }
    if (step.assertions.length) { timingFields.append(seconds('이 출력 상태를 유지할 시간(초)', step.holdMs, 'holdMs')); }
    timing.append(timingFields, el('p', 'ojmicrobit-muted', step.assertions.length ? '입력 후 ' + step.withinMs / 1000 + '초 안에 기대 출력이 되고, 그 뒤 ' + step.holdMs / 1000 + '초 동안 유지하면 통과합니다.' : step.outputSequences.length ? '출력 동작의 대기·유지 시간은 위 순서에서 각각 설정합니다. 뒤 입력 단계와 겹치면 단계 길이를 먼저 늘리세요.' : step.i2cAssertions.length ? 'I²C 기대 거래는 입력 후 반응 대기 한도 안에서 순서대로 검사합니다.' : '입력값을 바꾼 뒤 다음 단계로 이어집니다. 이 단계에는 출력 검사가 없습니다.')); card.append(timing);
    var controls = el('div', 'ojmicrobit-toolbar'); controls.append(button('단계 복제', function () { try { self.replaceScenario(scenario, duplicateScenarioStep(scenario, stepIndex)); } catch (error) { self.message(error.message, true); } }));
    if (count > 1) { controls.append(button('단계 삭제', function () { try { self.replaceScenario(scenario, removeScenarioStep(scenario, stepIndex, self.exercise.connections)); } catch (error) { self.message(error.message, true); } })); } card.append(controls); return card;
  };
  App.prototype.renderScenarios = function () {
    var self = this, previous = this.content.querySelector('[data-mb-scenarios]'), openAdvanced = new Set(), openTimes = new Set();
    this.sequenceDetails = new Set(); this.openStepCards = new Set(); if (previous) { previous.querySelectorAll('[data-mb-step-key]').forEach(function (node) { if (node.open) { self.openStepCards.add(node.getAttribute('data-mb-step-key')); } }); }
    this.closedAdvanced = this.closedAdvanced || new Set();
    if (previous) { previous.querySelectorAll('details[data-mb-advanced]').forEach(function (node) { if (node.open) { openAdvanced.add(node.getAttribute('data-mb-advanced')); } }); previous.querySelectorAll('details[data-mb-time]').forEach(function (node) { if (node.open) { openTimes.add(node.getAttribute('data-mb-time')); } }); previous.querySelectorAll('details[data-mb-sequence-details]').forEach(function (node) { if (node.open) { self.sequenceDetails.add(node.getAttribute('data-mb-sequence-details')); } }); }
    var management = el('details', 'ojmicrobit-focus-management'); management.append(el('summary', '', '예시 관리 · 이름·공개·복제·삭제'));
    var box = section('입력과 기대 동작', '단계를 만들고 → 핀을 고르고 → 값을 정하고 → 시간을 설정하세요. 병렬은 같은 시점의 핀, 직렬은 한 핀에서 이어지는 값이에요.'); box.setAttribute('data-mb-scenarios', ''); markField(box, 'scenarios'); box.tabIndex = -1;
    var publicGroup, hiddenGroup; if (this.config.compactEditor) {
      var tabs = el('div', 'ojmicrobit-condition-tabs'); tabs.setAttribute('role', 'group'); tabs.setAttribute('aria-label', '조건 공개 범위');
      [['public', '공개 예시', true], ['hidden', '비공개 채점', false]].forEach(function (entry) { var tab = button(entry[1] + ' ' + self.exercise.scenarios.filter(function (item) { return item.visible === entry[2]; }).length, function () { self.showCaseGroup(entry[2]); }); tab.setAttribute('data-mb-case-tab', entry[0]); tabs.append(tab); }); box.append(tabs);
      publicGroup = el('section', 'ojmicrobit-case-group'); publicGroup.setAttribute('data-mb-case-group', 'public'); hiddenGroup = el('section', 'ojmicrobit-case-group'); hiddenGroup.setAttribute('data-mb-case-group', 'hidden');
      publicGroup.append(el('p', 'ojmicrobit-muted', '학생에게 보여줄 예시예요. 제출 시 채점에도 사용해요.')); hiddenGroup.append(el('p', 'ojmicrobit-muted', '학생에게 숨기고 서버에서만 검사해요.'));
      var addPublic = button('+ 공개 예시 추가', function () { self.openStageBatchWizard(null, { visible: true }); }), addHidden = button('+ 비공개 채점 조건 추가', function () { self.openStageBatchWizard(null, { visible: false }); }, true); addPublic.setAttribute('data-mb-add-public', ''); addHidden.setAttribute('data-mb-add-hidden', ''); addPublic.disabled = addHidden.disabled = this.exercise.scenarios.length >= 20; publicGroup.append(addPublic); hiddenGroup.append(addHidden); box.append(publicGroup, hiddenGroup);
    }
    this.exercise.scenarios.forEach(function (scenario, index) {
      var card = el('section', 'ojmicrobit-example'), heading = el('div', 'ojmicrobit-example-heading'); heading.append(el('h3', '', '예시 ' + (index + 1)), el('span', 'ojp-status-badge ojp-status-badge--info', '처음부터 실행')); card.append(heading); if (self.config.compactEditor) { heading.replaceChildren(el('h3', '', scenario.name), el('span', 'ojmicrobit-focus-chip', scenario.visible ? '학생에게 공개' : '학생에게 비공개')); var toggleVisibility = button(scenario.visible ? '비공개 채점으로 전환' : '공개 예시로 전환', function () { scenario.visible = !scenario.visible; self.caseVisibility = scenario.visible; self.dirty = true; self.renderScenarios(); }); toggleVisibility.setAttribute('data-mb-case-visibility', scenario.id); heading.append(toggleVisibility); card.setAttribute('data-mb-case-id', scenario.id); }
      var options = el('details', 'ojmicrobit-example-options'); options.append(el('summary', '', '예시 이름·공개 설정')); var fields = el('div', 'ojmicrobit-fields'); fields.append(field('예시 이름', markField(input(scenario.name, 'text', function (value) { scenario.name = value; self.dirty = true; }), 'scenarios.' + index + '.name')), field('학생에게 이 예시 공개', markField(checkInput(scenario.visible, function (value) { scenario.visible = value; self.caseVisibility = scenario.visible; self.dirty = true; if (self.config.compactEditor) { self.renderScenarios(); } }), 'scenarios.' + index + '.visible'))); options.append(fields); card.append(options);
      var projection = projectScenarioSteps(scenario);
      if (self.config.compactEditor) { self.appendStageFlow(card, scenario); }
      else if (projection.simple) { projection.steps.forEach(function (step, stepIndex) { card.append(self.stepCard(scenario, step, stepIndex, projection.steps.length, openTimes)); });
        card.append(button('이어서 바꾸기 · 다음 단계', function () { try { self.replaceScenario(scenario, appendScenarioStep(scenario, self.exercise)); } catch (error) { self.message(error.message, true); } }));
      } else { self.appendScenarioStory(card, scenario); card.append(el('p', 'ojmicrobit-muted', '세밀한 조건이 있는 예시예요. 수정하려면 아래 세부 설정을 여세요.')); }
      var advanced = el('details', 'ojmicrobit-advanced'); advanced.setAttribute('data-mb-advanced', scenario.id); advanced.open = openAdvanced.has(scenario.id); advanced.append(el('summary', '', self.config.compactEditor ? '시간·허용 오차 자세히' : '세부 채점 설정 (선택)')); if (advanced.open) { self.fillAdvancedScenario(advanced, scenario); }
      advanced.addEventListener('toggle', function () { if (!advanced.isConnected) { return; } if (advanced.open) { self.closedAdvanced.delete(scenario.id); self.fillAdvancedScenario(advanced, scenario); } else { self.closedAdvanced.add(scenario.id); self.renderScenarios(); var replacement = Array.from(self.content.querySelectorAll('[data-mb-advanced]')).find(function (node) { return node.getAttribute('data-mb-advanced') === scenario.id; }); if (replacement) { replacement.querySelector('summary').focus(); } } }); var stage = self.config.compactEditor && card.querySelector('[data-mb-flow-stage]'); if (stage) { stage.append(advanced); } else { card.append(advanced); }
      var actions = el('div', 'ojmicrobit-toolbar'); actions.append(button('예시 복제 · 처음부터 검사', function () { var copied = clone(scenario); copied.id = 'case-' + uid().slice(0, 12); copied.name += ' 복사본'; self.exercise.scenarios.splice(index + 1, 0, copied); self.dirty = true; self.renderScenarios(); }), button('예시 삭제', function () { self.exercise.scenarios.splice(index, 1); self.dirty = true; self.renderScenarios(); })); if (self.config.compactEditor) { options.remove(); var visibility = heading.querySelector('[data-mb-case-visibility]'); if (visibility) { options.append(visibility); } if (self.exercise.scenarios.length > 1) { management.append(el('h3', '', '예시 ' + (index + 1))); } management.append(options, actions); } else { card.append(actions); } if (self.config.compactEditor) { (scenario.visible ? publicGroup : hiddenGroup).append(card); } else { box.append(card); }
    });
    var addExample = button(this.exercise.scenarios.length ? '+ 별도 예시 만들기 · 처음부터 실행' : '+ 첫 단계 만들기', function () { if (self.config.compactEditor) { self.openStageBatchWizard(null); } else { self.exercise.scenarios.push(newScenario(self.exercise, self.exercise.scenarios.length + 1)); self.dirty = true; self.renderScenarios(); } }, !this.exercise.scenarios.length); addExample.setAttribute('data-mb-stage-new', ''); if (this.config.compactEditor) { var deviceSummary = el('div', 'ojmicrobit-focus-device-summary'); deviceSummary.setAttribute('data-mb-focus-device-summary', ''); deviceSummary.append(el('span', '', '연결할 장치')); this.exercise.connections.forEach(function (item) { var itemLabel = el('span', 'ojmicrobit-connected-device'); itemLabel.append(hardwarePicture(item.device), el('span', '', 'P' + item.pin + ' ' + item.label)); deviceSummary.append(itemLabel); }); var configure = button('연결 변경', function () { var settings = self.content.querySelector('.ojmicrobit-focus-devices'); if (settings) { settings.open = !settings.open; configure.setAttribute('aria-expanded', String(settings.open)); } }); configure.setAttribute('aria-expanded', 'false'); deviceSummary.append(configure); box.prepend(deviceSummary); box.append(management); } else { addExample.classList.add('ojmicrobit-focus-add'); box.append(addExample); }
    if (previous) { previous.replaceWith(box); } else { this.content.append(box); }
    if (this.config.compactEditor) { this.showCaseGroup(this.caseVisibility !== false); }
  };
  App.prototype.showCaseGroup = function (visible) {
    this.caseVisibility = Boolean(visible); var selected = visible ? 'public' : 'hidden';
    this.content.querySelectorAll('[data-mb-case-group]').forEach(function (group) { group.hidden = group.getAttribute('data-mb-case-group') !== selected; });
    this.content.querySelectorAll('[data-mb-case-tab]').forEach(function (tab) { tab.setAttribute('aria-pressed', String(tab.getAttribute('data-mb-case-tab') === selected)); });
  };
  App.prototype.fillAdvancedScenario = function (advanced, scenario) {
    var self = this; while (advanced.children.length > 1) { advanced.lastChild.remove(); }
    var host = el('div'); this.renderDetailedScenarios(host, scenario); var detailed = host.querySelector('.ojmicrobit-scenario');
    advanced.append(field('전체 예시 실행 시간(ms)', markField(input(scenario.durationMs, 'number', function (value) { scenario.durationMs = value; self.dirty = true; }), 'scenarios.' + this.exercise.scenarios.indexOf(scenario) + '.durationMs')));
    Array.from(detailed.children).forEach(function (node) { if (node.tagName.toLowerCase() !== 'legend' && !node.classList.contains('ojmicrobit-fields') && !(node.tagName.toLowerCase() === 'button' && node.textContent === '시나리오 삭제')) { advanced.append(node); } });
  };
  App.prototype.captureProject = async function () {
    if (this.driver && this.driver.loaded) { this.project = await this.driver.capture(); }
    else if (!this.project) { throw new Error('MakeCode 프로젝트를 먼저 불러와 주세요.'); }
    return clone(this.project);
  };
  App.prototype.mountEditor = function (parent, starter) {
    var self = this, tools = el('div', 'ojmicrobit-toolbar'), frame = el('iframe', 'ojmicrobit-editor'), frameStatus = el('p', 'ojmicrobit-muted', 'MakeCode 편집기에 연결하고 있습니다.');
    frameStatus.setAttribute('aria-live', 'polite');
    tools.append(button('블록', function () { self.action(function () { return self.driver.request('switchblocks'); }); }),
      button('JavaScript', function () { self.action(function () { return self.driver.request('switchjavascript'); }); }),
      button('기본 보드 실행', function () { self.action(async function () { await self.driver.request('showsimulator'); await self.driver.request('startsimulator'); }); }));
    tools.append(button('HEX 다운로드', function () { self.action(async function () { var result = await self.driver.compileHex(); download(result.hex, result.name + '.hex', 'application/octet-stream'); self.message('HEX 파일을 다운로드했습니다. MICROBIT 드라이브로 복사할 수도 있습니다.'); }, 'MakeCode에서 실제 HEX 파일을 컴파일하고 있습니다.'); }));
    var connectButton = button('기기 연결', function () {
      // Call connect immediately in this click handler, before awaiting network/compiler work.
      if (self.busy) { self.message('앞선 작업을 마칠 때까지 기다려 주세요.'); return; }
      if (!global.isSecureContext || !global.navigator.usb || !global.OJMicrobitUSB) { self.message('USB 업로드는 HTTPS의 Chrome·Edge에서 지원됩니다. HEX 다운로드 후 MICROBIT 드라이브로 복사해 주세요.', true); return; }
      if (!self.usb) { self.usb = global.OJMicrobitUSB.createConnection(); }
      var connection = self.usb.connect(); self.action(async function () { await connection; usbStatus.textContent = '기기 연결됨'; self.message('마이크로비트에 연결했습니다. USB 업로드를 눌러 프로그램을 전송하세요.'); });
    });
    var uploadButton = button('USB 업로드', function () { self.action(async function () {
      if (!self.usb || self.usb.status !== 'Connected') { throw new Error('먼저 기기 연결을 눌러 마이크로비트를 선택해 주세요.'); }
      var result = await self.driver.compileHex(); await self.usb.flash(result.hex, function (stage, progress) { usbStatus.textContent = Number.isFinite(progress) ? '업로드 ' + Math.round(progress * 100) + '%' : '기기와 통신하고 있습니다.'; });
      usbStatus.textContent = '업로드 완료'; self.message('마이크로비트에 프로그램을 업로드했습니다.');
    }, 'HEX를 생성해 연결된 마이크로비트로 전송하고 있습니다.'); });
    var usbStatus = el('span', 'ojmicrobit-muted', global.navigator.usb && global.isSecureContext ? 'USB 연결 전' : '이 브라우저는 HEX 파일 전송을 이용하세요.');
    tools.append(connectButton, uploadButton, usbStatus); parent.append(tools, frameStatus, frame);
    this.driver = new EditorDriver(frame, { project: this.project, editorUrl: this.config.editorUrl,
      onStatus: function (text, error) { frameStatus.textContent = self.config.compactEditor && !error && self.driver && self.driver.loaded ? 'MakeCode 연결됨' : text; frameStatus.classList.toggle('is-error', error); if (self.liveLab) { self.liveLab.wake(); } },
      onProject: function (project) { var changed = projectSignature(self.project) !== projectSignature(project); self.project = project; if (changed) { self.dirty = true; self.backup(); self.refreshWorkspaceSaveState(); if (self.liveLab) { self.liveLab.changed('code'); } } else if (self.liveLab) { self.liveLab.wake(); } },
      onHex: function (result) { download(result.hex, result.name + '.hex', 'application/octet-stream'); self.message('MakeCode에서 생성한 HEX 파일을 다운로드했습니다.'); }
    });
    var details = el('details', 'ojmicrobit-import'), summary = el('summary', '', '프로젝트 파일 가져오기·내보내기'); details.append(summary);
    var file = input('', 'file'); file.accept = '.json,.ojmicrobit.json'; file.addEventListener('change', async function () {
      var chosen = file.files && file.files[0]; if (!chosen) { return; }
      if (chosen.size > 524288) { self.message('프로젝트 파일은 512KiB 이하로 가져와 주세요.', true); return; }
      if (self.dirty && !await self.confirm('가져온 프로젝트로 현재 편집 내용을 바꿀까요?')) { file.value = ''; return; }
      self.action(async function () { self.project = canonicalProject(JSON.parse(await chosen.text())); self.dirty = true;
        if (self.driver) { self.driver.dispose(); } frame.remove(); frame = el('iframe', 'ojmicrobit-editor'); parent.insertBefore(frame, details);
        self.driver = new EditorDriver(frame, { project: self.project, editorUrl: self.config.editorUrl, onProject: function (project) { var changed = projectSignature(self.project) !== projectSignature(project); self.project = project; if (changed) { self.dirty = true; self.backup(); self.refreshWorkspaceSaveState(); if (self.liveLab) { self.liveLab.changed('code'); } } else if (self.liveLab) { self.liveLab.wake(); } }, onStatus: function (text) { frameStatus.textContent = self.config.compactEditor && self.driver && self.driver.loaded ? 'MakeCode 연결됨' : text; if (self.liveLab) { self.liveLab.wake(); } }, onHex: function (result) { download(result.hex, result.name + '.hex', 'application/octet-stream'); } }); if (self.liveLab) { self.liveLab.changed('code'); } self.backup(); self.message('프로젝트를 가져왔습니다. 저장하면 서버에도 보관합니다.');
      });
    });
    details.append(field('저장한 프로젝트 파일', file), button('프로젝트 내보내기', function () { self.action(async function () { var project = await self.captureProject(); download(JSON.stringify(project, null, 2), 'microbit-project.ojmicrobit.json'); }); })); parent.append(details);
    if (this.config.compactEditor) {
      tools.classList.add('ojmicrobit-editor-toolbar'); frameStatus.classList.add('ojmicrobit-editor-connection');
      var modes = el('div', 'ojmicrobit-editor-modes'), modeButtons = Array.from(tools.querySelectorAll('button')).slice(0, 2), more = el('details', 'ojmicrobit-editor-more'), choices = el('div', 'ojmicrobit-editor-menu');
      modes.setAttribute('role', 'group'); modes.setAttribute('aria-label', '코드 표시 방식');
      modeButtons.forEach(function (control, index) { control.setAttribute('aria-pressed', String(index === (makeHeader(self.project, '').editor === 'blocksprj' ? 0 : 1))); control.addEventListener('click', function () { modeButtons.forEach(function (other) { other.setAttribute('aria-pressed', String(other === control)); }); }); modes.append(control); });
      Array.from(tools.childNodes).forEach(function (node) { choices.append(node); }); more.append(el('summary', '', '파일·기기'), choices); tools.append(modes);
      if (!starter && this.workspaceView) { var toggle = button('실행 결과 열기', function () { self.setWorkspaceResults(self.workspaceView.panel.hidden, true); }); toggle.setAttribute('data-mb-toggle-results', ''); toggle.setAttribute('aria-controls', this.workspaceView.panel.id); tools.append(toggle); this.workspaceView.toggle = toggle; }
      choices.append(button('프로젝트 가져오기·내보내기', function () { more.open = false; details.open = !details.open; if (details.open) { file.focus(); } })); tools.append(more); if (this.workspaceView && !starter) { this.setWorkspaceResults(!this.workspaceView.panel.hidden); }
    }
  };
  App.prototype.backupKey = function () { return 'oj_microbit_' + this.config.userId + '_' + (this.exercise && this.exercise.id || 'new') + (this.assignment ? '_assignment_' + this.assignment.id : ''); };
  App.prototype.backup = function () {
    if (!this.exercise || !this.project) { return; }
    try { global.sessionStorage.setItem(this.backupKey(), JSON.stringify({ project: this.project, revision: this.projectRevision, exerciseRevision: this.exercise.revision, at: Date.now() })); } catch (error) { /* Explicit project export is always available. */ }
  };
  App.prototype.problem = function (exercise, preview, assignment) {
    this.closeEditor(); this.assignment = assignment || null; this.exercise = exerciseModel(exercise); this.project = null; this.dirty = false; this.generation += 1; this.content.replaceChildren();
    var self = this, toolbar = el('div', 'ojmicrobit-toolbar'); toolbar.append(this.backButton());
    if (this.teacher && !assignment) { toolbar.append(button('문제 편집', function () { self.navigate({ view: 'edit', exerciseId: exercise.id, beforeId: 0 }); })); }
    this.contextBanner(this.content); this.content.append(toolbar); if (!this.config.shellNavigation) { this.content.append(el('p', 'ojmicrobit-workflow', '① 문제 확인 · ② 코드 작성·실험 · ③ 제출하여 채점 · ④ 제출 내역 확인')); }
    var info = section('#' + exercise.id + ' · ' + exercise.title); info.setAttribute('data-mb-problem', ''); info.append(el('p', 'ojmicrobit-description', exercise.description || '아래 연결과 공개 예시를 확인하고 프로그램을 작성하세요.'));
    var connections = table(['핀', '장치', '방향', '신호']);
    exercise.connections.forEach(function (connection) { var row = el('tr'); cell(row, 'P' + connection.pin); cell(row, connection.label); cell(row, connection.direction === 'input' ? '입력' : '출력'); cell(row, (signals.find(function (signal) { return signal[0] === connection.signal; }) || [null, connection.signal])[1]); connections.body.append(row); }); var wiring = el('details', 'ojmicrobit-optional'); wiring.append(el('summary', '', '장치 연결 자세히 보기'), connections.wrap); info.append(el('p', 'ojmicrobit-muted', '연결: ' + exercise.connections.map(function (entry) { return entry.label + ' (P' + entry.pin + ')'; }).join(', ')), wiring);
    if ((exercise.i2cDevices || []).length) { info.append(el('p', '', 'I²C 장치: ' + exercise.i2cDevices.map(function (device) { return device.label + ' · 주소 ' + addressText(device.address); }).join(', '))); }
    info.append(el('p', 'ojmicrobit-muted', '실험과 임시 저장은 연습이에요. 제출하여 채점할 때 결과가 남아요.'));
    this.content.append(info); if (preview) { this.content.append(el('p', 'ojp-notice ojp-notice--info', '교사 미리보기입니다. 학생의 저장 내용과 제출 기록을 만들지 않습니다.')); }
    this.renderPublicTests(Boolean(this.config.compactEditor)); var tests = this.content.querySelector('[data-mb-public-tests]'); tests.open = true;
    var actions = el('div', 'ojmicrobit-toolbar'); if (!exercise.sourceUnavailable && !(this.assignment && this.assignment.workAvailable === false) && !(this.teacher && this.assignment)) { actions.append(button(preview ? '편집기 미리보기' : '문제 풀기', function () { self.navigate(self.scopedRoute('solve')); }, true)); }
    if (this.teacher && this.assignment) { actions.append(button('수업 결과', function () { self.navigate(self.scopedRoute('results')); }, true)); }
    if (!preview || this.teacher) { actions.append(button(this.teacher ? '학생 제출 내역' : '내 제출 내역', function () { self.navigate(self.scopedRoute('history')); })); }
    this.content.append(actions); this.results = el('div'); this.content.append(this.results); if (this.config.compactEditor) { this.setupFocusedProblem(info, toolbar, tests, actions); } this.message(this.config.shellNavigation ? '' : '문제 설명과 연결·공개 예시를 확인한 뒤 문제를 풀어 보세요.'); this.runnerNotice();
  };
  App.prototype.setupFocusedProblem = function (info, toolbar, tests, actions) {
    var wrapper = el('div', 'ojmicrobit-focused-problem ojmicrobit-focus-surface ojmicrobit-immersive'), body = el('div', 'ojmicrobit-focus-read-body'), header = el('header', 'ojmicrobit-problem-heading ojmicrobit-focus-header'), exercise = this.exercise;
    wrapper.setAttribute('data-mb-focus-surface', 'problem');
    var description = info.querySelector('.ojmicrobit-description'), wiring = info.querySelector('details'), brief = el('section', 'ojmicrobit-problem-brief'), devices = el('section', 'ojmicrobit-problem-devices'), list = el('div', 'ojmicrobit-device-summary');
    var meta = el('div', 'ojmicrobit-student-header-meta'), heading = el('div', 'ojmicrobit-student-title-row'); meta.append(el('p', 'ojmicrobit-focus-breadcrumb', '마이크로비트 문제 / #' + exercise.id), el('span', 'ojmicrobit-focus-chip is-accent', exercise.status === 'published' ? '공개 문제' : '교사 미리보기')); heading.append(el('h1', 'ojmicrobit-focus-problem-title', exercise.title)); header.append(meta, heading);
    brief.append(el('h2', '', '이번에 만들 동작'), description); devices.append(el('h2', '', '연결할 장치'));
    exercise.connections.forEach(function (connection) { var item = el('span'); item.append(el('strong', '', 'P' + connection.pin), el('span', '', connection.label)); list.append(item); }); devices.append(list);
    (exercise.i2cDevices || []).forEach(function (device) { devices.append(el('p', 'ojmicrobit-muted', 'I²C · ' + device.label + ' · 주소 ' + addressText(device.address))); });
    if (wiring) { devices.append(wiring); }
    info.replaceChildren(brief, devices); info.classList.add('ojmicrobit-problem-overview'); toolbar.classList.add('ojmicrobit-problem-tools');
    tests.classList.add('ojmicrobit-focused-examples'); var publicTitle = tests.querySelector('[data-mb-public-title]'); publicTitle.textContent = '기대 동작을 살펴보세요'; var publicCount = exercise.scenarios.filter(function (item) { return item.visible; }).length; if (publicCount) { publicTitle.append(el('small', '', publicCount === 1 ? '공개 예시 1' : '공개 예시 ' + publicCount + '개')); } actions.classList.add('ojmicrobit-problem-actions');
    var note = el('div', 'ojmicrobit-problem-footer-copy'); note.append(el('p', 'ojmicrobit-muted', exercise.sourceUnavailable || this.assignment && this.assignment.workAvailable === false ? '이 문제는 읽기 전용입니다. 허용된 제출 내역을 확인할 수 있어요.' : '실제 보드 없이 풀 수 있어요.'));
    var context = this.content.querySelector('[data-mb-context]'); if (context) { context.classList.add('ojmicrobit-focus-context'); if (this.assignment) { header.append(context); } else { note.append(context); } }
    Array.from(actions.querySelectorAll('button')).forEach(function (control) {
      if (control.textContent === '문제 풀기') { control.textContent = 'MakeCode로 문제 풀기 →'; control.setAttribute('aria-label', '문제 풀기'); control.setAttribute('data-mb-solve', ''); heading.append(control); }
      if (control.getAttribute('data-mb-variant') !== 'primary') { control.classList.add('ojmicrobit-focus-history-link'); note.append(control); }
    });
    actions.prepend(note); info.before(wrapper); body.append(info, tests); wrapper.append(header, body, actions, this.results);
  };
  App.prototype.learn = async function (exercise, preview, assignment) {
    // Fetch and validate before closing the current editor so a permission or
    // connection failure leaves the current screen usable.
    var prefix = this.practicePath(exercise.id, assignment), saved = preview ? null : await this.api(prefix + '/project');
    var project = canonicalProject(saved && saved.project || exercise.starterProject);
    this.closeEditor(); this.assignment = assignment || null; this.exercise = exerciseModel(exercise); this.project = canonicalProject(exercise.starterProject); this.projectRevision = 0; this.previewTeacher = Boolean(preview); this.dirty = false; this.generation += 1;
    var self = this; this.project = project;
    if (saved && saved.project) { this.projectRevision = saved.revision || 0; }
    this.content.replaceChildren(); var toolbar = el('div', 'ojmicrobit-toolbar'); toolbar.append(this.backButton(), button('문제 설명', function () { self.navigate(self.scopedRoute(self.teacher ? 'preview' : 'detail')); })); this.content.append(toolbar); this.contextBanner(this.content);
    var info = section(exercise.title); info.append(el('p', 'ojmicrobit-description', exercise.description)); this.content.append(info);
    if (preview) { this.content.append(el('p', 'ojp-notice ojp-notice--info', '교사 미리보기입니다. 시작 프로젝트로 실행하며 학생의 저장 내용을 바꾸지 않습니다.')); }
    var recovery;
    try { recovery = JSON.parse(global.sessionStorage.getItem(this.backupKey()) || 'null'); } catch (error) { recovery = null; }
    if (recovery && recovery.project && recovery.exerciseRevision === exercise.revision && JSON.stringify(recovery.project) !== JSON.stringify(this.project)) {
      var recoveryBox = section('이 브라우저에 저장되지 않은 초안이 있습니다.'); recoveryBox.setAttribute('data-mb-recovery', '');
      recoveryBox.append(button('초안 파일 내려받기', function () { download(JSON.stringify(recovery.project, null, 2), 'microbit-recovery.ojmicrobit.json'); }),
        button('초안으로 복구', function () { self.project = canonicalProject(recovery.project); self.dirty = true; self.clearLiveExecution(); if (self.driver) { self.driver.dispose(); self.driver = null; } self.content.querySelector('[data-mb-editor]').replaceChildren(); self.mountEditor(self.content.querySelector('[data-mb-editor]')); peripheral.replaceChildren(el('h2', '', '내 코드의 실제 실행 결과'), el('p')); self.mountPeripherals(peripheral); recoveryBox.remove(); self.message('브라우저 초안을 복구했습니다. 서버 저장을 눌러 보관하세요.'); })); this.content.append(recoveryBox);
    }
    var workspace = el('div', 'ojmicrobit-workspace'), editor = section('MakeCode 편집기', '기본 보드 시뮬레이터는 MakeCode 안에서 실행합니다. 외부 센서·액추에이터는 오른쪽 핀 실습 화면에서 확인하세요.'); editor.setAttribute('data-mb-editor', '');
    var peripheral = section('외부 센서·액추에이터', '입력값으로 새 실행을 시작하고, 서버에서 실행한 핀 출력 기록을 시간에 따라 재생합니다.');
    workspace.append(editor, peripheral); this.content.append(workspace);
    var actions = el('div', 'ojmicrobit-toolbar');
    if (!preview) { actions.append(button('임시 저장', function () { self.action(async function () {
      var project = await self.captureProject(), before = JSON.stringify(project), result = await self.api(prefix + '/project', { project: project, revision: self.projectRevision });
      self.projectRevision = result.revision; if (JSON.stringify(self.project) === before) { self.dirty = false; try { global.sessionStorage.removeItem(self.backupKey()); } catch (error) { /* No browser draft. */ } }
      self.message('코드를 임시 저장했습니다. 제출·채점 기록은 만들지 않았습니다.');
    }, '프로젝트를 저장하고 있습니다.'); })); actions.lastChild.setAttribute('data-mb-save-project', ''); }
    if (!preview) { actions.append(button('제출하여 채점', function () { self.action(async function () {
      if (!self.runnerReady) { throw new Error('채점 서버 연결 후 사용할 수 있습니다.'); }
      var project = await self.captureProject(), result = await self.api(prefix + '/run', { project: project }); self.renderResult(result);
      self.message('제출 #' + result.id + ' · ' + (result.status === 'compile_error' ? '컴파일 오류를 고친 뒤 다시 제출하세요.' : result.status === 'runtime_error' ? '실행 오류가 발생했습니다. 오류 내용을 확인해 주세요.' : result.passed ? '모든 테스트를 통과했습니다.' : '채점이 끝났습니다. 테스트별 결과를 확인하세요.'), result.status === 'compile_error' || result.status === 'runtime_error');
    }, '프로젝트를 컴파일하고 입력 시나리오에 따라 채점하고 있습니다.'); }, true));
    actions.lastChild.setAttribute('data-mb-run-action', '');
    actions.append(button('내 제출 내역', function () { self.navigate(self.scopedRoute('history')); })); }
    var actionHint = el('p', 'ojmicrobit-muted', preview ? '미리보기에서는 센서 실험·HEX 다운로드를 사용할 수 있습니다. 제출하여 채점은 학생 화면에서 진행합니다.' : '임시 저장은 작성 중인 코드만 보관합니다. 제출하여 채점을 누르면 제출 내역이 남습니다.'); this.content.append(actions, actionHint); this.results = el('div'); this.content.append(this.results); this.renderPublicTests();
    if (this.config.compactEditor) { this.setupImmersiveWorkspace(info, toolbar, workspace, editor, peripheral, actions, actionHint); }
    this.mountEditor(editor); this.mountPeripherals(peripheral); this.message('코드를 작성하고 실행 결과를 열어 센서로 실험해 보세요.');
    if (this.runRetryUntil > Date.now()) { this.setRunCooldown(Math.ceil((this.runRetryUntil - Date.now()) / 1000)); }
  };
  App.prototype.refreshWorkspaceSaveState = function () {
    var state = this.content.querySelector('[data-mb-workspace-saved]'); if (state) { state.textContent = this.previewTeacher ? '교사 미리보기' : this.dirty ? '저장 필요' : '저장됨'; }
  };
  App.prototype.setWorkspaceResults = function (open, focus) {
    var view = this.workspaceView; if (!view || !view.surface.isConnected) { return; }
    // Visibility only: never move an iframe, capture/reimport code, or touch the live session.
    view.panel.hidden = !open; view.surface.classList.toggle('is-results-open', Boolean(open));
    if (view.toggle) { view.toggle.textContent = open ? '실행 결과 닫기' : '실행 결과 열기'; view.toggle.setAttribute('aria-expanded', String(Boolean(open))); }
    if (focus) { (open ? view.close : view.toggle).focus(); }
  };
  App.prototype.setWorkspaceProblem = function (open, focus) {
    var view = this.workspaceView; if (!view || !view.surface.isConnected) { return; }
    view.problem.hidden = !open; view.surface.classList.toggle('is-problem-open', Boolean(open)); view.question.setAttribute('aria-expanded', String(Boolean(open)));
    if (focus) { (open ? view.problemClose : view.question).focus(); }
  };
  App.prototype.setupImmersiveWorkspace = function (info, toolbar, workspace, editor, peripheral, actions, actionHint) {
    var self = this, surface = el('section', 'ojmicrobit-focus-surface ojmicrobit-immersive ojmicrobit-immersive-solve'), header = el('header', 'ojmicrobit-immersive-heading'), title = el('div'), headingActions = el('div', 'ojmicrobit-immersive-heading-actions'), saved = el('span', 'ojmicrobit-workspace-saved'), summary = el('div', 'ojmicrobit-immersive-summary');
    surface.setAttribute('data-mb-focus-surface', 'workspace');
    title.append(el('p', 'ojmicrobit-focus-breadcrumb', this.previewTeacher ? '마이크로비트 · 교사 미리보기' : this.assignment ? '수업 · ' + (this.assignment.classTitle || '수업 #' + this.assignment.classId) : '마이크로비트 · 공개 문제'), el('h1', '', this.exercise.title));
    saved.setAttribute('data-mb-workspace-saved', ''); saved.setAttribute('role', 'status'); headingActions.append(saved);
    var save = actions.querySelector('[data-mb-save-project]'), grade = actions.querySelector('[data-mb-run-action]');
    if (save) { save.textContent = '임시 저장'; headingActions.append(save); } if (grade) { grade.textContent = '채점하기'; headingActions.append(grade); }
    header.append(title, headingActions);
    var question = button('문제 보기', function () { self.setWorkspaceProblem(self.workspaceView.problem.hidden, true); }); question.setAttribute('data-mb-workspace-question', '');
    summary.append(el('span', '', this.exercise.description.split(/\r?\n/)[0] || '문제 설명을 읽고 코드를 만들어 보세요.'), question);
    var problem = el('section', 'ojmicrobit-workspace-problem'), problemHeading = el('div', 'ojmicrobit-workspace-panel-heading'), problemTitle = el('h2', '', '문제 설명'), problemClose = button('닫기', function () { self.setWorkspaceProblem(false, true); });
    problem.id = 'mb-workspace-problem-' + uid(); problem.hidden = true; problem.setAttribute('aria-label', '문제 설명'); question.setAttribute('aria-controls', problem.id); question.setAttribute('aria-expanded', 'false'); problemClose.setAttribute('aria-label', '문제 설명 닫기'); problemHeading.append(problemTitle, problemClose); problem.append(problemHeading, info); info.querySelector('h2').textContent = '이번에 만들 동작';
    var devices = el('div', 'ojmicrobit-workspace-wiring'); this.exercise.connections.forEach(function (connection) { var item = el('span'); item.append(hardwarePicture(connection.device), el('span', '', 'P' + connection.pin + ' ' + connection.label)); devices.append(item); }); problem.append(devices);
    var tests = this.content.querySelector('[data-mb-public-tests]'); if (tests) { problem.append(tests); }
    var panel = el('aside', 'ojmicrobit-workspace-results'), panelHeading = el('div', 'ojmicrobit-workspace-panel-heading'), labels = el('div'), close = button('닫기', function () { self.setWorkspaceResults(false, true); }); panel.id = 'mb-workspace-results-' + uid(); panel.hidden = true; panel.setAttribute('aria-label', '실행 결과'); labels.append(el('h2', '', '실행 결과'), el('p', 'ojmicrobit-muted', '내 코드가 만든 반응')); close.setAttribute('aria-label', '실행 결과 닫기'); close.setAttribute('data-mb-close-results', ''); panelHeading.append(labels, close);
    var tabs = el('div', 'ojmicrobit-workspace-result-tabs'), expected = el('section', 'ojmicrobit-workspace-expected'), expectedReady = false; expected.hidden = true; tabs.setAttribute('role', 'group'); tabs.setAttribute('aria-label', '결과 구분'); expected.setAttribute('data-mb-workspace-expected', '');
    function chooseExpected(value) { peripheral.hidden = value; expected.hidden = !value; actual.setAttribute('aria-pressed', String(!value)); target.setAttribute('aria-pressed', String(value)); labels.querySelector('p').textContent = value ? '공개 예시의 목표 동작' : '내 코드가 만든 반응'; if (value && !expectedReady) { expectedReady = true; expected.append(el('p', 'ojmicrobit-expected-source', '기대 동작 미리보기 · 작성한 코드의 실행 결과와 별개입니다.')); self.renderStudentExamples(expected); } }
    var actual = button('내 코드 실행', function () { chooseExpected(false); }), target = button('기대 동작', function () { chooseExpected(true); }); actual.setAttribute('aria-pressed', 'true'); target.setAttribute('aria-pressed', 'false'); actual.setAttribute('data-mb-result-actual', ''); target.setAttribute('data-mb-result-expected', ''); tabs.append(actual, target); panel.append(panelHeading, tabs, peripheral, expected);
    editor.classList.add('ojmicrobit-immersive-editor'); workspace.classList.add('ojmicrobit-immersive-workspace'); workspace.replaceChildren(editor, panel);
    var footer = el('footer', 'ojmicrobit-workspace-footer'), feedback = el('span', 'ojmicrobit-muted'), links = el('div'); feedback.setAttribute('data-mb-workspace-status', ''); feedback.setAttribute('role', 'status');
    if (toolbar.firstChild) { var back = toolbar.firstChild; back.textContent = this.assignment ? '수업 목록' : '문제 목록'; links.append(back); } while (actions.firstChild) { var history = actions.firstChild; history.textContent = '제출 기록'; links.append(history); } footer.append(feedback, links);
    surface.append(header, summary); var recovery = this.content.querySelector('[data-mb-recovery]'); if (recovery) { surface.append(recovery); } surface.append(problem, workspace, footer); this.content.prepend(surface); toolbar.remove(); actions.remove(); actionHint.remove();
    var context = this.content.querySelector('[data-mb-context]'); if (context) { context.classList.add('ojmicrobit-workspace-context'); footer.prepend(context); } var previewNote = this.content.querySelector('.ojp-notice'); if (this.previewTeacher && previewNote && !previewNote.hasAttribute('data-mb-context')) { previewNote.remove(); }
    this.workspaceView = { surface: surface, panel: panel, close: close, editor: editor, problem: problem, problemClose: problemClose, question: question, toggle: null };
    surface.addEventListener('keydown', function (event) { if (event.key === 'Escape') { if (!problem.hidden) { self.setWorkspaceProblem(false, true); } else if (!panel.hidden) { self.setWorkspaceResults(false, true); } } });
  };
  App.prototype.clearLiveExecution = function () { if (this.liveLab) { this.liveLab.dispose(); this.liveLab = null; } };
  App.prototype.mountLiveExecution = function (parent) {
    var self=this,root=parent,session=null,revision=0,pending=true,automatic=true,disposed=false,timer=null,paused=false,wantPause=false,actionPaused=false,transport={abort:null};
    root.classList.add('ojmicrobit-live-lab');root.setAttribute('data-mb-live-lab','');root.setAttribute('data-result-source','student-code');
    var title=root.querySelector('h2');if(title)title.textContent='내 코드의 실제 실행 결과';var intro=root.querySelector('p');if(intro)intro.textContent='코드를 계속 실행하며 센서 입력만 바꿔요. 변수·타이머·I²C 상태가 유지되고, 코드 변경이나 처음부터 다시 시작 때만 초기화해요.';
    var tools=el('div','ojmicrobit-toolbar ojmicrobit-live-tools'),enabled=checkInput(true,function(value){automatic=value;if(!value){pending=false;cancel();}else if(!session){restart(false);}});enabled.setAttribute('data-mb-live-auto','');
    var run=button('실행 시작 / 계속',function(){if(session){paused=false;wantPause=false;actionPaused=false;session.resume();root.classList.remove('is-paused');status.textContent='이전 실행 상태에서 계속 실행해요.';}else{restart(true);}},true),pause=button('일시정지',function(){pauseSession('변수·타이머를 보존하고 일시정지했어요. 실행 시작 / 계속으로 이어갈 수 있어요.');}),reset=button('처음부터 다시 시작',function(){restart(true);});
    run.setAttribute('data-mb-live-run','');run.setAttribute('data-mb-run-action','');pause.setAttribute('data-mb-live-stop','');reset.setAttribute('data-mb-live-reset','');tools.append(field('코드 변경 시 자동 시작',enabled),run,pause,reset);if(intro)intro.after(tools);else root.prepend(tools);
    if(self.config.compactEditor){var options=el('details','ojmicrobit-live-options');options.append(el('summary','','실행 설정'),tools.querySelector('label'));tools.append(options);run.textContent='실행 / 계속';reset.textContent='처음부터';if(intro){var help=el('details','ojmicrobit-live-help');help.append(el('summary','','실행 방식 안내'),intro);root.append(help);}}
    var status=el('p','ojmicrobit-live-status','MakeCode 연결 후 상태 유지 실행을 시작해요.'),errors=el('ul','ojmicrobit-diagnostics');status.setAttribute('role','status');status.setAttribute('data-mb-live-status','');errors.setAttribute('data-mb-live-errors','');root.append(status,errors);
    function cancel(){if(timer!==null){global.clearTimeout(timer);timer=null;}}
    function clearOutputs(){self.clearTrace();root.classList.add('is-paused');root.removeAttribute('data-code-signature');if(self.traceTable)self.traceTable.replaceChildren();if(self.i2cTraceTable)self.i2cTraceTable.replaceChildren();self.actuators.forEach(function(d){d.output.textContent='출력 대기';d.meter.value=0;d.indicator.classList.remove('is-active');if(d.card){d.card.classList.remove('is-on');d.card.setAttribute('data-state','unknown');d.card.removeAttribute('data-value');}});self.i2cDisplays.forEach(function(d){d.pointer=0;d.outputs.forEach(function(out){out.textContent='대기';});});}
    function snapshot(){var inputs=self.exercise.connections.filter(function(c){return c.direction==='input';}).map(function(c){var n=self.inputValues[c.pin],max=c.signal==='digital'?1:1023;if(!Number.isInteger(n)||n<0||n>max)throw Error('P'+c.pin+' 입력값은 0~'+max+' 정수예요.');return{atMs:0,pin:c.pin,signal:c.signal,value:n};});var i2cInputs=[];(self.exercise.i2cDevices||[]).forEach(function(d){d.registers.forEach(function(r){var n=self.i2cValues.get(d.address+':'+r.register);if(!Number.isInteger(n)||n<0||n>255)throw Error('I²C 입력값은 0~255 정수예요.');i2cInputs.push({atMs:0,address:d.address,register:r.register,value:n});});});return{inputs:inputs,i2cInputs:i2cInputs};}
    function pauseSession(text){cancel();wantPause=true;if(session)session.pause();paused=true;actionPaused=false;root.classList.add('is-paused');status.textContent=text;}
    function fail(error){if(disposed)return;if(session){session.dispose();session=null;}pending=false;paused=true;automatic=false;enabled.checked=false;clearOutputs();status.textContent=String(error.message||error)+' 자동 재시도는 하지 않습니다. 실행 시작 / 계속으로 다시 시도하세요.';}
    function restart(force){if(disposed)return;revision++;cancel();if(session){session.dispose();session=null;}paused=false;wantPause=Boolean(global.document.hidden);actionPaused=false;pending=automatic||force;clearOutputs();errors.replaceChildren();status.textContent=pending?'새 코드를 컴파일한 뒤 처음부터 실행해요.':'코드가 바뀌었어요. 실행 시작 / 계속을 눌러 주세요.';if(pending)wake(force?0:600);}
    function changed(reason){if(disposed)return;if(reason==='code'){restart(false);return;}try{var values=snapshot();if(session){session.inputs(values);status.textContent=paused?'일시정지 상태에서 입력만 바꿨어요. 실행을 계속하면 이 값을 읽어요.':'실행 상태를 유지하고 센서 입력만 전달했어요.';}else if(pending){status.textContent='현재 입력으로 연결 후 실행해요.';wake();}else{status.textContent='입력을 준비했어요. 실행 시작 / 계속을 누르세요.';}}catch(error){status.textContent=error.message;}}
    function wake(delay){if(disposed||self.busy||global.document.hidden)return;if(actionPaused&&session&&!wantPause){actionPaused=false;paused=false;session.resume();root.classList.remove('is-paused');}if(wantPause||!pending||self.liveExecutionFlight||timer!==null||!self.driver||!self.driver.loaded||self.runnerStatusPending)return;if(!self.runnerReady){status.textContent='상태 유지 실행을 시작하려면 최신 마이크로비트 실행기 연결이 필요해요.';return;}var wait=Math.max(delay||0,(self.liveLastStart||0)+1000-Date.now());timer=global.setTimeout(function(){timer=null;compile();},Math.max(0,wait));}
    async function compile(){
      if(disposed||self.busy||!pending||self.liveExecutionFlight||global.document.hidden)return;var token=revision,generation=self.generation,projectKey;
      try{snapshot();}catch(error){pending=false;status.textContent=error.message;return;}
      if(self.runRetryUntil>Date.now()){fail(Error('서버가 안내한 대기 시간 뒤 실행하세요.'));return;}
      if(!global.OJMicrobitLive){fail(Error('상태 유지 시뮬레이터 파일을 불러오지 못했어요. 새 ZIP 설치와 캐시를 확인하세요.'));return;}
      pending=false;self.liveLastStart=Date.now();status.textContent='최신 코드를 컴파일하고 있어요. 센서 변경은 다시 컴파일하지 않아요.';
      var flight=(async function(){var project=await self.captureProject();if(disposed||token!==revision||generation!==self.generation)return null;projectKey=projectSignature(project);return self.api(self.practicePath(self.exercise.id,self.assignment)+'/compile',{project:project},undefined,undefined,transport);})();self.liveExecutionFlight=flight;
      try{
        var result=await flight;if(!result||disposed||token!==revision||generation!==self.generation||projectKey!==projectSignature(self.project))return;
        if(result.status==='compile_error'){clearOutputs();status.textContent='컴파일 오류예요. 코드를 고치면 새로 실행해요.';(result.diagnostics||[]).slice(0,5).forEach(function(d){errors.append(el('li','',(d.file||'')+(d.line?':'+d.line:'')+' '+d.message));});return;}
        if(result.status!=='compiled'||result.liveProtocol!=='oj-live/1'||typeof result.js!=='string')throw Error('상태 유지 실행을 지원하는 최신 실행기로 업데이트해 주세요.');
        var values=snapshot(),created=global.OJMicrobitLive.create(root,{hostUrl:self.config.simulatorHostUrl,onFrame:function(frame){if(!disposed&&session===created){self.appendLiveFrame(frame);}},onState:function(state){if(disposed||session!==created)return;root.setAttribute('data-live-state',state);if(state==='running'&&(wantPause||self.busy||global.document.hidden)){created.pause();paused=true;root.classList.add('is-paused');return;}paused=state==='paused';root.classList.toggle('is-paused',paused);},onError:fail});session=created;
        await created.start({js:result.js,connections:clone(self.exercise.connections),i2cDevices:clone(self.exercise.i2cDevices||[]),inputs:values.inputs,i2cInputs:values.i2cInputs});
        if(disposed||token!==revision||session!==created)return;root.classList.remove('is-paused');root.setAttribute('data-code-signature',projectKey);status.textContent=self.config.compactEditor?'실행 중 · 변수와 타이머 유지':'같은 프로그램을 계속 실행 중이에요. 센서 변경은 변수·타이머를 초기화하지 않아요.';
        if(wantPause||self.busy||global.document.hidden)created.pause();
      }catch(error){if(!disposed&&token===revision&&generation===self.generation)fail(error);}
      finally{if(self.liveExecutionFlight===flight)self.liveExecutionFlight=null;if(self.liveLab)self.liveLab.wake();}
    }
    function visibility(){if(global.document.hidden){if(session)pauseSession('화면이 숨겨져 실행 상태를 보존하고 일시정지했어요. 돌아오면 실행 시작 / 계속을 누르세요.');else{cancel();paused=true;wantPause=true;}}}
    global.document.addEventListener('visibilitychange',visibility);
    var lab={changed:changed,wake:wake,hold:function(){cancel();if(session&&!paused){session.pause();paused=true;actionPaused=true;root.classList.add('is-paused');}},dispose:function(){if(disposed)return;disposed=true;revision++;cancel();if(session)session.dispose();session=null;if(transport.abort)transport.abort();self.clearTrace();global.document.removeEventListener('visibilitychange',visibility);}};this.liveLab=lab;wake(600);return lab;
  };
  App.prototype.mountPeripherals = function (parent) {
    this.clearLiveExecution();
    var self = this; this.actuators = new Map(); this.inputValues = {}; this.traceTable = null; this.i2cValues = new Map(); this.i2cDisplays = new Map(); this.i2cTraceTable = null;
    var board = el('div', 'ojmicrobit-pinboard'); parent.append(board);
    this.exercise.connections.forEach(function (connection) {
      var card = el('div', 'ojmicrobit-device'), title = el('div', 'ojmicrobit-device-title'), pinLabel = el('span', 'ojp-status-badge ojp-status-badge--neutral', 'P' + connection.pin + (self.config.compactEditor ? '' : ' · ' + connection.signal)); pinLabel.setAttribute('aria-label', 'P' + connection.pin + ' · ' + connection.signal); title.append(el('strong', '', connection.label), pinLabel); card.append(title, hardwarePicture(connection.device));
      if (connection.direction === 'input') {
        var control, value = el('output', 'ojmicrobit-device-value', '0'); self.inputValues[connection.pin] = 0;
        if (connection.signal === 'digital') {
          control = input('', 'checkbox'); control.addEventListener('change', function () { self.inputValues[connection.pin] = control.checked ? 1 : 0; value.textContent = self.inputValues[connection.pin]; card.classList.toggle('is-on', self.inputValues[connection.pin] > 0); if (self.liveLab) { self.liveLab.changed(false); } });
        } else {
          control = input(0, 'range'); control.min = '0'; control.max = '1023'; control.step = '1'; control.addEventListener('input', function () { self.inputValues[connection.pin] = Number(control.value); value.textContent = control.value; self.sensorHint.textContent = '입력이 바뀌었습니다. 새 실행 결과를 기다려 주세요.'; if (self.liveLab) { self.liveLab.changed(false); } });
        }
        control.setAttribute('aria-label', connection.label + ' P' + connection.pin + ' 입력'); card.setAttribute('data-mb-live-input', String(connection.pin)); var exact = input(0, 'number'); exact.min = 0; exact.max = connection.signal === 'digital' ? 1 : 1023; exact.step = 1; exact.setAttribute('aria-label', 'P' + connection.pin + ' 센서 직접 입력'); exact.addEventListener('input', function () { self.inputValues[connection.pin] = exact.value.trim() === '' ? undefined : Number(exact.value); if (connection.signal === 'digital') { control.checked = self.inputValues[connection.pin] === 1; } else { control.value = self.inputValues[connection.pin]; } value.textContent = exact.value; card.classList.toggle('is-on', self.inputValues[connection.pin] > 0); card.style.setProperty('--mb-preview-knob', (-135 + 270 * (self.inputValues[connection.pin] || 0) / 1023) + 'deg'); if (self.liveLab) { self.liveLab.changed(false); } }); control.addEventListener(connection.signal === 'digital' ? 'change' : 'input', function () { exact.value = self.inputValues[connection.pin]; card.style.setProperty('--mb-preview-knob', (-135 + 270 * self.inputValues[connection.pin] / 1023) + 'deg'); }); card.append(field(connection.signal === 'digital' ? '입력 켜기' : '센서 값', control), field('직접 입력', exact), value);
      } else {
        var indicator = el('div', 'ojmicrobit-indicator ' + (connection.device === 'servo' ? 'is-servo' + (connection.signal === 'servo_pulse' ? ' is-pulse' : '') : connection.device === 'motor' ? 'is-motor' : 'is-led')), output = el('output', 'ojmicrobit-device-value', '출력 대기'), meter = el('meter'); meter.min = 0; meter.max = connection.signal === 'digital' ? 1 : connection.signal === 'servo' ? 180 : connection.signal === 'servo_pulse' ? 20000 : 1023; meter.value = 0; meter.setAttribute('aria-label', connection.label + ' 출력');
        indicator.hidden = true; indicator.setAttribute('aria-hidden', 'true'); card.setAttribute('data-mb-actual-output', String(connection.pin)); card.setAttribute('data-state', 'unknown'); indicator.append(el('span')); card.append(indicator, meter, output);
        self.actuators.set(String(connection.pin), { connection: connection, indicator: indicator, output: output, meter: meter, card: card });
      }
      board.append(card);
    });
    (this.exercise.i2cDevices || []).forEach(function (device) {
      var card = el('div', 'ojmicrobit-device ojmicrobit-i2c-device'), title = el('div', 'ojmicrobit-device-title'), registers = table(['레지스터', '실험 입력값', '실제 거래 값']);
      title.append(el('strong', '', device.label), el('span', 'ojp-status-badge ojp-status-badge--neutral', 'I²C ' + addressText(device.address))); card.append(title);
      var display = { pointer: 0, outputs: new Map() }; self.i2cDisplays.set(String(device.address), display);
      device.registers.forEach(function (register) { var row = el('tr'), key = device.address + ':' + register.register, control = boundedInput(register.value, 0, 255, function (value) { self.i2cValues.set(key, value); if (self.liveLab) { self.liveLab.changed(false); } }), output = el('output', 'ojmicrobit-register-value', '대기');
        self.i2cValues.set(key, register.value); control.setAttribute('aria-label', device.label + ' 주소 ' + device.address + ' 레지스터 ' + register.register + ' 입력');
        cell(row, String(register.register) + (register.writable ? ' · 쓰기 허용' : ' · 읽기 전용')); cell(row, control); cell(row, output); display.outputs.set(register.register, output); registers.body.append(row);
      }); card.append(registers.wrap); board.append(card);
    });
    this.sensorHint = el('p', 'ojmicrobit-muted', '실행된 코드의 실제 핀 출력만 표시해요. 기록이 없는 핀은 출력 대기로 남아요.'); this.sensorHint.setAttribute('aria-live', 'polite'); parent.append(this.sensorHint);
    this.mountLiveExecution(parent);
    var trace = table(['시각(ms)', '핀', '출력 종류', '값']); this.traceTable = trace.body; var traceDetails = el('details', 'ojmicrobit-live-trace'); traceDetails.append(el('summary', '', '실제 핀 출력 기록'), trace.wrap); parent.append(traceDetails);
    if ((this.exercise.i2cDevices || []).length) { parent.append(el('h3', '', '실제 I²C 거래 기록')); var i2cTrace = table(['시각(ms)', '주소', '동작', '바이트', 'repeated']); this.i2cTraceTable = i2cTrace.body; parent.append(i2cTrace.wrap); }
  };
  App.prototype.appendLiveFrame = function(frame){
    var self=this,deviceWrites=new Map(),registerWrites=new Map(),pinRows=[],busRows=[];
    (frame.trace||[]).forEach(function(e){var d=self.actuators.get(String(e.pin)),max=e.signal==='digital'?1:e.signal==='servo'?180:e.signal==='servo_pulse'?20000:1023;if(e.kind!=='write'||!d||d.connection.signal!==e.signal||!Number.isFinite(e.atMs)||e.atMs<0||e.atMs>frame.atMs||!Number.isInteger(e.value)||e.value<(e.signal==='servo_pulse'?1:0)||e.value>max)return;deviceWrites.set(String(e.pin),e);pinRows.push(e);});
    (frame.i2cTrace||[]).forEach(function(e){var d=self.i2cDisplays.get(String(e.address));if(!d||e.kind!=='i2c'||!Number.isFinite(e.atMs)||e.atMs<0||e.atMs>frame.atMs||!['read','write'].includes(e.operation)||!Array.isArray(e.data)||e.data.length>64||e.data.some(function(v){return!Number.isInteger(v)||v<0||v>255;}))return;function register(v){if(d.outputs.has(d.pointer))registerWrites.set(d.outputs.get(d.pointer),v);d.pointer=(d.pointer+1)%256;}if(e.operation==='write'&&e.data.length){d.pointer=e.data[0];e.data.slice(1).forEach(register);}if(e.operation==='read')e.data.forEach(register);busRows.push(e);});
    deviceWrites.forEach(function(e,pin){var d=self.actuators.get(pin),max=e.signal==='digital'?1:e.signal==='servo'?180:e.signal==='servo_pulse'?20000:1023,ratio=e.value/max;d.output.textContent=self.config.compactEditor?(e.signal==='digital'?(e.value?'켜짐':'꺼짐'):e.signal==='pwm'?'세기 '+e.value:String(e.value)+(e.signal==='servo'?'°':e.signal==='servo_pulse'?'µs':'')):String(e.value)+(e.signal==='pwm'?' · '+Math.round(ratio*100)+'%':e.signal==='servo'?'°':e.signal==='servo_pulse'?'µs':'');d.meter.value=e.value;d.indicator.classList.toggle('is-active',e.signal==='servo'||e.value>0);if(d.card){d.card.setAttribute('data-state',e.value>0?'on':'off');d.card.setAttribute('data-value',String(e.value));d.card.classList.toggle('is-on',e.value>0);d.card.style.setProperty('--mb-preview-level',String(ratio));d.card.style.setProperty('--mb-preview-spin',(0.28+2.8*(1-ratio))+'s');if(e.signal==='servo')d.card.style.setProperty('--mb-preview-angle',(e.value-90)+'deg');}});
    registerWrites.forEach(function(v,out){out.textContent=v;});
    pinRows.slice(-60).forEach(function(e){var row=el('tr');cell(row,Math.round(e.atMs));cell(row,'P'+e.pin);cell(row,e.signal);cell(row,e.value);self.traceTable.prepend(row);});while(self.traceTable&&self.traceTable.children.length>60)self.traceTable.lastChild.remove();
    if(self.i2cTraceTable){busRows.slice(-100).forEach(function(e){var row=el('tr');cell(row,Math.round(e.atMs));cell(row,addressText(e.address));cell(row,e.operation==='read'?'읽기':'쓰기');cell(row,bytesText(e.data));cell(row,e.repeated?'true':'false');self.i2cTraceTable.prepend(row);});while(self.i2cTraceTable.children.length>100)self.i2cTraceTable.lastChild.remove();}
    self.sensorHint.textContent='실행 경과 '+(Math.floor(frame.atMs/100)/10)+'초 · 센서 입력을 바꿔도 실행 상태를 유지해요.';
  };
  App.prototype.playTrace = function (trace, durationMs, i2cTrace) {
    this.clearTrace(); this.traceTable.replaceChildren(); var self = this, sequence = this.previewSequence;
    this.actuators.forEach(function (device) { if (device.card) { device.card.setAttribute('data-state', 'unknown'); device.card.removeAttribute('data-value'); device.card.classList.remove('is-on'); } device.output.textContent = '출력 대기'; device.meter.value = 0; device.indicator.classList.remove('is-active'); device.indicator.style.removeProperty('--mb-level'); device.indicator.style.removeProperty('--mb-angle'); });
    if (this.i2cTraceTable) { this.i2cTraceTable.replaceChildren(); } this.i2cDisplays.forEach(function (device) { device.pointer = 0; device.outputs.forEach(function (output) { output.textContent = '대기'; }); });
    var writes = trace.filter(function (entry) { var device = self.actuators.get(String(entry.pin)); return entry.kind === 'write' && Number.isFinite(entry.atMs) && entry.atMs >= 0 && entry.atMs <= durationMs && device && device.connection.signal === entry.signal; }).slice(0, 5000);
    var transactions = (i2cTrace || []).filter(function (entry) { return entry.kind === 'i2c' && Number.isFinite(entry.atMs) && entry.atMs >= 0 && entry.atMs <= durationMs && self.i2cDisplays.has(String(entry.address)); }).slice(0, 5000);
    var events = writes.concat(transactions).map(function (entry, order) { return { entry: entry, order: order }; }).sort(function (a, b) { return a.entry.atMs - b.entry.atMs || a.order - b.order; }), cursor = 0;
    var clock = function () { return global.performance && global.performance.now ? global.performance.now() : Date.now(); }, started = clock();
    // One active timer and one DOM update per device each frame, even for dense traces.
    function pump() {
      if (sequence !== self.previewSequence || self.destroyed) { return; } self.traceTimers = [];
      var elapsed = clock() - started, deviceWrites = new Map(), registerWrites = new Map(), pinRows = [], busRows = [];
      while (cursor < events.length && events[cursor].entry.atMs <= elapsed) {
        var entry = events[cursor++].entry;
        if (entry.kind === 'write') { deviceWrites.set(String(entry.pin), entry); pinRows.push(entry); }
        else { var device = self.i2cDisplays.get(String(entry.address)), data = entry.data || [];
          function register(value) { if (device.outputs.has(device.pointer)) { registerWrites.set(device.outputs.get(device.pointer), value); } device.pointer = (device.pointer + 1) % 256; }
          if (entry.operation === 'write' && data.length) { device.pointer = data[0]; data.slice(1).forEach(register); }
          if (entry.operation === 'read') { data.forEach(register); } busRows.push(entry);
        }
      }
      deviceWrites.forEach(function (entry, pin) {
        var device = self.actuators.get(pin);
        var max = entry.signal === 'digital' ? 1 : entry.signal === 'servo' ? 180 : entry.signal === 'servo_pulse' ? 20000 : 1023, ratio = Math.min(1, Math.max(0, entry.value / max));
        device.output.textContent = String(entry.value) + (entry.signal === 'pwm' ? ' · ' + Math.round(ratio * 100) + '%' : entry.signal === 'servo' ? '°' : entry.signal === 'servo_pulse' ? 'µs' : ''); device.meter.value = entry.value;
        device.indicator.classList.toggle('is-active', entry.signal === 'servo' || entry.value > 0); device.indicator.style.setProperty('--mb-level', String(ratio));
        if (entry.signal === 'servo') { device.indicator.style.setProperty('--mb-angle', (entry.value - 90) + 'deg'); }
        if (device.card) { device.card.setAttribute('data-state', entry.value > 0 ? 'on' : 'off'); device.card.setAttribute('data-value', String(entry.value)); device.card.classList.toggle('is-on', entry.value > 0); device.card.style.setProperty('--mb-preview-level', String(ratio)); device.card.style.setProperty('--mb-preview-spin', (0.28 + 2.8 * (1 - ratio)) + 's'); if (entry.signal === 'servo') { device.card.style.setProperty('--mb-preview-angle', (entry.value - 90) + 'deg'); } }
      });
      registerWrites.forEach(function (value, output) { output.textContent = value; });
      pinRows.slice(-60).forEach(function (entry) { var row = el('tr'); cell(row, Math.round(entry.atMs)); cell(row, 'P' + entry.pin); cell(row, entry.signal); cell(row, entry.value); self.traceTable.prepend(row); });
      while (self.traceTable.children.length > 60) { self.traceTable.lastChild.remove(); }
      if (self.i2cTraceTable) { busRows.slice(-100).forEach(function (entry) { var row = el('tr'); cell(row, Math.round(entry.atMs)); cell(row, addressText(entry.address)); cell(row, entry.operation === 'read' ? '읽기' : '쓰기'); cell(row, bytesText(entry.data)); cell(row, entry.repeated ? 'true' : 'false'); self.i2cTraceTable.prepend(row); }); while (self.i2cTraceTable.children.length > 100) { self.i2cTraceTable.lastChild.remove(); } }
      if (cursor < events.length) { self.traceTimers.push(global.setTimeout(pump, Math.max(16, events[cursor].entry.atMs - elapsed))); }
      else if (elapsed < durationMs + 50) { self.traceTimers.push(global.setTimeout(pump, durationMs + 50 - elapsed)); }
      else { self.sensorHint.textContent = '실행 기록 재생이 끝났습니다. 입력값을 바꿔 다시 실행할 수 있습니다.'; }
    }
    this.sensorHint.textContent = writes.length || transactions.length ? '실제 실행의 핀 출력·I²C 거래 기록을 재생하고 있습니다.' : '이 실행에서는 기록된 출력이나 I²C 거래가 없습니다.';
    this.traceTimers.push(global.setTimeout(pump, 0));
  };
  App.prototype.appendScenarioStory = function (parent, scenario) {
    var self = this, list = el('ol', 'ojmicrobit-story');
    flowGroups(scenario).forEach(function (group, index) {
      var card = el('li', 'ojmicrobit-story-step'); card.append(el('strong', 'ojmicrobit-story-heading', (index + 1) + '단계 · ' + (group.atMs === 0 ? '처음' : '시작 후 ' + group.atMs / 1000 + '초')));
      var inputs = group.inputs.map(function (entry) { var connection = self.exercise.connections.find(function (item) { return item.pin === entry.pin; }) || { pin: entry.pin, label: '센서', signal: entry.signal, direction: 'input' }; return connection.label + ' (P' + entry.pin + '): ' + self.flowValue(connection, entry.value); });
      group.i2cInputs.forEach(function (entry) { var device = self.exercise.i2cDevices.find(function (item) { return item.address === entry.address; }); inputs.push((device ? device.label : 'I²C 장치') + '의 값: ' + entry.value); });
      if (self.config.compactEditor) { self.appendFocusInputs(card, group, true); if (!inputs.length) { card.append(el('p', 'ojmicrobit-story-input', '센서 값은 이전과 같아요.')); } } else { card.append(el('p', 'ojmicrobit-story-input', inputs.length ? '센서 값  ' + inputs.join(' · ') : '센서 값은 이전과 같아요.')); } self.appendFlowLanes(card, scenario, group, false); list.append(card);
    }); parent.append(list);
  };
  function svgShape(parent, name, attributes, text) { var node = global.document.createElementNS('http://www.w3.org/2000/svg', name); Object.keys(attributes || {}).forEach(function (key) { node.setAttribute(key, String(attributes[key])); }); if (text !== undefined) { node.textContent = text; } parent.append(node); return node; }
  function hardwarePicture(kind) {
      var svg = global.document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 120 90'); svg.setAttribute('class', 'ojmicrobit-hardware-picture'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('focusable', 'false');
      if (kind === 'led') { svgShape(svg, 'path', { d: 'M42 55V35a18 18 0 0 1 36 0v20Z', class: 'ojmicrobit-hardware-bulb' }); svgShape(svg, 'path', { d: 'M42 55h36M51 57v26M69 57v26', class: 'ojmicrobit-hardware-stroke' }); svgShape(svg, 'path', { d: 'M60 5V1M31 17l-6-6M89 17l6-6M24 36h-8M96 36h8', class: 'ojmicrobit-hardware-rays' }); }
      else if (kind === 'motor') { svgShape(svg, 'rect', { x: 9, y: 32, width: 44, height: 29, rx: 7, class: 'ojmicrobit-hardware-body' }); svgShape(svg, 'path', { d: 'M14 61v13h12M53 46h13', class: 'ojmicrobit-hardware-stroke' }); var rotor = svgShape(svg, 'g', { class: 'ojmicrobit-hardware-rotor' }); svgShape(rotor, 'circle', { cx: 85, cy: 46, r: 24, class: 'ojmicrobit-hardware-ring' }); svgShape(rotor, 'path', { d: 'M85 46C51 27 75 8 85 46M85 46c33-21 39 9 0 0M85 46c1 39-28 29 0 0', class: 'ojmicrobit-hardware-propeller' }); svgShape(rotor, 'circle', { cx: 85, cy: 46, r: 5, class: 'ojmicrobit-hardware-center' }); }
      else if (kind === 'servo') { svgShape(svg, 'rect', { x: 32, y: 37, width: 56, height: 36, rx: 6, class: 'ojmicrobit-hardware-body' }); var arm = svgShape(svg, 'g', { class: 'ojmicrobit-hardware-arm' }); svgShape(arm, 'path', { d: 'M60 35h35', class: 'ojmicrobit-hardware-stroke' }); svgShape(arm, 'circle', { cx: 60, cy: 35, r: 6, class: 'ojmicrobit-hardware-center' }); }
      else if (kind === 'i2c') { svgShape(svg, 'rect', { x: 32, y: 20, width: 56, height: 50, rx: 6, class: 'ojmicrobit-hardware-body' }); svgShape(svg, 'path', { d: 'M22 30h10M22 45h10M22 60h10M88 30h10M88 45h10M88 60h10', class: 'ojmicrobit-hardware-stroke' }); svgShape(svg, 'text', { x: 60, y: 49, 'text-anchor': 'middle', fill: 'currentColor' }, 'I²C'); }
      else if (kind === 'board') { svgShape(svg, 'rect', { x: 11, y: 7, width: 98, height: 76, rx: 10, class: 'ojmicrobit-hardware-board' }); svgShape(svg, 'text', { x: 60, y: 21, 'text-anchor': 'middle', class: 'ojmicrobit-hardware-board-text' }, 'micro:bit'); for (var y = 0; y < 5; y += 1) { for (var x = 0; x < 5; x += 1) { svgShape(svg, 'rect', { x: 43 + x * 7, y: 29 + y * 7, width: 4, height: 4, rx: 1, class: 'ojmicrobit-hardware-dot' }); } } svgShape(svg, 'text', { x: 60, y: 76, 'text-anchor': 'middle', class: 'ojmicrobit-hardware-board-text' }, 'P0  P1  P2'); }
      else { svgShape(svg, 'rect', { x: 20, y: 12, width: 80, height: 65, rx: 10, class: 'ojmicrobit-hardware-body' }); if (kind === 'light_sensor') { svgShape(svg, 'circle', { cx: 60, cy: 43, r: 16, class: 'ojmicrobit-hardware-ring' }); svgShape(svg, 'path', { d: 'M50 35l14 3-8 10 14 3', class: 'ojmicrobit-hardware-stroke' }); svgShape(svg, 'path', { d: 'M38 20l8 9M80 20l-8 9M25 31l12 4', class: 'ojmicrobit-hardware-stroke' }); } else if (kind === 'button') { svgShape(svg, 'circle', { cx: 60, cy: 44, r: 20, class: 'ojmicrobit-hardware-push' }); } else { svgShape(svg, 'circle', { cx: 60, cy: 44, r: 21, class: 'ojmicrobit-hardware-ring' }); svgShape(svg, 'path', { d: 'M60 44V27', class: 'ojmicrobit-hardware-knob' }); } svgShape(svg, 'path', { d: 'M40 77v8M60 77v8M80 77v8', class: 'ojmicrobit-hardware-stroke' }); }
      return svg;
    }
  App.prototype.clearExpectedPreview = function () { if (this.expectedLab) { this.expectedLab.dispose(); this.expectedLab = null; } };
  App.prototype.mountExpectedPreview = function (parent, scenario) {
    var self = this, model = expectedPreviewModel(this.exercise, scenario), root = el('section', 'ojmicrobit-expected-lab'), intro = el('div', 'ojmicrobit-expected-heading'), controls = el('div', 'ojmicrobit-expected-inputs'), outputs = el('div', 'ojmicrobit-expected-outputs'), flow = el('div', 'ojmicrobit-expected-flow'), values = {}, fields = new Map(), displays = new Map(), timer = null, epoch = 0, disposed = false, preferred = 0, paused = false, current = null;
    root.setAttribute('data-mb-expected-lab', ''); root.setAttribute('data-result-source', 'public-expectation'); root.setAttribute('aria-label', '기대 동작 미리보기'); intro.append(el('h3', '', '입력 → 마이크로비트 → 출력'), el('p', 'ojmicrobit-muted', '슬라이더는 공개 예시 값으로 맞춰져요. 다른 값은 직접 입력할 수 있어요.')); root.append(intro);
    var tools = el('div', 'ojmicrobit-expected-tools'), stage = select('0', [['', '입력과 일치하는 단계 없음']].concat(model.scenes.map(function (scene) { return [String(scene.index), (scene.index + 1) + '단계 · ' + (scene.atMs ? '시작 후 ' + scene.atMs / 1000 + '초' : '처음')]; })), function (value) { if (value !== '') { showScene(Number(value)); } }); stage.options[0].disabled = true; stage.setAttribute('data-mb-preview-stage', ''); tools.append(field('살펴볼 단계', stage));
    var replay = button('다시 보기', function () { play(resolveExpectedPreview(model, values, preferred)); }), pause = button('일시 정지', function () { stop(); paused = true; root.classList.add('is-paused'); status.textContent = '미리보기를 멈췄어요. 다시 보기로 처음부터 확인할 수 있어요.'; }); replay.setAttribute('data-mb-preview-replay', ''); pause.setAttribute('data-mb-preview-pause', ''); tools.append(replay, pause); root.append(tools);
    function change(control, value) { values[control.key] = value; preferred = null; syncFields(); play(resolveExpectedPreview(model, values, preferred)); }
    function renderInput(index) {
      fields.clear(); controls.replaceChildren(); var control = model.controls[index]; if (!control) { return; }
      var card = el('div', 'ojmicrobit-expected-sensor'), number = input('', 'number'), value = el('output', 'ojmicrobit-expected-sensor-value'); card.setAttribute('data-mb-preview-input', control.key); number.min = control.min; number.max = control.max; number.step = 1; number.setAttribute('aria-label', control.label + ' ' + (control.pin !== undefined ? 'P' + control.pin : '레지스터 ' + control.register) + ' 미리보기 입력값'); card.append(hardwarePicture(control.device), el('strong', '', control.label), el('span', 'ojmicrobit-muted', control.pin !== undefined ? 'P' + control.pin : 'I²C ' + addressText(control.address) + ' · ' + control.register));
      number.addEventListener('input', function () { change(control, number.value.trim() === '' ? undefined : Number(number.value)); }); var range;
      if (control.signal === 'digital') { range = input('', 'checkbox'); range.setAttribute('aria-label', control.label + ' 누른 상태'); range.addEventListener('change', function () { change(control, range.checked ? 1 : 0); }); card.append(field('켜기 / 누르기', range)); }
      else { range = input(0, 'range'); range.min = control.min; range.max = control.max; range.step = 1; range.setAttribute('aria-label', control.label + ' 공개 예시 값'); range.disabled = !control.values.length; range.addEventListener('input', function () { var n = Number(range.value); if (control.values.length) { n = control.values.reduce(function (nearest, candidate) { return Math.abs(candidate - n) < Math.abs(nearest - n) ? candidate : nearest; }, control.values[0]); } change(control, n); }); card.append(range); }
      value.hidden = control.signal !== 'digital'; card.append(field('입력값', number), value); controls.append(card); fields.set(control.key, { control: control, card: card, number: number, range: range, value: value }); syncFields();
    }
    if (model.controls.length > 1) { var sensorPicker = select('0', model.controls.map(function (control, index) { return [String(index), control.label + ' · ' + (control.pin !== undefined ? 'P' + control.pin : addressText(control.address) + '/' + control.register)]; }), function (value) { renderInput(Number(value)); }); sensorPicker.setAttribute('data-mb-preview-sensor', ''); tools.prepend(field('조절할 입력', sensorPicker)); }
    if (model.controls.length) { renderInput(0); }
    if (!model.controls.length) { controls.append(el('p', 'ojmicrobit-muted', '별도 입력 없이 실행하는 예시예요. 살펴볼 단계를 골라주세요.')); }
    var board = el('div', 'ojmicrobit-expected-board'); board.append(el('span', '', '→'), hardwarePicture('board'), el('span', '', '→')); flow.append(controls, board, outputs); root.append(flow);
    model.outputs.forEach(function (connection) { var card = el('div', 'ojmicrobit-expected-actuator'), output = el('output', 'ojmicrobit-expected-output', '조건 미정'); card.setAttribute('data-mb-preview-output', String(connection.pin)); card.setAttribute('data-state', 'unknown'); card.append(hardwarePicture(connection.device), el('strong', '', connection.label), el('span', 'ojmicrobit-muted', 'P' + connection.pin), output); outputs.append(card); displays.set(connection.pin, { card: card, output: output, connection: connection }); });
    if (!model.outputs.length) { outputs.append(el('p', 'ojmicrobit-muted', '핀 출력 조건은 없어요. I²C 거래는 아래 정확한 채점 조건에서 확인하세요.')); }
    var status = el('p', 'ojmicrobit-expected-status'), help = el('p', 'ojmicrobit-muted', '공개 예시의 기대값을 보여줘요. 순서가 있는 동작은 즉시 반응하고 최소 유지시간으로 재생해요. 학생 코드의 실행·채점 결과는 풀이 화면에서 확인하세요.'); status.setAttribute('data-mb-preview-status', ''); status.setAttribute('role', 'status'); root.append(status, help); parent.append(root);
    function stop() { epoch += 1; if (timer !== null) { global.clearTimeout(timer); timer = null; } }
    function syncFields() { fields.forEach(function (field, key) { var n = values[key], valid = Number.isInteger(n) && n >= field.control.min && n <= field.control.max; field.number.value = n === undefined ? '' : String(n); field.number.setAttribute('aria-invalid', valid ? 'false' : 'true'); if (field.control.signal === 'digital') { field.range.checked = n === 1; field.range.indeterminate = !valid; } else { field.range.value = valid ? n : field.control.min; field.range.setAttribute('aria-valuetext', valid ? String(n) : '입력값 확인 필요'); } field.card.style.setProperty('--mb-preview-knob', (valid ? -135 + 270 * n / field.control.max : 0) + 'deg'); field.card.classList.toggle('is-on', valid && n > 0); field.value.textContent = valid ? String(n) : '값을 확인해 주세요'; }); }
    function display(event) { var device = displays.get(event.pin); if (!device || device.connection.signal !== event.signal) { return; } var n = event.value, max = event.signal === 'digital' ? 1 : event.signal === 'servo' ? 180 : event.signal === 'servo_pulse' ? 20000 : 1023, level = Math.min(1, Math.max(0, n / max)); device.card.setAttribute('data-state', n > 0 ? 'on' : 'off'); device.card.setAttribute('data-value', String(n)); device.card.classList.toggle('is-on', n > 0); device.card.style.setProperty('--mb-preview-level', String(level)); device.card.style.setProperty('--mb-preview-spin', (0.28 + 2.8 * (1 - level)) + 's'); if (event.signal === 'servo') { device.card.style.setProperty('--mb-preview-angle', (n - 90) + 'deg'); } device.output.textContent = event.signal === 'pwm' ? '세기 ' + n : self.flowValue(device.connection, n); }
    function play(result) {
      stop(); current = result; paused = false; root.classList.remove('is-paused'); displays.forEach(function (device) { device.card.classList.remove('is-on'); device.card.setAttribute('data-state', 'unknown'); device.card.removeAttribute('data-value'); device.output.textContent = '조건 미정'; });
      if (result.status !== 'match') { Array.from(stage.options).forEach(function (option) { option.selected = option.value === ''; }); status.textContent = result.status === 'invalid' ? '입력 범위의 정수 값을 넣어주세요.' : result.status === 'ambiguous' ? '같은 입력을 쓰는 단계가 여러 개예요. 살펴볼 단계를 골라주세요.' : '이 입력값의 기대 출력은 공개 예시에 없어요. 슬라이더나 살펴볼 단계에서 공개된 값을 골라주세요.'; return; }
      var scene = result.scene, cursor = 0, token = epoch, started = Date.now(); Array.from(stage.options).forEach(function (option) { option.selected = option.value === String(scene.index); });
      function pump() { if (disposed || token !== epoch) { return; } timer = null; if (global.document.hidden) { pause.click(); return; } var elapsed = Date.now() - started; while (cursor < scene.events.length && scene.events[cursor].atMs <= elapsed) { display(scene.events[cursor]); cursor += 1; } if (cursor < scene.events.length) { timer = global.setTimeout(function () { if (!root.isConnected) { stop(); return; } pump(); }, Math.max(1, scene.events[cursor].atMs - elapsed)); } }
      status.textContent = (scene.index + 1) + '단계의 기대 동작이에요.' + (scene.unresolved.length ? ' 복합 조건이 있는 핀은 아래 시간 조건에서 확인하세요.' : '') + ' 선택한 단계 구간의 순서를 보여줘요.'; pump();
    }
    function showScene(index) { var scene = model.scenes[index]; if (!scene || disposed) { return; } values = Object.assign({}, scene.inputs); preferred = index; syncFields(); play(resolveExpectedPreview(model, values, preferred)); }
    function visibility() { if (global.document.hidden) { stop(); paused = true; root.classList.add('is-paused'); status.textContent = '화면을 벗어나 미리보기를 멈췄어요. 다시 보기로 확인하세요.'; } }
    global.document.addEventListener('visibilitychange', visibility);
    var lab = { root: root, model: model, showScene: showScene, dispose: function () { if (disposed) { return; } stop(); disposed = true; paused = true; root.classList.add('is-paused'); global.document.removeEventListener('visibilitychange', visibility); } }; this.expectedLab = lab;
    if (model.scenes.length) { showScene(0); } else { play({ status: 'unknown', scene: null }); } return lab;
  };
  App.prototype.renderStudentExamples = function (parent) {
    var self = this, visible = self.exercise.scenarios.filter(function (scenario) { return scenario.visible; });
    if (!visible.length) { parent.append(el('p', 'ojmicrobit-muted', '공개 예시는 없어요. 문제 설명을 읽고 MakeCode 풀이 화면에서 내 코드를 직접 실행할 수 있어요.')); return; }
    var picker = el('div', 'ojmicrobit-student-example-picker'), view = el('div', 'ojmicrobit-student-example-view');
    if (visible.length > 1) { var choice = select('0', visible.map(function (scenario, index) { return [String(index), '예시 ' + (index + 1) + ' · ' + (scenario.name || '입력과 출력')]; }), function (value) { render(visible[Number(value)]); }); choice.setAttribute('data-mb-scenario-picker', ''); picker.append(field('살펴볼 예시', choice), el('p', 'ojmicrobit-muted', '예시마다 프로그램을 처음부터 따로 실행해요.')); parent.append(picker); }
    parent.append(view);
    function render(scenario) {
      self.clearExpectedPreview(); view.replaceChildren(); var groups = flowGroups(scenario), inputs = new Map(), bus = new Map(), shown = 0;
      var intro = el('p', 'ojmicrobit-student-example-hint', '입력을 바꾸면 그림에서 기대 출력을 바로 확인할 수 있어요.'); view.append(intro); var lab = self.mountExpectedPreview(view, scenario), cards = el('details', 'ojmicrobit-student-scene-details'); cards.setAttribute('data-mb-example-cards', ''); cards.append(el('summary', '', '단계별 입력·출력 ' + groups.length + '개 보기')); view.append(cards);
      var grid = el('ol', 'ojmicrobit-student-overview-grid'); grid.setAttribute('data-mb-stage-overview', ''); grid.setAttribute('aria-label', '입력에 따른 기대 출력'); cards.append(grid);
      function appendNext() {
        var limit = Math.min(shown + 3, groups.length);
        for (; shown < limit; shown += 1) {
          var group = groups[shown], card = el('li', 'ojmicrobit-student-scene'), head = el('div', 'ojmicrobit-student-scene-heading'); card.setAttribute('data-mb-scene', String(shown));
          head.append(el('span', 'ojmicrobit-student-scene-number', String(shown + 1)), el('h3', '', group.atMs === 0 ? '처음' : '시작 후 ' + group.atMs / 1000 + '초')); var showPicture = button('그림으로 보기', function (index) { return function () { lab.showScene(index); if (lab.root.scrollIntoView) { lab.root.scrollIntoView({ block: 'nearest' }); } }; }(shown)); showPicture.setAttribute('data-mb-preview-scene', String(shown)); head.append(showPicture); card.append(head);
          group.inputs.forEach(function (entry) { inputs.set(entry.pin, entry); }); group.i2cInputs.forEach(function (entry) { bus.set(entry.address + ':' + entry.register, entry); });
          var inputBox = el('div', 'ojmicrobit-student-input'); inputBox.append(el('span', 'ojmicrobit-student-label', '입력'));
          inputs.forEach(function (entry) { var connection = self.exercise.connections.find(function (item) { return item.pin === entry.pin; }) || { label: '센서', signal: entry.signal }, row = el('div', 'ojmicrobit-student-input-row'), name = el('span'); name.append(el('span', '', connection.label), el('small', '', 'P' + entry.pin)); row.append(name, el('strong', '', self.flowValue(connection, entry.value))); inputBox.append(row); });
          bus.forEach(function (entry) { var row = el('div', 'ojmicrobit-student-input-row'); row.append(el('span', '', 'I²C ' + addressText(entry.address) + ' · 레지스터 ' + entry.register), el('strong', '', entry.value)); inputBox.append(row); });
          if (!inputs.size && !bus.size) { inputBox.append(el('p', 'ojmicrobit-muted', '별도 입력 없음')); } else if (!group.inputs.length && !group.i2cInputs.length && shown) { inputBox.append(el('p', 'ojmicrobit-student-unchanged', '입력은 앞 단계와 같아요.')); }
          var outputs = el('div', 'ojmicrobit-student-outputs'); outputs.append(el('span', 'ojmicrobit-student-label', '기대 출력')); self.appendFlowLanes(outputs, scenario, group, false); card.append(inputBox, el('div', 'ojmicrobit-student-down', '↓'), outputs);
          var parallel = outputs.querySelector('[data-mb-parallel-caption]'); if (parallel) { parallel.remove(); }
          if (!group.assertions.length && !group.outputSequences.length && !group.i2cAssertions.length) { outputs.append(el('p', 'ojmicrobit-muted', '이 시점에는 입력만 바뀌어요.')); }
          grid.append(card);
        }
        grid.setAttribute('data-mb-scene-count', String(grid.childNodes.length));
        if (more) { more.hidden = shown >= groups.length; more.textContent = '다음 단계 ' + Math.min(3, groups.length - shown) + '개 보기 · ' + (groups.length - shown) + '개 남음'; }
      }
      var more = button('', appendNext); more.setAttribute('data-mb-more-scenes', ''); appendNext(); cards.append(more);
      view.append(el('p', 'ojmicrobit-student-timing-hint', '얼마나 빨리 반응하고 얼마나 유지해야 하는지는 아래 시간 조건에서 확인하세요.')); self.renderTechnicalTests(view, [scenario]);
    }
    render(visible[0]);
  };
  App.prototype.renderPublicTests = function (expanded) {
    var self = this, tests = el(expanded ? 'section' : 'details', 'ojmicrobit-card ojmicrobit-example-readout'), populated = false, title = el(expanded ? 'h2' : 'summary', '', '입력과 기대 동작'); tests.setAttribute('data-mb-public-tests', ''); title.setAttribute('data-mb-public-title', ''); tests.append(title);
    function fill() { if (populated) { return; } populated = true; if (expanded) { self.renderStudentExamples(tests); return; }
      var visible = self.exercise.scenarios.filter(function (scenario) { return scenario.visible; });
      visible.forEach(function (scenario, index) { var example = el('section', 'ojmicrobit-story-example'); if (visible.length > 1) { example.append(el('h3', '', scenario.name || '예시 ' + (index + 1))); } self.appendScenarioStory(example, scenario); tests.append(example); });
      if (!visible.length) { tests.append(el('p', 'ojmicrobit-muted', '공개 예시는 없어요. 문제 설명에 따라 만들어 보세요.')); }
      else { self.renderTechnicalTests(tests); }
    }
    tests.addEventListener('toggle', function () { if (tests.open) { fill(); } }); if (expanded) { fill(); } this.content.append(tests);
  };
  App.prototype.renderTechnicalTests = function (parent, scenarios) {
    var self = this, tests = el('details', 'ojmicrobit-card'), populated = false; tests.setAttribute('data-mb-technical-tests', ''); tests.append(el('summary', '', scenarios ? '시간·동작 순서와 정확한 채점 조건 보기' : '세부 채점 조건 보기'));
    tests.addEventListener('toggle', function () { if (!tests.open || populated) { return; } populated = true;
    (scenarios || self.exercise.scenarios.filter(function (scenario) { return scenario.visible; })).forEach(function (scenario) {
      if (scenarios) { self.appendScenarioStory(tests, scenario); } tests.append(el('h3', '', scenario.name + ' · ' + scenario.durationMs + 'ms')); var rows = table(['기준 시각', '출력 핀', '기대값', '응답 제한', '유지 시간', '오차']);
      if ((scenario.inputs || []).length) { var inputs = table(['입력 시각', '센서 핀', '입력값']); scenario.inputs.forEach(function (entry) { var row = el('tr'); cell(row, entry.atMs / 1000 + '초'); cell(row, 'P' + entry.pin + ' · ' + entry.signal); cell(row, entry.value); inputs.body.append(row); }); tests.append(inputs.wrap); }
      if ((scenario.i2cInputs || []).length) { var busInputs = table(['입력 시각', 'I²C 주소', '레지스터', '입력값']); scenario.i2cInputs.forEach(function (entry) { var row = el('tr'); cell(row, entry.atMs / 1000 + '초'); cell(row, addressText(entry.address)); cell(row, entry.register); cell(row, entry.value); busInputs.body.append(row); }); tests.append(busInputs.wrap); }
      scenario.assertions.forEach(function (assertion) { var row = el('tr'); cell(row, assertion.afterMs + 'ms'); cell(row, 'P' + assertion.pin + ' · ' + assertion.signal); cell(row, assertion.expected); cell(row, assertion.withinMs + 'ms'); cell(row, assertion.holdMs + 'ms'); cell(row, assertion.tolerance); rows.body.append(row); }); tests.append(rows.wrap);
      (scenario.outputSequences || []).forEach(function (sequence) { tests.append(el('h4', '', 'P' + sequence.pin + ' · 출력 동작 순서 (' + sequence.afterMs / 1000 + '초부터)'));
        sequence.steps.forEach(function (step, index) { tests.append(el('p', '', (index + 1) + '. ' + (sequence.signal === 'digital' ? step.expected ? '켜짐' : '꺼짐' : step.expected + (sequence.signal === 'servo' ? '°' : sequence.signal === 'servo_pulse' ? 'µs' : '')) + ' · ' + step.holdMs / 1000 + '초 유지 · 반응 대기 ' + step.withinMs / 1000 + '초')); });
      });
      if ((scenario.i2cAssertions || []).length) { var transactions = table(['순서', '주소', '동작', '기대 바이트', 'repeated', '기준 시각', '응답 제한']);
        scenario.i2cAssertions.forEach(function (assertion, index) { var row = el('tr'); cell(row, index + 1); cell(row, addressText(assertion.address)); cell(row, assertion.operation); cell(row, bytesText(assertion.data)); cell(row, assertion.repeated ? 'true' : 'false'); cell(row, assertion.afterMs + 'ms'); cell(row, assertion.withinMs + 'ms'); transactions.body.append(row); }); tests.append(transactions.wrap);
      }
    }); if (!self.exercise.scenarios.some(function (scenario) { return scenario.visible; })) { tests.append(el('p', 'ojmicrobit-muted', '공개 예시는 없습니다. 문제 설명에 따라 작성하면 제출 시 교사의 검사 조건으로 채점합니다.')); } }); (parent || this.content).append(tests);
  };
  App.prototype.renderResult = function (result) {
    this.results.replaceChildren();
    var failedRun = result.status === 'compile_error' || result.status === 'runtime_error', box = section(result.status === 'compile_error' ? '컴파일 오류' : result.status === 'runtime_error' ? '실행 오류' : result.passed ? '테스트 통과' : '테스트 결과');
    if (!failedRun && Number.isFinite(result.score)) { box.append(el('strong', 'ojp-status-badge ojp-status-badge--' + (result.passed ? 'success' : 'danger'), result.score + '점')); }
    if (failedRun) { var diagnostics = el('ul', 'ojmicrobit-diagnostics');
      (result.diagnostics || []).forEach(function (item) { diagnostics.append(el('li', '', (item.file ? item.file + (item.line ? ':' + item.line : '') + ' ' : '') + item.message)); });
      if (!diagnostics.children.length) { diagnostics.append(el('li', '', '프로그램을 완료하지 못했습니다. 반복문·지원 API·실행 제한을 확인해 주세요.')); } box.append(diagnostics);
    }
    var rows = table(['테스트', '결과', '설명']);
    (result.scenarios || []).forEach(function (scenario, index) { var row = el('tr'); cell(row, scenario.name || scenario.id || '테스트 ' + (index + 1)); cell(row, scenario.passed ? '통과' : '실패');
      var pinErrors = (scenario.assertions || []).filter(function (item) { return !item.passed; }).map(function (item) { return item.message || ('P' + item.pin + ' ' + item.signal + ' 기대값 ' + item.expected + (item.observed ? ', 관찰값 ' + item.observed.value : ', 실제 출력 없음')); });
      var i2cErrors = (scenario.i2cAssertions || []).filter(function (item) { return !item.passed; }).map(function (item) { return 'I²C ' + addressText(item.address) + ' ' + item.operation + ' [' + bytesText(item.data) + '] 거래·순서·시간 확인 필요' + (item.observed ? ' (관찰 [' + bytesText(item.observed.data) + '])' : ''); });
      var sequenceErrors = (scenario.outputSequences || []).filter(function (item) { return !item.passed; }).map(function (item) { var failed = item.steps.findIndex(function (step) { return !step.passed; }); return 'P' + item.pin + ' 동작 ' + (failed + 1) + '의 출력값·반응 대기·유지 시간 확인 필요'; });
      var text = scenario.message || scenario.error || pinErrors.concat(i2cErrors, sequenceErrors).join(' / '); cell(row, typeof text === 'string' ? text : '실행 결과를 확인해 주세요.'); rows.body.append(row); });
    (result.scenarios || []).forEach(function (scenario) { (scenario.outputSequences || []).forEach(function (sequence) {
      var detail = el('details', 'ojmicrobit-result-sequence'); detail.open = !sequence.passed; detail.append(el('summary', '', (scenario.name || '테스트') + ' · P' + sequence.pin + ' 동작 순서 · ' + (sequence.passed ? '통과' : '실패')));
      var actions = table(['동작', '기대 상태', '유지 시간', '실제 일치 시각', '유지 완료 시각', '결과']);
      var firstFailed = sequence.steps.findIndex(function (step) { return !step.passed; });
      sequence.steps.forEach(function (step, index) { var row = el('tr'), skipped = firstFailed >= 0 && index > firstFailed; cell(row, index + 1); cell(row, sequence.signal === 'digital' ? step.expected ? '켜짐' : '꺼짐' : step.expected + (sequence.signal === 'servo' ? '°' : sequence.signal === 'servo_pulse' ? 'µs' : '')); cell(row, step.holdMs / 1000 + '초'); cell(row, Number.isFinite(step.matchedAtMs) ? step.matchedAtMs / 1000 + '초' : skipped ? '—' : '일치 없음'); cell(row, Number.isFinite(step.completedAtMs) ? step.completedAtMs / 1000 + '초' : skipped ? '—' : '유지 완료 못 함'); cell(row, step.passed ? '통과' : skipped ? '앞 동작 실패로 검사하지 않음' : step.observed ? '출력값·유지 시간 확인' : '기대 출력과 응답 제한 확인'); actions.body.append(row); }); detail.append(actions.wrap); box.append(detail);
    }); });
    box.append(rows.wrap); if (result.runtimeVersion) { box.append(el('p', 'ojmicrobit-muted', '실행기 ' + result.runtimeVersion + (result.projectHash ? ' · 프로젝트 ' + result.projectHash.slice(0, 12) : ''))); } this.results.append(box);
    if (failedRun && /^[a-f0-9]{32}$/i.test(result.requestId || '')) { box.append(el('p', 'ojmicrobit-muted', '문의 번호 ' + result.requestId)); }
  };
  App.prototype.history = async function (exerciseId, beforeId, cursors) {
    var trail = cursors || [0], path = (this.teacher ? 'teacher/' : '') + this.practicePath(exerciseId, this.assignment) + '/attempts', response = await this.api(path + '?limit=20' + (beforeId ? '&beforeId=' + beforeId : ''));
    this.renderHistory(items(response), { exerciseId: exerciseId, path: path, cursor: beforeId || 0, cursors: trail, response: response });
  };
  App.prototype.renderHistory = function (records, page) {
    this.results.replaceChildren(); var self = this, box = section(this.teacher ? '학생 제출 내역' : '내 제출 내역'), rows = table(['제출 번호', '학습자', '제출 당시 버전', '점수', '결과', '제출 시각', '']); box.setAttribute('data-mb-submissions', '');
    records.forEach(function (record) { var row = el('tr'), result = record.result || record; cell(row, record.id); cell(row, record.userId || '본인'); cell(row, record.exerciseRevision || '—'); cell(row, Number.isFinite(result.score) ? result.score : '—'); cell(row, record.summaryPending || result.status === 'legacy' ? '요약 준비 중' : result.status === 'compile_error' ? '컴파일 오류' : result.status === 'runtime_error' ? '실행 오류' : result.passed === null ? '—' : result.passed ? '통과' : '실패'); cell(row, record.createdAt || '');
      if (page) { cell(row, button('결과 보기', function () { self.action(async function () { var detail = await self.api(page.path + '/' + record.id); self.renderResult(detail.result); self.results.prepend(button('제출 내역으로', function () { self.action(function () { return self.history(page.exerciseId, page.cursor, page.cursors); }); })); if (detail.project && detail.project.files && typeof detail.project.files['main.ts'] === 'string') { var code = el('details', 'ojmicrobit-card'); code.append(el('summary', '', '제출 당시 코드')); code.append(el('pre', 'ojmicrobit-review-source', detail.project.files['main.ts'])); self.results.append(code); } }); })); } rows.body.append(row);
    });
    if (!records.length) { box.append(el('p', 'ojp-empty-state', '아직 제출 내역이 없습니다. 임시 저장만 한 코드는 표시되지 않습니다.')); } else { box.append(rows.wrap); } this.results.append(box);
    if (page) { box.append(this.pageControls(page.response, page.cursors, async function (cursor, trail) { await self.history(page.exerciseId, cursor, trail); self.setRoute(self.scopedRoute('history', cursor), 'push'); })); }
  };
  App.prototype.destroy = function () { if (this.stageWizard) { this.stageWizard.close(); } this.destroyed = true; this.requests.forEach(function (controller) { controller.abort(); }); if (this.runRetryTimer) { global.clearTimeout(this.runRetryTimer); } this.closeEditor(); global.removeEventListener('beforeunload', this.beforeUnload); global.removeEventListener('pageshow', this.pageShow); global.removeEventListener('popstate', this.popState); if (this.usb) { this.usb.disconnect().catch(function () {}); } };

  var api = { expectedPreviewModel: expectedPreviewModel, resolveExpectedPreview: resolveExpectedPreview, problemRoute: problemRoute, problemUrl: problemUrl, EditorDriver: EditorDriver, canonicalProject: canonicalProject, validHex: validHex, sampleExercise: sampleExercise, servoExercise: servoExercise, i2cExercise: i2cExercise, sequenceExercise: sequenceExercise, exerciseModel: exerciseModel, updateOutputSequence: updateOutputSequence, sequenceBudget: sequenceBudget,
    stageBatchChannels: stageBatchChannels, applyStageBatch: applyStageBatch, projectScenarioSteps: projectScenarioSteps, retimeScenarioStep: retimeScenarioStep, duplicateScenarioStep: duplicateScenarioStep, removeScenarioStep: removeScenarioStep, appendScenarioStep: appendScenarioStep, newScenario: newScenario, blankExercise: blankExercise, emptyScenario: emptyScenario, flowGroups: flowGroups, applyStageWizard: applyStageWizard, fieldLabel: fieldLabel, App: App, routeSection: routeSection, internalUrl: internalUrl };
  if (typeof module === 'object' && module.exports) { module.exports = api; }
  global.OJMicrobit = api;
  if (global.document) {
    function mount() { global.document.querySelectorAll('[data-oj-microbit]').forEach(function (root) { if (!root.ojMicrobit) { try { root.ojMicrobit = new App(root); root.ojMicrobit.start(); } catch (error) { root.append(el('p', 'ojp-notice ojp-notice--danger', error.message)); } } }); }
    if (global.document.readyState === 'loading') { global.document.addEventListener('DOMContentLoaded', mount); } else { mount(); }
  }
}(typeof window !== 'undefined' ? window : globalThis));

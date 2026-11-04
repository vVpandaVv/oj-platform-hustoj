const DIGITAL_PINS = new Set([...Array(17).keys(), 19, 20]);
const ANALOG_PINS = new Set([0, 1, 2, 3, 4, 10]);
const DEPENDENCIES = new Set(['core', 'radio', 'microphone', 'servo']);
const PROJECT_KEYS = new Set(['name', 'version', 'description', 'dependencies', 'files', 'testFiles', 'supportedTargets', 'preferredEditor', 'targetVersions', 'public']);
const WORKSPACE_FILES = new Set(['_history', '.simstate.json', '.git.json']);

function safeFilename(name) {
  return typeof name === 'string' && Buffer.byteLength(name, 'utf8') <= 240 && name.trim() === name
    && /^[\p{L}\p{N}_][\p{L}\p{N}\p{M} _.\-]*\.(ts|py|blocks|json|md|jres|txt)$/u.test(name)
    && !/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:[ .]|$)/i.test(name);
}
function fileList(value, files, label) {
  if (!Array.isArray(value) || value.length > 35) reject(`Invalid ${label}.`);
  const output = [], seen = new Set();
  for (const name of value) {
    if (WORKSPACE_FILES.has(name)) continue;
    if (!safeFilename(name) || name === 'pxt.json' || !Object.hasOwn(files, name) || seen.has(name)) reject(`Invalid ${label} file: ${JSON.stringify(name).slice(0, 240)}`);
    seen.add(name); output.push(name);
  }
  if (output.length > 32) reject(`Too many ${label} files.`);
  return output;
}

function reject(message) { throw new TypeError(message); }
function object(value, name) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) reject(`${name} must be an object.`);
  return value;
}
function integer(value, min, max, name) {
  if (!Number.isSafeInteger(value) || value < min || value > max) reject(`${name} is out of range.`);
  return value;
}
function signalRange(signal) {
  return signal === 'digital' ? [0, 1] : signal === 'servo' ? [0, 180] : signal === 'servo_pulse' ? [1, 20000] : [0, 1023];
}
function signalValue(signal, value) { return integer(value, ...signalRange(signal), 'Signal value'); }
function pin(value, signal) {
  if (!DIGITAL_PINS.has(value) || (signal === 'analog' && !ANALOG_PINS.has(value))) reject('Unsupported pin.');
  return value;
}

export function validateRequest(payload) {
  object(payload, 'Request');
  const project = object(payload.project, 'Project');
  if (typeof project.name !== 'string' || !project.name.length || project.name.length > 120) reject('Invalid project name.');
  const incomingFiles = object(project.files, 'Project files');
  if (Object.keys(incomingFiles).length > 35) reject('Too many project files.');
  const files = Object.fromEntries(Object.entries(incomingFiles).filter(([name]) => !WORKSPACE_FILES.has(name)));
  const names = Object.keys(files);
  if (names.length > 32 || !names.includes('main.ts') || !names.includes('pxt.json')) reject('Missing project files.');
  let bytes = Buffer.byteLength(project.name, 'utf8');
  for (const [name, content] of Object.entries(files)) {
    if (!safeFilename(name) || typeof content !== 'string' || content.includes('\0')) reject(`Invalid project file: ${JSON.stringify(name).slice(0, 240)}`);
    bytes += Buffer.byteLength(name, 'utf8') + Buffer.byteLength(content, 'utf8');
  }
  const portable = names.map(name => name.replace(/[A-Z]/g, character => character.toLowerCase()));
  if (new Set(portable).size !== portable.length) reject('Project filenames differ only by case.');
  project.files = files;
  if (bytes > 262144) reject('Project is too large.');
  let config;
  try { config = JSON.parse(files['pxt.json']); } catch { reject('Invalid pxt.json.'); }
  object(config, 'Project config');
  for (const key of Object.keys(config)) if (!PROJECT_KEYS.has(key)) reject(`Unsupported project configuration: ${key}`);
  object(config.dependencies, 'Project dependencies');
  if (config.dependencies.core !== '*') reject('The bundled core package is required.');
  for (const [dependency, version] of Object.entries(config.dependencies)) {
    if (!DEPENDENCIES.has(dependency) || version !== '*') reject('Only bundled core/radio/microphone/servo packages are supported.');
  }
  const sourceFiles = fileList(config.files, files, 'project source list');
  if (!sourceFiles.includes('main.ts')) reject('The project source list requires main.ts.');
  let configChanged = JSON.stringify(sourceFiles) !== JSON.stringify(config.files);
  config.files = sourceFiles;
  if (config.testFiles !== undefined) {
    const testFiles = fileList(config.testFiles, files, 'test source list');
    configChanged ||= JSON.stringify(testFiles) !== JSON.stringify(config.testFiles);
    config.testFiles = testFiles;
  }
  if (configChanged) {
    files['pxt.json'] = JSON.stringify(config);
    if (Buffer.byteLength(project.name, 'utf8') + Object.entries(files).reduce((total, [name, content]) => total + Buffer.byteLength(name, 'utf8') + Buffer.byteLength(content, 'utf8'), 0) > 262144) reject('Project is too large.');
  }
  if (config.preferredEditor !== undefined && !['blocksprj', 'tsprj', 'pyprj'].includes(config.preferredEditor)) reject('Invalid preferred editor.');
  if (config.supportedTargets !== undefined && JSON.stringify(config.supportedTargets) !== '["microbit"]') reject('Invalid target.');
  if (config.targetVersions !== undefined) {
    object(config.targetVersions, 'Target versions');
    for (const [key, value] of Object.entries(config.targetVersions)) {
      if (!['target', 'targetId', 'pxt', 'targetBuild'].includes(key) || typeof value !== 'string' || !/^[A-Za-z0-9_.+-]{1,64}$/.test(value) || key === 'targetId' && value !== 'microbit') reject('Invalid target version.');
    }
  }

  const exercise = object(payload.exercise, 'Exercise');
  const devices = exercise.i2cDevices === undefined ? [] : exercise.i2cDevices;
  if (!Array.isArray(devices) || devices.length > 16) reject('Invalid I2C devices.');
  const i2cDevices = new Map();
  for (const device of devices) {
    object(device, 'I2C device');
    integer(device.address, 0, 127, 'I2C address');
    if (i2cDevices.has(device.address)) reject('Duplicate I2C address.');
    if (!Array.isArray(device.registers) || device.registers.length > 256) reject('Invalid I2C register map.');
    const registers = new Set();
    for (const entry of device.registers) {
      object(entry, 'I2C register');
      integer(entry.register, 0, 255, 'I2C register');
      integer(entry.value, 0, 255, 'I2C byte');
      if (entry.writable !== undefined && typeof entry.writable !== 'boolean') reject('Invalid I2C register write permission.');
      if (registers.has(entry.register)) reject('Duplicate I2C register.');
      registers.add(entry.register);
    }
    i2cDevices.set(device.address, registers);
  }
  if (!Array.isArray(exercise.connections) || !exercise.connections.length && !devices.length || exercise.connections.length > 19) reject('Invalid connections.');
  const connections = new Map();
  for (const connection of exercise.connections) {
    object(connection, 'Connection');
    if (!['input', 'output'].includes(connection.direction)) reject('Invalid pin direction.');
    if (!(connection.direction === 'input' ? ['digital', 'analog'] : ['digital', 'pwm', 'servo', 'servo_pulse']).includes(connection.signal)) reject('Invalid connection signal.');
    pin(connection.pin, connection.signal);
    if (devices.length && [19, 20].includes(connection.pin)) reject('P19 and P20 are reserved for the configured I2C bus.');
    if (connections.has(connection.pin)) reject('Duplicate pin connection.');
    connections.set(connection.pin, connection);
  }
  if (!Array.isArray(exercise.scenarios) || exercise.scenarios.length < 1 || exercise.scenarios.length > 20) reject('Invalid scenarios.');
  let totalDuration = 0;
  for (const scenario of exercise.scenarios) {
    object(scenario, 'Scenario');
    if (typeof scenario.id !== 'string' || scenario.id.length < 1 || scenario.id.length > 64) reject('Invalid scenario identifier.');
    totalDuration += integer(scenario.durationMs, 1, 30000, 'Scenario duration');
    if (!Array.isArray(scenario.inputs) || scenario.inputs.length > 256) reject('Invalid input timeline.');
    if (!Array.isArray(scenario.assertions) || scenario.assertions.length > 256) reject('Invalid output assertions.');
    let previousTime = -1;
    for (const event of scenario.inputs) {
      object(event, 'Input');
      integer(event.atMs, 0, scenario.durationMs, 'Input time');
      if (event.atMs < previousTime) reject('Inputs must be ordered by time.');
      previousTime = event.atMs;
      const connection = connections.get(event.pin);
      if (!connection || connection.direction !== 'input' || connection.signal !== event.signal) reject('Input does not match a connection.');
      signalValue(event.signal, event.value);
    }
    for (const connection of connections.values()) {
      if (connection.direction === 'input' && !scenario.inputs.some(event => event.pin === connection.pin && event.atMs === 0)) reject('Every input connection needs an initial value at 0ms.');
    }
    for (const assertion of scenario.assertions) {
      object(assertion, 'Assertion');
      const connection = connections.get(assertion.pin);
      if (!connection || connection.direction !== 'output' || connection.signal !== assertion.signal) reject('Assertion does not match an output connection.');
      signalValue(assertion.signal, assertion.expected);
      integer(assertion.afterMs, 0, scenario.durationMs, 'Assertion start');
      integer(assertion.withinMs, 1, scenario.durationMs, 'Response deadline');
      integer(assertion.holdMs, 1, scenario.durationMs, 'Hold duration');
      integer(assertion.tolerance, 0, assertion.signal === 'digital' ? 0 : signalRange(assertion.signal)[1], 'Tolerance');
      if (assertion.afterMs + assertion.withinMs + assertion.holdMs > scenario.durationMs) reject('Assertion exceeds scenario duration.');
    }
    const outputSequences = scenario.outputSequences === undefined ? [] : scenario.outputSequences;
    if (!Array.isArray(outputSequences) || outputSequences.length > 64) reject('Invalid output sequences.');
    let sequenceSteps = 0;
    for (const sequence of outputSequences) {
      object(sequence, 'Output sequence');
      const connection = connections.get(sequence.pin);
      if (!connection || connection.direction !== 'output' || connection.signal !== sequence.signal) reject('Sequence does not match an output connection.');
      integer(sequence.afterMs, 0, scenario.durationMs - 1, 'Sequence start');
      if (!Array.isArray(sequence.steps) || sequence.steps.length < 1 || sequence.steps.length > 20) reject('An output sequence requires 1..20 steps.');
      sequenceSteps += sequence.steps.length;
      if (sequenceSteps > 256) reject('Too many output sequence steps.');
      let latestCompletion = sequence.afterMs;
      for (const step of sequence.steps) {
        object(step, 'Sequence step');
        signalValue(sequence.signal, step.expected);
        integer(step.withinMs, 1, scenario.durationMs, 'Sequence response deadline');
        integer(step.holdMs, 1, scenario.durationMs, 'Sequence hold duration');
        const [minimum, maximum] = signalRange(sequence.signal);
        integer(step.tolerance, 0, sequence.signal === 'digital' ? 0 : maximum, 'Sequence tolerance');
        if (sequence.signal !== 'digital' && step.expected - step.tolerance <= minimum && step.expected + step.tolerance >= maximum) reject('Sequence tolerance cannot cover the entire output range.');
        latestCompletion += step.withinMs + step.holdMs;
      }
      if (latestCompletion > scenario.durationMs) reject('Output sequence exceeds scenario duration.');
    }
    const i2cInputs = scenario.i2cInputs === undefined ? [] : scenario.i2cInputs;
    const i2cAssertions = scenario.i2cAssertions === undefined ? [] : scenario.i2cAssertions;
    if (!Array.isArray(i2cInputs) || i2cInputs.length > 256) reject('Invalid I2C input timeline.');
    if (!Array.isArray(i2cAssertions) || i2cAssertions.length > 256) reject('Invalid I2C assertions.');
    let previousI2CTime = -1;
    const i2cInputKeys = new Set();
    for (const event of i2cInputs) {
      object(event, 'I2C input');
      integer(event.atMs, 0, scenario.durationMs - 1, 'I2C input time');
      if (event.atMs < previousI2CTime) reject('I2C inputs must be ordered by time.');
      previousI2CTime = event.atMs;
      integer(event.address, 0, 127, 'I2C address');
      integer(event.register, 0, 255, 'I2C register');
      integer(event.value, 0, 255, 'I2C byte');
      if (!i2cDevices.get(event.address)?.has(event.register)) reject('I2C input register is not declared.');
      const key = `${event.atMs}:${event.address}:${event.register}`;
      if (i2cInputKeys.has(key)) reject('Duplicate I2C input at the same time.');
      i2cInputKeys.add(key);
    }
    for (const assertion of i2cAssertions) {
      object(assertion, 'I2C assertion');
      integer(assertion.address, 0, 127, 'I2C address');
      if (!i2cDevices.has(assertion.address)) reject('I2C assertion device is not declared.');
      if (!['read', 'write'].includes(assertion.operation) || typeof assertion.repeated !== 'boolean') reject('Invalid I2C transaction.');
      if (!Array.isArray(assertion.data) || assertion.data.length < 1 || assertion.data.length > 64) reject('Invalid I2C assertion bytes.');
      for (const value of assertion.data) integer(value, 0, 255, 'I2C byte');
      integer(assertion.afterMs, 0, scenario.durationMs - 1, 'I2C assertion start');
      integer(assertion.withinMs, 1, scenario.durationMs, 'I2C response deadline');
      if (assertion.afterMs + assertion.withinMs > scenario.durationMs) reject('I2C assertion exceeds scenario duration.');
    }
  }
  if (totalDuration > 120000) reject('Total simulation duration is too long.');
  return payload;
}

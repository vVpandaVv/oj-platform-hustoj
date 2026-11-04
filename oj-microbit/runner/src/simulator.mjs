import vm from 'node:vm';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { peripheralInstrumentation } from './peripherals.mjs';

const require = createRequire(import.meta.url);
const runtimeSources = [
  'pxt-core/built/pxtsim.js',
  'pxt-microbit/built/common-sim.js',
  'pxt-microbit/built/sim.js',
].map(file => readFileSync(require.resolve(file), 'utf8'));

const bootstrap = `
  var clock = { now: 0, next: 1, timers: new Map(), operations: 0 };
  var console = { log(){}, warn(){}, error(){}, debug(){} };
  var navigator = {};
  var performance = { now: () => clock.now + 1 };
  var randomState = 2654435769;
  Math.random = () => { randomState ^= randomState << 13; randomState ^= randomState >>> 17; randomState ^= randomState << 5; return (randomState >>> 0) / 4294967296; };
  var originalDate = Date;
  Date = class extends originalDate {
    constructor(...args) { super(...(args.length ? args : [clock.now])); }
    static now() { return 1700000000000 + clock.now; }
  };
  function setTimeout(fn, delay, ...args) {
    if (typeof fn !== 'function') throw new Error('String timers are disabled.');
    if (clock.timers.size >= 10000) throw new Error('Timer limit exceeded.');
    var id = clock.next++;
    clock.timers.set(id, { id, at: clock.now + Math.max(1, Number(delay) || 0), fn, args });
    return id;
  }
  function clearTimeout(id) { clock.timers.delete(id); }
  function setInterval(fn, delay, ...args) {
    var id = clock.next++;
    var tick = () => { fn(...args); if (clock.timers.has(id)) clock.timers.set(id, { id, at: clock.now + Math.max(1, Number(delay) || 0), fn: tick, args: [] }); };
    clock.timers.set(id, { id, at: clock.now + Math.max(1, Number(delay) || 0), fn: tick, args: [] });
    return id;
  }
  var clearInterval = clearTimeout;
`;

const instrument = `
  var trace = [], output = new Map(), inputs = new Map(), failure = null;
  var connections = new Map(payload.connections.map(c => [c.pin, c]));
  function pinNumber(id) { return id - 100; }
  function guardPin(id) {
    var n = pinNumber(id);
    if ((payload.i2cDevices || []).length && (n === 19 || n === 20)) throw new Error('P19 and P20 are reserved for the configured I2C bus.');
  }
  function inputValue(id, signal) {
    guardPin(id);
    var n = pinNumber(id), c = connections.get(n);
    if (c && c.direction === 'output') throw new Error('Cannot read from output connection P' + n);
    if (c && c.direction === 'input') {
      if (c.signal !== signal) throw new Error('Input signal type differs on P' + n);
      var pin = pxsim.getPin(id), value = inputs.get(n) ?? 0;
      if (pin) pin.value = signal === 'digital' ? (value ? 1023 : 0) : value;
    }
  }
  function capture(id, signal, observed) {
    guardPin(id);
    var n = pinNumber(id), c = connections.get(n), pin = pxsim.getPin(id);
    if (!pin) throw new Error('Unknown output pin.');
    if (c && c.direction === 'input') throw new Error('Cannot write to input connection P' + n);
    var value = observed === undefined ? (signal === 'digital' ? (pin.value ? 1 : 0) : pin.value) : observed;
    var minimum = signal === 'servo_pulse' ? 1 : 0;
    var maximum = signal === 'digital' ? 1 : signal === 'servo' ? 180 : signal === 'servo_pulse' ? 20000 : 1023;
    if (!Number.isInteger(value) || value < minimum || value > maximum) throw new Error('Output value is out of range.');
    var last = output.get(n);
    if (!last || last.signal !== signal || last.value !== value) {
      if (trace.length >= 50000) throw new Error('Output trace limit exceeded.');
      var item = { atMs: clock.now, pin: n, signal, value, kind: 'write' };
      trace.push(item); output.set(n, item);
    }
  }
  var readDigital = pxsim.pins.digitalReadPin, readAnalog = pxsim.pins.analogReadPin;
  var writeDigital = pxsim.pins.digitalWritePin, writeAnalog = pxsim.pins.analogWritePin;
  var setPeriod = pxsim.pins.analogSetPeriod;
  var setPull = pxsim.pins.setPull;
  pxsim.pins.digitalReadPin = id => { inputValue(id, 'digital'); return readDigital(id); };
  pxsim.pins.analogReadPin = id => { inputValue(id, 'analog'); return readAnalog(id); };
  pxsim.pins.digitalWritePin = (id, value) => { guardPin(id); writeDigital(id, value); capture(id, 'digital'); };
  pxsim.pins.analogWritePin = (id, value) => { guardPin(id); writeAnalog(id, value); capture(id, 'pwm'); };
  pxsim.pins.analogSetPeriod = (id, micros) => {
    guardPin(id);
    var c = connections.get(pinNumber(id));
    if (c && (c.direction !== 'output' || c.signal !== 'pwm')) throw new Error('PWM period requires a PWM output connection.');
    setPeriod(id, micros);
  };
  pxsim.pins.setPull = (id, pull) => {
    guardPin(id);
    var c = connections.get(pinNumber(id));
    if (c && c.direction !== 'input') throw new Error('Pull configuration requires an input connection.');
    setPull(id, pull);
  };
  ${peripheralInstrumentation}
  // Peripheral APIs without a declared device model remain fail-closed.
  for (var api of ['createI2C', 'createSPI', 'spiWrite', 'spiTransfer', 'spiPins', 'spiFormat', 'spiFrequency', 'pulseIn', 'onPulsed', 'pulseDuration', 'getPinAddress', 'setEvents', 'touchSetMode', 'analogPitch', 'analogSetPitchPin', 'setAudioPin']) {
    if (typeof pxsim.pins[api] === 'function') pxsim.pins[api] = () => { throw new Error('This GPIO exercise does not support that peripheral API.'); };
  }
  for (var api of ['onPinPressed', 'onPinReleased', 'pinIsPressed']) {
    if (typeof pxsim.input[api] === 'function') pxsim.input[api] = () => { throw new Error('Touch-pin events require a separate device model. Use digital pin input for this exercise.'); };
  }
  if (pxsim.serial && pxsim.serial.redirect) pxsim.serial.redirect = () => { throw new Error('Serial pin redirection is not supported in a GPIO scenario.'); };
  if (pxsim.control && pxsim.control.reset) pxsim.control.reset = () => { throw new Error('Board reset is not supported in a GPIO scenario.'); };
  pxsim.Runtime.messagePosted = () => {};
  pxsim.initCurrentRuntime = () => {
    var b = new pxsim.DalBoard();
    b.updateView = () => {};
    b.kill = () => {};
    pxsim.runtime.board = b;
  };
  var rt = new pxsim.Runtime({ type: 'run', code: payload.js, id: 'oj-microbit', mute: true });
  rt.errorHandler = error => { failure = String(error && error.message || error).slice(0, 1000); rt.dead = true; };
  rt.postError = () => {};
  for (var c of payload.connections) if (c.direction === 'input') inputs.set(c.pin, 0);
  for (var event of payload.inputs) {
    if (event.atMs === 0) inputs.set(event.pin, event.value);
    else {
      var eventCopy = event;
      ((e) => { var id = clock.next++; clock.timers.set(id, { id, at: e.atMs, fn: () => inputs.set(e.pin, e.value), args: [] }); })(eventCopy);
    }
  }
  rt.run(() => {});
`;

/** Runs genuine PXT bytecode JavaScript; the VM is defence in depth, not an OS sandbox. */
export function simulate(js, connections, scenario, i2cDevices = []) {
  const context = vm.createContext(Object.create(null), { name: 'microbit-pxt', microtaskMode: 'afterEvaluate' });
  const run = code => vm.runInContext(code, context, { timeout: 1000 });
  try {
    run(bootstrap);
    for (const source of runtimeSources) run(source);
    run(`var payload = ${JSON.stringify({ js, connections, inputs: scenario.inputs, i2cDevices, i2cInputs: scenario.i2cInputs || [] })};`);
    run(instrument);
    let operations = 0;
    while (operations++ < 100000) {
      const state = run(`JSON.stringify({ failure, dead: rt.dead, next: Math.min(...Array.from(clock.timers.values(), t => t.at), Infinity) })`);
      const info = JSON.parse(state);
      if (info.failure) return { trace: JSON.parse(run('JSON.stringify(trace)')), i2cTrace: JSON.parse(run('JSON.stringify(i2cTrace)')), error: info.failure };
      if (info.next === null || info.next > scenario.durationMs || info.dead) break;
      run(`
        var nextTimer = Array.from(clock.timers.values()).sort((a,b) => a.at-b.at || a.id-b.id)[0];
        if (nextTimer) { clock.now = nextTimer.at; clock.timers.delete(nextTimer.id); nextTimer.fn(...nextTimer.args); }
      `);
    }
    if (operations >= 100000) return { trace: [], i2cTrace: [], error: 'Simulation operation limit exceeded.' };
    return { trace: JSON.parse(run('JSON.stringify(trace)')), i2cTrace: JSON.parse(run('JSON.stringify(i2cTrace)')), error: null };
  } catch (error) {
    return { trace: [], i2cTrace: [], error: String(error.message || error).slice(0, 1000) };
  }
}

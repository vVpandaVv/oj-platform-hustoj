import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';

export function canaryPayload() {
  const scenario = (id, value, expected) => ({
    id, name: id, visible: true, durationMs: 200,
    inputs: [{ atMs: 0, pin: 0, signal: 'analog', value }],
    assertions: [{ pin: 1, signal: 'digital', expected, afterMs: 0, withinMs: 100, holdMs: 50, tolerance: 0 }],
    i2cInputs: [], i2cAssertions: [], outputSequences: []
  });
  return {
    project: { name: 'installation-canary', files: {
      'main.ts': 'pins.digitalWritePin(DigitalPin.P1, pins.analogReadPin(AnalogPin.P0) >= 512 ? 1 : 0)',
      'pxt.json': JSON.stringify({ name: 'installation-canary', dependencies: { core: '*' }, files: ['main.ts'], preferredEditor: 'tsprj' })
    } },
    exercise: {
      connections: [{ pin: 0, direction: 'input', signal: 'analog', device: 'sensor', label: 'canary sensor' }, { pin: 1, direction: 'output', signal: 'digital', device: 'led', label: 'canary LED' }],
      i2cDevices: [], scenarios: [scenario('canary-high', 700, 1), scenario('canary-low', 100, 0)]
    }
  };
}

export async function checkRunner(mode, url, token, runtimeVersion, fetchRequest = fetch) {
  const target = new URL(url);
  if (target.protocol !== 'http:' || target.hostname !== '127.0.0.1' || target.username || target.password || target.pathname !== '/' || target.search || target.hash) throw new Error('The installer only checks its local loopback runner.');
  if (!['health', 'canary'].includes(mode) || !/^[\x21-\x7e]{32,256}$/.test(token)) throw new Error('Invalid runner check configuration.');
  const payload = mode === 'canary' ? canaryPayload() : null;
  const response = await fetchRequest(new URL(payload ? '/v1/run' : '/health', target), {
    method: payload ? 'POST' : 'GET',
    headers: { Authorization: `Bearer ${token}`, ...(payload ? { 'Content-Type': 'application/json' } : {}) },
    ...(payload ? { body: JSON.stringify(payload) } : {}), redirect: 'error', signal: AbortSignal.timeout(payload ? 60000 : 5000)
  });
  if (!response.ok) throw new Error('Runner request failed.');
  const result = await response.json();
  if (result.sandbox !== 'docker-no-network' || result.runtimeVersion !== runtimeVersion) throw new Error('Runner isolation or runtime version does not match.');
  if (!payload) {
    if (result.ready !== true || result.status !== 'ok') throw new Error('Runner is not ready.');
  } else {
    const projectHash = createHash('sha256').update(JSON.stringify(payload.project)).digest('hex');
    if (result.status !== 'graded' || result.passed !== true || result.score !== 100 || result.projectHash !== projectHash || result.scenarios?.length !== 2 || result.scenarios.some((item, index) => item.id !== payload.exercise.scenarios[index].id || item.passed !== true || item.assertions?.length !== 1 || item.assertions[0].passed !== true)) throw new Error('Real GPIO canary grading failed.');
    if (!result.trace?.some(event => event.kind === 'write' && event.pin === 1 && event.signal === 'digital' && event.value === 1)) throw new Error('GPIO canary did not produce actual output evidence.');
  }
  return true;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    let token = '';
    for await (const chunk of process.stdin) {
      token += chunk;
      if (token.length > 256) throw new Error('Invalid token length.');
    }
    await checkRunner(process.argv[2], process.argv[3], token, process.argv[4]);
    process.stdout.write(`Runner ${process.argv[2] === 'canary' ? 'GPIO canary' : 'health'} passed.\n`);
  } catch {
    process.stderr.write('Runner check failed (readiness, isolation, runtime, authentication, or actual GPIO result).\n');
    process.exitCode = 1;
  }
}

import http from 'node:http';
import { randomBytes, timingSafeEqual } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { executeJob, inspectImage, DEFAULT_IMAGE } from './launcher.mjs';
import { runtimeVersion } from './version.mjs';
import { validateRequest } from './validation.mjs';

function send(response, status, payload) {
  const candidate = payload.error || payload.status || 'ok';
  response.operationCode = ['ok', 'unavailable', 'unauthorized', 'execution_unavailable', 'execution_timeout', 'not_found', 'json_required', 'busy', 'request_too_large', 'invalid_request', 'graded', 'preview', 'compile_error', 'runtime_error'].includes(candidate) ? candidate : 'invalid_response';
  response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff', 'X-Request-ID': response.requestId,
    ...(payload.retryAfterSeconds ? { 'Retry-After': String(payload.retryAfterSeconds) } : {}) });
  response.end(JSON.stringify({ ...payload, requestId: response.requestId }));
}

export function createImageReadinessCheck(inspect, now = () => performance.now()) {
  let pending = null, ready = false, expiresAt = -Infinity;
  return () => {
    if (pending) return pending;
    if (now() < expiresAt) return Promise.resolve(ready);
    // Cache both outcomes and share an ongoing inspection across health requests.
    pending = Promise.resolve().then(inspect).then(value => value === true, () => false).then(value => {
      ready = value;
      expiresAt = now() + 5000;
      return ready;
    }).finally(() => { pending = null; });
    return pending;
  };
}

export function createServer(options = {}) {
  const token = options.token || process.env.MICROBIT_RUNNER_TOKEN || '';
  if (Buffer.byteLength(token) < 32) throw new Error('MICROBIT_RUNNER_TOKEN must contain at least 32 bytes.');
  const mode = options.mode || process.env.MICROBIT_RUNNER_MODE || 'docker';
  if (mode !== 'docker' && !(mode === 'process' && process.env.NODE_ENV === 'test')) throw new Error('Production requests require the Docker sandbox.');
  const image = options.image || process.env.MICROBIT_WORKER_IMAGE || DEFAULT_IMAGE;
  const imageReady = createImageReadinessCheck(() => (options.inspectImage || inspectImage)(image));
  const capacity = options.capacity || 2;
  const launch = options.execute || (payload => executeJob(payload, { mode, image }));
  const log = options.logger || (metadata => process.stdout.write(JSON.stringify(metadata) + '\n'));
  let active = 0;
  const server = http.createServer(async (request, response) => {
    response.requestId = randomBytes(16).toString('hex');
    const started = performance.now();
    response.once('finish', () => {
      const metadata = { requestId: response.requestId, code: response.operationCode || 'unavailable', status: response.statusCode, durationMs: Math.max(0, Math.round(performance.now() - started)) };
      if (Number.isInteger(response.workerExitCode) && response.workerExitCode >= -1 && response.workerExitCode <= 255) metadata.exitCode = response.workerExitCode;
      try { log(metadata); } catch { /* Telemetry never changes a grade or response. */ }
    });
    const supplied = Buffer.from(request.headers.authorization || '');
    const expected = Buffer.from(`Bearer ${token}`);
    if (supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) { send(response, 401, { error: 'unauthorized' }); return; }
    if (request.method === 'GET' && request.url === '/health') {
      const ready = mode === 'docker' ? await imageReady() : true;
      send(response, ready ? 200 : 503, { status: ready ? 'ok' : 'unavailable', ...(ready ? {} : { error: 'execution_unavailable', retryAfterSeconds: 5 }), ready, runtimeVersion, sandbox: mode === 'docker' ? 'docker-no-network' : 'test-process', capacity, active });
      return;
    }
    if (request.method !== 'POST' || !['/v1/run', '/v1/preview'].includes(request.url)) { send(response, 404, { error: 'not_found' }); return; }
    if (!/^application\/json(?:;|$)/i.test(request.headers['content-type'] || '')) { send(response, 415, { error: 'json_required' }); return; }
    if (active >= capacity) { send(response, 429, { error: 'busy', retryAfterSeconds: 2 }); return; }
    active++;
    try {
      const chunks = [];
      let bytes = 0;
      for await (const chunk of request) {
        bytes += chunk.length;
        if (bytes > 2097152) { send(response, 413, { error: 'request_too_large' }); request.resume(); return; }
        chunks.push(chunk);
      }
      let payload;
      try { payload = JSON.parse(Buffer.concat(chunks).toString('utf8')); validateRequest(payload); }
      catch { send(response, 400, { error: 'invalid_request', message: 'Project or exercise input is invalid.' }); return; }
      const result = await launch(payload);
      // This marker belongs to the trusted launcher, never to student code or
      // the worker result. WordPress rejects test-process grading responses.
      send(response, 200, { ...result, sandbox: mode === 'docker' ? 'docker-no-network' : 'test-process' });
    } catch (error) {
      if (Number.isInteger(error?.exitCode)) response.workerExitCode = error.exitCode;
      const invalid = error?.code === 'invalid_request';
      const timedOut = error?.code === 'execution_timeout';
      send(response, invalid ? 400 : timedOut ? 504 : 503, { error: invalid ? 'invalid_request' : timedOut ? 'execution_timeout' : 'execution_unavailable',
        message: invalid ? 'Project or exercise input is invalid.' : timedOut ? 'Isolated execution time limit exceeded.' : 'The isolated execution service is unavailable.',
        ...(!invalid ? { retryAfterSeconds: 5 } : {}) });
    } finally { active--; }
  });
  server.requestTimeout = 45000;
  server.headersTimeout = 5000;
  server.keepAliveTimeout = 5000;
  return server;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const host = process.env.MICROBIT_RUNNER_HOST || '127.0.0.1';
  const mode = process.env.MICROBIT_RUNNER_MODE || 'docker';
  if (mode !== 'docker' && !['127.0.0.1', '::1'].includes(host)) throw new Error('Test execution must bind to loopback.');
  const port = Number(process.env.MICROBIT_RUNNER_PORT || 3037);
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Invalid port.');
  const server = createServer();
  server.listen(port, host, () => process.stdout.write(`OJ micro:bit runner listening on ${host}:${port}\n`));
}

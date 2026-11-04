import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createImageReadinessCheck, createServer } from '../src/server.mjs';
import { request } from './fixtures.mjs';
import { executeJob } from '../src/launcher.mjs';

const token = 'local-integration-token-32-bytes-minimum';
async function withServer(operation, options = {}) {
  const previous = process.env.NODE_ENV;
  process.env.NODE_ENV = 'test';
  const server = createServer({ token, mode: 'process', ...options });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const url = `http://127.0.0.1:${server.address().port}`;
  try { await operation(url); }
  finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); if (previous === undefined) delete process.env.NODE_ENV; else process.env.NODE_ENV = previous; }
}
const headers = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };

test('HTTP service requires authentication even for health and validates requests', async () => {
  await withServer(async url => {
    assert.equal((await fetch(`${url}/health`)).status, 401);
    const health = await fetch(`${url}/health`, { headers });
    assert.equal(health.status, 200);
    assert.equal((await health.json()).sandbox, 'test-process');
    assert.equal((await fetch(`${url}/v1/run`, { method: 'POST', headers, body: '{}' })).status, 400);
    assert.equal((await fetch(`${url}/v1/run`, { method: 'POST', headers: { Authorization: headers.Authorization }, body: '{}' })).status, 415);
  });
});
test('HTTP service runs the genuine PXT compiler in a fresh child process', async () => {
  await withServer(async url => {
    const response = await fetch(`${url}/v1/run`, { method: 'POST', headers, body: JSON.stringify(request()) });
    const result = await response.json();
    assert.equal(response.status, 200, JSON.stringify(result));
    assert.equal(result.passed, true);
    assert.equal(result.score, 100);
    assert.equal(result.sandbox, 'test-process');
    assert.equal(result.trace.find(event => event.pin === 1 && event.value === 1).atMs, 320);
  });
});
test('production startup rejects process execution without explicit test mode', () => {
  const previous = process.env.NODE_ENV;
  process.env.NODE_ENV = 'production';
  try { assert.throws(() => createServer({ token, mode: 'process' }), /Docker sandbox/); }
  finally { process.env.NODE_ENV = previous; }
});
test('isolation metadata belongs to the launcher and cannot be supplied by the worker result', async () => {
  await withServer(async url => {
    const response = await fetch(`${url}/v1/run`, { method: 'POST', headers, body: JSON.stringify(request()) });
    assert.equal(response.status, 200);
    assert.equal((await response.json()).sandbox, 'docker-no-network');
  }, { mode: 'docker', execute: async () => ({ sandbox: 'test-process' }) });
});
test('image readiness shares pending inspections and caches successful and failed checks for five seconds', async () => {
  let atMs = 0, calls = 0, complete;
  const check = createImageReadinessCheck(() => {
    calls++;
    return new Promise(resolve => { complete = resolve; });
  }, () => atMs);
  const first = check();
  assert.strictEqual(check(), first);
  await Promise.resolve();
  assert.equal(calls, 1);
  atMs = 1000;
  complete(true);
  assert.equal(await first, true);
  atMs = 5999;
  assert.equal(await check(), true);
  assert.equal(calls, 1);
  atMs = 6000;
  const expired = check();
  assert.strictEqual(check(), expired);
  await Promise.resolve();
  assert.equal(calls, 2);
  complete(false);
  assert.equal(await expired, false);
  atMs = 10999;
  assert.equal(await check(), false);
  assert.equal(calls, 2);
  atMs = 11000;
  const retry = check();
  await Promise.resolve();
  assert.equal(calls, 3);
  complete(true);
  assert.equal(await retry, true);
});
test('a rejected image inspection fails closed and remains cached', async () => {
  let calls = 0;
  const check = createImageReadinessCheck(async () => { calls++; throw new Error('Docker unavailable'); }, () => 0);
  assert.equal(await check(), false);
  assert.equal(await check(), false);
  assert.equal(calls, 1);
});
test('Docker health requests reuse image readiness without launching student jobs', async () => {
  let inspections = 0, launches = 0;
  await withServer(async url => {
    const responses = await Promise.all(Array.from({ length: 8 }, () => fetch(`${url}/health`, { headers })));
    for (const response of responses) {
      assert.equal(response.status, 200);
      assert.equal((await response.json()).sandbox, 'docker-no-network');
    }
    assert.equal(inspections, 1);
    assert.equal(launches, 0);
  }, { mode: 'docker', inspectImage: async () => { inspections++; return true; }, execute: async () => { launches++; return {}; } });
});
test('busy requests have bounded retry metadata, trusted IDs, and recover without automatic retries', async () => {
  let entered, release, calls = 0;
  const ready = new Promise(resolve => { entered = resolve; });
  const hold = new Promise(resolve => { release = resolve; });
  const logs = [];
  await withServer(async url => {
    const first = fetch(`${url}/v1/run`, { method: 'POST', headers, body: JSON.stringify(request()) });
    await ready;
    const busy = await fetch(`${url}/v1/run`, { method: 'POST', headers: { ...headers, 'X-Request-ID': 'forged-source-secret' }, body: JSON.stringify(request()) });
    const data = await busy.json();
    assert.equal(busy.status, 429);
    assert.equal(busy.headers.get('retry-after'), '2');
    assert.equal(data.retryAfterSeconds, 2);
    assert.match(data.requestId, /^[a-f0-9]{32}$/);
    assert.equal(busy.headers.get('x-request-id'), data.requestId);
    assert.equal(calls, 1);
    release();
    assert.equal((await first).status, 200);
    assert.equal((await fetch(`${url}/v1/run`, { method: 'POST', headers, body: JSON.stringify(request()) })).status, 200);
    assert.equal(calls, 2);
  }, { capacity: 1, logger: value => logs.push(value), execute: async () => { calls++; entered(); await hold; return { status: 'graded' }; } });
  assert.ok(logs.some(item => item.code === 'busy' && item.status === 429));
  assert.equal(JSON.stringify(logs).includes('forged-source-secret'), false);
});
test('worker timeout and infrastructure errors expose safe codes and safe log metadata only', async () => {
  const logs = [];
  let call = 0;
  await withServer(async url => {
    for (const [status, code] of [[504, 'execution_timeout'], [503, 'execution_unavailable']]) {
      const response = await fetch(`${url}/v1/run`, { method: 'POST', headers, body: JSON.stringify(request()) });
      const data = await response.json();
      assert.equal(response.status, status);
      assert.equal(data.error, code);
      assert.equal(data.retryAfterSeconds, 5);
      assert.equal(JSON.stringify(data).includes('RAW_SOURCE_TOKEN_PRIVATE_INPUT'), false);
    }
  }, { logger: value => logs.push(value), execute: async () => { const error = new Error('RAW_SOURCE_TOKEN_PRIVATE_INPUT'); error.code = call++ === 0 ? 'execution_timeout' : 'unknown_private_error'; error.exitCode = 137; throw error; } });
  assert.equal(JSON.stringify(logs).includes('RAW_SOURCE_TOKEN_PRIVATE_INPUT'), false);
  assert.equal(logs[1].exitCode, 137);
  assert.deepEqual(Object.keys(logs[0]).sort(), ['requestId', 'code', 'status', 'durationMs', 'exitCode'].sort());
});
test('the actual process watchdog returns a typed timeout without exposing worker details', async () => {
  await assert.rejects(executeJob(request(), { mode: 'process', timeoutMs: 1 }), error => error.code === 'execution_timeout');
});
test('genuine compiler/runtime student failures remain HTTP 200 grades and the service recovers', async () => {
  await withServer(async url => {
    for (const [source, status] of [['let broken: = 1', 'compile_error'], ['pins.spiWrite(1)', 'runtime_error']]) {
      const response = await fetch(`${url}/v1/run`, { method: 'POST', headers, body: JSON.stringify(request(source)) });
      const data = await response.json();
      assert.equal(response.status, 200);
      assert.equal(data.status, status);
      assert.equal(data.passed, false);
      assert.match(data.requestId, /^[a-f0-9]{32}$/);
    }
    const response = await fetch(`${url}/v1/run`, { method: 'POST', headers, body: JSON.stringify(request()) });
    assert.equal((await response.json()).passed, true);
  }, { logger: () => {} });
});

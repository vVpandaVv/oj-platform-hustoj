import test from 'node:test';
import assert from 'node:assert/strict';
import { grade } from '../src/grade.mjs';
import { runtimeVersion } from '../src/version.mjs';
import { canaryPayload, checkRunner } from '../install-tools/runner-check.mjs';

const token = 'installer-test-token-at-least-32-bytes';
const url = 'http://127.0.0.1:3037';
const response = result => ({ ok: true, json: async () => result });

test('installation canary uses the real PXT compiler and validates input/output evidence', async () => {
  const result = { ...await grade(canaryPayload()), sandbox: 'docker-no-network' };
  assert.equal(result.passed, true);
  assert.equal(result.score, 100);
  assert.equal(result.scenarios.length, 2);
  await checkRunner('canary', url, token, runtimeVersion, async (target, options) => {
    assert.equal(target.href, `${url}/v1/run`);
    assert.equal(options.headers.Authorization, `Bearer ${token}`);
    assert.equal(options.redirect, 'error');
    assert.deepEqual(JSON.parse(options.body), canaryPayload());
    return response(result);
  });
  for (const change of [{ sandbox: 'test-process' }, { runtimeVersion: 'wrong' }, { score: 0 }, { projectHash: 'wrong' }, { trace: [] }]) {
    await assert.rejects(checkRunner('canary', url, token, runtimeVersion, async () => response({ ...result, ...change })));
  }
});

test('read-only installer health requires the exact production readiness contract', async () => {
  const result = { ready: true, status: 'ok', runtimeVersion, sandbox: 'docker-no-network' };
  await checkRunner('health', url, token, runtimeVersion, async (target, options) => {
    assert.equal(target.href, `${url}/health`);
    assert.equal(options.method, 'GET');
    assert.equal(options.body, undefined);
    return response(result);
  });
  await assert.rejects(checkRunner('health', url, token, runtimeVersion, async () => response({ ...result, ready: false })));
  await assert.rejects(checkRunner('health', url, token, runtimeVersion, async () => ({ ok: false })));
  let calls = 0;
  for (const target of ['https://127.0.0.1:3037', 'http://example.com:3037', 'http://127.0.0.1:3037/path', 'http://user:password@127.0.0.1:3037']) {
    await assert.rejects(checkRunner('health', target, token, runtimeVersion, async () => { calls++; return response(result); }));
  }
  assert.equal(calls, 0);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { grade } from '../src/grade.mjs';
import { compileLive } from '../src/live-compile.mjs';
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
  const result = { ready: true, status: 'ok', runtimeVersion, liveProtocol:'oj-live/1', releaseVersion:'1.0.0-rc.14', sandbox: 'docker-no-network' };
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


test('compile canary validates real PXT output and rejects missing or forged compilation',async()=>{
 const result={...await compileLive({project:canaryPayload().project}),sandbox:'docker-no-network'};
 await checkRunner('compile',url,token,runtimeVersion,async(target,options)=>{assert.equal(target.href,url+'/v1/compile');assert.deepEqual(Object.keys(JSON.parse(options.body)),['project']);return response(result);});
 for(const change of [{status:'compile_error'},{liveProtocol:'old'},{sandbox:'test-process'},{projectHash:'wrong'},{js:''},{score:100},{diagnostics:[{message:'error'}]}])await assert.rejects(checkRunner('compile',url,token,runtimeVersion,async()=>response({...result,...change})));
});
test('health requires the selected live release while restored health accepts an older healthy runtime',async()=>{
 const result={ready:true,status:'ok',runtimeVersion,sandbox:'docker-no-network',releaseVersion:'1.0.0-rc.14',liveProtocol:'oj-live/1'};
 await checkRunner('health',url,token,runtimeVersion,async()=>response(result),'1.0.0-rc.14');
 for(const change of [{releaseVersion:undefined},{releaseVersion:'1.0.0-rc.13'},{liveProtocol:undefined}])await assert.rejects(checkRunner('health',url,token,runtimeVersion,async()=>response({...result,...change}),'1.0.0-rc.14'));
 const restored={...result,releaseVersion:undefined,liveProtocol:undefined,runtimeVersion:'pxt-microbit/8.0.0;pxt-core/12.0.0;oj-gpio/2'};
 await checkRunner('restored-health',url,token,runtimeVersion,async(target,options)=>{assert.equal(options.method,'GET');assert.equal(options.body,undefined);return response(restored);},'1.0.0-rc.14');
 for(const change of [{ready:false},{sandbox:'test-process'},{runtimeVersion:'not-pxt'}])await assert.rejects(checkRunner('restored-health',url,token,runtimeVersion,async()=>response({...restored,...change})));
});

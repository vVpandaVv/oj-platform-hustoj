import test from 'node:test';
import assert from 'node:assert/strict';
import {compileLive} from '../src/live-compile.mjs';
import {validateCompileRequest} from '../src/validation.mjs';
import {request} from './fixtures.mjs';
import {executeJob} from '../src/launcher.mjs';
test('live compilation returns actual PXT bytecode but no scenario/grade/hidden data',async()=>{
 const payload=request(),result=await compileLive({project:payload.project});assert.equal(result.status,'compiled');assert.equal(result.liveProtocol,'oj-live/1');assert.match(result.js,/pxsim/);assert.equal(result.projectHash.length,64);for(const key of ['score','passed','scenarios','trace','exercise'])assert.equal(result[key],undefined);
});
test('compile-only jobs use the same isolated process entry point',async()=>{
 const result=await executeJob({job:'compile',project:request().project},{mode:'process'});assert.equal(result.status,'compiled');assert.equal(typeof result.js,'string');assert.equal(result.scenarios,undefined);
});
test('invalid source produces diagnostics and cannot emit executable bytecode',async()=>{
 const project=request().project;project.files['main.ts']='let value: number = "invalid"';const result=await compileLive({project});assert.equal(result.status,'compile_error');assert(result.diagnostics.length);assert.equal(result.js,undefined);
});
test('compile-only policy rejects declarations, native metadata and remote dependencies',async()=>{
 for(const source of ['declare function external(): void;', '//% shim=pxsim.debugger\nfunction evil() {}']){const project=request().project;project.files['main.ts']=source;assert.equal((await compileLive({project})).status,'compile_error');}
 const project=request().project,config=JSON.parse(project.files['pxt.json']);config.dependencies.extra='github:remote/repo';project.files['pxt.json']=JSON.stringify(config);assert.throws(()=>validateCompileRequest({project}),/bundled/);
});

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const addonPath = new URL('../resources/js/addon.js', import.meta.url);
const source = readFileSync(addonPath, 'utf8');
const match = source.match(/\tfunction runtimeCodeLabel\(runtime\) \{[\s\S]*?\n\t\}/);

assert.ok(match, 'The runtime diagnostic label helper must exist.');
const runtimeCodeLabel = vm.runInNewContext(`(${match[0].trim()})`);

assert.equal(runtimeCodeLabel({ state: 'ready', code: '' }), '없음 (정상)');
assert.equal(runtimeCodeLabel({ state: 'ready', code: '   ' }), 'invalid');
assert.equal(runtimeCodeLabel({ state: 'failed', code: 'addon_runtime_invalid' }), 'addon_runtime_invalid');
assert.equal(runtimeCodeLabel({ state: 'unavailable', code: 'addon_api_unavailable' }), 'addon_api_unavailable');
assert.equal(runtimeCodeLabel({ state: 'ready' }), 'unknown');
assert.equal(runtimeCodeLabel({}), 'unknown');
assert.equal(runtimeCodeLabel(null), 'unknown');

console.log('OJ ML runtime code label regression passed.');

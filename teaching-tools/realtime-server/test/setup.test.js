'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { decode, render } = require('../src/setup');

test('decodes and renders a setup code', () => {
    const env = {
        REALTIME_SECRET: 'd'.repeat(48),
        ALLOWED_ORIGINS: 'https://oj.school.kr',
        PORT: '3210',
        HOST: '127.0.0.1',
        WS_PATH: '/classroom-live',
    };
    const code = 'ojrt1.' + Buffer.from(JSON.stringify({ v: 1, env })).toString('base64url');
    assert.deepEqual(decode(code), env);
    assert.match(render(env), /REALTIME_SECRET=/);
});

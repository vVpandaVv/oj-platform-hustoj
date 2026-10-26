'use strict';

// Production permission/controller/limiter and response-header code over HTTP.
// WordPress identity/cookies are fixture data; no live site is contacted.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const temp = path.join(root, '_notes/tmp/focus-tracker-tests');
fs.mkdirSync(temp, { recursive: true });
const database = path.join(temp, `http-security-${process.pid}-${Date.now()}.sqlite`);
let server; let base; let output = ''; let checks = 0;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(value, message) { assert.ok(value, message); checks += 1; }
async function call(url, body, nonce = 'focus-test-nonce') {
    return fetch(base + url, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify(body) });
}
(async () => {
    const port = await new Promise(resolve => { const socket = net.createServer(); socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)); }); });
    base = `http://127.0.0.1:${port}`;
    server = spawn(process.env.PHP_BINARY || (process.platform === 'win32' ? 'D:/php/php.exe' : 'php'), ['-d', 'extension=pdo_sqlite', '-S', `127.0.0.1:${port}`, path.join(__dirname, 'http-fixture.php')], { cwd: root, env: { ...process.env, FOCUS_TRACKER_TEST_DB: database }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    server.stdout.on('data', data => { output += data; }); server.stderr.on('data', data => { output += data; });
    let started = false;
    for (let attempt = 0; attempt < 100; attempt += 1) { try { if ((await call('/__test/state')).ok) { started = true; break; } } catch (_) {} await delay(75); }
    assert.ok(started, 'PHP HTTP fixture must start.');
    await call('/__test/reset', {});
    check((await call('/wp-json/focus-tracker/v1/checks/next', { problem_id: 123 }, 'bad-nonce')).status === 403, 'A bad nonce is rejected before processing.');
    check((await call('/wp-json/focus-tracker/v1/checks/next', { user_id: 2, problem_id: 123 })).status === 403, 'A stale account payload is rejected.');
    check((await call('/wp-json/focus-tracker/v1/checks/next', { problem_id: 123, draft: { language_id: 6, source: 'x'.repeat(525000) } })).status === 413, 'Oversized request bytes are rejected over HTTP.');
    let limited;
    for (let attempt = 0; attempt < 125; attempt += 1) {
        const response = await call('/wp-json/focus-tracker/v1/checks/next', { problem_id: 123 });
        if (response.status === 429) { limited = response; break; }
        assert.equal(response.status, 200, 'Normal budget requests remain successful.');
        assert.equal((await response.json()).status, 'inactive');
    }
    check(!!limited, 'The independent request budget eventually enforces 429.');
    const retry = Number(limited.headers.get('Retry-After'));
    check(Number.isInteger(retry) && retry >= 1 && retry <= 60, 'The actual HTTP response includes a bounded Retry-After header.');
    check((await limited.json()).code === 'focus_tracker_rate_limited', 'The rate-limited response uses the production error contract.');
    const state = await (await call('/__test/state')).json();
    check(state.participations.length === 0 && state.events.length === 0 && state.checks.length === 0, 'Outside-time retry traffic never creates learning data or draft snapshots.');
    check((await call('/wp-json/focus-tracker/v1/tracking/state', { user_id: 1, problem_id: 123, context_type: 'general', context_id: null })).status === 200, 'One channel limit does not block the scheduled-state control channel.');
    console.log(`HTTP security: ${checks} checks passed (nonce, account isolation, body limits, 429/Retry-After, no out-of-window records).`);
})().catch(error => { console.error(error.stack || error); console.error(output.slice(-7000)); process.exitCode = 1; }).finally(async () => {
    if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
    for (const suffix of ['', '-journal', '-wal', '-shm']) { try { fs.unlinkSync(database + suffix); } catch (error) { if (error.code !== 'ENOENT') throw error; } }
});

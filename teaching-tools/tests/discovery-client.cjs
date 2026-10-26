const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

function fixture() {
    let now = 0, sequence = 0, fail = false;
    let payload = { classroom: { active: false }, items: [] };
    const calls = [], timers = new Map();
    const document = { visibilityState: 'visible' };
    const window = {
        setTimeout(fn, delay) { const id = ++sequence; timers.set(id, { fn, at: now + delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) {
            calls.push({ url, options });
            return Promise.resolve({ ok: !fail, json: () => Promise.resolve(payload) });
        },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../resources/js/discovery-client.js'), 'utf8'), {
        window, document, AbortController, Date: { now: () => now },
    });
    const config = { restUrl: '/api', currentUserId: 2, nonce: 'signed' };
    return { window, document, config, calls, timers, read: (force) => window.HttDiscovery.read(config, force),
        time: (value) => { now = value; }, payload: (value) => { payload = value; }, fail: (value) => { fail = value; } };
}

test('classroom and assigned widgets share one authenticated in-flight request', async () => {
    const f = fixture();
    const [classroom, assigned] = await Promise.all([f.read(), f.read()]);
    assert.strictEqual(classroom, assigned);
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].url, '/api/discovery');
    assert.equal(f.calls[0].options.headers['X-WP-Nonce'], 'signed');
    assert.equal(f.calls[0].options.credentials, 'same-origin');
    assert.equal(f.timers.size, 0);
});

test('empty discovery backs off to 30 seconds, active lessons return to five seconds', async () => {
    const f = fixture();
    for (let time = 0; time <= 60000; time += 5000) { f.time(time); await f.read(); }
    assert.equal(f.calls.length, 4, '0, 5, 15 and 35 seconds, instead of two requests every five seconds');
    f.time(65000); f.payload({ classroom: { active: true }, items: [{ joinCode: 'NEW123' }] });
    const discovered = await f.read();
    assert.equal(discovered.items[0].joinCode, 'NEW123');
    f.time(70000); await f.read();
    assert.equal(f.calls.length, 6, 'Newly assigned lessons restore the active interval');
});

test('hidden pages make no requests and returning to a page refreshes immediately', async () => {
    const f = fixture(); await f.read();
    f.document.visibilityState = 'hidden'; f.time(60000); await f.read(true);
    assert.equal(f.calls.length, 1);
    f.document.visibilityState = 'visible'; await Promise.all([f.read(true), f.read(true)]);
    assert.equal(f.calls.length, 2, 'Two visibility handlers must still share a request');
});

test('failures back off and cached data never crosses actor or nonce boundaries', async () => {
    const f = fixture(); f.fail(true);
    await assert.rejects(f.read()); f.time(5000); await assert.rejects(f.read());
    assert.equal(f.calls.length, 1);
    f.fail(false); f.time(10000); await f.read();
    await f.window.HttDiscovery.read({ ...f.config, currentUserId: 3 });
    await f.window.HttDiscovery.read({ ...f.config, nonce: 'renewed' });
    assert.equal(f.calls.length, 4);
});

const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
function fixture(ticket = () => Promise.resolve({ mode: 'websocket', url: 'ws://test', ticket: 'signed' })) {
    let now = 0, id = 0;
    const timers = new Map(), sockets = [], statuses = [];
    const set = (fn, delay, interval = false) => { const key = ++id; timers.set(key, { fn, at: now + delay, delay, interval }); return key; };
    class Socket {
        static OPEN = 1;
        constructor() { this.readyState = 0; this.sent = []; sockets.push(this); }
        send(raw) { this.sent.push(JSON.parse(raw)); }
        close() { this.readyState = 3; this.onclose?.(); }
        open() { this.readyState = 1; this.onopen(); }
        receive(message) { this.onmessage({ data: JSON.stringify(message) }); }
    }
    const window = { setTimeout: set, clearTimeout: (key) => timers.delete(key), setInterval: (fn, delay) => set(fn, delay, true), clearInterval: (key) => timers.delete(key) };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../resources/js/realtime-client.js'), 'utf8'), { window, WebSocket: Socket, Date: { now: () => now } });
    const connection = window.HttRealtime.connect({ ticket, onStatus: (value) => statuses.push(value) });
    const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
    async function advance(ms) {
        const target = now + ms;
        for (;;) {
            const next = [...timers].sort((a, b) => a[1].at - b[1].at).find((entry) => entry[1].at <= target);
            if (!next) break;
            now = next[1].at; timers.delete(next[0]);
            if (next[1].interval) timers.set(next[0], { ...next[1], at: now + next[1].delay });
            next[1].fn(); await flush();
        }
        now = target; await flush();
    }
    return { sockets, statuses, connection, flush, advance, timers };
}
test('ticket deadline returns to fallback instead of waiting forever', async () => {
    const f = fixture(() => new Promise(() => {}));
    await f.flush(); await f.advance(8000);
    assert.equal(f.statuses.at(-1), 'fallback'); f.connection.stop();
});
test('socket handshake deadline closes and reconnects', async () => {
    const f = fixture(); await f.flush(); f.sockets[0].open(); await f.advance(8000);
    assert.equal(f.sockets[0].readyState, 3); assert.equal(f.statuses.at(-1), 'retry');
    await f.advance(500); assert.equal(f.sockets.length, 2); f.connection.stop();
});
test('heartbeat detects an open but silent socket and stop cancels watchdogs', async () => {
    const f = fixture(); await f.flush(); const socket = f.sockets[0]; socket.open(); socket.receive({ t: 'ready', heartbeat: true });
    await f.advance(15000); assert.equal(socket.sent.at(-1).t, 'ping');
    socket.receive({ t: 'pong' }); await f.advance(15000); assert.equal(socket.readyState, 1);
    await f.advance(10000); assert.equal(socket.readyState, 3);
    f.connection.stop(); assert.equal(f.timers.size, 0);
});
test('old modules without heartbeat remain usable and late tickets cannot restart a stopped connection', async () => {
    const f = fixture(); await f.flush(); f.sockets[0].open(); f.sockets[0].receive({ t: 'ready' });
    await f.advance(60000); assert.equal(f.sockets[0].readyState, 1); f.connection.stop();
    let resolve; const late = fixture(() => new Promise((done) => { resolve = done; })); await late.flush();
    late.connection.stop(); resolve({ mode: 'websocket', url: 'ws://test', ticket: 'signed' }); await late.flush();
    assert.equal(late.sockets.length, 0);
});

test('disconnected preview fragments are not replayed as incomplete strokes', async () => {
    const f = fixture(); await f.flush();
    for (const t of ['preview', 'preview-start', 'preview-points', 'preview-shape']) {
        assert.equal(f.connection.send({ t, id: 'stale', points: [[1, 2]] }), false);
    }
    assert.equal(f.connection.queue.length, 0);
    f.connection.send({ t: 'snapshot', state: { revision: 1 } });
    f.connection.send({ t: 'snapshot', state: { revision: 2 } });
    const socket = f.sockets[0]; socket.open(); socket.receive({ t: 'ready' });
    assert.deepEqual(socket.sent.map((message) => message.t), ['hello', 'snapshot']);
    assert.equal(socket.sent.at(-1).state.revision, 2);
    f.connection.stop();
});

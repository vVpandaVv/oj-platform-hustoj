'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { spawn } = require('node:child_process');
const WebSocket = require('ws');
const { issueTicket, signRequest } = require('../src/sign');

const SECRET = 's'.repeat(48);
const PORT = 3290 + Math.floor(Math.random() * 500);
const BASE = `http://127.0.0.1:${PORT}/arena`;

let proc;

test.before(async () => {
    proc = spawn(process.execPath, [path.join(__dirname, '..', 'src', 'index.js')], {
        env: { ...process.env, ARENA_SECRET: SECRET, PORT: String(PORT), HOST: '127.0.0.1', WP_API: '', ALLOWED_ORIGINS: 'https://oj.test' },
        stdio: 'pipe',
    });
    for (let k = 0; k < 50; k++) {
        try {
            const res = await fetch(BASE + '/healthz');
            if (res.ok) return;
        } catch { /* not up yet */ }
        await new Promise((r) => setTimeout(r, 100));
    }
    throw new Error('server did not start');
});

test.after(() => proc && proc.kill());

async function api(route, body) {
    const raw = JSON.stringify(body);
    const { time, signature } = signRequest(SECRET, 'POST', route, raw);
    const res = await fetch(BASE + route, { method: 'POST', headers: { 'x-arena-time': time, 'x-arena-signature': signature }, body: raw });
    return { status: res.status, body: await res.json() };
}

function client(claims, origin = 'https://oj.test') {
    const ws = new WebSocket(`ws://127.0.0.1:${PORT}/arena`, { origin });
    const inbox = [];
    const waiters = [];
    let rid = 0;
    ws.on('message', (d) => {
        const msg = JSON.parse(d.toString());
        inbox.push(msg);
        for (const w of [...waiters]) if (w.pred(msg)) { waiters.splice(waiters.indexOf(w), 1); w.resolve(msg); }
    });
    const api = {
        ws, inbox,
        open: () => new Promise((resolve, reject) => { ws.once('open', resolve); ws.once('error', reject); ws.once('unexpected-response', (_r, res) => reject(new Error('HTTP ' + res.statusCode))); }),
        wait(pred, ms = 3000) {
            const hit = inbox.find(pred);
            if (hit) return Promise.resolve(hit);
            return new Promise((resolve, reject) => {
                waiters.push({ pred, resolve });
                setTimeout(() => reject(new Error('timeout')), ms);
            });
        },
        req(t, extra = {}) {
            const id = ++rid;
            ws.send(JSON.stringify({ t, rid: id, ...extra }));
            return api.wait((m) => m.t === 'ack' && m.rid === id);
        },
        hello() {
            return api.req('hello', { ticket: issueTicket(SECRET, { v: 1, exp: Date.now() / 1000 + 60, ...claims }) });
        },
    };
    return api;
}

test('rejects unsigned API calls and foreign origins', async () => {
    const res = await fetch(BASE + '/api/sessions', { method: 'POST', body: '{}' });
    assert.equal(res.status, 401);
    const c = client({ uid: 1, role: 'learner' }, 'https://evil.test');
    await assert.rejects(c.open());
});

test('class session end to end', async () => {
    const up = await api('/api/sessions', {
        id: 77, title: '3반', settings: { teams: 2, limitMin: 2 },
        roster: [{ uid: 10, name: '가' }, { uid: 11, name: '나' }],
        problems: { normal: [{ code: 'x = 1', tags: ['변수'] }], boss: [] },
    });
    assert.equal(up.body.ok, true);

    const outsider = client({ uid: 99, name: '?', role: 'learner', sid: 77 });
    await outsider.open();
    assert.equal((await outsider.hello()).why, 'not-in-roster');

    const teacher = client({ uid: 1, name: 'T', role: 'teacher', sid: 77 });
    await teacher.open();
    assert.equal((await teacher.hello()).ok, true);

    const s = client({ uid: 10, name: '가', role: 'learner', sid: 77 });
    await s.open();
    assert.equal((await s.hello()).ok, true);
    await s.wait((m) => m.t === 'arena');
    assert.equal((await s.req('team', { no: 1 })).ok, true);

    assert.equal((await teacher.req('start')).ok, true);
    const state = await s.wait((m) => m.t === 'state');
    assert.equal(state.cells.length, 24);
    assert.ok(state.startMs > 0, 'countdown first');
    assert.equal((await s.req('occupy')).why, 'countdown');

    assert.equal((await teacher.req('stop')).ok, true);
    const result = await s.wait((m) => m.t === 'round_result');
    assert.equal(result.result.sessionId, 77);
    assert.equal(result.result.teams[0].players[0].uid, 10);

    const closed = await api('/api/sessions/close', { id: 77 });
    assert.equal(closed.body.existed, true);
    await s.wait((m) => m.t === 'closed');
    for (const c of [outsider, teacher, s]) c.ws.close();
});

test('free rooms: create, list, join', async () => {
    const a = client({ uid: 20, name: 'A', role: 'learner', sid: 0, free: true });
    const b = client({ uid: 21, name: 'B', role: 'learner', sid: 0, free: true });
    await a.open(); await b.open();
    await a.hello(); await b.hello();
    const made = await a.req('room_create', { title: '연습', settings: { limitMin: 1 } });
    assert.equal(made.ok, true);
    const list = await b.wait((m) => m.t === 'rooms' && m.rooms.length > 0);
    assert.equal(list.rooms[0].title, '연습');
    assert.equal((await b.req('room_join', { id: made.id })).ok, true);
    assert.equal((await b.req('start')).why, 'not-host');
    assert.equal((await a.req('start')).ok, true);
    await b.wait((m) => m.t === 'state');
    a.ws.close(); b.ws.close();
});

test('free lobby needs the free claim', async () => {
    const c = client({ uid: 30, name: 'C', role: 'learner', sid: 0, free: false });
    await c.open();
    assert.equal((await c.hello()).why, 'free-off');
    c.ws.close();
});

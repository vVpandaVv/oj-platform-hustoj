'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const net = require('node:net');
const path = require('node:path');
const { spawn } = require('node:child_process');
const WebSocket = require('ws');

const secret = 'e'.repeat(48);
const origin = 'https://oj.school.kr';

function freePort() {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.on('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const port = server.address().port;
            server.close(() => resolve(port));
        });
    });
}

function ticket(role, uid) {
    const now = Math.floor(Date.now() / 1000);
    const payload = Buffer.from(JSON.stringify({
        v: 1,
        app: 'teaching-tools',
        channel: 'whiteboard',
        room: 'whiteboard:77',
        role,
        uid,
        iat: now,
        exp: now + 120,
    })).toString('base64url');
    return payload + '.' + crypto.createHmac('sha256', secret).update(payload).digest('base64url');
}

function nextMessage(socket, type) {
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('message timeout: ' + type)), 3000);
        function onMessage(raw) {
            const message = JSON.parse(raw.toString('utf8'));
            if (message.t !== type) return;
            clearTimeout(timeout);
            socket.off('message', onMessage);
            resolve(message);
        }
        socket.on('message', onMessage);
    });
}

async function connect(url, role, uid) {
    const socket = new WebSocket(url, { headers: { Origin: origin } });
    await new Promise((resolve, reject) => {
        socket.once('open', resolve);
        socket.once('error', reject);
    });
    const ready = nextMessage(socket, 'ready');
    socket.send(JSON.stringify({ t: 'hello', ticket: ticket(role, uid) }));
    await ready;
    return socket;
}

test('relays a live whiteboard snapshot over a real WebSocket', async (context) => {
    const port = await freePort();
    const cwd = path.join(__dirname, '..');
    const child = spawn(process.execPath, ['src/index.js'], {
        cwd,
        env: {
            ...process.env,
            REALTIME_SECRET: secret,
            ALLOWED_ORIGINS: origin,
            HOST: '127.0.0.1',
            PORT: String(port),
            WS_PATH: '/classroom-live',
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    context.after(() => {
        if (!child.killed) child.kill();
    });

    await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('server start timeout')), 5000);
        child.stdout.on('data', (chunk) => {
            if (chunk.toString('utf8').includes('"event":"server-started"')) {
                clearTimeout(timeout);
                resolve();
            }
        });
        child.once('exit', (code) => reject(new Error('server exited early: ' + code)));
    });

    const health = await fetch(`http://127.0.0.1:${port}/classroom-live/healthz`).then((response) => response.json());
    assert.equal(health.ok, true);

    const url = `ws://127.0.0.1:${port}/classroom-live`;
    const host = await connect(url, 'host', 1);
    const viewer = await connect(url, 'viewer', 2);
    context.after(() => {
        host.close();
        viewer.close();
    });

    const received = nextMessage(viewer, 'snapshot');
    host.send(JSON.stringify({ t: 'snapshot', state: { pages: [{ elements: [] }], currentPage: 0 } }));
    assert.deepEqual((await received).state, { pages: [{ elements: [] }], currentPage: 0 });
});

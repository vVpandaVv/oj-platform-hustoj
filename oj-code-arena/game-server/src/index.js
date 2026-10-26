'use strict';

const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');

// Minimal .env loader (KEY=VALUE lines) so the server runs without extra packages.
// Reads .env, or oj-code-arena.env as downloaded from WordPress.
(function loadEnv() {
    const file = ['.env', 'oj-code-arena.env'].map((n) => path.join(__dirname, '..', n)).find((f) => fs.existsSync(f));
    if (!file) return;
    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$/);
        if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
    }
})();

const { WebSocketServer } = require('ws');
const config = require('./config');
const { verifyRequest } = require('./sign');
const { Hub } = require('./hub');
const { version } = require('../package.json');

if (!config.secret || config.secret.length < 32) {
    console.error('ARENA_SECRET is missing or shorter than 32 characters. Copy it from the OJ console (교사 메뉴 › 코드 아레나 › 설정).');
    process.exit(1);
}

const hub = new Hub();

function json(res, status, body) {
    res.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    res.end(JSON.stringify(body));
}

function readBody(req, limit = 2 * 1024 * 1024) {
    return new Promise((resolve, reject) => {
        let size = 0;
        const chunks = [];
        req.on('data', (c) => {
            size += c.length;
            if (size > limit) {
                reject(new Error('too-large'));
                req.destroy();
            } else {
                chunks.push(c);
            }
        });
        req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        req.on('error', reject);
    });
}

async function handleApi(req, res, route) {
    const raw = req.method === 'GET' ? '' : await readBody(req);
    if (!verifyRequest(config.secret, req.method, route, req.headers['x-arena-time'], req.headers['x-arena-signature'], raw)) {
        return json(res, 401, { ok: false, why: 'bad-signature' });
    }
    let body = {};
    if (raw) {
        try { body = JSON.parse(raw); } catch { return json(res, 400, { ok: false, why: 'bad-json' }); }
    }
    if (req.method === 'GET' && route === '/api/status') return json(res, 200, { ok: true, version, ...hub.status() });
    if (req.method === 'POST' && route === '/api/sessions') return json(res, 200, hub.upsertSession(body));
    if (req.method === 'POST' && route === '/api/sessions/close') return json(res, 200, hub.closeSession(body.id));
    return json(res, 404, { ok: false, why: 'not-found' });
}

const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://local');
    const route = url.pathname.startsWith(config.path) ? url.pathname.slice(config.path.length) || '/' : url.pathname;
    if (req.method === 'GET' && route === '/healthz') return json(res, 200, { ok: true, version });
    if (route.startsWith('/api/')) {
        handleApi(req, res, route).catch((err) => json(res, 500, { ok: false, why: err.message }));
        return;
    }
    json(res, 404, { ok: false, why: 'not-found' });
});

const wss = new WebSocketServer({ noServer: true, maxPayload: config.maxMessageBytes });

server.on('upgrade', (req, socket, head) => {
    const url = new URL(req.url, 'http://local');
    const origin = String(req.headers.origin || '').replace(/\/+$/, '');
    const pathOk = url.pathname === config.path || url.pathname === config.path + '/';
    const originOk = !config.origins.length || config.origins.includes(origin);
    if (!pathOk || !originOk) {
        socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
        socket.destroy();
        return;
    }
    wss.handleUpgrade(req, socket, head, (ws) => {
        const conn = hub.connect(ws);
        // Sockets that never say hello are dropped.
        const helloTimer = setTimeout(() => { if (!conn.ready) ws.terminate(); }, 10000);
        let alive = true;
        ws.on('pong', () => { alive = true; });
        const ping = setInterval(() => {
            if (!alive) return ws.terminate();
            alive = false;
            ws.ping();
        }, 20000);
        ws.on('message', (data) => hub.message(conn, data.toString('utf8')));
        ws.on('close', () => {
            clearInterval(ping);
            clearTimeout(helloTimer);
            hub.disconnect(conn);
        });
        ws.on('error', () => {});
    });
});

setInterval(() => hub.tick(), config.tickMs);

server.listen(config.port, config.host, () => {
    console.log(`Code Arena server ${version} on http://${config.host}:${config.port}${config.path}`);
    if (!config.wpApi) console.log('WP_API is not set: results will not be saved to WordPress.');
    if (!config.origins.length) console.log('ALLOWED_ORIGINS is not set: any site may open a WebSocket.');
});

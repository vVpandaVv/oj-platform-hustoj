'use strict';

const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');

(function loadEnv() {
    const file = ['.env', 'oj-realtime.env']
        .map((name) => path.join(__dirname, '..', name))
        .find((candidate) => fs.existsSync(candidate));
    if (!file) return;
    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
        const match = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*?)\s*$/);
        if (match && process.env[match[1]] === undefined) {
            process.env[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2');
        }
    }
})();

const { WebSocketServer } = require('ws');
const config = require('./config');
const { Hub } = require('./hub');
const { version } = require('../package.json');

if (config.secret.length < 32) {
    console.error('REALTIME_SECRET is missing or shorter than 32 characters.');
    process.exit(1);
}

const hub = new Hub(config);

function clientIp(request) {
    const forwarded = String(request.headers['x-forwarded-for'] || '').split(',')[0].trim();
    return (forwarded || String(request.socket.remoteAddress || '')).slice(0, 80);
}

function log(level, event, details = {}) {
    const value = JSON.stringify({ time: new Date().toISOString(), level, event, ...details });
    (level === 'error' ? console.error : console.log)(value);
}

function json(response, status, body) {
    response.writeHead(status, {
        'content-type': 'application/json; charset=utf-8',
        'cache-control': 'no-store',
    });
    response.end(JSON.stringify(body));
}

function relativeRoute(rawUrl) {
    const url = new URL(rawUrl, 'http://local');
    if (url.pathname === config.path) return '/';
    if (url.pathname.startsWith(config.path + '/')) return url.pathname.slice(config.path.length);
    return url.pathname;
}

const server = http.createServer((request, response) => {
    const route = relativeRoute(request.url);
    if (request.method === 'GET' && route === '/healthz') {
        return json(response, 200, { ok: true, version, ...hub.status() });
    }
    return json(response, 404, { ok: false, why: 'not-found' });
});

const wss = new WebSocketServer({ noServer: true, maxPayload: config.maxMessageBytes });

server.on('upgrade', (request, socket, head) => {
    const url = new URL(request.url, 'http://local');
    const origin = String(request.headers.origin || '').replace(/\/+$/, '');
    const pathOk = url.pathname === config.path || url.pathname === config.path + '/';
    const originOk = !config.origins.length || config.origins.includes(origin);
    if (!pathOk || !originOk) {
        socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
        socket.destroy();
        return;
    }

    wss.handleUpgrade(request, socket, head, (websocket) => {
        const ip = clientIp(request);
        const connection = hub.connect(websocket, { ip });
        if (!connection) return;
        log('info', 'connection-opened', { ip });
        websocket.isAlive = true;
        const helloTimer = setTimeout(() => {
            if (!connection.ready) websocket.terminate();
        }, 10000);
        websocket.on('pong', () => { websocket.isAlive = true; });
        websocket.on('message', (data) => hub.message(connection, data.toString('utf8')));
        websocket.on('close', () => {
            clearTimeout(helloTimer);
            hub.disconnect(connection);
            log('info', 'connection-closed', { ip });
        });
        websocket.on('error', () => {});
    });
});

const pingTimer = setInterval(() => {
    for (const connection of hub.connections) {
        const socket = connection.socket;
        if (socket.readyState !== 1) continue;
        if (socket.isAlive === false) {
            socket.terminate();
            continue;
        }
        socket.isAlive = false;
        socket.ping();
    }
    hub.tick();
}, 20000);
pingTimer.unref?.();

server.listen(config.port, config.host, () => {
    log('info', 'server-started', { version, host: config.host, port: config.port, path: config.path });
});

'use strict';

const http = require('node:http');
const path = require('node:path');
const { WebSocketServer } = require('ws');
const { Rooms } = require('./rooms');
const { authenticated, json } = require('./protocol');
const { version } = require('../package.json');

function log(level, event, details = {}) {
    const record = JSON.stringify({ time: new Date().toISOString(), level, event, ...details });
    (level === 'error' ? console.error : console.log)(record);
}

function clientIp(request) {
    const forwarded = String(request.headers['x-forwarded-for'] || '').split(',')[0].trim();
    return (forwarded || String(request.socket.remoteAddress || '')).slice(0, 80);
}

function createHost(settings, options = {}) {
    const rooms = new Rooms();
    const root = options.root || path.resolve(__dirname, '..');
    const apps = new Map();
    const sockets = new Set();
    const timers = [];
    for (const app of settings.apps) {
        const moduleRoot = path.resolve(root, 'modules', app.id);
        const entry = path.resolve(moduleRoot, app.adapter);
        if (!entry.startsWith(moduleRoot + path.sep)) throw new Error('Adapter outside module directory');
        const factory = options.adapters?.[app.id] || require(entry).createAdapter;
        const adapter = factory({ app, rooms, root: moduleRoot });
        const connections = new Set();
        const status = () => ({
            ok: true, service: 'oj-realtime-hub', protocol: 1, version,
            app: app.id, appVersion: app.version, path: app.path,
            transport: 'websocket', authenticated: true,
            connections: connections.size, ...adapter.status(), ...rooms.status(app.id),
        });
        apps.set(app.id, { app, adapter, connections, status });
        const timer = setInterval(() => adapter.tick?.(), adapter.tickMs || 20000);
        timer.unref?.();
        timers.push(timer);
    }

    function target(url) {
        const pathname = new URL(url, 'http://local').pathname;
        return [...apps.values()].find(({ app }) => pathname === app.path || pathname.startsWith(app.path + '/'));
    }
    const server = http.createServer(async (request, response) => {
        try {
            const found = target(request.url);
            if (!found) return json(response, 404, { ok: false, why: 'not-found' });
            const route = new URL(request.url, 'http://local').pathname.slice(found.app.path.length) || '/';
            if (request.method === 'GET' && route === '/healthz') {
                return json(response, 200, { ok: true, service: 'oj-realtime-hub', protocol: 1, version, app: found.app.id });
            }
            if (request.method === 'GET' && route === '/status') {
                if (!authenticated(found.app, request, route)) return json(response, 401, { ok: false, why: 'bad-signature' });
                return json(response, 200, found.status());
            }
            if (!await found.adapter.http?.(request, response, route, found.status)) json(response, 404, { ok: false, why: 'not-found' });
        } catch (error) {
            log('error', 'http-error', { message: error.message });
            if (!response.headersSent) json(response, 500, { ok: false, why: 'server-error' });
        }
    });

    server.on('upgrade', (request, socket, head) => {
        let found, pathname;
        try { found = target(request.url); pathname = new URL(request.url, 'http://local').pathname; }
        catch { socket.end('HTTP/1.1 400 Bad Request\r\n\r\n'); return; }
        if (!found || ![found.app.path, found.app.path + '/'].includes(pathname)
            || !found.app.origins.includes(String(request.headers.origin || '').replace(/\/+$/, ''))
            || sockets.size >= (settings.maxConnections || 2000)) {
            log('warning', 'upgrade-rejected', { app: found?.app?.id || '', ip: clientIp(request) });
            socket.end('HTTP/1.1 403 Forbidden\r\n\r\n');
            return;
        }
        found.wss.handleUpgrade(request, socket, head, (ws) => {
            sockets.add(ws);
            found.connections.add(ws);
            const ip = clientIp(request);
            const connection = found.adapter.connect(ws, { ip });
            if (!connection) { ws.close(1013, 'busy'); sockets.delete(ws); found.connections.delete(ws); return; }
            log('info', 'connection-opened', { app: found.app.id, ip });
            ws.alive = true;
            const timer = setTimeout(() => { if (!connection.ready) ws.terminate(); }, 10000);
            ws.on('pong', () => { ws.alive = true; });
            ws.on('message', (data) => {
                Promise.resolve().then(() => found.adapter.message(connection, data.toString('utf8'))).catch(() => ws.close(1011, 'message-error'));
            });
            ws.on('close', () => {
                clearTimeout(timer);
                sockets.delete(ws);
                found.connections.delete(ws);
                found.adapter.disconnect(connection);
                log('info', 'connection-closed', { app: found.app.id, ip });
            });
            ws.on('error', () => {});
        });
    });
    for (const found of apps.values()) found.wss = new WebSocketServer({ noServer: true, maxPayload: found.adapter.maxPayload || 8192 });
    const ping = setInterval(() => {
        for (const socket of sockets) {
            if (!socket.alive) socket.terminate();
            else { socket.alive = false; socket.ping(); }
        }
    }, 20000);
    ping.unref?.();
    timers.push(ping);
    function close() {
        timers.forEach(clearInterval);
        sockets.forEach((socket) => socket.terminate());
        for (const found of apps.values()) { found.adapter.close?.(); found.wss.close(); }
        return new Promise((resolve) => server.close(resolve));
    }
    return { server, rooms, apps, close };
}

module.exports = { createHost };

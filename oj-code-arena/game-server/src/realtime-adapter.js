'use strict';

const config = require('./config');
const { verifyRequest, readTicket } = require('./sign');
const { Hub } = require('./hub');

function createAdapter({ app, rooms }) {
    Object.assign(config, { secret: app.secret, wpApi: app.env.WP_API || '', path: app.path, origins: app.origins });
    for (const [key, name] of Object.entries({ maxSessions: 'MAX_SESSIONS', maxRooms: 'MAX_ROOMS', graceMs: 'GRACE_MS', idleMs: 'IDLE_MS' })) {
        if (/^\d+$/.test(app.env[name] || '')) config[key] = Number(app.env[name]);
    }
    const hub = new Hub({ rooms: rooms.channel(app.id, 'arena') });
    const reply = (response, status, body) => {
        response.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'no-store' });
        response.end(JSON.stringify(body));
    };
    return {
        tickMs: config.tickMs, maxPayload: config.maxMessageBytes,
        connect: (socket) => hub.connect(socket),
        disconnect: (connection) => hub.disconnect(connection),
        message(connection, raw) {
            if (!connection.ready) {
                let message; try { message = JSON.parse(raw); } catch { return; }
                const claims = readTicket(app.secret, message?.ticket);
                if (claims?.app !== app.id || claims.channel !== 'arena') {
                    connection.send({ t: 'ack', rid: message?.rid, ok: false, why: 'bad-namespace' });
                    connection.close(); return;
                }
            }
            return hub.message(connection, raw);
        },
        tick: () => hub.tick(), status: () => hub.status(),
        close() { for (const timer of hub.graceTimers.values()) clearTimeout(timer); },
        async http(request, response, route, status) {
            if (!route.startsWith('/api/')) return false;
            const chunks = [];
            let bytes = 0;
            for await (const chunk of request) {
                bytes += chunk.length;
                if (bytes > 2 * 1024 * 1024) { reply(response, 413, { ok: false }); return true; }
                chunks.push(chunk);
            }
            const raw = Buffer.concat(chunks).toString('utf8');
            if (!verifyRequest(app.secret, request.method, route, request.headers['x-arena-time'], request.headers['x-arena-signature'], raw)) {
                reply(response, 401, { ok: false, why: 'bad-signature' }); return true;
            }
            let body;
            try { body = raw ? JSON.parse(raw) : {}; } catch { reply(response, 400, { ok: false }); return true; }
            if (request.method === 'GET' && route === '/api/status') reply(response, 200, status());
            else if (request.method === 'POST' && route === '/api/sessions') reply(response, 200, hub.upsertSession(body));
            else if (request.method === 'POST' && route === '/api/sessions/close') reply(response, 200, hub.closeSession(body.id));
            else reply(response, 404, { ok: false });
            return true;
        },
    };
}

module.exports = { createAdapter };

'use strict';

// Minimal second addon for shared-host tests; contains no product addon code.
const crypto = require('node:crypto');

function issueTicket(secret, claims) {
    const payload = Buffer.from(JSON.stringify(claims)).toString('base64url');
    return payload + '.' + crypto.createHmac('sha256', secret).update(payload).digest('base64url');
}

function createAdapter({ app, rooms }) {
    const channel = rooms.channel(app.id, 'sample');
    const json = (response, status, body) => {
        response.writeHead(status, { 'content-type': 'application/json; charset=utf-8' });
        response.end(JSON.stringify(body));
    };
    return {
        status: () => ({}),
        connect: (socket) => ({ socket, ready: false }),
        disconnect() {},
        message(connection, raw) {
            const message = JSON.parse(raw);
            const [payload, mac] = String(message.ticket || '').split('.');
            const expected = crypto.createHmac('sha256', app.secret).update(payload || '').digest('base64url');
            const claims = mac === expected ? JSON.parse(Buffer.from(payload, 'base64url')) : null;
            if (!claims || claims.app !== app.id || claims.exp <= Date.now() / 1000) {
                connection.socket.send(JSON.stringify({ t: 'ack', ok: false, why: 'bad-namespace' }));
                connection.socket.close();
                return;
            }
            connection.ready = true;
            if (!channel.has(claims.room)) channel.set(claims.room, {});
            connection.socket.send(JSON.stringify({ t: 'ack', ok: true }));
        },
        async http(request, response, route) {
            if (request.method !== 'POST' || route !== '/api/rooms') return false;
            const chunks = [];
            for await (const chunk of request) chunks.push(chunk);
            const raw = Buffer.concat(chunks).toString('utf8');
            const time = String(request.headers['x-oj-realtime-time'] || '');
            const actual = String(request.headers['x-oj-realtime-signature'] || '');
            const expected = crypto.createHmac('sha256', app.secret)
                .update(`${request.method}\n${route}\n${time}\n${raw}`).digest('hex');
            if (actual !== expected || Math.abs(Date.now() / 1000 - Number(time)) > 300) {
                json(response, 401, { ok: false });
                return true;
            }
            const body = JSON.parse(raw);
            channel.set('c' + body.id, { title: body.title });
            json(response, 200, { ok: true });
            return true;
        },
    };
}

module.exports = { createAdapter, issueTicket };

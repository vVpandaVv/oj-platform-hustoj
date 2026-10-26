'use strict';

const { Hub } = require('./hub');
const { readTicket } = require('./ticket');

function createAdapter({ app, rooms }) {
    const defaults = require('./config');
    const config = { ...defaults, secret: app.secret, origins: app.origins, path: app.path };
    for (const [key, name] of Object.entries({ maxRooms: 'MAX_ROOMS', maxConnections: 'MAX_CONNECTIONS', maxConnectionsPerIp: 'MAX_CONNECTIONS_PER_IP', maxConnectionsPerUser: 'MAX_CONNECTIONS_PER_USER', maxMessageBytes: 'MAX_MESSAGE_BYTES', messagesPerSecond: 'MESSAGES_PER_SECOND', roomIdleMs: 'ROOM_IDLE_MS', hostGoneMs: 'HOST_GONE_MS' })) {
        if (/^\d+$/.test(app.env[name] || '')) config[key] = Number(app.env[name]);
    }
    const hub = new Hub(config, { rooms, app: app.id });
    return {
        maxPayload: config.maxMessageBytes,
        connect: (socket, metadata) => hub.connect(socket, metadata), disconnect: (connection) => hub.disconnect(connection),
        message(connection, raw) {
            if (!connection.ready) {
                let message; try { message = JSON.parse(raw); } catch { return; }
                const claims = readTicket(app.secret, message?.ticket);
                if (claims?.app !== app.id) { connection.close(4003, 'bad-namespace'); return; }
            }
            return hub.message(connection, raw);
        },
        tick: () => hub.tick(), status: () => hub.status(),
    };
}

module.exports = { createAdapter };

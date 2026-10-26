'use strict';

// Adapter for the already-installed pre-module relay; no WordPress plugin directory is required.
function createAdapter({ app, rooms, root }) {
    const path = require('node:path');
    const { Hub } = require(path.join(root, 'realtime-server/src/hub'));
    const { readTicket } = require(path.join(root, 'realtime-server/src/ticket'));
    const config = { ...require(path.join(root, 'realtime-server/src/config')), secret: app.secret };
    for (const [key, name] of Object.entries({ maxRooms: 'MAX_ROOMS', maxConnections: 'MAX_CONNECTIONS', maxConnectionsPerIp: 'MAX_CONNECTIONS_PER_IP', maxConnectionsPerUser: 'MAX_CONNECTIONS_PER_USER', maxMessageBytes: 'MAX_MESSAGE_BYTES', messagesPerSecond: 'MESSAGES_PER_SECOND', roomIdleMs: 'ROOM_IDLE_MS', hostGoneMs: 'HOST_GONE_MS' })) {
        if (/^\d+$/.test(app.env[name] || '')) config[key] = Number(app.env[name]);
    }
    const hub = new Hub(config);
    const channels = new Map();
    function sync() {
        for (const map of channels.values()) map.clear();
        for (const room of hub.rooms.values()) {
            const [, channel, ...key] = room.id.split(':');
            const map = rooms.channel(app.id, channel);
            channels.set(channel, map);
            map.set(key.join(':'), room);
        }
    }
    return {
        maxPayload: config.maxMessageBytes,
        connect: (socket, metadata) => hub.connect(socket, metadata), disconnect: (connection) => hub.disconnect(connection),
        message(connection, raw) {
            if (!connection.ready) {
                let message; try { message = JSON.parse(raw); } catch { return; }
                if (readTicket(app.secret, message?.ticket)?.app !== app.id) { connection.close(4003, 'bad-namespace'); return; }
            }
            hub.message(connection, raw); sync();
        },
        tick() { hub.tick(); sync(); }, status: () => hub.status(),
    };
}
module.exports = { createAdapter };

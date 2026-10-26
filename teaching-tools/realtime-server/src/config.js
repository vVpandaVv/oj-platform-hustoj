'use strict';

function int(name, fallback) {
    const value = parseInt(process.env[name] || '', 10);
    return Number.isFinite(value) ? value : fallback;
}

function list(name) {
    return String(process.env[name] || '')
        .split(',')
        .map((value) => value.trim().replace(/\/+$/, ''))
        .filter(Boolean);
}

module.exports = {
    port: int('PORT', 3210),
    host: process.env.HOST || '127.0.0.1',
    secret: process.env.REALTIME_SECRET || '',
    origins: list('ALLOWED_ORIGINS'),
    path: '/' + String(process.env.WS_PATH || '/classroom-live').replace(/^\/+|\/+$/g, ''),
    maxRooms: int('MAX_ROOMS', 200),
    maxConnections: int('MAX_CONNECTIONS', 2000),
    maxConnectionsPerIp: int('MAX_CONNECTIONS_PER_IP', 200),
    maxConnectionsPerUser: int('MAX_CONNECTIONS_PER_USER', 8),
    maxMessageBytes: int('MAX_MESSAGE_BYTES', 2 * 1024 * 1024),
    messagesPerSecond: int('MESSAGES_PER_SECOND', 90),
    roomIdleMs: int('ROOM_IDLE_MS', 4 * 60 * 60 * 1000),
    hostGoneMs: int('HOST_GONE_MS', 5 * 60 * 1000),
};

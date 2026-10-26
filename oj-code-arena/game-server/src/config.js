'use strict';

// All settings come from environment variables (or a .env file next to
// package.json, loaded by index.js). Durations are in milliseconds.
function int(name, fallback) {
    const v = parseInt(process.env[name] || '', 10);
    return Number.isFinite(v) ? v : fallback;
}

function list(name) {
    return String(process.env[name] || '')
        .split(',')
        .map((s) => s.trim().replace(/\/+$/, ''))
        .filter(Boolean);
}

module.exports = {
    port: int('PORT', 3200),
    // Loopback by default: put nginx in front (see deploy/nginx-arena.conf).
    host: process.env.HOST || '127.0.0.1',
    // Shared with the WordPress plugin (OJ console › 교사 메뉴 › 코드 아레나 › 설정).
    secret: process.env.ARENA_SECRET || '',
    // REST base of the plugin, e.g. https://oj.example.com/wp-json/oj-code-arena/v1
    wpApi: String(process.env.WP_API || '').replace(/\/+$/, ''),
    // Browser origins allowed to open a WebSocket (the WordPress site).
    origins: list('ALLOWED_ORIGINS'),
    path: process.env.WS_PATH || '/arena',

    graceMs: int('GRACE_MS', 60000),
    tickMs: 200,
    countdownMs: 5000,
    maxSessions: int('MAX_SESSIONS', 40),
    maxRooms: int('MAX_ROOMS', 60),
    roomGapMs: 30000,
    roomWaitCloseMs: 10 * 60000,
    sessionIdleCloseMs: 4 * 3600000,
    idleMs: int('IDLE_MS', 90000),
    idleWarnMs: 15000,
    maxBots: 40,
    msgPerSec: 40,
    maxMessageBytes: 8192,
    bankCacheMs: 5 * 60000,
};

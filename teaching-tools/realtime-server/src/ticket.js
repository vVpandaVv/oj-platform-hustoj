'use strict';

const crypto = require('node:crypto');

function decode(value) {
    try {
        return Buffer.from(String(value), 'base64url');
    } catch {
        return null;
    }
}

function readTicket(secret, ticket, nowSeconds = Date.now() / 1000) {
    const parts = String(ticket || '').split('.');
    if (parts.length !== 2 || secret.length < 32) return null;

    const expected = crypto.createHmac('sha256', secret).update(parts[0]).digest();
    const actual = decode(parts[1]);
    if (!actual || actual.length !== expected.length || !crypto.timingSafeEqual(actual, expected)) return null;

    let claims;
    try {
        claims = JSON.parse(decode(parts[0]).toString('utf8'));
    } catch {
        return null;
    }

    if (!claims || claims.v !== 1) return null;
    if (!/^[a-z0-9][a-z0-9-]{0,47}$/.test(String(claims.app || ''))) return null;
    if (!/^[a-z0-9][a-z0-9-]{0,47}$/.test(String(claims.channel || ''))) return null;
    if (!['host', 'viewer'].includes(claims.role)) return null;
    if (!/^[A-Za-z0-9:_-]{1,80}$/.test(String(claims.room || ''))) return null;
    if (!(Number(claims.uid) > 0)) return null;
    if (!(Number(claims.exp) > nowSeconds) || Number(claims.exp) > nowSeconds + 300) return null;
    if (Number(claims.iat) > nowSeconds + 30) return null;

    return {
        app: claims.app,
        channel: claims.channel,
        room: claims.room,
        role: claims.role,
        uid: Number(claims.uid),
        exp: Number(claims.exp),
    };
}

module.exports = { readTicket };

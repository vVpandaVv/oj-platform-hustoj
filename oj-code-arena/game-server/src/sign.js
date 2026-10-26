'use strict';

const crypto = require('node:crypto');

// Tickets are "<base64url(json)>.<base64url(hmac-sha256(first part))>",
// issued by the WordPress plugin (Support\Ticket::issue) for one user.
// Server-to-server calls carry X-Arena-Time + X-Arena-Signature =
// hex(hmac-sha256("<METHOD>\n<route>\n<time>\n<raw body>")), so a signature only fits
// the one call it was made for. route is relative to the API base
// ("/api/sessions" on this server, "/server/results" on WordPress).

const SKEW_SEC = 300;

function b64url(buf) {
    return Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function fromB64url(s) {
    return Buffer.from(String(s).replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

function hmac(secret, data) {
    return crypto.createHmac('sha256', secret).update(data).digest();
}

function safeEqual(a, b) {
    return a.length === b.length && crypto.timingSafeEqual(a, b);
}

function issueTicket(secret, claims) {
    const body = b64url(JSON.stringify(claims));
    return body + '.' + b64url(hmac(secret, body));
}

function readTicket(secret, ticket, nowSec) {
    if (!secret || typeof ticket !== 'string' || ticket.length > 4096) return null;
    const dot = ticket.indexOf('.');
    if (dot < 1) return null;
    const body = ticket.slice(0, dot);
    const sig = fromB64url(ticket.slice(dot + 1));
    if (!safeEqual(sig, hmac(secret, body))) return null;
    let claims;
    try {
        claims = JSON.parse(fromB64url(body).toString('utf8'));
    } catch {
        return null;
    }
    if (!claims || claims.v !== 1 || typeof claims.exp !== 'number') return null;
    if (claims.exp < (nowSec ?? Date.now() / 1000)) return null;
    if (!Number.isInteger(claims.uid) || claims.uid <= 0) return null;
    return claims;
}

function base(method, route, time, body) {
    return String(method).toUpperCase() + '\n' + route + '\n' + time + '\n' + body;
}

function signRequest(secret, method, route, body, nowSec) {
    const time = String(Math.floor(nowSec ?? Date.now() / 1000));
    return { time, signature: hmac(secret, base(method, route, time, body)).toString('hex') };
}

function verifyRequest(secret, method, route, time, signature, body, nowSec) {
    if (!secret || !/^\d{9,12}$/.test(String(time || '')) || !/^[0-9a-f]{64}$/.test(String(signature || ''))) {
        return false;
    }
    const now = nowSec ?? Date.now() / 1000;
    if (Math.abs(now - Number(time)) > SKEW_SEC) return false;
    return safeEqual(Buffer.from(signature, 'hex'), hmac(secret, base(method, route, time, body)));
}

module.exports = { issueTicket, readTicket, signRequest, verifyRequest, b64url };

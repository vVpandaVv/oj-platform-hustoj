'use strict';

const crypto = require('node:crypto');

function signature(secret, method, route, time, body = '') {
    return crypto.createHmac('sha256', secret).update(`${method.toUpperCase()}\n${route}\n${time}\n${body}`).digest('hex');
}

function authenticated(app, request, route, body = '') {
    const time = String(request.headers['x-oj-realtime-time'] || '');
    const actual = String(request.headers['x-oj-realtime-signature'] || '');
    if (!/^\d{9,12}$/.test(time) || Math.abs(Date.now() / 1000 - Number(time)) > 300 || !/^[a-f0-9]{64}$/.test(actual)) return false;
    return crypto.timingSafeEqual(Buffer.from(actual, 'hex'), Buffer.from(signature(app.secret, request.method, route, time, body), 'hex'));
}

function json(response, status, body) {
    response.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    response.end(JSON.stringify(body));
}

module.exports = { signature, authenticated, json };

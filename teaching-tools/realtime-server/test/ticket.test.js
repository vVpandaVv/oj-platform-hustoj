'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { readTicket } = require('../src/ticket');

function ticket(secret, claims) {
    const payload = Buffer.from(JSON.stringify(claims)).toString('base64url');
    const signature = crypto.createHmac('sha256', secret).update(payload).digest('base64url');
    return payload + '.' + signature;
}

test('accepts a valid teaching-tools whiteboard ticket', () => {
    const secret = 'a'.repeat(48);
    const claims = {
        v: 1,
        app: 'teaching-tools',
        channel: 'whiteboard',
        room: 'whiteboard:42',
        role: 'viewer',
        uid: 7,
        iat: 1000,
        exp: 1120,
    };
    assert.deepEqual(readTicket(secret, ticket(secret, claims), 1001), {
        app: 'teaching-tools',
        channel: 'whiteboard',
        room: 'whiteboard:42',
        role: 'viewer',
        uid: 7,
        exp: 1120,
    });
});

test('rejects expired, altered, and malformed-app tickets', () => {
    const secret = 'b'.repeat(48);
    const base = {
        v: 1,
        app: 'teaching-tools',
        channel: 'whiteboard',
        room: 'whiteboard:9',
        role: 'host',
        uid: 3,
        iat: 1000,
        exp: 1100,
    };
    assert.equal(readTicket(secret, ticket(secret, base), 1200), null);
    assert.equal(readTicket(secret, ticket(secret, { ...base, app: '../other' }), 1001), null);
    assert.equal(readTicket(secret, ticket(secret, base) + 'x', 1001), null);
});

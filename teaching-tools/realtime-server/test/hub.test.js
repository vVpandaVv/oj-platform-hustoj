'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { Hub } = require('../src/hub');

const secret = 'c'.repeat(48);
const config = {
    secret,
    maxConnections: 20,
    maxRooms: 10,
    messagesPerSecond: 100,
    roomIdleMs: 10000,
};

function makeTicket(role, uid, channel = 'whiteboard') {
    const payload = Buffer.from(JSON.stringify({
        v: 1,
        app: 'teaching-tools',
        channel,
        room: `${channel}:12`,
        role,
        uid,
        iat: 1000,
        exp: 1120,
    })).toString('base64url');
    return payload + '.' + crypto.createHmac('sha256', secret).update(payload).digest('base64url');
}

function socket() {
    return {
        readyState: 1,
        sent: [],
        send(raw) { this.sent.push(JSON.parse(raw)); },
        close(code, reason) { this.closed = { code, reason }; },
    };
}

test('broadcasts host snapshots and previews to viewers', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const hostSocket = socket();
    const viewerSocket = socket();
    const host = hub.connect(hostSocket);
    const viewer = hub.connect(viewerSocket);

    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1) }));
    hub.message(viewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 2) }));
    hub.message(host, JSON.stringify({ t: 'snapshot', state: { pages: [{ elements: [] }], currentPage: 0 } }));
    hub.message(host, JSON.stringify({ t: 'preview', page: 0, element: { type: 'draw', points: [[1, 2], [3, 4]] } }));

    assert.equal(viewerSocket.sent[0].t, 'ready');
    assert.equal(viewerSocket.sent.at(-2).t, 'snapshot');
    assert.equal(viewerSocket.sent.at(-1).t, 'preview');
});

test('viewer messages cannot mutate a room', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const viewerSocket = socket();
    const viewer = hub.connect(viewerSocket);
    hub.message(viewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 2) }));
    hub.message(viewer, JSON.stringify({ t: 'snapshot', state: { secret: true } }));
    assert.equal(hub.rooms.get('teaching-tools:whiteboard:whiteboard:12').snapshot, null);
});

test('acknowledges heartbeat without broadcasting or mutating whiteboard state', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const hostSocket = socket(); const viewerSocket = socket();
    const host = hub.connect(hostSocket); const viewer = hub.connect(viewerSocket);
    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1) }));
    hub.message(viewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 2) }));
    assert.equal(viewerSocket.sent[0].heartbeat, true);
    const count = hostSocket.sent.length;
    hub.message(viewer, JSON.stringify({ t: 'ping' }));
    assert.deepEqual(viewerSocket.sent.at(-1), { t: 'pong' });
    assert.equal(hostSocket.sent.length, count);
    assert.equal(viewer.room.snapshot, null);
});

test('supports whiteboard previews through the last PDF page', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const host = hub.connect(socket());
    const viewerSocket = socket();
    const viewer = hub.connect(viewerSocket);
    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1) }));
    hub.message(viewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 2) }));
    hub.message(host, JSON.stringify({ t: 'preview-start', id: 'last', page: 499, element: { type: 'draw', points: [[1, 2]] } }));
    assert.equal(viewerSocket.sent.at(-1).page, 499);
    hub.message(host, JSON.stringify({ t: 'preview-shape', id: 'shape', page: 499, element: { type: 'rect', x: 0, y: 0, width: 10, height: 10 } }));
    assert.equal(viewerSocket.sent.at(-1).page, 499);
    const count = viewerSocket.sent.length;
    hub.message(host, JSON.stringify({ t: 'preview', page: 500, element: { type: 'draw', points: [[1, 2]] } }));
    assert.equal(viewerSocket.sent.length, count);
});

test('enforces per-IP and per-user connection limits', () => {
    const ipHub = new Hub({ ...config, maxConnectionsPerIp: 1 }, { now: () => 1001000 });
    const firstSocket = socket();
    const secondSocket = socket();
    assert.ok(ipHub.connect(firstSocket, { ip: '192.0.2.1' }));
    assert.equal(ipHub.connect(secondSocket, { ip: '192.0.2.1' }), null);
    assert.equal(secondSocket.closed.reason, 'ip-limit');

    const userHub = new Hub({ ...config, maxConnectionsPerUser: 1 }, { now: () => 1001000 });
    const first = userHub.connect(socket(), { ip: '192.0.2.2' });
    const duplicateSocket = socket();
    const duplicate = userHub.connect(duplicateSocket, { ip: '192.0.2.3' });
    userHub.message(first, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 22, 'classroom') }));
    userHub.message(duplicate, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 22, 'classroom') }));
    assert.equal(duplicateSocket.closed.reason, 'user-limit');
});

test('keeps participant events disabled outside participant-enabled channels', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const hostSocket = socket();
    const viewerSocket = socket();
    const host = hub.connect(hostSocket);
    const viewer = hub.connect(viewerSocket);

    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1) }));
    hub.message(viewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 2) }));
    hub.message(viewer, JSON.stringify({ t: 'participant-event', event: 'signal', data: { signal: 'help' } }));

    assert.equal(hostSocket.sent.some((message) => message.t === 'participant-event'), false);
});

test('forwards only bounded board update notices on the quickboard channel', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const hostSocket = socket();
    const viewerSocket = socket();
    const host = hub.connect(hostSocket);
    const viewer = hub.connect(viewerSocket);

    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1, 'quickboard') }));
    hub.message(viewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 23, 'quickboard') }));
    hub.message(viewer, JSON.stringify({
        t: 'participant-event', event: 'board-updated', data: { revision: 4, elements: ['not-forwarded'] }, uid: 999,
    }));

    assert.deepEqual(hostSocket.sent.at(-1), {
        t: 'participant-event',
        event: 'board-updated',
        data: { revision: 4 },
        uid: 23,
    });

    const count = hostSocket.sent.length;
    hub.message(viewer, JSON.stringify({ t: 'participant-event', event: 'signal', data: { signal: 'help' } }));
    hub.message(viewer, JSON.stringify({ t: 'participant-event', event: 'board-updated', data: { revision: -1 } }));
    assert.equal(hostSocket.sent.length, count);
});

test('forwards participant events to the host with the ticket user id', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const hostSocket = socket();
    const viewerSocket = socket();
    const host = hub.connect(hostSocket);
    const viewer = hub.connect(viewerSocket);

    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1, 'classroom') }));
    hub.message(viewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 22, 'classroom') }));
    hub.message(viewer, JSON.stringify({
        t: 'participant-event',
        event: 'signal',
        data: { signal: 'help', signalAt: 1001 },
        uid: 999,
    }));

    assert.deepEqual(hostSocket.sent.at(-1), {
        t: 'participant-event',
        event: 'signal',
        data: { signal: 'help', signalAt: 1001 },
        uid: 22,
    });
    assert.equal(hub.rooms.get('teaching-tools:classroom:classroom:12').snapshot, null);

    const count = hostSocket.sent.length;
    hub.message(viewer, JSON.stringify({ t: 'participant-event', event: 'other', data: { value: true } }));
    assert.equal(hostSocket.sent.length, count);
});

test('notifies the host when the first viewer connection arrives and the last one leaves', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const hostSocket = socket();
    const firstSocket = socket();
    const secondSocket = socket();
    const host = hub.connect(hostSocket);
    const first = hub.connect(firstSocket);
    const second = hub.connect(secondSocket);

    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1, 'classroom') }));
    hub.message(first, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 22, 'classroom') }));
    hub.message(second, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 22, 'classroom') }));

    assert.equal(hostSocket.sent.filter((message) => message.t === 'participant-online').length, 1);
    hub.disconnect(first);
    assert.equal(hostSocket.sent.filter((message) => message.t === 'participant-offline').length, 0);
    hub.disconnect(second);
    assert.deepEqual(hostSocket.sent.at(-1), { t: 'participant-offline', uid: 22 });
});

test('delivers targeted classroom events only to selected ticket identities', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const hostSocket = socket();
    const selectedSocket = socket();
    const otherSocket = socket();
    const host = hub.connect(hostSocket);
    const selected = hub.connect(selectedSocket);
    const other = hub.connect(otherSocket);

    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1, 'classroom') }));
    hub.message(selected, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 22, 'classroom') }));
    hub.message(other, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 23, 'classroom') }));
    hub.message(host, JSON.stringify({
        t: 'targeted-event',
        event: 'activity',
        viewerUids: [22],
        data: { title: '심화 활동' },
    }));

    assert.deepEqual(selectedSocket.sent.at(-1), {
        t: 'event', event: 'activity', data: { title: '심화 활동' },
    });
    assert.equal(otherSocket.sent.some((message) => message.event === 'activity'), false);
});

test('assembles point deltas for viewers that join during a stroke', () => {
    const hub = new Hub(config, { now: () => 1001000 });
    const hostSocket = socket();
    const firstViewerSocket = socket();
    const host = hub.connect(hostSocket);
    const firstViewer = hub.connect(firstViewerSocket);
    hub.message(host, JSON.stringify({ t: 'hello', ticket: makeTicket('host', 1) }));
    hub.message(firstViewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 2) }));

    hub.message(host, JSON.stringify({
        t: 'preview-start', id: '5', page: 0,
        element: { type: 'draw', color: '#000', width: 4, points: [[1, 1]] },
    }));
    hub.message(host, JSON.stringify({ t: 'preview-points', id: '5', points: [[2, 2], [3, 3]] }));
    assert.equal(firstViewerSocket.sent.at(-1).t, 'preview-points');

    const lateViewerSocket = socket();
    const lateViewer = hub.connect(lateViewerSocket);
    hub.message(lateViewer, JSON.stringify({ t: 'hello', ticket: makeTicket('viewer', 3) }));
    assert.deepEqual(lateViewerSocket.sent.at(-1).element.points, [[1, 1], [2, 2], [3, 3]]);
});

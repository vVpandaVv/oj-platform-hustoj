'use strict';

const { readTicket } = require('./ticket');

class Hub {
    constructor(config, options = {}) {
        this.config = config;
        this.now = options.now || Date.now;
        this.rooms = new Map();
        this.directory = options.rooms || null;
        this.app = options.app || null;
        this.connections = new Set();
    }

    connect(socket, metadata = {}) {
        if (this.connections.size >= this.config.maxConnections) {
            socket.close(1013, 'busy');
            return null;
        }
        const ip = String(metadata.ip || '').slice(0, 80);
        const perIpLimit = Number(this.config.maxConnectionsPerIp || 200);
        if (ip && [...this.connections].filter((item) => item.ip === ip).length >= perIpLimit) {
            socket.close(1013, 'ip-limit');
            return null;
        }

        const connection = {
            socket,
            ready: false,
            role: '',
            uid: 0,
            room: null,
            ip,
            tokens: this.config.messagesPerSecond,
            lastFill: this.now(),
            send: (message) => {
                if (socket.readyState === 1) socket.send(JSON.stringify(message));
            },
            close: (code = 1000, reason = 'closed') => {
                try { socket.close(code, reason); } catch { /* already closed */ }
            },
        };
        this.connections.add(connection);
        return connection;
    }

    disconnect(connection) {
        if (!connection) return;
        this.connections.delete(connection);
        const room = connection.room;
        if (!room) return;

        if (room.host === connection) {
            room.host = null;
            room.preview = null;
            this.broadcast(room.viewers, { t: 'host-offline' });
        } else {
            room.viewers.delete(connection);
            const stillOnline = [...room.viewers].some((viewer) => viewer.uid === connection.uid);
            if (room.host && !stillOnline) {
                room.host.send({ t: 'participant-offline', uid: connection.uid });
            }
        }
        room.touchedAt = this.now();
        connection.room = null;
    }

    allow(connection) {
        const now = this.now();
        const rate = this.config.messagesPerSecond;
        connection.tokens = Math.min(rate, connection.tokens + ((now - connection.lastFill) / 1000) * rate);
        connection.lastFill = now;
        if (connection.tokens < 1) return false;
        connection.tokens -= 1;
        return true;
    }

    message(connection, raw) {
        if (!connection || !this.allow(connection)) return;
        let message;
        try {
            message = JSON.parse(raw);
        } catch {
            return;
        }
        if (!message || typeof message.t !== 'string') return;

        if (!connection.ready) {
            this.hello(connection, message);
            return;
        }
        if (!connection.room) return;
        if (message.t === 'ping') {
            connection.send({ t: 'pong' });
            return;
        }

        if (connection.role === 'viewer') {
            if (
                ['classroom', 'quickboard'].includes(connection.room.channel)
                && message.t === 'participant-event'
                && /^[a-z0-9][a-z0-9:_-]{0,47}$/.test(String(message.event || ''))
                && Object.prototype.hasOwnProperty.call(message, 'data')
                && connection.room.host
            ) {
                let data = message.data;
                if (connection.room.channel === 'classroom') {
                    if (message.event !== 'signal') return;
                    const signal = String(data?.signal || '');
                    if (!['', 'ready', 'understood', 'unsure', 'repeat', 'help', 'done'].includes(signal)) return;
                    data = { signal, signalAt: signal ? Math.floor(this.now() / 1000) : 0 };
                } else if (connection.room.channel === 'quickboard') {
                    if (message.event !== 'board-updated') return;
                    const revision = Number(data?.revision || 0);
                    if (!Number.isInteger(revision) || revision < 1 || revision > 1000000) return;
                    data = { revision };
                }
                connection.room.host.send({
                    t: 'participant-event',
                    event: message.event,
                    data,
                    uid: connection.uid,
                });
            }
            return;
        }

        if (connection.role !== 'host') return;

        const room = connection.room;
        room.touchedAt = this.now();
        if (message.t === 'snapshot' && message.state && typeof message.state === 'object' && !Array.isArray(message.state)) {
            room.snapshot = { t: 'snapshot', state: message.state };
            room.preview = null;
            this.broadcast(room.viewers, room.snapshot);
            return;
        }
        if (
            message.t === 'preview'
            && Number.isInteger(message.page)
            && message.page >= 0
            && message.page < 500
            && message.element
            && typeof message.element === 'object'
            && !Array.isArray(message.element)
        ) {
            room.preview = { t: 'preview', page: message.page, element: message.element };
            this.broadcast(room.viewers, room.preview);
            return;
        }
        if (
            message.t === 'preview-start'
            && /^[A-Za-z0-9_-]{1,32}$/.test(String(message.id || ''))
            && Number.isInteger(message.page)
            && message.page >= 0
            && message.page < 500
            && message.element
            && typeof message.element === 'object'
            && Array.isArray(message.element.points)
        ) {
            room.preview = {
                t: 'preview',
                id: String(message.id),
                page: message.page,
                element: message.element,
            };
            this.broadcast(room.viewers, {
                t: 'preview-start',
                id: room.preview.id,
                page: room.preview.page,
                element: room.preview.element,
            });
            return;
        }
        if (
            message.t === 'preview-points'
            && room.preview
            && room.preview.id === String(message.id || '')
            && room.preview.element
            && Array.isArray(room.preview.element.points)
            && Array.isArray(message.points)
            && message.points.length <= 512
        ) {
            const remaining = Math.max(0, 20000 - room.preview.element.points.length);
            const points = message.points.slice(0, remaining);
            room.preview.element.points.push(...points);
            if (points.length) {
                this.broadcast(room.viewers, { t: 'preview-points', id: room.preview.id, points });
            }
            return;
        }
        if (
            message.t === 'preview-shape'
            && /^[A-Za-z0-9_-]{1,32}$/.test(String(message.id || ''))
            && Number.isInteger(message.page)
            && message.page >= 0
            && message.page < 500
            && message.element
            && typeof message.element === 'object'
            && !Array.isArray(message.element)
        ) {
            room.preview = {
                t: 'preview',
                id: String(message.id),
                page: message.page,
                element: message.element,
            };
            this.broadcast(room.viewers, {
                t: 'preview-shape',
                id: room.preview.id,
                page: room.preview.page,
                element: room.preview.element,
            });
            return;
        }
        if (
            message.t === 'event'
            && /^[a-z0-9][a-z0-9:_-]{0,47}$/.test(String(message.event || ''))
            && Object.prototype.hasOwnProperty.call(message, 'data')
        ) {
            this.broadcast(room.viewers, { t: 'event', event: message.event, data: message.data });
            return;
        }
        if (
            message.t === 'targeted-event'
            && room.channel === 'classroom'
            && /^[a-z0-9][a-z0-9:_-]{0,47}$/.test(String(message.event || ''))
            && Object.prototype.hasOwnProperty.call(message, 'data')
            && Array.isArray(message.viewerUids)
            && message.viewerUids.length <= 500
        ) {
            const viewerUids = new Set(message.viewerUids
                .map((uid) => Number(uid))
                .filter((uid) => Number.isInteger(uid) && uid > 0));
            this.broadcast(
                [...room.viewers].filter((viewer) => viewerUids.has(viewer.uid)),
                { t: 'event', event: message.event, data: message.data }
            );
            return;
        }
        if (message.t === 'close') {
            this.closeRoom(room);
        }
    }

    hello(connection, message) {
        if (message.t !== 'hello') {
            connection.send({ t: 'error', why: 'hello-first' });
            connection.close(4001, 'hello-first');
            return;
        }

        const claims = readTicket(this.config.secret, message.ticket, this.now() / 1000);
        if (!claims) {
            connection.send({ t: 'error', why: 'bad-ticket' });
            connection.close(4003, 'bad-ticket');
            return;
        }

        if (this.app && claims.app !== this.app) { connection.close(4003, 'bad-namespace'); return; }
        const perUserLimit = Number(this.config.maxConnectionsPerUser || 8);
        const userConnections = [...this.connections].filter((item) => item !== connection && item.ready && item.uid === claims.uid);
        if (userConnections.length >= perUserLimit) {
            connection.send({ t: 'error', why: 'user-limit' });
            connection.close(1013, 'user-limit');
            return;
        }

        const roomId = `${claims.app}:${claims.channel}:${claims.room}`;
        let room = this.rooms.get(roomId);
        if (!room) {
            if (this.rooms.size >= this.config.maxRooms) {
                connection.send({ t: 'error', why: 'too-many-rooms' });
                connection.close(1013, 'busy');
                return;
            }
            room = {
                id: roomId,
                channel: claims.channel,
                host: null,
                viewers: new Set(),
                snapshot: null,
                preview: null,
                touchedAt: this.now(),
            };
            this.rooms.set(room.id, room);
            if (this.directory) {
                room.channelRooms = this.directory.channel(claims.app, claims.channel);
                room.channelKey = claims.room;
                room.channelRooms.set(claims.room, room);
            }
        }

        connection.ready = true;
        connection.role = claims.role;
        connection.uid = claims.uid;
        connection.room = room;
        room.touchedAt = this.now();

        if (claims.role === 'host') {
            if (room.host && room.host !== connection) {
                room.host.close(4000, 'replaced');
            }
            room.host = connection;
            connection.send({
                t: 'ready',
                heartbeat: true,
                role: 'host',
                hostOnline: true,
                viewerUids: [...new Set([...room.viewers].map((viewer) => viewer.uid))],
            });
            this.broadcast(room.viewers, { t: 'host-online' });
            return;
        }

        const alreadyOnline = [...room.viewers].some((viewer) => viewer.uid === connection.uid);
        room.viewers.add(connection);
        connection.send({ t: 'ready', role: 'viewer', hostOnline: Boolean(room.host), heartbeat: true });
        if (room.host && !alreadyOnline) {
            room.host.send({ t: 'participant-online', uid: connection.uid });
        }
        if (room.snapshot) connection.send(room.snapshot);
        if (room.preview) connection.send(room.preview);
    }

    closeRoom(room) {
        this.broadcast(room.viewers, { t: 'closed' });
        for (const viewer of room.viewers) {
            viewer.room = null;
            viewer.close(1000, 'closed');
        }
        room.viewers.clear();
        if (room.host) room.host.room = null;
        this.rooms.delete(room.id);
        room.channelRooms?.delete(room.channelKey);
    }

    broadcast(connections, message) {
        for (const connection of connections) connection.send(message);
    }

    tick() {
        const now = this.now();
        for (const room of [...this.rooms.values()]) {
            const limit = room.host ? this.config.roomIdleMs : (this.config.hostGoneMs || this.config.roomIdleMs);
            if (now - room.touchedAt > limit) this.closeRoom(room);
        }
    }

    status() {
        return { rooms: this.rooms.size, connections: this.connections.size };
    }
}

module.exports = { Hub };

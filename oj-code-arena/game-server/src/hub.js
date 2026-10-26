'use strict';

const crypto = require('node:crypto');
const config = require('./config');
const { Arena } = require('./arena');
const { readTicket } = require('./sign');
const Items = require('./items');
const wp = require('./wp');

const AVATARS = 8;
const COLORS = 8;

function str(v, max) {
    return String(v == null ? '' : v).slice(0, max);
}

function cleanProblems(input) {
    const src = input && typeof input === 'object' ? input : {};
    const tags = (t) => (Array.isArray(t) ? t.map((x) => str(x, 20)).filter(Boolean).slice(0, 10) : []);
    const normal = (Array.isArray(src.normal) ? src.normal : [])
        .map((p) => ({ code: str(p && p.code, 200), tags: tags(p && p.tags) }))
        .filter((p) => p.code.trim());
    const boss = (Array.isArray(src.boss) ? src.boss : [])
        .map((p) => {
            const code = str(p && p.code, 1200);
            return {
                title: str(p && p.title, 80) || '보스 문제',
                code,
                input: str(p && p.input, 400),
                answers: (Array.isArray(p && p.answers) ? p.answers : []).map((a) => str(a, 300)).filter((a) => a.trim()).slice(0, 8),
                blank: code.includes('___'),
                tags: tags(p && p.tags),
            };
        })
        .filter((p) => p.code.trim() && p.answers.length);
    return { normal: normal.slice(0, 3000), boss: boss.slice(0, 1000) };
}

/** Holds every arena and every connection, and routes client messages. */
class Hub {
    constructor(opts = {}) {
        this.now = opts.now || Date.now;
        this.arenas = opts.rooms || new Map();
        this.lobby = new Set(); // learners looking at the free-room list
        this.looks = new Map(); // uid → { avatar, color }
        this.lastRoomAt = new Map();
        this.graceTimers = new Map();
        this.buckets = new Map(); // uid → token bucket, shared by all of a user's sockets
        this.lobbyDirty = false;
        this.postResult = opts.postResult || wp.postResult;
        this.loadBank = opts.loadBank || wp.bank;
    }

    // ── WordPress → server ───────────────────────────────────

    upsertSession(body) {
        const id = parseInt(body && body.id, 10);
        if (!(id > 0)) return { ok: false, why: 'bad-id' };
        const key = 'c' + id;
        const roster = Array.isArray(body.roster)
            ? body.roster.map((r) => ({ uid: parseInt(r.uid, 10), name: str(r.name, 40) })).filter((r) => r.uid > 0)
            : null;
        let arena = this.arenas.get(key);
        if (arena && !arena.closed) {
            arena.title = str(body.title, 80) || arena.title;
            arena.context = str(body.context, 80);
            if (roster) arena.roster = new Map(roster.map((r) => [r.uid, r.name]));
            if (arena.phase !== 'playing') {
                arena.problems = cleanProblems(body.problems);
                if (body.settings) arena.updateSettings(body.settings);
            }
            arena.changed();
            return { ok: true, id: key, phase: arena.phase, created: false };
        }
        const classes = [...this.arenas.values()].filter((a) => a.mode === 'class');
        if (classes.length >= config.maxSessions) return { ok: false, why: 'too-many-sessions' };
        arena = new Arena({
            id: key, mode: 'class', wpId: id, title: str(body.title, 80), context: str(body.context, 80),
            settings: body.settings, problems: cleanProblems(body.problems), roster, now: this.now,
            hooks: this.arenaHooks(),
        });
        this.arenas.set(key, arena);
        return { ok: true, id: key, phase: arena.phase, created: true };
    }

    closeSession(id) {
        const arena = this.arenas.get('c' + parseInt(id, 10));
        if (!arena) return { ok: true, existed: false };
        arena.close('ended');
        return { ok: true, existed: true };
    }

    status() {
        let players = 0;
        for (const a of this.arenas.values()) players += a.members.size;
        return {
            sessions: [...this.arenas.values()].filter((a) => a.mode === 'class').length,
            rooms: [...this.arenas.values()].filter((a) => a.mode === 'free').length,
            players,
        };
    }

    arenaHooks() {
        return {
            onResult: (arena, result) => {
                if (result.teams.some((t) => t.players.some((p) => !p.bot))) this.postResult(result);
            },
            onClosed: (arena) => {
                this.arenas.delete(arena.id);
                if (arena.mode === 'free') this.lobbyDirty = true;
            },
            onChange: (arena) => {
                if (arena.mode === 'free') this.lobbyDirty = true;
            },
        };
    }

    // ── connections ──────────────────────────────────────────

    connect(ws) {
        const conn = {
            ws, uid: 0, name: '', role: '', sid: 0, free: false, look: null, arena: null,
            tokens: config.msgPerSec, lastFill: this.now(), ready: false,
            send: (msg) => {
                if (ws.readyState === 1) ws.send(JSON.stringify(msg));
            },
            close: () => {
                try { ws.close(4000, 'closed'); } catch { /* already closed */ }
            },
        };
        return conn;
    }

    disconnect(conn) {
        this.lobby.delete(conn);
        const arena = conn.arena;
        if (!arena) return;
        arena.detach(conn);
        if (arena.mode === 'free' && conn.uid && (conn.role === 'learner')) {
            const key = arena.id + ':' + conn.uid;
            clearTimeout(this.graceTimers.get(key));
            const timer = setTimeout(() => {
                this.graceTimers.delete(key);
                arena.expire(conn.uid);
            }, arena.phase === 'playing' ? config.graceMs : 15000);
            timer.unref?.();
            this.graceTimers.set(key, timer);
        }
    }

    // 40 messages per second per user (per socket until hello), so opening
    // more sockets with one ticket does not raise the limit.
    allow(conn) {
        const now = this.now();
        let b = conn;
        if (conn.uid) {
            b = this.buckets.get(conn.uid);
            if (!b) {
                b = { tokens: config.msgPerSec, lastFill: now };
                this.buckets.set(conn.uid, b);
            }
        }
        b.tokens = Math.min(config.msgPerSec, b.tokens + ((now - b.lastFill) / 1000) * config.msgPerSec);
        b.lastFill = now;
        if (b.tokens < 1) return false;
        b.tokens -= 1;
        return true;
    }

    async message(conn, raw) {
        if (!this.allow(conn)) return;
        let msg;
        try {
            msg = JSON.parse(raw);
        } catch {
            return;
        }
        if (!msg || typeof msg.t !== 'string') return;
        let res;
        try {
            res = conn.ready ? await this.route(conn, msg) : await this.hello(conn, msg);
        } catch (err) {
            console.error('[hub]', msg.t, err);
            res = { ok: false, why: 'server-error' };
        }
        if (msg.rid !== undefined && res !== undefined) conn.send({ t: 'ack', rid: msg.rid, ...res });
    }

    hello(conn, msg) {
        if (msg.t !== 'hello') return { ok: false, why: 'hello-first' };
        const claims = readTicket(config.secret, msg.ticket, this.now() / 1000);
        if (!claims || !['learner', 'teacher', 'board'].includes(claims.role)) {
            return { ok: false, why: 'bad-ticket' };
        }
        conn.uid = claims.uid;
        conn.name = str(claims.name, 40) || '학생';
        conn.role = claims.role;
        conn.sid = parseInt(claims.sid, 10) || 0;
        conn.free = !!claims.free;
        conn.look = this.looks.get(conn.uid) || { avatar: conn.uid % AVATARS, color: (conn.uid * 3) % COLORS };
        conn.ready = true;

        const base = { ok: true, me: { uid: conn.uid, name: conn.name, role: conn.role, look: conn.look }, items: Items.ITEMS };
        if (conn.sid > 0) {
            const arena = this.arenas.get('c' + conn.sid);
            if (!arena || arena.closed) return { ok: false, why: 'no-session' };
            if (conn.role === 'learner') {
                const why = arena.canEnter(claims);
                if (why) return { ok: false, why };
            }
            this.enter(conn, arena);
            return base;
        }
        if (conn.role !== 'learner' || !conn.free) return { ok: false, why: 'free-off' };
        // Back into a free room after a reload?
        for (const a of this.arenas.values()) {
            if (a.mode === 'free' && a.members.has(conn.uid)) {
                this.enter(conn, a);
                return base;
            }
        }
        this.lobby.add(conn);
        conn.send(this.roomList());
        return base;
    }

    enter(conn, arena) {
        this.lobby.delete(conn);
        clearTimeout(this.graceTimers.get(arena.id + ':' + conn.uid));
        conn.arena = arena;
        arena.attach(conn);
    }

    roomList() {
        return {
            t: 'rooms',
            rooms: [...this.arenas.values()].filter((a) => a.mode === 'free' && !a.closed).map((a) => a.summary()),
        };
    }

    async route(conn, msg) {
        const arena = conn.arena;
        if (msg.t === 'look') {
            const look = { avatar: Math.abs(parseInt(msg.avatar, 10) || 0) % AVATARS, color: Math.abs(parseInt(msg.color, 10) || 0) % COLORS };
            conn.look = look;
            this.looks.set(conn.uid, look);
            arena?.setLook(conn.uid, look);
            return { ok: true, look };
        }
        if (conn.role === 'teacher') return arena ? this.teacher(conn, arena, msg) : { ok: false, why: 'no-session' };
        if (conn.role === 'board') return { ok: false, why: 'read-only' };
        if (!arena) return this.freeLobby(conn, msg);
        return this.learner(conn, arena, msg);
    }

    async freeLobby(conn, msg) {
        switch (msg.t) {
            case 'rooms':
                return { ok: true, ...this.roomList() };
            case 'room_create': {
                const last = this.lastRoomAt.get(conn.uid) || 0;
                if (this.now() - last < config.roomGapMs) return { ok: false, why: 'too-soon', waitMs: config.roomGapMs - (this.now() - last) };
                const rooms = [...this.arenas.values()].filter((a) => a.mode === 'free');
                if (rooms.length >= config.maxRooms) return { ok: false, why: 'too-many-rooms' };
                const problems = await this.loadBank();
                let id;
                do { id = 'r' + crypto.randomBytes(3).toString('hex'); } while (this.arenas.has(id));
                const title = str(msg.title, 30).trim() || `${conn.name}의 방`;
                const arena = new Arena({
                    id, mode: 'free', title, settings: msg.settings, problems: cleanProblems(problems),
                    hostUid: conn.uid, now: this.now, hooks: this.arenaHooks(),
                });
                this.arenas.set(id, arena);
                this.lastRoomAt.set(conn.uid, this.now());
                this.enter(conn, arena);
                this.lobbyDirty = true;
                return { ok: true, id };
            }
            case 'room_join': {
                const arena = this.arenas.get(str(msg.id, 16));
                if (!arena || arena.mode !== 'free') return { ok: false, why: 'no-room' };
                const why = arena.canEnter({ uid: conn.uid });
                if (why) return { ok: false, why };
                this.enter(conn, arena);
                this.lobbyDirty = true;
                return { ok: true };
            }
            default:
                return { ok: false, why: 'not-in-room' };
        }
    }

    learner(conn, arena, msg) {
        const match = arena.round ? arena.matchOf(arena.members.get(conn.uid)?.team) : null;
        const pid = String(conn.uid);
        const isHost = arena.mode === 'free' && arena.hostUid === conn.uid;
        switch (msg.t) {
            case 'move':
                match?.move(pid, { dir: msg.dir, jump: !!msg.jump, to: msg.to, seq: msg.seq });
                return undefined;
            case 'occupy': return match ? match.occupy(pid) : { ok: false, why: 'not-playing' };
            case 'submit': return match ? match.submit(pid, str(msg.text, 400)) : { ok: false, why: 'not-playing' };
            case 'release': match?.release(pid, 'esc'); return undefined;
            case 'grab': return match ? match.grab(pid, msg.key) : { ok: false, why: 'not-playing' };
            case 'boss_submit': return match ? match.bossSubmit(pid, str(msg.text, 1200)) : { ok: false, why: 'not-playing' };
            case 'giveup': match?.giveUp(pid); return undefined;
            case 'item': return match ? match.useItem(pid, msg.slot) : { ok: false, why: 'not-playing' };
            case 'defend': return match ? match.defend(pid) : { ok: false, why: 'not-playing' };
            case 'poke': match?.touch(match.players.get(pid)); return undefined;
            case 'team':
                if (arena.mode !== 'class' || !arena.settings.selfPick) return { ok: false, why: 'teacher-picks' };
                return arena.setTeam(conn.uid, msg.no);
            case 'leave':
                if (arena.mode !== 'free') return { ok: false, why: 'class' };
                arena.removeMember(conn.uid);
                conn.arena = null;
                if (conn.free) {
                    this.lobby.add(conn);
                    conn.send(this.roomList());
                }
                this.lobbyDirty = true;
                return { ok: true };
            case 'start': return isHost ? arena.start() : { ok: false, why: 'not-host' };
            case 'settings': return isHost ? arena.updateSettings(msg.settings) : { ok: false, why: 'not-host' };
            case 'bot':
                if (!isHost) return { ok: false, why: 'not-host' };
                return msg.op === 'remove' ? arena.removeBot(str(msg.id, 10)) : arena.addBot(1);
            default:
                return { ok: false, why: 'unknown' };
        }
    }

    teacher(conn, arena, msg) {
        switch (msg.t) {
            case 'settings': return arena.updateSettings(msg.settings);
            case 'start': return arena.start();
            case 'pause': return arena.pause(!!msg.on);
            case 'stop': return arena.stop();
            case 'close': arena.close('ended'); return { ok: true };
            case 'kick': return arena.kick(parseInt(msg.uid, 10));
            case 'move': return arena.setTeam(parseInt(msg.uid, 10), msg.team);
            case 'auto': arena.autoAssign(); return { ok: true };
            case 'attacks': arena.setAttacks(!!msg.on); return { ok: true };
            case 'bot_add': return arena.addBot(msg.team);
            case 'bot_remove': return arena.removeBot(str(msg.id, 10));
            case 'bot_fill': arena.fillBots(); return { ok: true };
            case 'bot_clear': arena.clearBots(); return { ok: true };
            default: return { ok: false, why: 'unknown' };
        }
    }

    tick() {
        const now = this.now();
        for (const arena of [...this.arenas.values()]) {
            arena.tick();
            if (arena.closed) continue;
            const quiet = now - arena.touchedAt;
            if (arena.mode === 'free' && arena.phase === 'lobby' && quiet > config.roomWaitCloseMs) arena.close('timeout');
            if (arena.mode === 'class' && arena.phase !== 'playing' && quiet > config.sessionIdleCloseMs) arena.close('timeout');
        }
        if (this.lobbyDirty && this.lobby.size) {
            const list = this.roomList();
            for (const c of this.lobby) c.send(list);
        }
        this.lobbyDirty = false;
    }
}

module.exports = { Hub, cleanProblems };

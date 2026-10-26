'use strict';

const Moves = require('../../shared/arena-moves');
const Grade = require('../../shared/arena-grade');
const Items = require('./items');
const Bot = require('./bot');
const { buildCells, shortCodes, COLS } = require('./board');

const MAX_INPUT = 300;
const GATHER_MS = 6000;

/**
 * One team's board for one round. Everything that changes the board goes
 * through here; the owner (an Arena) drives tick() and routes attacks
 * between teams.
 *
 * opts: {
 *   team, seed, codes, blocks, limitMs, occMs, bossWaitMs, bossLimitMs,
 *   penalty, botPace, startAt, now(), rand(), attacksOn(),
 *   idle: { ms, warnMs } | null,
 *   out: { team(msg), player(id, msg) },
 *   on: { end(match), attack(match, p, itemId) → bool, attackDone(match, inc, blocked), feed(text, kind) }
 * }
 */
class Match {
    constructor(opts) {
        this.o = opts;
        this.team = opts.team;
        this.now = opts.now;
        this.rand = opts.rand;
        this.cols = COLS;
        this.cells = buildCells(opts.seed, opts.codes, opts.blocks);
        this.rows = this.cells.length / COLS;
        this.short = shortCodes(opts.codes);
        this.botPace = opts.botPace || 'normal';
        this.players = new Map();
        this.occ = new Map(); // cell → { id, until }
        this.boss = null;
        this.fx = { stall: 0, ice: 0, fog: 0, mirror: 0 };
        this.incoming = [];
        this.startAt = opts.startAt;
        this.endAt = opts.startAt + opts.limitMs;
        this.pausedAt = 0;
        this.pausedTotal = 0;
        this.ended = null;
        this.doneAt = 0;
        this.lastInput = opts.startAt;
        this.idleWarned = false;
        this.stats = { bosses: 0, sent: 0, got: 0, blocked: 0 };
        this.seq = 0;
        this.grid = {
            cols: this.cols,
            rows: this.rows,
            isSolved: (i) => this.cells[i].solved,
            isBlocked: (i) => !!this.boss && this.boss.i === i,
        };
    }

    // ── players ──────────────────────────────────────────────

    join(info) {
        let p = this.players.get(info.id);
        if (!p) {
            const spot = this.randomOpenCell();
            p = {
                id: info.id, uid: info.uid || 0, name: info.name, avatar: info.avatar, color: info.color,
                bot: !!info.bot, online: true, r: spot.r, c: spot.c,
                solved: 0, bosses: 0, occ: -1, deck: [], shields: 0, hintUntil: 0, botState: null,
            };
            this.players.set(p.id, p);
            this.o.out.team({ t: 'player', p: this.pub(p) });
        } else if (!p.online) {
            this.setOnline(p.id, true);
        }
        if (!p.bot) this.o.out.player(p.id, this.snapshot(p.id));
        return p;
    }

    leave(id) {
        const p = this.players.get(id);
        if (!p) return;
        this.dropBusy(p, 'left');
        this.players.delete(id);
        this.o.out.team({ t: 'left', id });
    }

    setOnline(id, online) {
        const p = this.players.get(id);
        if (!p || p.online === online) return;
        p.online = online;
        if (!online) this.dropBusy(p, 'left');
        this.o.out.team({ t: 'online', id, on: online });
    }

    dropBusy(p, why) {
        if (p.occ >= 0) this.release(p.id, why);
        if (this.boss && this.boss.by === p.id) this.endBoss('left');
    }

    humans(onlineOnly = true) {
        return [...this.players.values()].filter((p) => !p.bot && (!onlineOnly || p.online));
    }

    pub(p) {
        return {
            id: p.id, name: p.name, avatar: p.avatar, color: p.color, bot: p.bot,
            online: p.online, r: p.r, c: p.c, solved: p.solved, bosses: p.bosses,
        };
    }

    // ── state helpers ────────────────────────────────────────

    remaining(t) {
        const now = this.pausedAt || this.now();
        return Math.max(0, t - now);
    }

    elapsed() {
        const now = this.ended ? this.doneAt : (this.pausedAt || this.now());
        return Math.max(0, now - this.startAt - this.pausedTotal);
    }

    // Why a player can't act right now ('' when they can).
    blocker(p) {
        if (this.ended) return 'ended';
        if (this.pausedAt) return 'paused';
        if (this.now() < this.startAt) return 'countdown';
        if (!p) return 'unknown';
        if (this.isFrozen(this.now())) return 'frozen';
        return '';
    }

    isFrozen(now) {
        return this.fx.stall > now || this.fx.ice > now;
    }

    isFree(i) {
        const cell = this.cells[i];
        return !!cell && !cell.solved && !this.occ.has(i) && !(this.boss && this.boss.i === i);
    }

    standingOn(i) {
        for (const p of this.players.values()) if (p.r * this.cols + p.c === i) return true;
        return false;
    }

    randomOpenCell() {
        const open = [];
        for (let i = 0; i < this.cells.length; i++) if (!(this.boss && this.boss.i === i)) open.push(i);
        const i = open[Math.floor(this.rand() * open.length)] || 0;
        return { r: Math.floor(i / this.cols), c: i % this.cols };
    }

    solvedCount() {
        return this.cells.reduce((n, c) => n + (c.solved ? 1 : 0), 0);
    }

    progress() {
        const solved = this.solvedCount();
        return { solved, total: this.cells.length, pct: Math.floor((solved * 100) / this.cells.length) };
    }

    snapshot(meId) {
        const me = this.players.get(meId);
        const now = this.now();
        return {
            t: 'state',
            team: this.team,
            me: meId,
            cols: this.cols,
            rows: this.rows,
            cells: this.cells.map((c) => ({ code: c.code, solved: c.solved, by: c.by, crown: c.crown })),
            players: [...this.players.values()].map((p) => this.pub(p)),
            occ: [...this.occ.entries()].map(([i, o]) => ({ i, by: o.id, ms: this.remaining(o.until) })),
            boss: this.bossPub(),
            fx: this.fxPub(),
            hintMs: me ? this.remaining(me.hintUntil) : 0,
            deck: me ? this.deckPub(me) : null,
            incoming: this.incoming.map((inc) => this.incomingPub(inc)),
            limits: { occMs: this.o.occMs, bossWaitMs: this.o.bossWaitMs, bossLimitMs: this.o.bossLimitMs },
            startMs: Math.max(0, this.startAt - (this.pausedAt || now)),
            endMs: this.remaining(this.endAt),
            paused: !!this.pausedAt,
            attacks: !!this.o.attacksOn(),
            ended: this.ended ? this.result() : null,
            question: me && this.boss && this.boss.by === meId && this.boss.phase === 'fight' ? this.questionPub() : null,
        };
    }

    fxPub() {
        return {
            stall: this.remaining(this.fx.stall), ice: this.remaining(this.fx.ice),
            fog: this.remaining(this.fx.fog), mirror: this.remaining(this.fx.mirror),
        };
    }

    deckPub(p) {
        const attacks = !!this.o.attacksOn();
        return {
            slots: Array.from({ length: Items.DECK_SIZE }, (_, k) => {
                const id = p.deck[k];
                if (!id) return null;
                const it = Items.publicItem(id);
                if (it.kind === 'attack' && !attacks) it.off = true;
                return it;
            }),
            shields: p.shields,
        };
    }

    bossPub() {
        const b = this.boss;
        if (!b) return null;
        const out = { i: b.i, phase: b.phase, ms: this.remaining(b.until), by: b.by, title: b.q.title };
        if (b.phase === 'gather') Object.assign(out, this.gatherCount());
        return out;
    }

    questionPub() {
        const q = this.boss.q;
        return { title: q.title, code: q.code, input: q.input || '', blank: !!q.blank, ms: this.remaining(this.boss.until) };
    }

    incomingPub(inc) {
        return { id: inc.id, item: Items.publicItem(inc.item), from: inc.fromTeam, fromName: inc.fromName, ms: this.remaining(inc.at) };
    }

    sendDeck(p) {
        if (!p.bot) this.o.out.player(p.id, { t: 'deck', ...this.deckPub(p) });
    }

    touch(p) {
        if (p && !p.bot) {
            this.lastInput = this.now();
            if (this.idleWarned) {
                this.idleWarned = false;
                this.o.out.team({ t: 'idle', warn: false });
            }
        }
    }

    // ── movement ─────────────────────────────────────────────

    move(id, cmd) {
        const p = this.players.get(id);
        if (!p) return;
        this.touch(p);
        const seq = Number.isInteger(cmd && cmd.seq) ? cmd.seq : -1;
        const fighting = this.boss && this.boss.by === id;
        if (this.blocker(p) || p.occ >= 0 || fighting) {
            if (!p.bot) this.o.out.player(id, { t: 'pos', id, r: p.r, c: p.c, seq });
            return;
        }
        const safe = {
            dir: ['L', 'R', 'U', 'D'].includes(cmd.dir) ? cmd.dir : null,
            jump: !!cmd.jump,
            to: ['home', 'end', 'first', 'last'].includes(cmd.to) ? cmd.to : null,
        };
        const flipped = this.fx.mirror > this.now();
        const next = Moves.move(this.grid, p.r, p.c, safe, flipped);
        p.r = next.r;
        p.c = next.c;
        this.o.out.team({ t: 'pos', id, r: p.r, c: p.c, seq });
        if (this.boss && this.boss.phase === 'gather') this.checkGather();
    }

    // ── occupy / submit ──────────────────────────────────────

    occupy(id) {
        const p = this.players.get(id);
        const why = this.blocker(p);
        if (why) return { ok: false, why };
        this.touch(p);
        if (p.occ >= 0 || (this.boss && this.boss.by === id)) return { ok: false, why: 'busy' };
        const i = p.r * this.cols + p.c;
        const cell = this.cells[i];
        if (cell.solved) return { ok: false, why: 'solved' };
        if (this.boss && this.boss.i === i) return { ok: false, why: 'boss' };
        const held = this.occ.get(i);
        if (held) return { ok: false, why: 'taken', by: this.players.get(held.id)?.name || '' };
        const until = this.now() + this.o.occMs;
        this.occ.set(i, { id, until });
        p.occ = i;
        this.o.out.team({ t: 'occ', i, by: id, ms: this.o.occMs });
        return { ok: true, i, ms: this.o.occMs };
    }

    release(id, why) {
        const p = this.players.get(id);
        if (!p || p.occ < 0) return;
        const i = p.occ;
        this.occ.delete(i);
        p.occ = -1;
        this.o.out.team({ t: 'occ', i, by: null, why: why || 'release' });
    }

    submit(id, text) {
        const p = this.players.get(id);
        const why = this.blocker(p);
        if (why) return { ok: false, why };
        this.touch(p);
        if (p.occ < 0) return { ok: false, why: 'not-holding' };
        const i = p.occ;
        const correct = Grade.check({ code: this.cells[i].code }, String(text || '').slice(0, MAX_INPUT));
        if (!correct) {
            this.release(id, 'wrong');
            return { ok: true, correct: false, i };
        }
        this.occ.delete(i);
        p.occ = -1;
        this.o.out.team({ t: 'occ', i, by: null, why: 'solved' });
        p.solved++;
        this.solve(i, p.name, 'type');
        this.o.out.team({ t: 'stat', id, solved: p.solved, bosses: p.bosses });
        this.checkClear();
        return { ok: true, correct: true, i };
    }

    solve(i, by, via, crown) {
        const cell = this.cells[i];
        cell.solved = true;
        cell.by = by;
        cell.crown = crown || null;
        this.o.out.team({ t: 'cell', i, solved: true, by, crown: cell.crown, via });
    }

    checkClear() {
        if (!this.ended && this.cells.every((c) => c.solved)) this.end('clear');
    }

    // ── bosses ───────────────────────────────────────────────

    // q: { title, code, input, answers, blank }. Returns false when a boss is
    // already up or no cell is available.
    spawnBoss(q, opts = {}) {
        if (this.ended || this.boss || !q) return false;
        const now = this.now();
        const usable = (i) => this.isFree(i) && !this.standingOn(i);
        let i = -1;
        let phase = 'wait';
        let until = now + this.o.bossWaitMs;
        if (opts.gather && this.humans().length >= 3) {
            const col = opts.side ? this.cols - 2 : 1;
            for (let k = 0; k < this.rows && i < 0; k++) {
                const cand = ((opts.row + k) % this.rows) * this.cols + col;
                if (usable(cand)) i = cand;
            }
            if (i >= 0) { phase = 'gather'; until = now + GATHER_MS; }
        }
        if (i < 0 && opts.gatherOnly) return false;
        if (i < 0) {
            const pool = [];
            for (let k = 0; k < this.cells.length; k++) if (usable(k)) pool.push(k);
            if (!pool.length) return false;
            i = pool[Math.floor(this.rand() * pool.length)];
        }
        this.boss = { i, q, phase, until, by: null };
        this.o.out.team({ t: 'boss', ...this.bossPub() });
        return true;
    }

    grab(id, key) {
        const p = this.players.get(id);
        const why = this.blocker(p);
        if (why) return { ok: false, why };
        this.touch(p);
        const b = this.boss;
        if (!b || b.phase !== 'wait') return { ok: false, why: 'no-boss' };
        if (p.occ >= 0) return { ok: false, why: 'busy' };
        const br = Math.floor(b.i / this.cols);
        const bc = b.i % this.cols;
        const okSide = br === p.r && ((key === 'Delete' && bc === p.c + 1) || (key === 'Backspace' && bc === p.c - 1));
        if (!okSide) return { ok: false, why: 'not-adjacent' };
        b.phase = 'fight';
        b.by = id;
        b.until = this.now() + this.o.bossLimitMs;
        this.o.out.team({ t: 'boss', ...this.bossPub() });
        return { ok: true, q: this.questionPub() };
    }

    bossSubmit(id, text) {
        const p = this.players.get(id);
        const why = this.blocker(p);
        if (why && why !== 'frozen') return { ok: false, why };
        this.touch(p);
        const b = this.boss;
        if (!b || b.phase !== 'fight' || b.by !== id) return { ok: false, why: 'no-fight' };
        const correct = Grade.check(b.q, String(text || '').slice(0, 1200));
        if (!correct) {
            this.endBoss('wrong');
            return { ok: true, correct: false };
        }
        p.solved++;
        p.bosses++;
        this.stats.bosses++;
        const i = b.i;
        this.boss = null;
        this.solve(i, p.name, 'boss', p.name);
        this.o.out.team({ t: 'boss', i: -1, end: 'win', by: id });
        this.o.out.team({ t: 'stat', id, solved: p.solved, bosses: p.bosses });
        const item = Items.rollReward(this.rand, { penalty: !!this.o.penalty, attacks: !!this.o.attacksOn() });
        this.give(p, item);
        this.checkClear();
        return { ok: true, correct: true, item: Items.publicItem(item) };
    }

    giveUp(id) {
        if (this.boss && this.boss.by === id) this.endBoss('giveup');
    }

    endBoss(reason) {
        if (!this.boss) return;
        const by = this.boss.by;
        this.boss = null;
        this.o.out.team({ t: 'boss', i: -1, end: reason, by });
    }

    gatherCount() {
        const b = this.boss;
        const br = Math.floor(b.i / this.cols);
        const bc = b.i % this.cols;
        const humans = this.humans();
        const got = humans.filter((p) => p.r === br && Math.abs(p.c - bc) === 1).length;
        return { got, need: humans.length };
    }

    checkGather() {
        const b = this.boss;
        if (!b || b.phase !== 'gather') return;
        const { got, need } = this.gatherCount();
        this.o.out.team({ t: 'gather', got, need });
        if (need > 0 && got >= need) {
            const i = b.i;
            this.boss = null;
            this.stats.bosses++;
            this.solve(i, '모두', 'gather', '모두');
            this.o.out.team({ t: 'boss', i: -1, end: 'gather', by: null });
            for (const p of this.humans()) this.give(p, Items.rollGather(this.rand, { attacks: !!this.o.attacksOn() }));
            this.checkClear();
        }
    }

    // ── items ────────────────────────────────────────────────

    give(p, id) {
        const it = Items.ITEMS[id];
        if (!it) return;
        if (it.kind === 'penalty') {
            this.fx.stall = this.now() + Items.DURATION.stall;
            this.o.out.team({ t: 'fx', type: 'stall', ms: Items.DURATION.stall, by: p.name });
            this.o.out.team({ t: 'item', kind: 'penalty', by: p.name, item: Items.publicItem(id) });
            for (const q of this.players.values()) if (q.occ >= 0) this.release(q.id, 'stall');
            return;
        }
        let lost = false;
        if (it.kind === 'shield') {
            if (p.shields < Items.MAX_SHIELDS) p.shields++;
            else lost = true;
        } else if (p.deck.length < Items.DECK_SIZE) {
            p.deck.push(id);
        } else {
            lost = true;
        }
        this.sendDeck(p);
        this.o.out.team({ t: 'item', kind: 'get', by: p.name, item: Items.publicItem(id), lost });
    }

    neighbours(i) {
        const r = Math.floor(i / this.cols);
        const c = i % this.cols;
        const out = [];
        for (let dr = -1; dr <= 1; dr++) {
            for (let dc = -1; dc <= 1; dc++) {
                if (!dr && !dc) continue;
                const nr = r + dr;
                const nc = c + dc;
                if (nr >= 0 && nc >= 0 && nr < this.rows && nc < this.cols) out.push(nr * this.cols + nc);
            }
        }
        return out;
    }

    // Solves cells for an item (no personal score).
    sweepCells(list, by) {
        let n = 0;
        for (const i of list) {
            const cell = this.cells[i];
            if (cell.solved || (this.boss && this.boss.i === i)) continue;
            const held = this.occ.get(i);
            if (held) this.release(held.id, 'item');
            this.solve(i, by, 'item');
            n++;
        }
        return n;
    }

    useItem(id, slot) {
        const p = this.players.get(id);
        const why = this.blocker(p);
        if (why) return { ok: false, why };
        this.touch(p);
        const k = Number(slot) - 1;
        const itemId = p.deck[k];
        if (!itemId) return { ok: false, why: 'empty' };
        const it = Items.ITEMS[itemId];
        const here = p.r * this.cols + p.c;

        if (it.kind === 'attack') {
            if (!this.o.attacksOn()) return { ok: false, why: 'attacks-off' };
            if (!this.o.on.attack(this, p, itemId)) return { ok: false, why: 'no-target' };
            this.stats.sent++;
        } else if (itemId === 'bomb') {
            this.sweepCells(this.neighbours(here), p.name);
        } else if (itemId === 'sweep') {
            const list = [];
            for (let c = p.c + 1; c < this.cols; c++) list.push(p.r * this.cols + c);
            this.sweepCells(list, p.name);
        } else if (itemId === 'drop') {
            const list = [];
            for (let r = p.r + 1; r < this.rows; r++) list.push(r * this.cols + p.c);
            this.sweepCells(list, p.name);
        } else if (itemId === 'hint') {
            p.hintUntil = this.now() + Items.DURATION.hint;
            this.o.out.player(p.id, { t: 'hint', ms: Items.DURATION.hint });
        } else if (itemId === 'shrink') {
            for (const i of this.neighbours(here)) {
                if (!this.isFree(i)) continue;
                const code = this.short[Math.floor(this.rand() * this.short.length)];
                this.cells[i].code = code;
                this.o.out.team({ t: 'code', i, code });
            }
        }
        p.deck.splice(k, 1);
        this.sendDeck(p);
        this.o.out.team({ t: 'item', kind: it.kind === 'attack' ? 'attack' : 'use', by: p.name, item: Items.publicItem(itemId) });
        this.checkClear();
        return { ok: true, item: Items.publicItem(itemId) };
    }

    receiveAttack(inc) {
        if (this.ended) return false;
        const entry = { ...inc, at: this.now() + Items.DEFEND_MS };
        this.incoming.push(entry);
        this.o.out.team({ t: 'incoming', ...this.incomingPub(entry) });
        return true;
    }

    defend(id) {
        const p = this.players.get(id);
        if (!p || this.ended) return { ok: false, why: 'ended' };
        this.touch(p);
        if (!this.incoming.length) return { ok: false, why: 'nothing' };
        if (p.shields <= 0) return { ok: false, why: 'no-shield' };
        const inc = this.incoming.shift();
        p.shields--;
        this.stats.blocked++;
        this.sendDeck(p);
        this.o.out.team({ t: 'incoming_end', id: inc.id, blocked: true, by: p.name });
        this.o.on.attackDone(this, inc, true);
        return { ok: true, blocked: Items.publicItem(inc.item) };
    }

    applyAttack(inc) {
        const now = this.now();
        this.stats.got++;
        const id = inc.item;
        if (id === 'ice' || id === 'fog' || id === 'mirror') {
            this.fx[id] = now + Items.DURATION[id];
            this.o.out.team({ t: 'fx', type: id, ms: Items.DURATION[id], by: inc.fromName });
            if (id === 'ice') for (const p of this.players.values()) if (p.occ >= 0) this.release(p.id, 'ice');
        } else if (id === 'undo') {
            const solved = [];
            this.cells.forEach((c, i) => { if (c.solved) solved.push(i); });
            for (let n = 0; n < Items.UNDO_COUNT && solved.length; n++) {
                const i = solved.splice(Math.floor(this.rand() * solved.length), 1)[0];
                Object.assign(this.cells[i], { solved: false, by: null, crown: null });
                this.o.out.team({ t: 'cell', i, solved: false, by: null, crown: null, via: 'undo' });
            }
        } else if (id === 'scatter') {
            const spot = this.randomOpenCell();
            for (const p of this.players.values()) {
                if (this.boss && this.boss.by === p.id) continue;
                if (p.occ >= 0) this.release(p.id, 'scatter');
                p.r = spot.r;
                p.c = spot.c;
                this.o.out.team({ t: 'pos', id: p.id, r: p.r, c: p.c, seq: -1 });
            }
        }
        this.o.out.team({ t: 'incoming_end', id: inc.id, blocked: false });
        this.o.on.attackDone(this, inc, false);
    }

    // ── clock ────────────────────────────────────────────────

    pause(on) {
        const now = this.now();
        if (on && !this.pausedAt && !this.ended) {
            this.pausedAt = now;
        } else if (!on && this.pausedAt) {
            const shift = now - this.pausedAt;
            // Paused during the countdown: the whole round starts later.
            if (this.pausedAt < this.startAt) this.startAt += shift;
            else this.pausedTotal += shift;
            this.pausedAt = 0;
            this.endAt += shift;
            for (const o of this.occ.values()) o.until += shift;
            if (this.boss) this.boss.until += shift;
            for (const k of Object.keys(this.fx)) if (this.fx[k]) this.fx[k] += shift;
            for (const inc of this.incoming) inc.at += shift;
            for (const p of this.players.values()) {
                if (p.hintUntil) p.hintUntil += shift;
                if (p.botState) p.botState.nextAt += shift;
            }
            this.lastInput += shift;
        } else {
            return;
        }
        this.o.out.team({ t: 'pause', on: !!this.pausedAt, endMs: this.remaining(this.endAt), startMs: Math.max(0, this.startAt - (this.pausedAt || now)) });
    }

    tick() {
        if (this.ended || this.pausedAt) return;
        const now = this.now();
        if (now < this.startAt) return;

        for (const [i, o] of [...this.occ.entries()]) {
            if (o.until <= now) {
                const p = this.players.get(o.id);
                if (p) this.release(p.id, 'timeout');
                else this.occ.delete(i);
            }
        }
        const b = this.boss;
        if (b && b.until <= now) {
            this.endBoss(b.phase === 'fight' ? 'timeout' : b.phase === 'gather' ? 'scattered' : 'escaped');
        } else if (b && b.phase === 'gather') {
            this.checkGather();
        }
        while (this.incoming.length && this.incoming[0].at <= now) this.applyAttack(this.incoming.shift());

        for (const p of this.players.values()) if (p.bot) Bot.tick(this, p, now);

        const idle = this.o.idle;
        if (idle && this.humans().length) {
            const quiet = now - this.lastInput;
            if (!this.idleWarned && quiet >= idle.ms) {
                this.idleWarned = true;
                this.o.out.team({ t: 'idle', warn: true, ms: idle.warnMs });
            } else if (quiet >= idle.ms + idle.warnMs) {
                this.end('idle');
                return;
            }
        }
        if (now >= this.endAt) this.end('time');
    }

    end(reason) {
        if (this.ended) return;
        this.ended = reason;
        this.doneAt = this.now();
        this.boss = null;
        this.occ.clear();
        for (const p of this.players.values()) p.occ = -1;
        this.incoming = [];
        this.o.out.team({ t: 'end', result: this.result() });
        this.o.on.end(this);
    }

    result() {
        const prog = this.progress();
        return {
            team: this.team,
            reason: this.ended,
            cleared: this.ended === 'clear',
            ms: this.elapsed(),
            limitMs: this.o.limitMs,
            ...prog,
            bosses: this.stats.bosses,
            attacks: { sent: this.stats.sent, got: this.stats.got, blocked: this.stats.blocked },
            players: [...this.players.values()]
                .map((p) => ({ id: p.id, uid: p.uid, name: p.name, avatar: p.avatar, color: p.color, bot: p.bot, solved: p.solved, bosses: p.bosses, score: p.solved + p.bosses * 3 }))
                .sort((a, b) => b.score - a.score),
        };
    }
}

module.exports = { Match, GATHER_MS, MAX_INPUT };

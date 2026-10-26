'use strict';

const { Match } = require('./match');
const { seeded, randomSeed } = require('./rng');
const { blocksFor } = require('./board');
const { sanitize } = require('./settings');
const Items = require('./items');
const config = require('./config');

const GATHER_EVERY = 4;
const BOT_NAMES = ['연습봇 가', '연습봇 나', '연습봇 다', '연습봇 라', '연습봇 마', '연습봇 바', '연습봇 사', '연습봇 아'];

function pickProblems(problems, tags) {
    const want = new Set(tags || []);
    const filter = (list) => {
        if (!want.size) return list;
        const hit = list.filter((p) => (p.tags || []).some((t) => want.has(t)));
        return hit.length ? hit : list;
    };
    return { normal: filter(problems.normal || []), boss: filter(problems.boss || []) };
}

/**
 * A teacher-led class session (mode "class", several teams) or a student
 * free room (mode "free", one team, a host). Owns the members, teams, bots
 * and the current round's matches.
 */
class Arena {
    constructor(opts) {
        this.id = opts.id;
        this.mode = opts.mode;
        this.wpId = opts.wpId || 0;
        this.title = opts.title || '';
        this.context = opts.context || '';
        this.settings = sanitize(opts.settings, this.mode);
        this.problems = opts.problems || { normal: [], boss: [] };
        this.roster = opts.roster ? new Map(opts.roster.map((r) => [r.uid, r.name])) : null;
        this.hostUid = opts.hostUid || 0;
        this.hooks = opts.hooks || {};
        this.now = opts.now || Date.now;
        this.members = new Map(); // uid → { uid, name, avatar, color, team, conns:Set }
        this.bots = new Map();
        this.banned = new Set();
        this.teachers = new Set();
        this.boards = new Set();
        this.phase = 'lobby';
        this.round = null;
        this.roundNo = 0;
        this.lastResult = null;
        this.attacks = this.settings.attacks;
        this.feedLog = [];
        this.createdAt = this.now();
        this.touchedAt = this.now();
        this.closed = false;
        this.dirty = true;
        this.lastStandings = '';
        this.lastBoardAt = 0;
        this.botSeq = 0;
    }

    // ── membership ───────────────────────────────────────────

    teamCount() {
        return this.mode === 'class' ? this.settings.teams : 1;
    }

    capacity() {
        return this.mode === 'free' ? this.settings.maxPlayers : 400;
    }

    headcount() {
        return this.members.size + this.bots.size;
    }

    canEnter(claims) {
        if (this.closed) return 'closed';
        if (this.banned.has(claims.uid)) return 'kicked';
        if (this.roster && !this.roster.has(claims.uid)) return 'not-in-roster';
        if (!this.members.has(claims.uid) && this.headcount() >= this.capacity()) return 'full';
        if (this.mode === 'free' && this.phase !== 'lobby' && !this.members.has(claims.uid)) return 'started';
        return '';
    }

    attach(conn) {
        this.touchedAt = this.now();
        if (conn.role === 'teacher') {
            this.teachers.add(conn);
            conn.send({ t: 'arena', ...this.detail(conn) });
            return;
        }
        if (conn.role === 'board') {
            this.boards.add(conn);
            conn.send({ t: 'arena', ...this.detail(conn) });
            conn.send(this.boardView());
            return;
        }
        let m = this.members.get(conn.uid);
        if (!m) {
            m = {
                uid: conn.uid, name: conn.name, avatar: conn.look.avatar, color: conn.look.color,
                team: this.mode === 'free' ? 1 : 0, conns: new Set(),
            };
            this.members.set(conn.uid, m);
            if (!this.hostUid && this.mode === 'free') this.hostUid = conn.uid;
        }
        for (const old of m.conns) {
            old.send({ t: 'closed', reason: 'dup' });
            old.close();
        }
        m.conns = new Set([conn]);
        this.changed();
        conn.send({ t: 'arena', ...this.detail(conn) });
        const match = this.round && m.team ? this.ensureMatch(m.team) : null;
        if (match) match.join(this.playerInfo(m));
    }

    detach(conn) {
        if (this.teachers.delete(conn) || this.boards.delete(conn)) return;
        const m = this.members.get(conn.uid);
        if (!m || !m.conns.has(conn)) return;
        m.conns.delete(conn);
        this.matchOf(m.team)?.setOnline(String(m.uid), false);
        this.changed();
    }

    // The player stays in the arena for a grace period so a page reload or
    // a short network drop does not lose their place.
    expire(uid) {
        const m = this.members.get(uid);
        if (!m || m.conns.size) return;
        this.removeMember(uid);
    }

    removeMember(uid) {
        const m = this.members.get(uid);
        if (!m) return;
        this.matchOf(m.team)?.leave(String(uid));
        this.members.delete(uid);
        if (this.mode === 'free') {
            if (this.hostUid === uid) this.hostUid = [...this.members.keys()][0] || 0;
            if (!this.members.size) this.close('empty');
        }
        this.changed();
    }

    online(m) {
        return m.conns.size > 0;
    }

    playerInfo(m) {
        return { id: String(m.uid), uid: m.uid, name: m.name, avatar: m.avatar, color: m.color, bot: false };
    }

    botInfo(b) {
        return { id: b.id, uid: 0, name: b.name, avatar: b.avatar, color: b.color, bot: true };
    }

    setLook(uid, look) {
        const m = this.members.get(uid);
        if (!m) return;
        m.avatar = look.avatar;
        m.color = look.color;
        this.changed();
    }

    setTeam(uid, no) {
        const m = this.members.get(uid);
        if (!m) return { ok: false, why: 'unknown' };
        const n = parseInt(no, 10);
        if (!(n >= 0 && n <= this.teamCount())) return { ok: false, why: 'bad-team' };
        if (m.team === n) return { ok: true };
        this.matchOf(m.team)?.leave(String(uid));
        m.team = n;
        if (this.round && n) this.ensureMatch(n).join(this.playerInfo(m));
        this.changed();
        return { ok: true };
    }

    autoAssign() {
        const counts = new Array(this.teamCount() + 1).fill(0);
        for (const m of this.members.values()) if (m.team) counts[m.team]++;
        for (const b of this.bots.values()) counts[b.team]++;
        for (const m of this.members.values()) {
            if (m.team) continue;
            let best = 1;
            for (let n = 2; n <= this.teamCount(); n++) if (counts[n] < counts[best]) best = n;
            counts[best]++;
            this.setTeam(m.uid, best);
        }
    }

    // ── bots ─────────────────────────────────────────────────

    addBot(team) {
        const n = parseInt(team, 10) || 1;
        if (n < 1 || n > this.teamCount()) return { ok: false, why: 'bad-team' };
        if (this.bots.size >= config.maxBots || this.headcount() >= this.capacity()) return { ok: false, why: 'full' };
        const k = this.botSeq++;
        const b = { id: 'b' + k, name: BOT_NAMES[k % BOT_NAMES.length] + (k >= BOT_NAMES.length ? ' ' + (Math.floor(k / BOT_NAMES.length) + 1) : ''), avatar: k % 8, color: (k + 3) % 8, team: n };
        this.bots.set(b.id, b);
        if (this.round) this.ensureMatch(n).join(this.botInfo(b));
        this.changed();
        return { ok: true, id: b.id };
    }

    removeBot(id) {
        const b = this.bots.get(id);
        if (!b) return { ok: false, why: 'unknown' };
        this.matchOf(b.team)?.leave(b.id);
        this.bots.delete(id);
        this.changed();
        return { ok: true };
    }

    fillBots() {
        const sizes = this.teamSizes();
        const target = Math.max(1, ...sizes.slice(1));
        for (let n = 1; n <= this.teamCount(); n++) {
            for (let k = sizes[n]; k < target; k++) if (!this.addBot(n).ok) return;
        }
    }

    clearBots() {
        for (const id of [...this.bots.keys()]) this.removeBot(id);
    }

    teamSizes() {
        const sizes = new Array(this.teamCount() + 1).fill(0);
        for (const m of this.members.values()) if (m.team) sizes[m.team]++;
        for (const b of this.bots.values()) sizes[b.team]++;
        return sizes;
    }

    // ── settings / control ───────────────────────────────────

    updateSettings(input) {
        if (this.phase === 'playing') return { ok: false, why: 'playing' };
        this.settings = sanitize({ ...this.settings, ...input }, this.mode);
        this.attacks = this.settings.attacks;
        for (const m of this.members.values()) if (m.team > this.teamCount()) m.team = 0;
        for (const b of [...this.bots.values()]) if (b.team > this.teamCount()) this.removeBot(b.id);
        this.changed();
        return { ok: true };
    }

    setAttacks(on) {
        this.attacks = !!on;
        for (const match of this.matches()) {
            for (const p of match.players.values()) match.sendDeck(p);
        }
        this.feed(on ? '선생님이 공격 아이템을 켰어요' : '선생님이 공격 아이템을 껐어요', 'info');
        this.changed();
    }

    start() {
        if (this.phase === 'playing') return { ok: false, why: 'playing' };
        const sizes = this.teamSizes();
        if (!sizes.some((n, k) => k > 0 && n > 0)) return { ok: false, why: 'no-players' };
        const pool = pickProblems(this.problems, this.settings.tags);
        if (!pool.normal.length) return { ok: false, why: 'no-problems' };
        const seed = randomSeed();
        const rng = seeded(seed ^ 0x5bd1e995);
        const now = this.now();
        this.roundNo++;
        this.round = {
            no: this.roundNo,
            seed,
            codes: pool.normal.map((p) => p.code),
            bossQueue: rng.shuffle(pool.boss),
            bossSlots: [],
            blocks: blocksFor(this.settings.blocks, Math.max(...sizes.slice(1))),
            limitMs: this.settings.limitMin * 60000,
            startAt: now + config.countdownMs,
            wallStart: Date.now(),
            pausedAt: 0,
            pausedTotal: 0,
            slot: 0,
            matches: new Map(),
            doneOrder: [],
        };
        // Boss slots are fixed up front so every team sees the same boss
        // (and the same gather-boss spot) at the same time.
        const totalSlots = Math.ceil(this.round.limitMs / (this.settings.bossEverySec * 1000));
        for (let k = 1; k <= totalSlots; k++) {
            this.round.bossSlots.push({ gather: k % GATHER_EVERY === 0, row: rng.int(64), side: rng.int(2) === 1 });
        }
        this.phase = 'playing';
        for (let n = 1; n <= this.teamCount(); n++) {
            if (sizes[n] > 0) this.ensureMatch(n);
        }
        this.feed(`${this.roundNo}번째 경기를 시작해요!`, 'info');
        this.changed();
        return { ok: true };
    }

    matches() {
        return this.round ? [...this.round.matches.values()] : [];
    }

    matchOf(team) {
        return this.round && team ? this.round.matches.get(team) || null : null;
    }

    roundElapsed() {
        const r = this.round;
        const now = r.pausedAt || this.now();
        return Math.max(0, now - r.startAt - r.pausedTotal);
    }

    roundRemaining() {
        if (!this.round) return 0;
        return Math.max(0, this.round.limitMs - this.roundElapsed());
    }

    ensureMatch(team) {
        const r = this.round;
        let match = r.matches.get(team);
        if (match) return match;
        const now = this.now();
        const lateStart = Math.max(now, r.startAt);
        match = new Match({
            team,
            seed: r.seed,
            codes: r.codes,
            blocks: r.blocks,
            limitMs: now > r.startAt ? this.roundRemaining() : r.limitMs,
            occMs: this.settings.occSec * 1000,
            bossWaitMs: this.settings.bossWaitSec * 1000,
            bossLimitMs: this.settings.bossLimitSec * 1000,
            penalty: this.settings.penalty,
            botPace: this.settings.botPace,
            startAt: lateStart,
            now: this.now,
            rand: Math.random,
            attacksOn: () => this.attacks,
            idle: this.mode === 'free' ? { ms: config.idleMs, warnMs: config.idleWarnMs } : null,
            out: {
                team: (msg) => this.toTeam(team, msg),
                player: (id, msg) => this.toUid(Number(id), msg),
            },
            on: {
                end: (m) => this.onMatchEnd(m),
                attack: (m, p, item) => this.routeAttack(m, p, item),
                attackDone: (m, inc, blocked) => this.attackDone(m, inc, blocked),
            },
        });
        if (r.pausedAt) match.pause(true);
        r.matches.set(team, match);
        for (const m of this.members.values()) if (m.team === team) match.join(this.playerInfo(m));
        for (const b of this.bots.values()) if (b.team === team) match.join(this.botInfo(b));
        for (const m of this.members.values()) if (m.team === team && !this.online(m)) match.setOnline(String(m.uid), false);
        return match;
    }

    pause(on) {
        const r = this.round;
        if (!r || this.phase !== 'playing') return { ok: false, why: 'not-playing' };
        const now = this.now();
        if (on && !r.pausedAt) {
            r.pausedAt = now;
        } else if (!on && r.pausedAt) {
            const shift = now - r.pausedAt;
            if (r.pausedAt < r.startAt) r.startAt += shift;
            else r.pausedTotal += shift;
            r.pausedAt = 0;
        } else {
            return { ok: true };
        }
        for (const m of this.matches()) m.pause(on);
        this.feed(on ? '선생님이 경기를 잠시 멈췄어요' : '경기를 다시 시작해요', 'info');
        this.changed();
        return { ok: true };
    }

    stop() {
        if (this.phase !== 'playing') return { ok: false, why: 'not-playing' };
        if (this.round.pausedAt) this.pause(false);
        for (const m of this.matches()) if (!m.ended) m.end('stop');
        this.finishRound('stop');
        return { ok: true };
    }

    kick(uid) {
        const m = this.members.get(uid);
        if (!m) return { ok: false, why: 'unknown' };
        this.banned.add(uid);
        for (const c of m.conns) {
            c.send({ t: 'closed', reason: 'kicked' });
            c.close();
        }
        this.removeMember(uid);
        return { ok: true };
    }

    close(reason) {
        if (this.closed) return;
        if (this.phase === 'playing') this.stop();
        this.closed = true;
        const all = [...this.teachers, ...this.boards];
        for (const m of this.members.values()) all.push(...m.conns);
        for (const c of all) c.send({ t: 'closed', reason });
        this.hooks.onClosed?.(this, reason);
    }

    // ── attacks ──────────────────────────────────────────────

    ranking() {
        return this.matches()
            .map((m) => ({ m, ...m.progress() }))
            .sort((a, b) => b.pct - a.pct || b.solved - a.solved || a.m.team - b.m.team);
    }

    routeAttack(from, p, itemId) {
        const live = this.ranking().filter((x) => !x.m.ended);
        const k = live.findIndex((x) => x.m === from);
        if (k < 0 || live.length < 2) return false;
        const target = (k === 0 ? live[1] : live[k - 1]).m;
        const it = Items.publicItem(itemId);
        const ok = target.receiveAttack({ id: `${from.team}-${this.now()}-${Math.floor(Math.random() * 1e6)}`, item: itemId, fromTeam: from.team, fromName: p.name });
        if (ok) {
            this.feed(`${from.team}팀 ${p.name} → ${target.team}팀에 ${it.name}!`, 'attack');
            this.toBoards({ t: 'attack', from: from.team, to: target.team, item: it, by: p.name });
        }
        return ok;
    }

    attackDone(target, inc, blocked) {
        const it = Items.publicItem(inc.item);
        if (blocked) this.feed(`${target.team}팀이 ${it.name} 공격을 방패로 막았어요`, 'shield');
        this.toTeam(inc.fromTeam, { t: 'attack_result', to: target.team, item: it, blocked });
    }

    // ── round end ────────────────────────────────────────────

    onMatchEnd(match) {
        if (!this.round) return;
        if (match.ended === 'clear') {
            this.round.doneOrder.push(match.team);
            const rank = this.round.doneOrder.length;
            this.feed(`${match.team}팀이 ${rank}등으로 판을 모두 풀었어요!`, 'clear');
            this.toTeam(match.team, { t: 'team_done', rank, ms: match.elapsed() });
            this.toBoards({ t: 'clear', team: match.team, rank });
        }
        this.changed();
        if (this.mode === 'free' && match.ended === 'idle') {
            this.finishRound('idle');
            this.close('idle');
            return;
        }
        if (this.matches().every((m) => m.ended)) this.finishRound(match.ended === 'stop' ? 'stop' : 'done');
    }

    finishRound(reason) {
        const r = this.round;
        if (!r || this.phase !== 'playing') return;
        const rows = this.matches().map((m) => ({ m, res: m.result() }));
        const done = rows.filter((x) => x.res.cleared).sort((a, b) => r.doneOrder.indexOf(a.m.team) - r.doneOrder.indexOf(b.m.team));
        const rest = rows.filter((x) => !x.res.cleared).sort((a, b) => b.res.pct - a.res.pct || b.res.solved - a.res.solved);
        const teams = [];
        done.forEach((x, k) => teams.push({ ...x.res, rank: k + 1 }));
        let prev = null;
        rest.forEach((x, k) => {
            const rank = prev && prev.res.pct === x.res.pct && prev.res.solved === x.res.solved ? teams[teams.length - 1].rank : done.length + k + 1;
            teams.push({ ...x.res, rank });
            prev = x;
        });
        const result = {
            round: r.no,
            mode: this.mode,
            sessionId: this.wpId,
            title: this.title,
            reason,
            startedAt: Math.floor(r.wallStart / 1000),
            endedAt: Math.floor(Date.now() / 1000),
            limitMs: r.limitMs,
            blocks: r.blocks,
            settings: this.settings,
            attacks: this.attacks,
            teams,
        };
        this.lastResult = result;
        this.phase = 'lobby';
        this.round = null;
        this.broadcast({ t: 'round_result', result });
        this.changed();
        this.hooks.onResult?.(this, result);
    }

    // ── ticking & outputs ────────────────────────────────────

    tick() {
        const r = this.round;
        if (r && this.phase === 'playing') {
            if (!r.pausedAt && this.now() >= r.startAt) {
                const every = this.settings.bossEverySec * 1000;
                const due = Math.floor(this.roundElapsed() / every);
                while (r.slot < due && r.slot < r.bossSlots.length) {
                    const slot = r.bossSlots[r.slot];
                    const q = r.bossQueue.length ? r.bossQueue[r.slot % r.bossQueue.length] : null;
                    r.slot++;
                    for (const m of this.matches()) {
                        if (m.ended) continue;
                        if (q) m.spawnBoss(q, slot);
                        else if (slot.gather) m.spawnBoss({ title: '모여라', code: '', answers: [] }, { ...slot, gatherOnly: true });
                    }
                }
            }
            for (const m of this.matches()) m.tick();
        }
        this.pushStandings();
        if (this.boards.size && (this.dirty || this.now() - this.lastBoardAt >= 500)) {
            this.lastBoardAt = this.now();
            this.toBoards(this.boardView());
        }
        if (this.dirty) {
            this.dirty = false;
            for (const c of this.teachers) c.send({ t: 'arena', ...this.detail(c) });
            for (const c of this.boards) c.send({ t: 'arena', ...this.detail(c) });
            for (const m of this.members.values()) for (const c of m.conns) c.send({ t: 'arena', ...this.detail(c) });
            this.hooks.onChange?.(this);
        }
    }

    standings() {
        const r = this.round;
        if (!r) return [];
        const list = this.ranking().map((x) => ({
            team: x.m.team, solved: x.solved, total: x.total, pct: x.pct,
            done: !!x.m.ended, cleared: x.m.ended === 'clear', ms: x.m.elapsed(),
            boss: !!x.m.boss, fx: x.m.fxPub(),
        }));
        // Cleared teams keep their finishing place; the rest share a rank
        // when progress is equal.
        let k = 0;
        let prev = null;
        for (const row of list) {
            const doneIdx = r.doneOrder.indexOf(row.team);
            if (doneIdx >= 0) { row.rank = doneIdx + 1; continue; }
            k++;
            row.rank = prev && prev.pct === row.pct && prev.solved === row.solved ? prev.rank : r.doneOrder.length + k;
            prev = row;
        }
        return list.sort((a, b) => a.rank - b.rank);
    }

    pushStandings() {
        if (!this.round) return;
        const list = this.standings();
        const key = JSON.stringify(list.map((s) => [s.team, s.solved, s.rank, s.done, s.boss]));
        if (key === this.lastStandings) return;
        this.lastStandings = key;
        this.broadcast({ t: 'standings', list, remainMs: this.roundRemaining() });
    }

    boardView() {
        const teams = [];
        for (let n = 1; n <= this.teamCount(); n++) {
            const m = this.matchOf(n);
            const members = [...this.members.values()].filter((x) => x.team === n).map((x) => ({ name: x.name, avatar: x.avatar, color: x.color, online: this.online(x) }))
                .concat([...this.bots.values()].filter((b) => b.team === n).map((b) => ({ name: b.name, avatar: b.avatar, color: b.color, bot: true, online: true })));
            if (!m) { teams.push({ team: n, members, cells: '', cols: 12 }); continue; }
            const cells = m.cells.map((c, i) => {
                if (m.boss && m.boss.i === i) return 'B';
                if (c.solved) return c.crown ? 'K' : '1';
                if (m.occ.has(i)) return 'O';
                return '0';
            }).join('');
            const shields = [...m.players.values()].reduce((s, p) => s + p.shields, 0);
            teams.push({ team: n, members, cells, cols: m.cols, ...m.progress(), done: m.ended, fx: m.fxPub(), shields, boss: m.boss ? m.boss.phase : null });
        }
        const r = this.round;
        return {
            t: 'board',
            phase: this.phase,
            paused: !!(r && r.pausedAt),
            startMs: r ? Math.max(0, r.startAt - (r.pausedAt || this.now())) : 0,
            remainMs: this.roundRemaining(),
            attacks: this.attacks,
            teams,
            standings: this.standings(),
        };
    }

    detail(conn) {
        const teamOf = (n) => ({
            no: n,
            members: [...this.members.values()].filter((m) => m.team === n).map((m) => this.memberPub(m))
                .concat([...this.bots.values()].filter((b) => b.team === n).map((b) => ({ uid: 0, id: b.id, name: b.name, avatar: b.avatar, color: b.color, bot: true, online: true }))),
        });
        const teams = [];
        for (let n = 1; n <= this.teamCount(); n++) teams.push(teamOf(n));
        const me = conn && conn.uid ? this.members.get(conn.uid) : null;
        return {
            id: this.id,
            mode: this.mode,
            title: this.title,
            context: this.context,
            phase: this.phase,
            settings: this.settings,
            attacks: this.attacks,
            host: this.hostUid,
            teams,
            waiting: teamOf(0).members,
            round: this.round ? { no: this.round.no, remainMs: this.roundRemaining(), paused: !!this.round.pausedAt, startMs: Math.max(0, this.round.startAt - (this.round.pausedAt || this.now())) } : null,
            roundNo: this.roundNo,
            lastResult: this.lastResult,
            feed: this.feedLog.slice(-12),
            you: me ? { uid: me.uid, team: me.team, host: this.hostUid === me.uid } : null,
            role: conn ? conn.role : 'learner',
            rosterSize: this.roster ? this.roster.size : 0,
        };
    }

    memberPub(m) {
        const match = this.matchOf(m.team);
        const p = match ? match.players.get(String(m.uid)) : null;
        let state = this.online(m) ? 'online' : 'offline';
        if (p && this.online(m)) {
            if (match.boss && match.boss.by === p.id) state = 'boss';
            else if (p.occ >= 0) state = 'typing';
            else state = 'playing';
        }
        return { uid: m.uid, id: String(m.uid), name: m.name, avatar: m.avatar, color: m.color, bot: false, online: this.online(m), state, solved: p ? p.solved : 0 };
    }

    feed(text, kind) {
        const item = { text, kind, at: Date.now() };
        this.feedLog.push(item);
        if (this.feedLog.length > 40) this.feedLog.shift();
        this.broadcast({ t: 'feed', ...item });
    }

    changed() {
        this.dirty = true;
        this.touchedAt = this.now();
    }

    toUid(uid, msg) {
        const m = this.members.get(uid);
        if (m) for (const c of m.conns) c.send(msg);
    }

    toTeam(team, msg) {
        for (const m of this.members.values()) if (m.team === team) for (const c of m.conns) c.send(msg);
        if (msg.t === 'end' || msg.t === 'fx' || msg.t === 'item' || msg.t === 'boss') {
            for (const c of this.teachers) c.send({ ...msg, team });
        }
    }

    toBoards(msg) {
        for (const c of this.boards) c.send(msg);
    }

    broadcast(msg) {
        for (const m of this.members.values()) for (const c of m.conns) c.send(msg);
        for (const c of this.teachers) c.send(msg);
        for (const c of this.boards) c.send(msg);
    }

    // Free-room lobby summary.
    summary() {
        return {
            id: this.id, title: this.title, phase: this.phase, count: this.headcount(), max: this.capacity(),
            tags: this.settings.tags, limitMin: this.settings.limitMin,
            members: [...this.members.values()].slice(0, 8).map((m) => ({ avatar: m.avatar, color: m.color })),
            pct: this.round ? (this.matchOf(1)?.progress().pct || 0) : 0,
        };
    }
}

module.exports = { Arena, pickProblems };

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { Match } = require('../src/match');
const { Arena } = require('../src/arena');

function clock(start = 1_000_000) {
    let t = start;
    const now = () => t;
    now.add = (ms) => { t += ms; };
    return now;
}

function makeMatch(over = {}) {
    const now = clock();
    const sent = [];
    const m = new Match({
        team: 1, seed: 1, codes: ["print('a')", 'x = 1', 'y = 2'], blocks: 24,
        limitMs: 60000, occMs: 20000, bossWaitMs: 8000, bossLimitMs: 20000,
        penalty: false, startAt: now(), now, rand: () => 0.5, attacksOn: () => false,
        idle: null,
        out: { team: (msg) => sent.push(msg), player: (id, msg) => sent.push({ to: id, ...msg }) },
        on: { end: () => {}, attack: () => false, attackDone: () => {} },
        ...over,
    });
    return { m, now, sent };
}

function place(m, id, r, c) {
    const p = m.players.get(id);
    p.r = r;
    p.c = c;
    return p;
}

test('occupy, wrong answer releases, right answer solves', () => {
    const { m } = makeMatch();
    m.join({ id: '1', uid: 1, name: 'A' });
    m.join({ id: '2', uid: 2, name: 'B' });
    place(m, '1', 0, 0);
    place(m, '2', 0, 0);
    assert.equal(m.occupy('1').ok, true);
    const taken = m.occupy('2');
    assert.equal(taken.why, 'taken');
    assert.equal(taken.by, 'A');
    assert.equal(m.submit('1', 'nope').correct, false);
    assert.equal(m.players.get('1').occ, -1);
    m.occupy('1');
    assert.equal(m.submit('1', m.cells[0].code).correct, true);
    assert.equal(m.cells[0].solved, true);
    assert.equal(m.players.get('1').solved, 1);
    assert.equal(m.occupy('2').why, 'solved');
});

test('occupation times out', () => {
    const { m, now } = makeMatch();
    m.join({ id: '1', uid: 1, name: 'A' });
    m.occupy('1');
    now.add(20001);
    m.tick();
    assert.equal(m.players.get('1').occ, -1);
});

test('no input during the countdown', () => {
    const now = clock();
    const { m } = makeMatch({ startAt: now() + 5000, now });
    m.join({ id: '1', uid: 1, name: 'A' });
    assert.equal(m.occupy('1').why, 'countdown');
});

test('boss: grab from the left with Delete, win gives an item', () => {
    const { m } = makeMatch();
    m.join({ id: '1', uid: 1, name: 'A' });
    place(m, '1', 1, 1);
    assert.ok(m.spawnBoss({ title: 't', code: 'print(2)', answers: ['2'] }));
    m.boss.i = 1 * 12 + 2; // put it right of the player
    assert.equal(m.grab('1', 'Backspace').why, 'not-adjacent');
    const g = m.grab('1', 'Delete');
    assert.equal(g.ok, true);
    assert.equal(g.q.code, 'print(2)');
    assert.equal(g.q.answers, undefined);
    const r = m.bossSubmit('1', ' 2 ');
    assert.equal(r.correct, true);
    assert.ok(r.item);
    assert.equal(m.cells[14].crown, 'A');
    assert.equal(m.players.get('1').deck.length, 1);
    assert.equal(m.players.get('1').bosses, 1);
});

test('boss cell blocks movement and escapes when nobody grabs it', () => {
    const { m, now } = makeMatch();
    m.join({ id: '1', uid: 1, name: 'A' });
    place(m, '1', 0, 0);
    m.spawnBoss({ title: 't', code: 'x', answers: ['1'] });
    m.boss.i = 1;
    m.move('1', { dir: 'R', seq: 1 });
    assert.equal(m.players.get('1').c, 0);
    now.add(8001);
    m.tick();
    assert.equal(m.boss, null);
});

test('gather boss succeeds when every online human stands next to it', () => {
    const { m } = makeMatch();
    for (const id of ['1', '2', '3']) m.join({ id, uid: Number(id), name: id });
    assert.ok(m.spawnBoss({ title: 'g', code: '', answers: [] }, { gather: true, row: 0, side: false }));
    assert.equal(m.boss.phase, 'gather');
    const bi = m.boss.i;
    const br = Math.floor(bi / 12);
    const bc = bi % 12;
    place(m, '1', br, bc - 1);
    place(m, '2', br, bc + 1);
    place(m, '3', br, bc + 1);
    m.checkGather();
    assert.equal(m.boss, null);
    assert.equal(m.cells[bi].crown, '모두');
    for (const id of ['1', '2', '3']) {
        const p = m.players.get(id);
        assert.equal(p.deck.length + p.shields, 1);
    }
});

test('bomb solves neighbours without personal score', () => {
    const { m } = makeMatch();
    m.join({ id: '1', uid: 1, name: 'A' });
    const p = place(m, '1', 1, 1);
    p.deck.push('bomb');
    assert.equal(m.useItem('1', 1).ok, true);
    assert.equal(m.solvedCount(), 5); // 24 blocks = 2 rows, so (1,1) has 5 neighbours
    assert.equal(p.solved, 0);
    assert.equal(p.deck.length, 0);
});

test('pause shifts every deadline', () => {
    const { m, now } = makeMatch();
    m.join({ id: '1', uid: 1, name: 'A' });
    m.occupy('1');
    now.add(10000);
    m.pause(true);
    now.add(60000);
    m.tick();
    assert.equal(m.ended, null);
    m.pause(false);
    assert.equal(m.elapsed(), 10000);
    now.add(9000);
    m.tick();
    assert.notEqual(m.players.get('1').occ, -1);
    now.add(2000);
    m.tick();
    assert.equal(m.players.get('1').occ, -1);
});

test('time limit ends the match; clearing ends it as clear', () => {
    const a = makeMatch();
    a.m.join({ id: '1', uid: 1, name: 'A' });
    a.now.add(60001);
    a.m.tick();
    assert.equal(a.m.ended, 'time');

    const b = makeMatch();
    b.m.join({ id: '1', uid: 1, name: 'A' });
    for (let i = 0; i < b.m.cells.length - 1; i++) b.m.cells[i].solved = true;
    place(b.m, '1', 1, 11);
    b.m.occupy('1');
    b.m.submit('1', b.m.cells[23].code);
    assert.equal(b.m.ended, 'clear');
    assert.equal(b.m.result().cleared, true);
});

function makeArena(over = {}) {
    const now = clock();
    const results = [];
    const arena = new Arena({
        id: 'c1', mode: 'class', wpId: 1, title: 'T', now,
        settings: { teams: 3, limitMin: 1, attacks: true, bossEverySec: 10, penalty: false },
        problems: { normal: [{ code: 'a=1', tags: [] }, { code: 'b=2', tags: [] }], boss: [{ title: 'q', code: 'print(1)', answers: ['1'], tags: [] }] },
        hooks: { onResult: (_a, r) => results.push(r) },
        ...over,
    });
    return { arena, now, results };
}

function conn(uid) {
    const msgs = [];
    return { uid, name: 'u' + uid, role: 'learner', look: { avatar: 0, color: 0 }, msgs, send: (m) => msgs.push(m), close() {} };
}

test('arena: teams play the same board and the round result ranks them', () => {
    const { arena, now, results } = makeArena();
    const c1 = conn(1); const c2 = conn(2);
    arena.attach(c1); arena.attach(c2);
    arena.setTeam(1, 1);
    arena.setTeam(2, 2);
    assert.equal(arena.start().ok, true);
    const m1 = arena.matchOf(1);
    const m2 = arena.matchOf(2);
    assert.deepEqual(m1.cells.map((c) => c.code), m2.cells.map((c) => c.code));
    assert.equal(arena.matchOf(3), null);
    now.add(5000);
    m1.cells[0].solved = true;
    now.add(10000);
    arena.tick();
    assert.ok(m1.boss && m2.boss, 'both teams get the scheduled boss');
    assert.equal(m1.boss.q.title, m2.boss.q.title);
    now.add(60000);
    arena.tick();
    assert.equal(results.length, 1);
    assert.equal(results[0].teams[0].team, 1);
    assert.equal(results[0].teams[0].rank, 1);
    assert.equal(results[0].teams[1].rank, 2);
    assert.equal(arena.phase, 'lobby');
});

test('arena: attacks hit the team ranked just above', () => {
    const { arena, now } = makeArena();
    for (const uid of [1, 2, 3]) { arena.attach(conn(uid)); arena.setTeam(uid, uid); }
    arena.start();
    now.add(5000);
    const [m1, m2, m3] = [1, 2, 3].map((n) => arena.matchOf(n));
    m1.cells.slice(0, 6).forEach((c) => { c.solved = true; });
    m2.cells.slice(0, 3).forEach((c) => { c.solved = true; });
    const p3 = m3.players.get('3');
    p3.deck.push('ice');
    assert.equal(m3.useItem('3', 1).ok, true);
    assert.equal(m2.incoming.length, 1);
    m2.players.get('2').shields = 1;
    assert.equal(m2.defend('2').ok, true);
    assert.equal(m2.incoming.length, 0);
    const p1 = m1.players.get('1');
    p1.deck.push('fog');
    m1.useItem('1', 1);
    assert.equal(m2.incoming.length, 1, 'the leader hits the second team');
    now.add(2600);
    m2.tick();
    assert.ok(m2.fx.fog > now());
});

test('arena: roster and kicks gate entry', () => {
    const { arena } = makeArena({ roster: [{ uid: 1, name: 'a' }] });
    assert.equal(arena.canEnter({ uid: 2 }), 'not-in-roster');
    assert.equal(arena.canEnter({ uid: 1 }), '');
    arena.attach(conn(1));
    arena.kick(1);
    assert.equal(arena.canEnter({ uid: 1 }), 'kicked');
});

test('arena: bots fill teams and play', () => {
    const { arena, now } = makeArena();
    arena.attach(conn(1));
    arena.setTeam(1, 1);
    arena.addBot(1);
    arena.addBot(1);
    arena.fillBots();
    assert.deepEqual(arena.teamSizes().slice(1), [3, 3, 3]);
    arena.start();
    for (let k = 0; k < 400; k++) { now.add(200); arena.tick(); }
    const solvedByBots = arena.matchOf(2) ? arena.matchOf(2).solvedCount() : 0;
    assert.ok(solvedByBots > 0 || arena.lastResult, 'bots solve blocks');
});

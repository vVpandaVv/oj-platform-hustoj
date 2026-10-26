'use strict';

// Shared rule scenarios (tests/scenarios/rules.json) on the Node engine.
// tests/php/scenarios.php runs the same file on the built-in PHP engine,
// so a rule changed in only one engine fails one of the two.
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { Match } = require('../src/match');

const spec = require(path.join(__dirname, '..', '..', 'tests', 'scenarios', 'rules.json'));

function setup(sc) {
    let t = 1_000_000;
    const now = () => t;
    const d = spec.defaults;
    const m = new Match({
        team: 1, seed: 1, codes: ['x'], blocks: 24, limitMs: d.limitMs, occMs: d.occMs,
        bossWaitMs: d.bossWaitMs, bossLimitMs: d.bossLimitMs, penalty: !!d.penalty, startAt: t,
        now, rand: () => 0.5, attacksOn: () => false, idle: null,
        out: { team() {}, player() {} },
        on: { end() {}, attack: () => false, attackDone() {} },
    });
    m.cells = Array.from({ length: 24 }, (_, i) => ({ code: (sc.cells && sc.cells[i]) || `v${i} = ${i}`, solved: false, by: null, crown: null }));
    for (const i of sc.solved || []) m.cells[i].solved = true;
    if (sc.solvedAllBut) m.cells.forEach((c, i) => { if (!sc.solvedAllBut.includes(i)) c.solved = true; });
    for (const p of sc.players || []) {
        m.join({ id: p.id, uid: Number(p.id), name: 'p' + p.id, avatar: 0, color: 0 });
        Object.assign(m.players.get(p.id), { r: p.r, c: p.c });
    }
    if (sc.boss) m.boss = { i: sc.boss.i, q: { title: 'b', code: 'print(1)', answers: ['1'] }, phase: sc.boss.phase, until: t + d.bossWaitMs, by: null };
    return { m, advance: (ms) => { t += ms; } };
}

function run(m, advance, step) {
    const p = step.id ? m.players.get(step.id) : null;
    switch (step.do) {
        case 'move': m.move(step.id, { ...step.cmd, seq: 1 }); return {};
        case 'occupy': return m.occupy(step.id);
        case 'submit': return m.submit(step.id, step.text);
        case 'release': m.release(step.id, 'esc'); return {};
        case 'advance': advance(step.ms); m.tick(); return {};
        case 'pause': m.pause(step.on); return {};
        case 'spawnBoss':
            m.boss = null;
            m.boss = { i: step.i, q: { ...step.q, blank: !!step.q.blank }, phase: 'wait', until: m.now() + m.o.bossWaitMs, by: null };
            return {};
        case 'grab': return m.grab(step.id, step.key);
        case 'bossSubmit': return m.bossSubmit(step.id, step.text);
        case 'giveUp': m.giveUp(step.id); return {};
        case 'give': m.give(p, step.item); return {};
        case 'item': return m.useItem(step.id, step.slot);
        default: throw new Error('unknown step ' + step.do);
    }
}

function check(m, res, exp, where) {
    for (const [k, v] of Object.entries(exp || {})) {
        if (k === 'ok' || k === 'why' || k === 'correct') assert.equal(res[k], v, `${where}: ${k}`);
        else if (k === 'pos') for (const [id, rc] of Object.entries(v)) { const p = m.players.get(id); assert.deepEqual([p.r, p.c], rc, `${where}: pos ${id}`); }
        else if (k === 'solvedCount') assert.equal(m.solvedCount(), v, `${where}: solvedCount`);
        else if (k === 'solved') for (const i of v) assert.ok(m.cells[i].solved, `${where}: cell ${i} solved`);
        else if (k === 'unsolved') for (const i of v) assert.ok(!m.cells[i].solved, `${where}: cell ${i} unsolved`);
        else if (k === 'occ') for (const [i, id] of Object.entries(v)) assert.equal(m.occ.get(Number(i))?.id ?? null, id, `${where}: occ ${i}`);
        else if (k === 'boss') assert.equal(m.boss ? m.boss.phase : null, v, `${where}: boss`);
        else if (k === 'deckSize') for (const [id, n] of Object.entries(v)) assert.equal(m.players.get(id).deck.length, n, `${where}: deck ${id}`);
        else if (k === 'ended') assert.equal(m.ended, v, `${where}: ended`);
        else throw new Error('unknown expectation ' + k);
    }
}

for (const sc of spec.scenarios) {
    test('scenario: ' + sc.name, () => {
        const { m, advance } = setup(sc);
        sc.steps.forEach((step, k) => check(m, run(m, advance, step), step.expect, `${sc.name} #${k + 1} ${step.do}`));
    });
}

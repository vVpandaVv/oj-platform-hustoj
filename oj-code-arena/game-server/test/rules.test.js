'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const Moves = require('../../shared/arena-moves');
const Grade = require('../../shared/arena-grade');
const Bank = require('../../shared/arena-bank');
const sign = require('../src/sign');
const { sanitize } = require('../src/settings');
const { blocksFor, buildCells } = require('../src/board');

function grid(cols, rows, solved = [], blocked = []) {
    return { cols, rows, isSolved: (i) => solved.includes(i), isBlocked: (i) => blocked.includes(i) };
}

test('single steps stop at edges and blocked cells', () => {
    const g = grid(4, 2, [], [2]);
    assert.deepEqual(Moves.step(g, 0, 0, 'L'), { r: 0, c: 0 });
    assert.deepEqual(Moves.step(g, 0, 1, 'R'), { r: 0, c: 1 });
    assert.deepEqual(Moves.step(g, 0, 1, 'D'), { r: 1, c: 1 });
});

test('jump slides along unsolved runs and skips solved runs', () => {
    const g = grid(6, 1, [2, 3]);
    assert.deepEqual(Moves.step(g, 0, 0, 'R', true), { r: 0, c: 1 });
    assert.deepEqual(Moves.step(g, 0, 1, 'R', true), { r: 0, c: 4 });
    assert.deepEqual(Moves.step(g, 0, 4, 'R', true), { r: 0, c: 5 });
});

test('home/end and first/last avoid blocked cells', () => {
    const g = grid(4, 2, [], [0, 7]);
    assert.deepEqual(Moves.edge(g, 0, 2, 'home'), { r: 0, c: 1 });
    assert.deepEqual(Moves.edge(g, 0, 1, 'end'), { r: 0, c: 3 });
    assert.deepEqual(Moves.edge(g, 1, 1, 'first'), { r: 0, c: 1 });
    assert.deepEqual(Moves.edge(g, 0, 1, 'last'), { r: 1, c: 2 });
});

test('mirror flips arrow direction', () => {
    const g = grid(3, 1);
    assert.deepEqual(Moves.move(g, 0, 1, { dir: 'L' }, true), { r: 0, c: 2 });
});

test('code grading ignores spaces outside strings and quote style', () => {
    assert.ok(Grade.sameCode("print( 'a' ,'b' )", 'print("a","b")'));
    assert.ok(!Grade.sameCode("print('a b')", "print('ab')"));
    assert.ok(!Grade.sameCode("print('x)", "print('x')"));
    assert.ok(Grade.sameCode("print(\"It's\")", "print('It\\'s')"));
    assert.ok(!Grade.sameCode('x=1', 'x=2'));
});

test('boss grading: output ignores whitespace, blank uses code rules', () => {
    assert.ok(Grade.check({ code: 'print(1)', answers: ['3 2 1'] }, '321'));
    assert.ok(!Grade.check({ code: 'print(1)', answers: ['3 2 1'] }, ''));
    assert.ok(Grade.check({ code: 'for i in ___:', answers: ['range(1, 6)'], blank: true }, 'range(1,6)'));
    assert.ok(!Grade.check({ code: 'for i in ___:', answers: ['range(1, 6)'], blank: true }, 'range(1,7)'));
});

test('bank parser reads normal and boss problems and reports errors', () => {
    const { items, errors } = Bank.parse([
        '@일반 출력', "print('a')", '안녕', '',
        '@보스 합 | 반복문', 's = 0', 'print(s)', '<- 5', '-> 0 || 0.0',
        '@보스 빈칸', 'x = ___', '-> 1',
        '@보스 정답 없음', 'print(1)',
    ].join('\n'));
    assert.equal(items.length, 3);
    assert.deepEqual(items[1].answers, ['0', '0.0']);
    assert.equal(items[1].input, '5');
    assert.equal(items[2].blank, true);
    assert.equal(errors.length, 2);
    const again = Bank.parse(Bank.serialize(items));
    assert.equal(again.items.length, 3);
    assert.equal(again.errors.length, 0);
});

test('tickets: valid, tampered and expired', () => {
    const secret = 'x'.repeat(40);
    const t = sign.issueTicket(secret, { v: 1, uid: 7, role: 'learner', exp: 2000 });
    assert.equal(sign.readTicket(secret, t, 1000).uid, 7);
    assert.equal(sign.readTicket(secret, t, 3000), null);
    assert.equal(sign.readTicket('y'.repeat(40), t, 1000), null);
    assert.equal(sign.readTicket(secret, t.replace(/^./, 'A'), 1000), null);
});

test('request signatures check time skew', () => {
    const secret = 'z'.repeat(40);
    const { time, signature } = sign.signRequest(secret, 'POST', '/api/sessions', '{"a":1}', 1700000000);
    assert.ok(sign.verifyRequest(secret, 'POST', '/api/sessions', time, signature, '{"a":1}', 1700000100));
    assert.ok(!sign.verifyRequest(secret, 'POST', '/api/sessions', time, signature, '{"a":2}', 1700000100));
    assert.ok(!sign.verifyRequest(secret, 'POST', '/api/sessions', time, signature, '{"a":1}', 1700009999));
    // bound to the call it was made for
    assert.ok(!sign.verifyRequest(secret, 'POST', '/api/sessions/close', time, signature, '{"a":1}', 1700000100));
    assert.ok(!sign.verifyRequest(secret, 'GET', '/api/sessions', time, signature, '{"a":1}', 1700000100));
});

test('settings are clamped', () => {
    const s = sanitize({ teams: 99, limitMin: 0, blocks: 50, attacks: true }, 'class');
    assert.equal(s.teams, 8);
    assert.equal(s.limitMin, 1);
    assert.equal(s.blocks, 48);
    assert.equal(s.attacks, true);
    assert.equal(sanitize({ attacks: true }, 'free').attacks, false);
});

test('boards: auto size and identical layout per seed', () => {
    assert.equal(blocksFor(0, 1), 24);
    assert.equal(blocksFor(0, 9), 96);
    assert.equal(blocksFor(60, 3), 60);
    const codes = ['a=1', 'b=2', 'c=3'];
    assert.deepEqual(buildCells(5, codes, 24), buildCells(5, codes, 24));
    assert.equal(buildCells(5, codes, 24).length, 24);
});

test('room tags cannot carry HTML (stored XSS through settings)', () => {
    const s = sanitize({ tags: ['<img src=x', 'onerror=alert(1)>', '"q"', '반복문'] }, 'free');
    assert.ok(s.tags.every((t) => !/[<>"'`&]/.test(t)));
    assert.ok(s.tags.includes('반복문'));
});

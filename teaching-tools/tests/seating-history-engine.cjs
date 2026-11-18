const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const scope = { window: {} };
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../resources/js/seating-engine.js'), 'utf8'), scope);
const engine = scope.window.HttSeatingEngine;
const students = Array.from({ length: 31 }, (_, i) => 'S' + (i + 1));
const studentKeys = Object.fromEntries(students.map((name, i) => [name, 'u:' + (i + 1)]));
const positions = result => result.seats.filter(s => s.student).map(s => ({ id: studentKeys[s.student], row: s.row, col: s.col }));
const cases = [];
const trials = Number(process.env.SEATING_HISTORY_TRIALS || 200);
assert(Number.isInteger(trials) && trials >= 20 && trials <= 2000);
for (const [rows, count] of [[5, 30], [6, 30], [6, 31]]) {
    const history = [];
    let latestSeat = 0, latestMate = 0, recentSeat = 0, recentMate = 0;
    const start = performance.now();
    for (let round = 0; round < trials; round++) {
        const result = engine.arrange({ rows, cols: 6, students: students.slice(0, count), frontNames: students.slice(0, 10), studentKeys, history });
        assert(!result.error, result.error);
        assert.equal(result.unseated.length, 0);
        assert.equal(result.evaluation.hard.length, 0);
        for (const seat of result.seats) if (students.slice(0, 10).includes(seat.student)) assert(seat.row < 2);
        const report = result.evaluation.repetition;
        latestSeat += report.latestSeat; latestMate += report.latestMate;
        recentSeat += report.recentSeat; recentMate += report.recentMate;
        history.unshift({ positions: positions(result) }); history.splice(5);
    }
    assert.equal(latestSeat, 0, 'avoidable consecutive seat repeats');
    assert.equal(latestMate, 0, 'avoidable consecutive desk-mate repeats');
    let previous = null, baselineSeats = 0, baselineMates = 0;
    for (let round = 0; round < trials; round++) {
        const draw = engine.arrange({ rows, cols: 6, students: students.slice(0, count), frontNames: students.slice(0, 10) });
        const grid = new Map(draw.seats.filter(s => s.student).map(s => [s.id, s.student]));
        const pairs = new Set(draw.seats.filter(s => s.student && s.col % 2 === 0).flatMap(s => {
            const mate = grid.get(s.row + '-' + (s.col + 1));
            return mate ? [[s.student, mate].sort().join(':')] : [];
        }));
        if (previous) {
            for (const [id, name] of grid) if (previous.grid.get(id) === name) baselineSeats++;
            for (const pair of pairs) if (previous.pairs.has(pair)) baselineMates++;
        }
        previous = { grid, pairs };
    }
    cases.push({ rows, count, front: 10, trials, latestSeat, latestMate, recentSeat, recentMate,
        memorylessLatestSeat: baselineSeats, memorylessLatestMate: baselineMates,
        milliseconds: Math.round(performance.now() - start) });
}
assert(engine.arrange({ rows: 6, cols: 6, students, frontNames: students.slice(0, 13) }).error);
assert(engine.arrange({ rows: 5, cols: 6, students }).error);
assert(engine.arrange({ rows: 6, cols: 6, students, frontNames: students.slice(0, 10), disabledSeatIds: ['0-0', '0-1', '0-2'] }).error);
const first = engine.arrange({ rows: 6, cols: 6, students, studentKeys });
const history = [{ positions: positions(first) }];
const renamed = students.map((name, i) => 'Renamed' + (i + 1));
const result = engine.arrange({ rows: 6, cols: 6, students: renamed, studentKeys: Object.fromEntries(renamed.map((name, i) => [name, 'u:' + (i + 1)])), history, frontNames: renamed.slice(0, 10), apartPairs: [[renamed[0], renamed[1]]], buddyPairs: [[renamed[2], renamed[3]]], diagonal: true, apartGap: 2 });
assert.equal(result.evaluation.hard.length, 0);
assert.equal(result.evaluation.soft.length, 0);
assert.equal(result.evaluation.repetition.rounds, 1);
const locked = engine.arrange({ rows: 6, cols: 6, students, studentKeys, history, lockedAssignments: { [first.seats.find(s => s.student === students[0]).id]: students[0] } });
assert.equal(locked.evaluation.repetition.latestSeat, 1, 'fixed seat wins over history');
assert.equal(locked.evaluation.hard.length, 0);
const crowded = engine.arrange({ rows: 6, cols: 6, students, frontNames: students.slice(0, 10),
    buddyPairs: [[students[0], students[10]], [students[1], students[11]], [students[2], students[12]]] });
assert.equal(crowded.unseated.length, 0, 'Incompatible buddy requests must not consume other priority students front capacity');
assert.equal(crowded.evaluation.hard.length, 0);
assert(crowded.evaluation.soft.length > 0, 'An incompatible buddy request must be reported');
const pair = first.seats.filter(s => s.row === 0 && (s.col === 0 || s.col === 1)).map(s => s.student).filter(Boolean);
if (pair.length === 2) {
    const explicit = engine.arrange({ rows: 6, cols: 6, students, studentKeys, history, buddyPairs: [pair] });
    assert.equal(explicit.evaluation.soft.length, 0);
    assert(explicit.evaluation.repetition.latestMate >= 1, 'Requested buddy must win over repeat preference');
}
const report = { cases, checks: 'front capacity, identities, required constraints, fixed seats', historyLimit: 5,
    sourceSha256: require('node:crypto').createHash('sha256').update(fs.readFileSync(require('node:path').join(__dirname, '../resources/js/seating-engine.js'))).digest('hex'),
    limitations: 'Synthetic rosters and native Math.random. No production database. Memoryless control uses the same fixed two-row front zone to isolate the effect of history. Repetition avoidance is a bounded heuristic, not a guarantee for every constraint set.' };
const directory = require('node:path').resolve(__dirname, '../../_notes/verification/2026-11-16/teaching-tools');
fs.mkdirSync(directory, { recursive: true });
fs.writeFileSync(require('node:path').join(directory, 'seating-history-engine.json'), JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify(report, null, 2));

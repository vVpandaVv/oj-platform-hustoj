// Diagnostic probe: run the shipped solver unchanged and measure repeated seats/desk-mates.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');

const sourcePath = path.join(__dirname, '../resources/js/seating-engine.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const context = { window: {} };
vm.runInNewContext(source, context);
const engine = context.window.HttSeatingEngine;
const classroom = process.argv.includes('--classroom');
const trials = Number(process.env.SEATING_DISTRIBUTION_TRIALS || 2000);
assert(Number.isInteger(trials) && trials >= 20 && trials <= 20000);
const students = Array.from({ length: 30 }, (_, index) => 'S' + String(index + 1).padStart(2, '0'));
const base = { rows: 5, cols: 6, students, apartGap: 2, diagonal: true };
const percent = (count, total) => Number((count * 100 / total).toFixed(2));

function deskMates(seats) {
    const byDesk = new Map();
    const mates = new Map();
    for (const seat of seats) {
        if (!seat.student) continue;
        const desk = seat.row + '-' + Math.floor(seat.col / 2);
        if (!byDesk.has(desk)) byDesk.set(desk, []);
        byDesk.get(desk).push(seat.student);
    }
    for (const pair of byDesk.values()) {
        if (pair.length === 2) { mates.set(pair[0], pair[1]); mates.set(pair[1], pair[0]); }
    }
    return mates;
}

function measure(name, overrides) {
    const options = { ...base, ...overrides };
    const scenarioStudents = options.students;
    const occupancy = Object.fromEntries(scenarioStudents.map((student) => [student, new Map()]));
    const tracked = Object.fromEntries(['S01', 'S02', 'S15'].map((student) => [student, { corner: 0, front: 0, sameSeat: 0, sameMate: 0, priorTenMate: 0, paired: 0, previousPaired: 0 }]));
    let previous = null, previousMates = null;
    const history = [];
    let sameSeats = 0, sameRow = 0, nearby = 0, repeatedPairs = 0, anyPairRepeat = 0, hardFailures = 0, softFailures = 0;
    let priorTenTotal = 0;
    const started = Date.now();
    for (let round = 0; round < trials; round++) {
        const result = engine.arrange(options);
        assert(!result.error && !result.unseated.length);
        hardFailures += result.evaluation.hard.length > 0 ? 1 : 0;
        softFailures += result.evaluation.soft.length > 0 ? 1 : 0;
        const positions = new Map(result.seats.filter((seat) => seat.student).map((seat) => [seat.student, seat]));
        const mates = deskMates(result.seats);
        let repeatedStudents = 0;
        for (const student of scenarioStudents) {
            const seat = positions.get(student), mate = mates.get(student);
            const histogram = occupancy[student];
            histogram.set(seat.id, (histogram.get(seat.id) || 0) + 1);
            const metric = tracked[student];
            if (metric) {
                if (mate) metric.paired++;
                if ((seat.row === 0 || seat.row === options.rows - 1) && (seat.col === 0 || seat.col === options.cols - 1)) metric.corner++;
                if (seat.row < 2) metric.front++;
            }
            if (previous) {
                const old = previous.get(student);
                if (metric && previousMates.has(student)) metric.previousPaired++;
                if (old.id === seat.id) { sameSeats++; if (metric) metric.sameSeat++; }
                if (old.row === seat.row) sameRow++;
                if (Math.max(Math.abs(old.row - seat.row), Math.abs(old.col - seat.col)) <= 1) nearby++;
                if (mate && previousMates.get(student) === mate) { repeatedStudents++; if (metric) metric.sameMate++; }
            }
            if (history.length === 10 && mate && metric && history.some((past) => past.get(student) === mate)) metric.priorTenMate++;
        }
        if (previous) {
            repeatedPairs += repeatedStudents / 2;
            if (repeatedStudents) anyPairRepeat++;
        }
        if (history.length === 10) priorTenTotal++;
        history.push(mates); if (history.length > 10) history.shift();
        previous = positions; previousMates = mates;
    }
    const trackedResult = {};
    for (const [student, counts] of Object.entries(tracked)) {
        const histogram = [...occupancy[student]].sort((a, b) => b[1] - a[1]);
        const frontZoneEnds = histogram.filter(([id]) => { const [r, c] = id.split('-').map(Number); return r < 2 && (c === 0 || c === options.cols - 1); }).reduce((sum, entry) => sum + entry[1], 0);
        trackedResult[student] = {
            distinctSeats: histogram.length,
            frontZoneEndPercent: percent(frontZoneEnds, trials),
            frontTwoRowsPercent: percent(counts.front, trials),
            sameSeatAsPreviousPercent: percent(counts.sameSeat, trials - 1),
            sameMateAsPreviousPercent: percent(counts.sameMate, trials - 1),
            sameMateGivenPreviousMatePercent: percent(counts.sameMate, counts.previousPaired),
            noMatePercent: percent(trials - counts.paired, trials),
            mateSeenInLastTenPercent: percent(counts.priorTenMate, priorTenTotal),
            seatHistogram: Object.fromEntries(histogram),
        };
    }
    return {
        scenario: name, rows: options.rows, cols: options.cols, students: scenarioStudents.length, frontStudents: (options.frontNames || []).length,
        trials, durationMs: Date.now() - started,
        hardFailureLayouts: hardFailures, softFailureLayouts: softFailures,
        meanUnchangedSeatsPerNewLayout: Number((sameSeats / (trials - 1)).toFixed(3)),
        sameRowPercent: percent(sameRow, (trials - 1) * scenarioStudents.length),
        sameOrNeighborSeatPercent: percent(nearby, (trials - 1) * scenarioStudents.length),
        meanRepeatedDeskPairsPerNewLayout: Number((repeatedPairs / (trials - 1)).toFixed(3)),
        layoutsWithAtLeastOneRepeatedPairPercent: percent(anyPairRepeat, trials - 1),
        tracked: trackedResult,
    };
}

const standardScenarios = [
    ['unconstrained', {}],
    ['apart_pair', { apartPairs: [['S01', 'S02']] }],
    ['front_apart_pair', { frontNames: ['S01', 'S02'], apartPairs: [['S01', 'S02']] }],
    ['six_front_only', { frontNames: students.slice(0, 6) }],
    ['six_front_three_apart_pairs', { frontNames: students.slice(0, 6), apartPairs: [['S01', 'S02'], ['S03', 'S04'], ['S05', 'S06']] }],
    ['fixed_buddy_pair', { buddyPairs: [['S01', 'S02']] }],
];
const classroomScenarios = [];
for (const [rows, count] of [[6, 30], [6, 31], [5, 30]]) {
    const roster = Array.from({ length: count }, (_, index) => 'S' + String(index + 1).padStart(2, '0'));
    for (const front of [0, 2, 6]) {
        classroomScenarios.push([`${rows}x6_${count}_front${front}`, { rows, students: roster, frontNames: roster.slice(0, front) }]);
    }
}
const scenarios = classroom ? classroomScenarios : standardScenarios;
const results = [];
for (const [name, overrides] of scenarios) {
    const result = measure(name, overrides); results.push(result);
    assert.equal(result.hardFailureLayouts, 0, 'Diagnostic scenarios must be feasible');
    console.log(JSON.stringify({ scenario: result.scenario, meanUnchangedSeats: result.meanUnchangedSeatsPerNewLayout,
        repeatPairLayoutsPercent: result.layoutsWithAtLeastOneRepeatedPairPercent, S01: result.tracked.S01 }));
}

// Count all legal ordered front-zone placements versus the maximum-distance subset.
const frontSeats = Array.from({ length: 12 }, (_, i) => ({ row: Math.floor(i / 6), col: i % 6 }));
const legal = [];
for (const a of frontSeats) for (const b of frontSeats) {
    const dr = Math.abs(a.row - b.row), dc = Math.abs(a.col - b.col);
    if (Math.max(dr, dc) >= 3) legal.push({ a, b, distance: dr * dr + dc * dc });
}
const greatest = Math.max(...legal.map((item) => item.distance));
const maximal = legal.filter((item) => item.distance === greatest);

const report = {
    source: path.relative(path.resolve(__dirname, '../..'), sourcePath), sourceSha256: crypto.createHash('sha256').update(source).digest('hex'),
    sample: { preset: classroom ? 'User-described 5x6/6x6, 30/31 students, front-only; front counts not yet confirmed' : 'Full 5x6 reference',
        trialsPerScenario: trials, random: 'Native Math.random; no seeded override' },
    baselineTheory: { reference: 'Full 5x6 with 30 students and no conditions', meanSameSeatStudents: 1, sameSeatPercent: 100 / 30, sameRowPercent: 20, sameOrNeighborSeatPercent: 100 * 208 / 900,
        sameMatePercent: 100 / 29, meanRepeatedDeskPairs: 15 / 29, mateSeenInLastTenPercent: 100 * (1 - (28 / 29) ** 10) },
    frontPairChoices: { legalOrderedPlacements: legal.length, maximalDistanceOrderedPlacements: maximal.length, maximalDistanceSquared: greatest, placements: maximal },
    results,
    capacityWarnings: classroom ? ['5x6 has 30 seats; 31 students cannot all be seated. That over-capacity combination is not sampled.'] : [],
    limitations: 'Synthetic rosters; no production database. The classroom preset uses front counts 0/2/6 as sensitivity cases, not confirmed actual settings. No disabled or locked seats. Empty neighbors are not counted as repeated desk-mates. Production arrange() receives no history.'
};
const directory = path.resolve(__dirname, '../../_notes/verification/2026-11-15/teaching-tools');
const filename = classroom ? 'seating-classroom-distribution.json' : 'seating-distribution.json';
fs.mkdirSync(directory, { recursive: true });
fs.writeFileSync(path.join(directory, filename), JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify({ frontPairChoices: report.frontPairChoices, report: path.join(directory, filename) }));

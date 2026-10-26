'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const pack = JSON.parse(fs.readFileSync(path.join(__dirname, '../docs/content-review/seed-cases.json'), 'utf8'));
const gameKinds = {
    extract: 'data-extraction', impute: 'missing-values', outlier: 'outlier-detection',
    visualize: 'data-visualization', structure: 'data-structure-lab', classify: 'ai-classifier-lab',
    'model-split': 'ai-model-lab', 'model-metrics': 'ai-model-lab',
};
const number = (value) => { assert.equal(typeof value, 'number'); assert.ok(Number.isFinite(value)); return value; };
const numeric = (values) => { assert.ok(values.length); return values.map(number); };
const sum = (values) => numeric(values).reduce((a, b) => a + b, 0);
const mean = (values) => sum(values) / values.length;
const compare = (a, b) => a < b ? -1 : a > b ? 1 : 0;
const median = (values) => {
    const sorted = numeric(values).sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
};
const field = (row, key) => { assert.ok(Object.hasOwn(row, key), `Missing field: ${key}`); return row[key]; };
const unique = (values) => assert.equal(new Set(values).size, values.length, 'Duplicate identifier');

function equal(actual, expected, location = 'answer') {
    if (typeof expected === 'number') {
        assert.ok(Math.abs(number(actual) - expected) < 1e-8, `${location}: ${actual} != ${expected}`);
    } else if (Array.isArray(expected)) {
        assert.ok(Array.isArray(actual), location);
        assert.equal(actual.length, expected.length, location);
        expected.forEach((value, index) => equal(actual[index], value, `${location}[${index}]`));
    } else if (expected && typeof expected === 'object') {
        assert.ok(actual && typeof actual === 'object' && !Array.isArray(actual), location);
        assert.deepEqual(Object.keys(actual).sort(), Object.keys(expected).sort(), location);
        Object.keys(expected).forEach((key) => equal(actual[key], expected[key], `${location}.${key}`));
    } else {
        assert.equal(actual, expected, location);
    }
}

function matches(row, rule) {
    if (rule.all) return rule.all.every((child) => matches(row, child));
    const value = field(row, rule.field);
    if (rule.op === 'notNull') return value !== null;
    if (rule.op === 'eq') return value === rule.value;
    if (value === null) return false;
    number(value); number(rule.value);
    switch (rule.op) {
        case 'lt': return value < rule.value;
        case 'lte': return value <= rule.value;
        case 'gt': return value > rule.value;
        case 'gte': return value >= rule.value;
        default: throw new Error(`Unsupported filter: ${rule.op}`);
    }
}

function extract(data, policy) {
    unique(data.rows.map((row) => field(row, 'id')));
    unique(policy.columns);
    let rows = data.rows.map((row) => ({ ...row }));
    if (policy.derive) {
        const d = policy.derive;
        rows.forEach((row) => {
            const denominator = number(field(row, d.denominator));
            assert.notEqual(denominator, 0, 'Undefined ratio');
            row[d.field] = number(field(row, d.numerator)) / denominator;
        });
    }
    if (policy.filter) rows = rows.filter((row) => matches(row, policy.filter));
    if (policy.groupBy) {
        const groups = new Map();
        rows.forEach((row) => {
            const key = field(row, policy.groupBy);
            groups.set(key, (groups.get(key) || 0) + number(field(row, policy.sum)));
        });
        rows = Array.from(groups, ([key, value]) => ({ [policy.groupBy]: key, [policy.resultField]: value }));
    }
    if (policy.sort) {
        policy.sort.forEach((key) => assert.ok(['asc', 'desc'].includes(key.direction)));
        rows.sort((a, b) => {
            for (const key of policy.sort) {
                const order = compare(field(a, key.field), field(b, key.field));
                if (order) return order * (key.direction === 'asc' ? 1 : -1);
            }
            return 0;
        });
    }
    const projected = rows.map((row) => Object.fromEntries(policy.columns.map((key) => [key, field(row, key)])));
    return policy.groupBy ? { rows: projected } : { rowIds: rows.map((row) => row.id), rows: projected };
}

function impute(data, policy) {
    const observed = data.values.filter((value) => value !== null);
    const replacements = {};
    assert.ok(data.values.includes(null));
    if (policy.method === 'interpolate') {
        assert.equal(data.times.length, data.values.length);
        numeric(data.times).forEach((time, index) => assert.ok(index === 0 || time > data.times[index - 1]));
    }
    data.values.forEach((value, index) => {
        if (value !== null) return;
        let answer;
        switch (policy.method) {
            case 'mean': answer = mean(observed); break;
            case 'median': answer = median(observed); break;
            case 'mode': {
                const counts = new Map();
                observed.forEach((item) => counts.set(item, (counts.get(item) || 0) + 1));
                const ranked = [...counts].sort((a, b) => b[1] - a[1]);
                assert.ok(ranked.length && (ranked.length === 1 || ranked[0][1] > ranked[1][1]), 'Ambiguous mode');
                answer = ranked[0][0]; break;
            }
            case 'previous': {
                let previous = index - 1;
                while (previous >= 0 && data.values[previous] === null) previous--;
                assert.ok(previous >= 0, 'No previous observation');
                answer = data.values[previous]; break;
            }
            case 'interpolate': {
                let left = index - 1;
                let right = index + 1;
                while (left >= 0 && data.values[left] === null) left--;
                while (right < data.values.length && data.values[right] === null) right++;
                assert.ok(left >= 0 && right < data.values.length, 'Interpolation requires both endpoints');
                const fraction = (data.times[index] - data.times[left]) / (data.times[right] - data.times[left]);
                answer = number(data.values[left]) + fraction * (number(data.values[right]) - data.values[left]);
                break;
            }
            case 'leave': answer = null; break;
            default: throw new Error(`Unsupported imputation: ${policy.method}`);
        }
        if (typeof answer === 'number' && policy.precision !== undefined) {
            assert.ok(Number.isInteger(policy.precision) && policy.precision >= 0 && policy.precision <= 6);
            answer = Number(answer.toFixed(policy.precision));
        }
        replacements[index] = answer;
    });
    return { replacements };
}

function outlier(data, policy) {
    if (policy.method === 'group-range') {
        unique(data.rows.map((row) => row.id));
        const rowIds = data.rows.filter((row) => {
            const [min, max] = field(policy.ranges, row.group);
            assert.ok(number(min) <= number(max));
            return number(row.value) < min || row.value > max;
        }).map((row) => row.id);
        return { rowIds, action: rowIds.length ? 'review' : 'keep' };
    }
    const values = numeric(data.values);
    let lower, upper;
    const bounds = {};
    if (policy.method === 'range') {
        lower = number(policy.min); upper = number(policy.max);
        assert.ok(lower <= upper);
    } else if (policy.method === 'iqr-halves') {
        assert.ok(values.length >= 4);
        const sorted = [...values].sort((a, b) => a - b);
        const half = Math.floor(sorted.length / 2);
        // Odd-sized samples exclude the overall median from both halves.
        bounds.q1 = median(sorted.slice(0, half));
        bounds.q3 = median(sorted.slice(-half));
        assert.ok(number(policy.factor) > 0);
        const spread = (bounds.q3 - bounds.q1) * policy.factor;
        lower = bounds.lower = bounds.q1 - spread;
        upper = bounds.upper = bounds.q3 + spread;
    } else throw new Error(`Unsupported outlier policy: ${policy.method}`);
    const indexes = values.flatMap((value, index) => value < lower || value > upper ? [index] : []);
    return { indexes, ...bounds, action: indexes.length ? 'review' : 'keep' };
}

function visualize(data, policy) {
    const chartTypes = ['line', 'bar', 'pie', 'stacked100', 'histogram', 'scatter'];
    assert.ok(policy.acceptedCharts.length);
    unique(policy.acceptedCharts);
    policy.acceptedCharts.forEach((chart) => assert.ok(chartTypes.includes(chart)));
    switch (policy.task) {
        case 'max-increase': {
            assert.equal(data.x.length, data.y.length);
            numeric(data.x).forEach((value, index) => assert.ok(index === 0 || value > data.x[index - 1]));
            numeric(data.y);
            const differences = data.y.slice(1).map((value, index) => value - data.y[index]);
            const largest = Math.max(...differences);
            assert.equal(differences.filter((value) => value === largest).length, 1, 'Specify a tie policy');
            const at = differences.indexOf(largest);
            return { interval: [data.x[at], data.x[at + 1]], increase: largest };
        }
        case 'share': {
            unique(data.labels);
            assert.equal(data.labels.length, data.values.length);
            numeric(data.values).forEach((value) => assert.ok(value >= 0));
            assert.equal(sum(data.values), number(data.total));
            assert.ok(data.total > 0 && data.labels.includes(policy.category));
            return { share: data.values[data.labels.indexOf(policy.category)] / data.total };
        }
        case 'histogram': {
            assert.ok(data.edges.length >= 2);
            numeric(data.edges).forEach((edge, index) => assert.ok(index === 0 || edge > data.edges[index - 1]));
            const counts = data.edges.slice(1).map(() => 0);
            numeric(data.values).forEach((value) => {
                const indexes = counts.flatMap((_, index) => value >= data.edges[index] &&
                    (value < data.edges[index + 1] || index === counts.length - 1 && value === data.edges[index + 1]) ? [index] : []);
                assert.equal(indexes.length, 1, 'Observation outside bins or counted twice');
                counts[indexes[0]]++;
            });
            assert.equal(sum(counts), data.values.length);
            return { counts };
        }
        case 'association': {
            assert.equal(data.design, 'observational');
            data.points.forEach((point) => { assert.equal(point.length, 2); numeric(point); });
            const mx = mean(data.points.map((point) => point[0]));
            const my = mean(data.points.map((point) => point[1]));
            const covariance = sum(data.points.map(([x, y]) => (x - mx) * (y - my)));
            return { direction: covariance > 0 ? 'positive' : covariance < 0 ? 'negative' : 'none', causalConclusion: false };
        }
        default: throw new Error(`Unsupported visualization: ${policy.task}`);
    }
}

// A small, offline trace oracle for authored cases, not the production game engine.
function structure(data, policy) {
    assert.equal(policy.emptyPolicy, 'error-and-continue');
    assert.ok(['stack', 'queue', 'priority-queue'].includes(data.structure));
    const queue = data.structure === 'queue';
    const priority = data.structure === 'priority-queue';
    if (priority) assert.equal(policy.tieBreak, 'fifo');
    const entries = [], outputs = [], states = [], errorSteps = [];
    data.operations.forEach((operation, index) => {
        const insert = queue ? 'enqueue' : 'push';
        const remove = queue ? 'dequeue' : 'pop';
        const peek = queue ? 'front' : 'peek';
        if (operation.op === insert) {
            assert.equal(typeof operation.value, 'string');
            if (priority) number(operation.priority);
            entries.push({ ...operation, insertedAt: index });
            if (priority) entries.sort((a, b) => b.priority - a.priority || a.insertedAt - b.insertedAt);
        } else if (operation.op === remove || operation.op === peek) {
            if (!entries.length) errorSteps.push(index);
            else {
                const at = queue || priority ? 0 : entries.length - 1;
                outputs.push(entries[at].value);
                if (operation.op === remove) entries.splice(at, 1);
            }
        } else throw new Error(`Unsupported structure operation: ${operation.op}`);
        states.push(entries.map((entry) => entry.value));
    });
    return { outputs, final: entries.map((entry) => entry.value), errorSteps, states };
}

function classify(data, policy) {
    [...data.training, ...data.queries].forEach((point) => { number(point.x); number(point.y); });
    data.training.forEach((point) => assert.ok(['A', 'B'].includes(point.label)));
    if (policy.method === 'threshold') {
        assert.ok(['x', 'y'].includes(policy.axis));
        number(policy.threshold);
        assert.ok(['gte', 'lte'].includes(policy.comparison));
        const predict = (point) => (policy.comparison === 'gte' ? point[policy.axis] >= policy.threshold : point[policy.axis] <= policy.threshold) ? 'B' : 'A';
        data.training.forEach((point) => assert.equal(predict(point), point.label, 'Inconsistent training label'));
        return { labels: data.queries.map(predict) };
    }
    assert.equal(policy.method, 'knn');
    assert.equal(policy.distance, 'squared-euclidean');
    assert.equal(policy.distanceTieBreak, 'id-ascending');
    unique(data.training.map((point) => field(point, 'id')));
    assert.ok(Number.isInteger(policy.k) && policy.k > 0 && policy.k <= data.training.length && policy.k % 2 === 1);
    const neighbors = data.queries.map((query) => data.training.map((point) => ({
        ...point, distance: (point.x - query.x) ** 2 + (point.y - query.y) ** 2,
    })).sort((a, b) => a.distance - b.distance || compare(a.id, b.id)).slice(0, policy.k));
    return {
        labels: neighbors.map((points) => points.filter((point) => point.label === 'A').length > policy.k / 2 ? 'A' : 'B'),
        neighbors: neighbors.map((points) => points.map((point) => point.id)),
    };
}

function modelSplit(data, policy) {
    assert.equal(policy.method, 'train-mean');
    unique([...data.train, ...data.validation, ...data.test].map((row) => field(row, 'id')));
    const average = mean(data.train.filter((row) => row.value !== null).map((row) => row.value));
    return { mean: average, trainValues: data.train.map((row) => row.value === null ? average : number(row.value)) };
}

function modelMetrics(data, policy) {
    assert.equal(policy.priority, 'recall');
    assert.equal(policy.positiveClass, '불량');
    assert.equal(policy.zeroDivision, 0);
    assert.ok(number(policy.maxFalsePositives) >= 0);
    unique(data.models.map((model) => model.id));
    const metrics = {};
    const candidates = [];
    let classTotals;
    data.models.forEach(({ id, matrix }) => {
        assert.equal(matrix.length, 2);
        matrix.forEach((row) => { assert.equal(row.length, 2); row.forEach((value) => assert.ok(Number.isInteger(value) && value >= 0)); });
        const [[tn, fp], [fn, tp]] = matrix;
        if (classTotals) assert.deepEqual([tn + fp, fn + tp], classTotals, 'Different evaluation populations');
        else classTotals = [tn + fp, fn + tp];
        const total = tn + fp + fn + tp;
        assert.ok(total > 0 && tp + fn > 0);
        const precision = tp + fp ? tp / (tp + fp) : 0;
        const recall = tp / (tp + fn);
        metrics[id] = { accuracy: (tn + tp) / total, precision, recall, f1: precision + recall ? 2 * precision * recall / (precision + recall) : 0 };
        if (fp <= policy.maxFalsePositives) candidates.push({ id, recall });
    });
    candidates.sort((a, b) => b.recall - a.recall);
    assert.ok(candidates.length && (candidates.length === 1 || candidates[0].recall > candidates[1].recall), 'No unique model selection');
    return { selected: candidates[0].id, metrics };
}

const calculators = { extract, impute, outlier, visualize, structure, classify, 'model-split': modelSplit, 'model-metrics': modelMetrics };
function validateCase(item) {
    for (const key of ['id', 'gameId', 'level', 'skill', 'kind', 'prompt', 'explanation', 'misconception']) {
        assert.equal(typeof item[key], 'string', key);
        assert.ok(item[key].trim(), key);
    }
    assert.match(item.id, /^[a-z0-9-]+$/);
    assert.ok(['easy', 'normal', 'hard'].includes(item.level));
    assert.ok(Object.hasOwn(calculators, item.kind), `Unknown task kind: ${item.kind}`);
    assert.equal(item.gameId, gameKinds[item.kind]);
    assert.ok(item.data && item.grading && item.expected);
    const original = JSON.stringify(item);
    equal(calculators[item.kind](item.data, item.grading), item.expected, item.id);
    assert.equal(JSON.stringify(item), original, 'Validation modified source data');
}

function validatePack(value) {
    assert.equal(value.schemaVersion, 1);
    assert.equal(value.status, 'draft-not-runtime');
    assert.ok(value.audience && value.provenance && value.cases.length);
    unique(value.cases.map((item) => item.id));
    value.cases.forEach(validateCase);
    return value.cases.length;
}

const passed = validatePack(pack);
const mutations = [
    ['wrong extraction answer', 'extract-stock-filter', (item) => { item.expected.rowIds = ['A']; }],
    ['wrong missing answer', 'missing-mean-policy', (item) => { item.expected.replacements['2'] = 0; }],
    ['zero denominator', 'extract-per-person', (item) => { item.data.rows[0].members = 0; }],
    ['unknown kind', 'extract-stock-filter', (item) => { item.kind = 'unsupported'; }],
    ['wrong game mapping', 'extract-stock-filter', (item) => { item.gameId = 'image-data'; }],
    ['ambiguous mode', 'missing-mode-estimate', (item) => { item.data.values[3] = '파랑'; item.data.values.push('빨강'); }],
    ['unsorted times', 'missing-time-interpolation', (item) => { item.data.times[1] = 21; }],
    ['overlapping split IDs', 'model-train-only-preprocessing', (item) => { item.data.test[0].id = 't1'; }],
    ['uncovered histogram value', 'viz-bin-boundary', (item) => { item.data.values.push(31); }],
    ['wrong trace state', 'structure-peek-pop', (item) => { item.expected.states[2] = ['A']; }],
    ['inconsistent threshold label', 'classifier-inclusive-boundary', (item) => { item.data.training[0].label = 'B'; }],
    ['wrong metric', 'model-accuracy-trap', (item) => { item.expected.metrics['detect-defects'].accuracy = 1; }],
];
for (const [name, id, mutate] of mutations) {
    const item = structuredClone(pack.cases.find((candidate) => candidate.id === id));
    assert.ok(item, `Missing mutation fixture: ${id}`);
    mutate(item);
    assert.throws(() => validateCase(item), undefined, name);
}
const duplicate = structuredClone(pack);
duplicate.cases.push(structuredClone(duplicate.cases[0]));
assert.throws(() => validatePack(duplicate), /Duplicate identifier/);
const active = structuredClone(pack);
active.status = 'runtime';
assert.throws(() => validatePack(active));

console.log(`PASS: ${passed} draft content cases; ${mutations.length + 2} invalid-content guards.`);
console.log('Checks cover computed answers and data invariants, not classroom effectiveness or runtime integration.');

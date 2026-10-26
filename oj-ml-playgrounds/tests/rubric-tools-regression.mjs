import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const window = { confirm: () => true };
const context = vm.createContext({ window, console });
vm.runInContext(readFileSync(new URL('../resources/js/rubric-tools.js', import.meta.url), 'utf8'), context);
const rubric = window.OJMLRubric;
assert.ok(rubric, 'Real helper registered');
const plain = value => JSON.parse(JSON.stringify(value));
const definition = { version: 1, criteria: [
    { id: 'a', title: '데이터 이해', description: '근거를 설명하세요.', maxScore: 25 },
    { id: 'b', title: '해석', description: '한계를 제시하세요.', maxScore: 75.25 },
] };
const attack = '<script>alert(1)</script><img src=x onerror=alert(1)>';
const malicious = { version: 1, criteria: [{ id: attack, title: attack, description: attack, maxScore: 25 }] };
for (const content of [
    rubric.editorHtml(malicious), rubric.criteriaHtml(malicious),
    rubric.reviewFields(malicious, null),
    rubric.releasedHtml({ status: 'released', details: { rubricSnapshot: malicious, criteriaScores: [{ id: attack, score: 3, feedback: attack }] } }),
]) {
    assert.doesNotMatch(content, /<script|<img/);
    assert.match(content, /&lt;script/);
}
assert.match(rubric.editorHtml(definition, { disabled: true }), / disabled/);
assert.match(rubric.editorHtml({ old: 'saved' }), /data-rubric-legacy="1"/);
assert.match(rubric.editorHtml({ criteria: [{ key: 'old-weight' }] }), /data-rubric-legacy="1"/);
assert.match(rubric.editorHtml({ old: 'saved' }), /명시적으로 교체/);
assert.equal(rubric.criteriaHtml([]), '');
assert.equal(rubric.reviewFields([], null), '');
assert.equal(rubric.releasedHtml({ status: 'draft', details: { rubricSnapshot: definition, criteriaScores: [] } }), '');
assert.match(rubric.releasedHtml({ status: 'released', details: { rubricSnapshot: definition, criteriaScores: [{ id: 'a', score: 0, feedback: '성찰' }] } }), /0 \/ 25점/);
assert.match(rubric.reviewFields(definition, { details: { rubricSnapshot: { version: 1, criteria: [{ id: 'c', title: '과거 기준', description: '', maxScore: 10 }] }, criteriaScores: [] } }), /과거 기준/);

function editorRow(id, title, maxScore, description = '') {
    const values = {
        '[data-rubric-title]': { value: title },
        '[data-rubric-max]': { value: String(maxScore) },
        '[data-rubric-description]': { value: description },
    };
    return { dataset: { criterionId: id }, querySelector: key => values[key] };
}
function editor(rows, original = null) {
    return {
        dataset: original ? { rubricLegacy: '1', rubricOriginal: JSON.stringify(original) } : {},
        matches: value => value === '[data-rubric-editor]',
        querySelectorAll: () => rows,
    };
}
assert.deepEqual(plain(rubric.readEditor(editor([editorRow('a', '이해', 10.12)]))), {
    version: 1, criteria: [{ id: 'a', title: '이해', description: '', maxScore: 10.12 }],
});
assert.deepEqual(plain(rubric.readEditor(editor([], { old: 'exact legacy' }))), { old: 'exact legacy' });
for (const rows of [
    [editorRow('a', '', 1)], [editorRow('a', '제목', 0)], [editorRow('a', '제목', 0.001)],
    [editorRow('a', '제목', 10000.01)], [editorRow('a', '제목', Infinity)],
    [editorRow('a', '제목', 1), editorRow('a', '중복', 1)],
    [editorRow('1bad', '제목', 1)], Array.from({ length: 26 }, (_, i) => editorRow('item' + i, '제목', 1)),
]) assert.throws(() => rubric.readEditor(editor(rows)));
assert.deepEqual(plain(rubric.readEditor(editor([]))), { version: 1, criteria: [] });

function scoreRow(id, value, maximum = 25, feedback = '') {
    const values = { '[data-rubric-score]': { value: String(value) }, '[data-rubric-feedback]': { value: feedback } };
    return { dataset: { criterionId: id, maxScore: String(maximum) }, querySelector: key => values[key] };
}
function reviewArea(rows) {
    const total = { textContent: '' };
    return {
        dataset: {}, matches: value => value === '[data-rubric-review]',
        querySelectorAll: () => rows, querySelector: () => total,
        addEventListener(name, callback) { this[name] = callback; }, total,
    };
}
const area = reviewArea([scoreRow('a', 20.01), scoreRow('b', 70.12, 75.25)]);
assert.deepEqual(plain(rubric.readReview(area)), { criteriaScores: [
    { id: 'a', score: 20.01, feedback: '' }, { id: 'b', score: 70.12, feedback: '' },
] });
rubric.bindReview(area);
assert.match(area.total.textContent, /90.13 \/ 100.25점/);
const blank = reviewArea([scoreRow('a', '', 25, '피드백'), scoreRow('b', '', 75.25)]);
assert.equal(rubric.readReview(blank).criteriaScores[0].score, null);
assert.equal(rubric.readReview(blank).criteriaScores[0].feedback, '피드백');
rubric.bindReview(blank);
assert.match(blank.total.textContent, /점수 없이/);
for (const rows of [
    [scoreRow('a', 0.001)], [scoreRow('a', 26)], [scoreRow('a', -1)], [scoreRow('a', Infinity)],
    [scoreRow('a', 20), scoreRow('b', '')], [scoreRow('a', 20, 25, '가'.repeat(2001))],
]) assert.throws(() => rubric.readReview(reviewArea(rows)));
console.log('rubric-tools-regression passed');

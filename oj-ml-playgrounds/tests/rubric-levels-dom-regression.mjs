import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the installed local frontend test runtime.');
const requirePackage = createRequire(path.join(frontend, 'package.json'));
const { JSDOM } = requirePackage('jsdom');
const dom = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only' });
const { window } = dom;
window.eval(readFileSync(new URL('../resources/js/rubric-tools.js', import.meta.url), 'utf8'));
const api = window.OJMLRubric;
const plain = value => JSON.parse(JSON.stringify(value));
let allow = true, confirmations = 0;
window.confirm = () => { confirmations++; return allow; };
const original = { version: 1, criteria: [
    { id: 'past_a', title: '교사 작성 항목', description: '원래 설명\n두 번째 줄', maxScore: 12.34 },
    { id: 'tiny', title: '소수점 배점', description: '작은 배점도 그대로 보존합니다.', maxScore: 0.01 },
] };
function render(value, options) {
    window.document.body.innerHTML = '<form>' + api.editorHtml(value, options) + '</form>';
    const editor = window.document.querySelector('[data-rubric-editor]');
    api.bindEditor(editor);
    return editor;
}
async function click(node, selector) {
    const button = node.querySelector(selector);
    assert(button, 'Expected control: ' + selector);
    button.click();
    await new Promise(resolve => setImmediate(resolve));
}
function input(node, selector, value) {
    const target = node.querySelector(selector);
    target.value = value;
    target.dispatchEvent(new window.Event('input', { bubbles: true }));
}
function formFor(definition, review) {
    window.document.body.innerHTML = '<form>' + api.reviewFields(definition, review || null) + '</form>';
    const form = window.document.querySelector('form');
    api.bindReview(form);
    return form;
}
function choose(row, id) {
    row.querySelector('[data-rubric-level]').value = id;
    row.querySelector('[data-rubric-level]').dispatchEvent(new window.Event('change', { bubbles: true }));
}

try {
    let editor = render([], { isNew: true });
    let definition = plain(api.readEditor(editor));
    assert.equal(definition.version, 2);
    assert.equal(definition.criteria.length, 4);
    for (const item of definition.criteria) {
        assert.equal(item.levels.length, 4);
        assert.deepEqual(item.levels.map(level => level.score), [25, 20, 10, 0]);
        assert.equal(new Set(item.levels.map(level => level.id)).size, 4);
        assert.equal(new Set(item.levels.map(level => level.description)).size, 4);
        assert(item.levels.every(level => level.label && level.description.length > 20));
        assert.equal(item.maxScore, Math.max(...item.levels.map(level => level.score)));
    }
    assert.match(definition.criteria[0].levels[0].description, /학습 유형과 타깃·특성/);
    assert.equal(editor.querySelectorAll('[data-rubric-level-item]').length, 16);
    assert.equal(editor.closest('form').checkValidity(), true);
    const firstRow = editor.querySelector('[data-rubric-item]');
    assert(firstRow.querySelector('[data-rubric-max]').readOnly);
    input(firstRow, '[data-rubric-level-score]', '42.15');
    assert.equal(firstRow.querySelector('[data-rubric-max]').value, '42.15');
    assert.equal(api.readEditor(editor).criteria[0].maxScore, 42.15);
    firstRow.querySelector('[data-rubric-max]').value = '999';
    assert.equal(api.readEditor(editor).criteria[0].maxScore, 42.15, 'The maximum is derived from levels, not the readonly display.');

    const originalScore = firstRow.querySelector('[data-rubric-level-score]').value;
    for (const score of ['20', '-1', '10000.01', '0.001', '']) {
        input(firstRow, '[data-rubric-level-score]', score);
        assert.throws(() => api.readEditor(editor), undefined, 'Invalid or duplicate level score: ' + score);
    }
    input(firstRow, '[data-rubric-level-score]', originalScore);
    firstRow.open = false;
    firstRow.querySelector('[data-rubric-level-score]').value = '20';
    assert.throws(() => api.readEditor(editor), /서로 달라야/);
    assert.equal(firstRow.open, true, 'Custom score validation reveals a collapsed item too.');
    input(firstRow, '[data-rubric-level-score]', originalScore);
    for (const selector of ['[data-rubric-level-label]', '[data-rubric-level-description]']) {
        const previous = firstRow.querySelector(selector).value;
        input(firstRow, selector, '');
        assert.throws(() => api.readEditor(editor), /등급 이름|판단 기준/);
        assert.equal(editor.closest('form').checkValidity(), false);
        input(firstRow, selector, previous);
    }
    const levelNodes = firstRow.querySelectorAll('[data-rubric-level-item]');
    const oldId = levelNodes[1].dataset.levelId;
    levelNodes[1].dataset.levelId = levelNodes[0].dataset.levelId;
    assert.throws(() => api.readEditor(editor), /식별자/);
    levelNodes[1].dataset.levelId = oldId;

    await click(firstRow, '[data-rubric-level-add]');
    assert.equal(firstRow.querySelectorAll('[data-rubric-level-item]').length, 5);
    assert.match(firstRow.querySelector('[data-rubric-summary-meta]').textContent, /5등급$/);
    const additional = firstRow.querySelector('[data-rubric-levels-editor]').lastElementChild;
    assert.equal(window.document.activeElement, additional.querySelector('[data-rubric-level-label]'));
    input(additional, '[data-rubric-level-label]', '추가 성취 수준');
    input(additional, '[data-rubric-level-score]', '5.5');
    input(additional, '[data-rubric-level-description]', '변수는 제시했지만 목표와의 관련 근거를 구체적으로 설명하지 못했다.');
    assert.equal(api.readEditor(editor).criteria[0].levels.length, 5);
    await click(additional, '[data-rubric-level-remove]');
    assert.equal(api.readEditor(editor).criteria[0].levels.length, 4);
    assert.match(firstRow.querySelector('[data-rubric-summary-meta]').textContent, /4등급$/);
    await click(firstRow, '[data-rubric-level-remove]');
    await click(firstRow, '[data-rubric-level-remove]');
    assert.equal(firstRow.querySelectorAll('[data-rubric-level-item]').length, 2);
    await click(firstRow, '[data-rubric-level-remove]');
    assert.equal(firstRow.querySelectorAll('[data-rubric-level-item]').length, 2, 'UI deletion retains the minimum two levels.');
    assert.match(editor.querySelector('[data-rubric-error]').textContent, /최소 2개/);
    firstRow.querySelector('[data-rubric-level-item]').remove();
    assert.throws(() => api.readEditor(editor), /2~10개/);

    editor = render([], { isNew: true });
    const crowded = editor.querySelector('[data-rubric-item]');
    for (let index = 0; index < 7; index++) await click(crowded, '[data-rubric-level-add]');
    assert.equal(crowded.querySelectorAll('[data-rubric-level-item]').length, 10);
    assert.match(editor.querySelector('[data-rubric-error]').textContent, /최대 10개/);
    crowded.querySelector('[data-rubric-levels-editor]').append(crowded.querySelector('[data-rubric-level-item]').cloneNode(true));
    assert.throws(() => api.readEditor(editor), /2~10개/);

    editor = render(original);
    assert.equal(editor.dataset.rubricVersion, '1');
    assert.equal(editor.querySelector('[data-rubric-level-item]'), null);
    assert.deepEqual(plain(api.readEditor(editor)), original, 'Existing v1 criteria remain free-score and byte-equivalent by value.');
    allow = false;
    await click(editor, '[data-rubric-upgrade]');
    assert.deepEqual(plain(api.readEditor(editor)), original, 'Cancelling conversion must not mutate the old rubric.');
    allow = true;
    await click(editor, '[data-rubric-upgrade]');
    const converted = plain(api.readEditor(editor));
    assert.equal(converted.version, 2);
    for (let index = 0; index < original.criteria.length; index++) {
        const { levels, ...retained } = converted.criteria[index];
        assert.deepEqual(retained, original.criteria[index], 'Conversion preserves criterion ID, title, description and original maximum.');
        assert.equal(new Set(levels.map(level => level.score)).size, levels.length);
        assert(levels.length >= 2);
    }
    assert.deepEqual(converted.criteria[1].levels.map(level => level.score), [0.01, 0]);
    assert.equal(converted.criteria[1].levels.at(-1).label, '미충족');
    assert.equal(editor.querySelector('[data-rubric-upgrade]'), null);
    await click(editor, '[data-rubric-mode-choice="total"]');
    assert.deepEqual(plain(api.readEditor(editor)), { version: 1, criteria: [] }, 'Total-score mode uses the established empty-v1 contract, never an invalid empty v2.');
    await click(editor, '[data-rubric-mode-choice="criteria"]');
    assert.deepEqual(plain(api.readEditor(editor)), converted, 'Unsaved level edits survive switching to total mode and back.');

    editor = render(original, { disabled: true });
    const beforeConfirm = confirmations;
    await click(editor, '[data-rubric-upgrade]');
    assert.deepEqual(plain(api.readEditor(editor)), original);
    assert.equal(confirmations, beforeConfirm, 'Published/locked rubrics cannot be converted.');
    editor = render({ version: 1, criteria: [] });
    assert.equal(editor.dataset.rubricVersion, '1');
    await click(editor, '[data-rubric-mode-choice="criteria"]');
    assert.equal(api.readEditor(editor).version, 1, 'Selecting items for an old total rubric does not implicitly migrate its scoring model.');
    await click(editor, '[data-rubric-upgrade]');
    assert.equal(api.readEditor(editor).version, 2);

    editor = render([], { isNew: true });
    editor.querySelector('[data-rubric-preset-name]').value = 'experiments';
    await click(editor, '[data-rubric-preset]');
    definition = plain(api.readEditor(editor));
    assert.equal(definition.version, 2);
    assert.deepEqual(definition.criteria.map(item => item.maxScore), [20, 30, 30, 20]);
    assert.match(definition.criteria[2].levels[0].description, /실패·취소/);

    let form = formFor(definition);
    assert.equal(form.querySelectorAll('[data-rubric-level]').length, 4);
    assert([...form.querySelectorAll('[data-rubric-grade-picker]')].every(picker => picker.hidden), 'Reviewers see the current score before opening full grade choices.');
    assert.equal(form.querySelector('[data-rubric-review-help]').open, false);
    assert([...form.querySelectorAll('[data-rubric-selected-level]')].every(note => note.hidden), 'Unselected rows do not repeat instructions.');
    assert.equal(form.querySelector('input[type="number"]'), null, 'Level-based reviews have no arbitrary numeric score field.');
    const blank = plain(api.readReview(form));
    assert(blank.criteriaScores.every(entry => entry.levelId === null && entry.score === null));
    let rows = [...form.querySelectorAll('[data-rubric-score-item]')];
    assert(rows[0].querySelector('[data-rubric-grade-adjust]').compareDocumentPosition(rows[0].querySelector('[data-rubric-grade-picker]')) & window.Node.DOCUMENT_POSITION_FOLLOWING);
    choose(rows[0], definition.criteria[0].levels[0].id);
    assert.throws(() => api.readReview(form), /모든 항목의 등급/);
    rows.forEach((row, index) => choose(row, definition.criteria[index].levels[0].id));
    let grades = plain(api.readReview(form));
    assert.equal(grades.criteriaScores.reduce((sum, item) => sum + item.score, 0), 100);
    assert.match(form.querySelector('[data-rubric-review-total]').textContent, /100 \/ 100점/);
    assert.match(rows[0].querySelector('[data-rubric-selected-level]').textContent, /충분히 충족 · 20점/);
    assert.equal(rows[0].querySelector('[data-rubric-selected-level]').hidden, false);
    assert.match(rows[0].querySelector('[data-rubric-selected-level]').textContent, /바꿀 조건·유지할 조건/);
    assert.equal(rows[0].querySelectorAll('[data-selected-level]').length, 1);
    rows[0].querySelector('[data-rubric-score]').value = '999';
    assert.equal(api.readReview(form).criteriaScores[0].score, 20, 'Hidden numeric input is not an authority; the selected canonical level determines the score.');
    choose(rows[0], definition.criteria[0].levels.at(-1).id);
    grades = plain(api.readReview(form));
    assert.equal(grades.criteriaScores[0].score, 0);
    assert.equal(grades.criteriaScores[0].levelId, 'not_yet');
    assert.match(rows[0].querySelector('[data-rubric-selected-level]').textContent, /미충족 · 0점/);
    assert.equal(rows[0].querySelectorAll('[data-rubric-grade-selected]:not([hidden])').length, 1);
    assert.equal(rows[0].querySelector('[data-selected-level] strong').textContent, '미충족 · 0점');
    const invented = window.document.createElement('option'); invented.value = 'invented'; invented.textContent = '임의 등급';
    rows[0].querySelector('[data-rubric-level]').append(invented);
    choose(rows[0], 'invented');
    assert.throws(() => api.readReview(form), /정의된 평가 등급/);
    rows.forEach(row => choose(row, ''));
    assert(api.readReview(form).criteriaScores.every(entry => entry.levelId === null && entry.score === null));

    const stored = { status: 'released', details: { version: 2, rubricSnapshot: definition, criteriaScores: grades.criteriaScores } };
    form = formFor(original, stored);
    rows = [...form.querySelectorAll('[data-rubric-score-item]')];
    assert.equal(rows[0].querySelector('[data-rubric-level]').value, 'not_yet');
    assert.equal(rows[0].querySelector('[data-rubric-score]').value, '0');
    assert.deepEqual(plain(api.readReview(form)), grades, 'Review snapshots control saved grade IDs and zero scores, not the latest rubric.');
    assert.match(api.criteriaHtml(definition), /등급별 점수·판단 기준/);
    assert.match(api.criteriaHtml(definition), /같은 조건의 성공 실험/);
    assert.match(api.releasedHtml(stored), /선택 등급: 미충족/);
    assert.match(api.releasedHtml(stored), /0 \/ 20점/);
    for (const markup of [api.criteriaHtml(definition), api.releasedHtml(stored)]) {
        const section = window.document.createElement('section'); section.innerHTML = markup;
        assert([...section.querySelectorAll('.oj-ml-rubric-level-guide')].every(guide => !guide.open), 'Student-facing full tables start collapsed too.');
        assert(section.textContent.includes(definition.criteria[0].description), 'The compact presentation retains full item descriptions inside the table.');
    }
    const released = window.document.createElement('section'); released.innerHTML = api.releasedHtml(stored);
    assert.match(released.querySelector('.oj-ml-rubric-selected-level').textContent, /탐구 질문과/);
    assert(released.querySelector('.oj-ml-rubric-selected-level').compareDocumentPosition(released.querySelector('.oj-ml-rubric-level-guide')) & window.Node.DOCUMENT_POSITION_FOLLOWING);
    assert.equal(api.releasedHtml({ ...stored, status: 'draft' }), '');

    const attack = '<img src=x onerror=alert(1)><script>alert(1)</script>';
    const malicious = plain(definition);
    malicious.criteria[0].levels[0].label = attack;
    malicious.criteria[0].levels[0].description = attack;
    malicious.criteria[0].levels[0].id = attack;
    for (const markup of [api.editorHtml(malicious), api.criteriaHtml(malicious), api.reviewFields(malicious), api.releasedHtml({ status: 'released', details: { version: 2, rubricSnapshot: malicious, criteriaScores: [{ id: malicious.criteria[0].id, levelId: attack, score: 20 }] } })]) {
        assert.doesNotMatch(markup, /<img|<script/);
        assert.match(markup, /&lt;img/);
    }
    assert.equal(window.OJP, undefined);
    assert.equal(window.OJMLTeacherWorkflow, undefined);
    console.log('rubric-levels-dom-regression passed');
} finally {
    window.close();
}

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
const source = readFileSync(new URL('../resources/js/rubric-tools.js', import.meta.url), 'utf8');
window.eval(source);
const rubric = window.OJMLRubric;
const plain = value => JSON.parse(JSON.stringify(value));
let confirmations = [], allow = true;
window.confirm = title => { confirmations.push(title); return allow; };
assert.equal(window.OJP, undefined, 'Editor must not depend on the AI or platform JavaScript being loaded.');
assert.equal(window.OJMLTeacherWorkflow, undefined);

function editor(value, options) {
    window.document.body.innerHTML = '<form>' + rubric.editorHtml(value, options) + '</form>';
    const node = window.document.querySelector('[data-rubric-editor]');
    rubric.bindEditor(node);
    return node;
}
async function click(node, selector) {
    const button = node.querySelector(selector);
    assert(button, 'Expected button: ' + selector);
    button.click();
    await new Promise(resolve => setImmediate(resolve));
}
function input(node, selector, value) {
    const field = node.querySelector(selector);
    field.value = value;
    field.dispatchEvent(new window.Event('input', { bubbles: true }));
}
const saved = { version: 1, criteria: [
    { id: 'preserve_key', title: '기존 항목', description: '기존 기준\n두 번째 줄', maxScore: 12.34 },
] };

try {
    let node = editor([], { isNew: true });
    let data = plain(rubric.readEditor(node));
    assert.equal(node.dataset.rubricMode, 'criteria');
    assert.equal(data.version, 2, 'New assignments use level-based scoring.');
    assert.equal(data.criteria.length, 4, 'Only new assignments start with explicit criteria.');
    assert.equal(data.criteria.reduce((sum, item) => sum + item.maxScore, 0), 100);
    assert(data.criteria.every(item => item.title && item.description && item.id));
    assert.match(node.textContent, /AI 평가 플러그인이 없어도 채점·저장·공개/);
    assert.match(node.querySelector('[data-rubric-total]').textContent, /4개 항목 · 총 배점 100점/);
    assert.equal(node.querySelectorAll('[data-rubric-item-number]')[3].textContent, '평가 항목 4');
    assert.deepEqual([...node.querySelectorAll('[data-rubric-item]')].map(item => item.tagName), ['DETAILS', 'DETAILS', 'DETAILS', 'DETAILS']);
    assert.deepEqual([...node.querySelectorAll('[data-rubric-item]')].map(item => item.open), [true, false, false, false], 'Only the first authoring item opens initially.');
    assert.equal(node.querySelector('[data-rubric-help]').open, false, 'General instructions stay available on demand.');
    assert.equal(node.querySelector('[data-rubric-summary-title]').textContent, data.criteria[0].title);
    assert.equal(node.querySelector('[data-rubric-summary-meta]').textContent, '25점 · 4등급');
    assert.equal(node.querySelector('[data-rubric-mode-choice="criteria"]').getAttribute('aria-pressed'), 'true');
    assert.equal(node.querySelectorAll('[data-rubric-add]').length, 1);
    assert.equal(node.querySelector('[data-rubric-add]').closest('.oj-ml-rubric-toolbar'), node.querySelector('.oj-ml-rubric-toolbar'));
    assert.equal(node.querySelector('[data-rubric-add]').closest('[data-rubric-workspace]'), null, 'The add action stays above all criteria and outside hidden total-mode fields.');
    for (const row of node.querySelectorAll('[data-rubric-row]')) {
        assert.equal(row.querySelector('[data-rubric-remove]').parentElement, row, 'Delete is a sibling of the disclosure, visible even while its details is collapsed.');
        assert.equal(row.querySelector('summary button'), null, 'The native disclosure and delete button are separate keyboard controls.');
        assert.match(row.querySelector('[data-rubric-remove]').getAttribute('aria-label'), /평가 항목 \d+ .+ 삭제/);
    }
    assert.equal(node.closest('form').checkValidity(), true);

    input(node, '[data-rubric-title]', '교사가 작성한 기준');
    assert.equal(node.querySelector('[data-rubric-summary-title]').textContent, '교사가 작성한 기준');
    assert.match(node.querySelector('[data-rubric-remove]').getAttribute('aria-label'), /교사가 작성한 기준 삭제/);
    assert.equal(node.querySelector('[data-rubric-max]').readOnly, true);
    input(node, '[data-rubric-level-score]', '35.25');
    assert.equal(node.querySelector('[data-rubric-max]').value, '35.25');
    assert.equal(node.querySelector('[data-rubric-summary-meta]').textContent, '35.25점 · 4등급');
    const edited = plain(rubric.readEditor(node));
    allow = false;
    await click(node, '[data-rubric-mode-choice="total"]');
    assert.equal(node.dataset.rubricMode, 'criteria', 'Cancel must keep criteria and input values.');
    assert.deepEqual(plain(rubric.readEditor(node)), edited);
    allow = true;
    await click(node, '[data-rubric-mode-choice="total"]');
    assert.deepEqual(plain(rubric.readEditor(node)), { version: 1, criteria: [] });
    assert(node.querySelector('[data-rubric-workspace]').hidden);
    assert(node.querySelector('[data-rubric-workspace]').disabled, 'Hidden required inputs must not block submitting total-score mode.');
    assert.equal(node.querySelector('[data-rubric-mode-choice="total"]').getAttribute('aria-pressed'), 'true');
    input(node, '[data-rubric-title]', '');
    assert.equal(node.closest('form').checkValidity(), true);
    input(node, '[data-rubric-title]', '교사가 작성한 기준');
    await click(node, '[data-rubric-mode-choice="criteria"]');
    assert.deepEqual(plain(rubric.readEditor(node)), edited, 'Switching back before saving preserves authored criteria and IDs.');
    assert.equal(node.querySelector('[data-rubric-workspace]').disabled, false);

    node.querySelector('[data-rubric-preset-name]').value = 'experiments';
    allow = false;
    await click(node, '[data-rubric-preset]');
    assert.deepEqual(plain(rubric.readEditor(node)), edited, 'Cancelled template replacement is non-mutating.');
    allow = true;
    await click(node, '[data-rubric-preset]');
    data = plain(rubric.readEditor(node));
    assert.deepEqual(data.criteria.map(item => item.maxScore), [20, 30, 30, 20]);
    assert.match(data.criteria[2].description, /실패·취소 기록/);
    assert.match(data.criteria[1].description, /매개변수/);
    assert.equal(data.criteria.reduce((sum, item) => sum + item.maxScore, 0), 100);

    node = editor([], { isNew: true });
    const beforeDelete = plain(rubric.readEditor(node));
    const collapsedRow = node.querySelectorAll('[data-rubric-row]')[2];
    const collapsedItem = collapsedRow.querySelector('[data-rubric-item]');
    const deleteButton = collapsedRow.querySelector('[data-rubric-remove]');
    deleteButton.focus();
    allow = false;
    await click(collapsedRow, '[data-rubric-remove]');
    assert.deepEqual(plain(rubric.readEditor(node)), beforeDelete, 'Cancelling deletion preserves every v2 item, grade and point value.');
    assert.equal(collapsedItem.open, false, 'A separate delete action must not toggle its disclosure.');
    assert.equal(window.document.activeElement, deleteButton, 'Cancelling deletion restores keyboard focus to its trigger.');
    const nextSummary = collapsedRow.nextElementSibling.querySelector('summary');
    allow = true;
    await click(collapsedRow, '[data-rubric-remove]');
    assert.deepEqual(plain(rubric.readEditor(node)), { ...beforeDelete, criteria: beforeDelete.criteria.filter((_, index) => index !== 2) });
    assert.equal(node.querySelectorAll('[data-rubric-item]').length, 3);
    assert.equal(window.document.activeElement, nextSummary, 'Deleting a middle item moves keyboard focus to the next remaining summary.');
    const lastRow = node.querySelector('[data-rubric-rows]').lastElementChild;
    const previousSummary = lastRow.previousElementSibling.querySelector('summary');
    await click(lastRow, '[data-rubric-remove]');
    assert.equal(window.document.activeElement, previousSummary, 'Deleting the last item focuses the previous remaining summary.');

    const beforePendingDelete = plain(rubric.readEditor(node));
    let answerDelete, pendingPrompts = 0;
    window.OJP = { confirm: () => { pendingPrompts++; return new Promise(resolve => { answerDelete = resolve; }); } };
    await click(node, '[data-rubric-remove]');
    assert.equal(node.querySelector('[data-rubric-remove]').disabled, true, 'A pending confirmation prevents duplicate delete prompts.');
    await click(node, '[data-rubric-remove]');
    assert.equal(pendingPrompts, 1);
    node.disabled = true;
    answerDelete(true);
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(plain(rubric.readEditor(node)), beforePendingDelete, 'A rubric locked while confirmation is open cannot be mutated by the delayed answer.');
    delete window.OJP;

    node = editor(saved);
    assert.deepEqual(plain(rubric.readEditor(node)), saved, 'Existing valid criteria and precision are preserved.');
    await click(node, '[data-rubric-add]');
    assert.equal(node.querySelectorAll('[data-rubric-item]').length, 2);
    assert.equal(node.querySelectorAll('[data-rubric-item]')[1].open, true, 'New items open before focusing the title.');
    assert.equal(window.document.activeElement, node.querySelectorAll('[data-rubric-title]')[1]);
    assert.equal(node.closest('form').checkValidity(), false, 'A new unnamed criterion cannot silently submit.');
    node.querySelectorAll('[data-rubric-title]')[1].value = '추가 항목';
    assert.equal(plain(rubric.readEditor(node)).criteria[1].maxScore, 25);
    await click(node, '[data-rubric-remove]');
    assert.equal(node.querySelector('[data-rubric-item-number]').textContent, '평가 항목 1');
    await click(node, '[data-rubric-remove]');
    assert.throws(() => rubric.readEditor(node), /평가 항목이 필요/);
    assert.match(node.querySelector('[data-rubric-total]').textContent, /평가 항목이 필요/);
    assert.equal(window.document.activeElement, node.querySelector('[data-rubric-add]'), 'Deleting the final item focuses the always-visible add action.');
    await click(node, '[data-rubric-mode-choice="total"]');
    assert.deepEqual(plain(rubric.readEditor(node)), { version: 1, criteria: [] });

    for (const value of [[], { version: 1, criteria: [] }]) {
        node = editor(value);
        assert.equal(node.dataset.rubricMode, 'total', 'Existing total-score assignments are never auto-migrated.');
        assert.equal(node.querySelectorAll('[data-rubric-item]').length, 0);
        assert.deepEqual(plain(rubric.readEditor(node)), { version: 1, criteria: [] });
        assert.equal(node.querySelector('[data-rubric-add]').matches(':disabled'), false);
        await click(node, '[data-rubric-add]');
        assert.equal(node.dataset.rubricMode, 'criteria', 'The visible add action explicitly starts item-based authoring from total-only mode.');
        assert.equal(node.querySelectorAll('[data-rubric-item]').length, 1, 'Adding one item must not silently insert the four-item starter.');
        input(node, '[data-rubric-title]', '총점 방식에서 추가한 항목');
        assert.equal(plain(rubric.readEditor(node)).version, 1, 'Adding an item does not silently upgrade existing v1 scoring.');
    }
    const legacy = { criteria: [{ title: '오래된 기준', score: 7 }], note: '<img src=x onerror=alert(1)>' };
    node = editor(legacy, { isNew: true });
    assert.equal(node.dataset.rubricMode, 'legacy', 'Even a new/duplicated legacy rubric is not implicitly replaced.');
    assert.deepEqual(plain(rubric.readEditor(node)), legacy);
    assert.equal(node.querySelector('img'), null);
    assert.equal(node.querySelector('[data-rubric-add]').disabled, true, 'Legacy criteria require explicit replacement before item edits.');
    await click(node, '[data-rubric-add]');
    assert.deepEqual(plain(rubric.readEditor(node)), legacy);
    allow = false;
    await click(node, '[data-rubric-replace]');
    assert.deepEqual(plain(rubric.readEditor(node)), legacy);
    allow = true;
    await click(node, '[data-rubric-replace]');
    assert.equal(plain(rubric.readEditor(node)).criteria.length, 4);
    assert.equal(node.querySelector('[data-rubric-modes]').hidden, false);
    assert.equal(node.querySelector('[data-rubric-legacy-notice]'), null);
    assert.equal(node.querySelector('[data-rubric-add]').disabled, false);

    node = editor(saved, { disabled: true });
    const original = plain(rubric.readEditor(node));
    await click(node, '[data-rubric-preset]');
    await click(node, '[data-rubric-remove]');
    await click(node, '[data-rubric-mode-choice="total"]');
    await click(node, '[data-rubric-add]');
    assert.deepEqual(plain(rubric.readEditor(node)), original, 'Published/locked assignments cannot change criteria.');
    assert.equal(node.querySelector('[data-rubric-add]').matches(':disabled'), true);
    assert.equal(node.querySelector('[data-rubric-remove]').matches(':disabled'), true);

    node = editor({ version: 1, criteria: Array.from({ length: 25 }, (_, index) => ({ id: 'c' + index, title: '기준 ' + index, description: '', maxScore: 1 })) });
    await click(node, '[data-rubric-add]');
    assert.equal(node.querySelectorAll('[data-rubric-item]').length, 25);
    assert.match(node.querySelector('[data-rubric-error]').textContent, /최대 25개/);

    const review = { details: { version: 1, rubricSnapshot: saved, criteriaScores: [{ id: 'preserve_key', score: 0, feedback: '기존 피드백' }] } };
    window.document.body.innerHTML = '<form>' + rubric.reviewFields({ version: 1, criteria: [] }, review) + '</form>';
    const reviewForm = window.document.querySelector('form');
    rubric.bindReview(reviewForm);
    assert.equal(reviewForm.querySelector('[data-rubric-score]').value, '0', 'A saved zero score is not discarded.');
    assert.equal(reviewForm.querySelector('[data-rubric-feedback]').value, '기존 피드백');
    assert.match(reviewForm.textContent, /기존 항목/);
    assert.match(reviewForm.textContent, /AI 플러그인 없이도/);
    assert.match(reviewForm.querySelector('[data-rubric-review-total]').textContent, /0 \/ 12.34점/);
    assert.deepEqual(plain(rubric.readReview(reviewForm)), { criteriaScores: [{ id: 'preserve_key', score: 0, feedback: '기존 피드백' }] });
    input(reviewForm, '[data-rubric-score]', '12.34');
    assert.match(reviewForm.querySelector('[data-rubric-review-total]').textContent, /12.34 \/ 12.34점/);
    assert.equal(reviewForm.querySelector('[data-rubric-review-help]').open, false);

    node = editor([], { isNew: true });
    const unchanged = plain(rubric.readEditor(node));
    const outer = window.document.createElement('details');
    outer.innerHTML = '<summary>외부 과제 영역</summary>';
    outer.append(node.closest('form'));
    window.document.body.append(outer);
    const second = node.querySelectorAll('[data-rubric-item]')[1];
    const criterion = second.querySelector('[data-rubric-level-description]');
    const description = criterion.value;
    assert.equal(outer.open, false);
    assert.equal(second.open, false);
    criterion.value = '';
    assert.equal(criterion.checkValidity(), false);
    assert.equal(second.open, true, 'Standalone rubric validation opens the collapsed item.');
    assert.equal(outer.open, true, 'Validation also opens ancestor details outside the helper.');
    criterion.value = description;
    assert.deepEqual(plain(rubric.readEditor(node)), unchanged, 'Presentation toggles do not alter the rubric payload.');
    assert(confirmations.length >= 4, 'Potentially destructive mode/template replacement requires explicit confirmation.');
    console.log('rubric-editor-dom-regression passed');
} finally {
    dom.window.close();
}

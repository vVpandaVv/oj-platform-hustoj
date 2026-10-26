import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the installed local frontend test runtime.');
const { JSDOM } = createRequire(path.join(frontend, 'package.json'))('jsdom');
const dom = new JSDOM('<!doctype html><main class="oj-ml-app"></main>', { url: 'https://fixture.example.test/review', runScripts: 'outside-only' });
const { window } = dom;
window.fetch = () => assert.fail('Grade adjustment must never send a request.');
window.eval(readFileSync(new URL('../resources/js/rubric-tools.js', import.meta.url), 'utf8'));
const addon = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8'), closing = addon.lastIndexOf('}());');
window.eval(addon.slice(0, closing) + 'window.gradeTest = { applyAiReviewToForm };\n' + addon.slice(closing));
const rubric = window.OJMLRubric, plain = value => JSON.parse(JSON.stringify(value));
const definition = { version: 2, criteria: [
    { id: 'data', title: '데이터 이해', description: '통계와 선택 이유를 비교합니다.', maxScore: 40, evidenceRequirements: ['datasetStatistics'], levels: [
        { id: 'excellent', label: '충분히 충족', score: 40, description: '결측률과 분포를 확인하고 처리 선택의 이유를 설명했다.' },
        { id: 'partial', label: '일부 충족', score: 20, description: '통계는 있으나 처리 이유의 근거가 부족하다.' },
        { id: 'missing', label: '미충족', score: 0, description: '데이터를 이해한 근거를 확인할 수 없다.' },
    ] },
    { id: 'runs', title: '실험 비교', description: '동일 조건을 비교합니다.', maxScore: 60, levels: [
        { id: 'excellent', label: '충분히 충족', score: 60, description: '동일 평가 조건에서 지표와 한계를 설명했다.' },
        { id: 'partial', label: '일부 충족', score: 30, description: '비교는 있으나 평가 조건이 불분명하다.' },
        { id: 'missing', label: '미충족', score: 0, description: '실험 비교가 없다.' },
    ] },
] };
const saved = { version: 3, status: 'released', score: 50, maxScore: 100, feedback: '기존 교사 총평', details: { rubricSnapshot: definition, criteriaScores: [
    { id: 'data', levelId: 'partial', score: 20, feedback: '기존 데이터 피드백' },
    { id: 'runs', levelId: 'partial', score: 30, feedback: '기존 실험 피드백' },
] } };
let saves = 0;
function mount(review = saved, current = definition) {
    window.document.querySelector('main').innerHTML = '<form>' + rubric.reviewFields(current, review)
        + '<textarea name="feedback"></textarea><input name="expectedVersion" value="3"><p data-oj-ml-review-version></p><button type="submit">비공개 저장</button></form>';
    const form = window.document.querySelector('form');
    form.addEventListener('submit', event => { event.preventDefault(); saves++; });
    rubric.bindReview(form);
    return form;
}
try {
    const original = plain(saved);
    let form = mount(saved, { version: 2, criteria: [{ ...definition.criteria[0], title: '바뀐 최신 기준' }] });
    let rows = [...form.querySelectorAll('[data-rubric-score-item]')];
    assert.equal(rows.length, 2, 'Stored rubric snapshot, not later assignment edits, controls grading.');
    assert.equal(rows[0].querySelector('[data-rubric-grade-score]').textContent, '20 / 40점');
    assert.match(rows[0].querySelector('[data-rubric-selected-level]').textContent, /일부 충족 · 20점\n통계는/);
    assert.equal(rows[0].querySelector('[data-rubric-level]').closest('label').hidden, true, 'The select is a canonical bridge, not duplicate visible UI.');
    assert.equal(rows[0].querySelector('[data-rubric-grade-picker]').hidden, true);
    assert.equal(rows[0].querySelector('[data-rubric-grade-adjust]').getAttribute('aria-expanded'), 'false');
    assert.match(form.querySelector('[data-rubric-review-total]').textContent, /50 \/ 100점/);
    const trigger = rows[0].querySelector('[data-rubric-grade-adjust]');
    trigger.click();
    const picker = rows[0].querySelector('[data-rubric-grade-picker]');
    assert.equal(picker.hidden, false);
    assert.equal(trigger.getAttribute('aria-controls'), picker.id);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(window.document.activeElement.dataset.rubricGradeChoice, 'partial');
    assert.equal(rows[0].querySelectorAll('[aria-pressed="true"]').length, 1);
    form.dataset.reviewSaving = '1';
    const savingState = plain(rubric.readReview(form));
    const choices = [rows[0].querySelector('[data-rubric-grade-choice="excellent"]'), rows[0].querySelector('[data-rubric-grade-clear]'), trigger];
    choices.forEach(button => button.click());
    window.document.activeElement.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(picker.hidden, false, 'A pending save cannot toggle the adjustment panel.');
    assert.deepEqual(plain(rubric.readReview(form)), savingState, 'Saving flag alone blocks grade and clear mutations.');
    form.querySelectorAll('button').forEach(button => { button.disabled = true; });
    choices.forEach(button => { button.click(); button.dispatchEvent(new window.MouseEvent('click', { bubbles: true })); });
    assert.deepEqual(plain(rubric.readReview(form)), savingState, 'Native and programmatically dispatched clicks respect disabled controls.');
    form.querySelectorAll('button').forEach(button => { button.disabled = false; });
    delete form.dataset.reviewSaving;
    rows[0].querySelector('[data-rubric-grade-choice="excellent"]').click();
    assert.equal(rows[0].querySelector('[data-rubric-level]').value, 'excellent');
    assert.equal(rows[0].querySelector('[data-rubric-score]').value, '40');
    assert.equal(rows[0].querySelector('[data-rubric-grade-score]').textContent, '40 / 40점');
    assert.match(rows[0].querySelector('[data-rubric-selected-level]').textContent, /결측률/);
    assert.match(form.querySelector('[data-rubric-review-total]').textContent, /70 \/ 100점/);
    assert.equal(rows[0].querySelector('[data-rubric-feedback]').value, '기존 데이터 피드백');
    assert.equal(window.document.activeElement, trigger);
    assert.equal(picker.hidden, true);
    assert.equal(saves, 0, 'Clicking a grade does not save or publish.');
    trigger.click();
    assert.equal(window.document.activeElement.dataset.rubricGradeChoice, 'excellent');
    window.document.activeElement.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(picker.hidden, true); assert.equal(window.document.activeElement, trigger);
    trigger.click(); rows[0].querySelector('[data-rubric-grade-clear]').click();
    assert.equal(rows[0].querySelector('[data-rubric-level]').value, '');
    assert.equal(rows[0].querySelector('[data-rubric-score]').value, '');
    assert.equal(rows[0].querySelector('[data-rubric-selected-level]').hidden, true);
    assert.throws(() => rubric.readReview(form), /모든 항목의 등급/);
    rows[1].querySelector('[data-rubric-grade-adjust]').click(); rows[1].querySelector('[data-rubric-grade-clear]').click();
    assert(rubric.readReview(form).criteriaScores.every(item => item.score === null));
    assert.equal(saves, 0);

    window.gradeTest.applyAiReviewToForm(form, { ...saved, status: 'draft', version: 4, details: { rubricSnapshot: definition, criteriaScores: [
        { id: 'data', levelId: 'excellent', score: 40, feedback: 'AI 제안 근거' },
        { id: 'runs', levelId: 'missing', score: 0, feedback: '비교 내용 확인 필요' },
    ] } });
    assert.equal(rows[0].querySelector('[data-rubric-grade-score]').textContent, '40 / 40점');
    assert.equal(rows[1].querySelector('[data-rubric-grade-score]').textContent, '0 / 60점');
    assert.equal(rows[1].querySelector('[data-rubric-grade-choice="missing"]').getAttribute('aria-pressed'), 'true');
    assert.equal(form.elements.expectedVersion.value, '4');
    assert.equal(rows[0].querySelector('[data-rubric-feedback]').value, 'AI 제안 근거');
    assert.equal(saves, 0, 'Applying an already-persisted AI response to the form does not submit again.');
    rows[1].querySelector('[data-rubric-grade-adjust]').click(); rows[1].querySelector('[data-rubric-grade-choice="partial"]').click();
    const adjusted = plain(rubric.readReview(form));
    assert.equal(adjusted.criteriaScores[1].score, 30);
    assert.equal(adjusted.criteriaScores[1].feedback, '비교 내용 확인 필요');
    form.requestSubmit(); assert.equal(saves, 1);
    form = mount({ ...saved, details: { rubricSnapshot: definition, ...adjusted } });
    assert.equal(form.querySelectorAll('[data-rubric-grade-score]')[1].textContent, '30 / 60점', 'Reloaded saved adjustment displays the chosen grade and points.');
    assert.deepEqual(saved, original, 'All local operations leave prior snapshots immutable.');
    const ids = [...form.querySelectorAll('[data-rubric-grade-picker]')].map(node => node.id);
    assert.equal(new Set(ids).size, ids.length);
    form.querySelector('[data-rubric-review]').disabled = true;
    form.querySelector('[data-rubric-grade-adjust]').click();
    assert.equal(form.querySelector('[data-rubric-grade-picker]').hidden, true);

    const student = window.document.createElement('section'); student.innerHTML = rubric.releasedHtml(saved);
    assert.equal(student.querySelector('button, select, input, textarea'), null, 'Released student grades remain read-only.');
    assert.match(student.textContent, /선택 등급: 일부 충족/);
    form = mount({ details: { rubricSnapshot: { version: 1, criteria: [{ id: 'old', title: '이전 방식', description: '', maxScore: 10 }] }, criteriaScores: [{ id: 'old', score: 7.5, feedback: '유지' }] } });
    assert.equal(form.querySelector('[data-rubric-score]').type, 'number');
    assert.equal(form.querySelector('[data-rubric-grade-adjust]'), null);
    assert.equal(rubric.readReview(form).criteriaScores[0].score, 7.5);
    const attack = '<img src=x onerror="pwn()">';
    const markup = rubric.reviewFields({ version: 2, criteria: [{ ...definition.criteria[0], title: attack, levels: [{ id: 'safe', label: attack, description: attack, score: 40 }] }] }, null);
    assert.doesNotMatch(markup, /<img|onerror="/); assert.match(markup, /&lt;img/);
    console.log('Grade adjustment DOM regression passed: stored/AI grades, click changes, totals, clear, focus, snapshot preservation, explicit save and legacy/student boundaries.');
} finally { dom.window.close(); }

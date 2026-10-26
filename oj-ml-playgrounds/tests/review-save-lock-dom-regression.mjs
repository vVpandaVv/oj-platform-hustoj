import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the installed local frontend test runtime.');
const { JSDOM } = createRequire(path.join(frontend, 'package.json'))('jsdom');
const addon = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8'), closing = addon.lastIndexOf('}());');
const rubricSource = readFileSync(new URL('../resources/js/rubric-tools.js', import.meta.url), 'utf8');
const plain = value => JSON.parse(JSON.stringify(value));
const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await new Promise(resolve => setImmediate(resolve)); };
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }

for (const version of [0, 1, 2]) {
    const dom = new JSDOM('<!doctype html><main class="oj-ml-app"></main>', { url: 'https://fixture.example.test/results?assignment_id=4', runScripts: 'outside-only' });
    const w = dom.window;
    try {
        w.fetch = () => assert.fail('This test only uses local API doubles.');
        w.eval(rubricSource);
        w.eval(addon.slice(0, closing) + 'window.reviewSaveTest = { renderConsoleResults };\n' + addon.slice(closing));
        const root = w.document.querySelector('main'); Object.assign(root.dataset, { assignmentId: '4', canReview: '1', canManage: '0' });
        const definition = version ? { version, criteria: [{ id: 'data', title: '데이터 이해', description: '선택 이유', maxScore: 10,
            ...(version === 2 ? { levels: [{ id: 'high', label: '충분', score: 10, description: '근거가 충분함' }, { id: 'low', label: '일부', score: 5, description: '일부 근거' }] } : {}) }] } : null;
        let review = { version: 3, status: 'draft', score: 5, maxScore: 10, feedback: '기존 총평',
            ...(version ? { details: { rubricSnapshot: definition, criteriaScores: [{ id: 'data', score: 5, feedback: '기존 항목 의견', ...(version === 2 ? { levelId: 'low' } : {}) }] } } : {}) };
        const calls = [], pending = [];
        let heldRefresh = null, holdNextRefresh = false;
        const result = () => ({ items: [{ userId: 22, displayName: '합성 학생', canEvaluate: true, attemptsUsed: 1,
            attempt: { id: 'attempt-lock', status: 'accepted', attemptNo: 1, rubric: definition, review: plain(review) } }], total: 1, assignment: { id: 4, status: 'published', maxAttempts: 3 } });
        const api = {
            async get(route) {
                if (route === 'console/report-assignment-options') return { items: [{ id: 4, title: '평가 잠금 시험' }], total: 1 };
                if (route.startsWith('console/assignments/4/results?')) {
                    if (holdNextRefresh) { holdNextRefresh = false; heldRefresh = deferred(); return heldRefresh.promise; }
                    return result();
                }
                assert.fail('Unexpected GET: ' + route);
            },
            async post(route, payload) { assert.match(route, /\/(review|release)$/); const item = deferred(); calls.push({ route, payload: plain(payload) }); pending.push(item); return item.promise; },
        };
        await w.reviewSaveTest.renderConsoleResults(root, api);
        let form = root.querySelector('[data-oj-ml-review-form]');
        form.elements.feedback.value = '저장할 총평';
        if (version) { form.querySelector('[data-rubric-feedback]').value = '저장할 항목 의견'; }
        if (version === 0) form.elements.score.value = '7';
        if (version === 1) form.querySelector('[data-rubric-score]').value = '7';
        form.insertAdjacentHTML('beforeend', '<input data-disabled-sentinel disabled value="keep"><select data-extra-select><option value="keep" selected>유지</option></select>');
        form.setAttribute('aria-busy', 'false');
        const initial = Array.from(form.querySelectorAll('input, textarea, select, button')).map(control => ({ control, disabled: control.disabled, value: control.value }));
        const submit = action => form.dispatchEvent(new w.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: form.querySelector('[data-review-action="' + action + '"]') }));
        submit('save'); await settle();
        assert.equal(calls.length, 1);
        assert.equal(form.dataset.reviewSaving, '1'); assert.equal(form.getAttribute('aria-busy'), 'true');
        assert(initial.every(({ control }) => control.disabled && control.matches(':disabled')), 'Every editable field is disabled while saving v' + version);
        assert.equal(calls[0].payload.feedback, '저장할 총평', 'Payload is captured before locking controls.');
        assert.equal(calls[0].payload.expectedVersion, 3);
        if (version) assert.equal(calls[0].payload.criteriaScores[0].feedback, '저장할 항목 의견');
        if (version === 1) assert.equal(calls[0].payload.criteriaScores[0].score, 7);
        if (version === 0) assert.equal(calls[0].payload.score, 7);
        submit('save'); submit('release'); await settle();
        assert.equal(calls.length, 1, 'Even synthetic duplicate submits are ignored.');
        pending.shift().reject(Object.assign(new Error('버전 충돌 시험'), { code: 'OJ_ML_REVIEW_VERSION_CONFLICT' })); await settle();
        assert.equal(form.dataset.reviewSaving, undefined); assert.equal(form.getAttribute('aria-busy'), 'false');
        for (const prior of initial) { assert.equal(prior.control.disabled, prior.disabled); assert.equal(prior.control.value, prior.value); }
        assert.equal(root.querySelector('[data-oj-ml-review-form]'), form, 'Failed saving retains the same form and values.');
        assert.match(root.querySelector('[data-oj-ml-status]').textContent, /입력한 점수와 피드백은 유지/);

        holdNextRefresh = true; submit('save'); await settle();
        const savedPayload = calls[1].payload;
        review = { ...review, version: 4, feedback: savedPayload.feedback,
            ...(version ? { details: { rubricSnapshot: definition, criteriaScores: savedPayload.criteriaScores } } : { score: savedPayload.score, maxScore: savedPayload.maxScore }) };
        pending.shift().resolve({ review: plain(review) }); await settle();
        assert(heldRefresh, 'The actual save handler refreshes results after persistence.');
        assert.equal(form.dataset.reviewSaving, '1');
        assert(initial.every(({ control }) => control.disabled), 'Controls remain locked through the pending refresh.');
        heldRefresh.resolve(result()); await settle();
        assert.equal(form.isConnected, false);
        assert.equal(form.dataset.reviewSaving, undefined);
        assert.equal(form.getAttribute('aria-busy'), 'false');
        assert(initial.every(prior => prior.control.disabled === prior.disabled), 'Original disabled states are restored even for the replaced form.');
        form = root.querySelector('[data-oj-ml-review-form]');
        assert.equal(form.elements.feedback.value, '저장할 총평');
        assert.equal(form.elements.feedback.disabled, false);
        assert.equal(form.elements.expectedVersion.value, '4');
        if (version) assert.equal(form.querySelector('[data-rubric-feedback]').value, '저장할 항목 의견');

        // A save can succeed before the separate release request fails; retain the new version and unlock exactly.
        submit('release'); await settle();
        pending.shift().resolve({ review: { ...plain(review), version: 5 } }); await settle();
        assert(calls.at(-1).route.endsWith('/release'));
        assert.equal(form.elements.expectedVersion.value, '5');
        assert.equal(form.elements.feedback.disabled, true);
        pending.shift().reject(new Error('공개 연결 실패')); await settle();
        assert.equal(form.dataset.reviewSaving, undefined);
        assert.equal(form.hasAttribute('aria-busy'), false);
        assert.equal(form.elements.feedback.disabled, false);
        assert.equal(form.elements.feedback.value, '저장할 총평');
        assert.equal(form.elements.expectedVersion.value, '5', 'Retry uses the already-saved version instead of stale state.');
    } finally { dom.window.close(); }
}
console.log('Review save lock DOM regression passed: real result binding, total/v1/v2 controls, duplicate guard, failure restoration, success refresh and partial release failure.');

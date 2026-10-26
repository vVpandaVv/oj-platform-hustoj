import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/js/addon.js', import.meta.url), 'utf8');
const browser = { window: { OJP: { confirm: async () => true } }, document: { addEventListener() {} }, Event, TextEncoder };
const closing = source.lastIndexOf('}());');
assert.ok(closing > 0);
vm.runInNewContext(source.slice(0, closing) + 'window.aiTest = { aiReviewCodeMessage, aiReviewErrorMessage, aiReviewBodyHtml, applyAiReviewToForm, loadAiReview, bindAiReview, resultDetailsRowHtml };\n' + source.slice(closing), browser);
const ui = browser.window.aiTest;
const plain = value => JSON.parse(JSON.stringify(value));
const result = { id: 'evaluation-1', state: 'ready', inputHash: 'input-hash', sourceSha256: 'original-hash', score: 8, maxScore: 10,
    criteriaScores: [{ id: 'process', title: '실험 과정', score: 8, feedback: '모델 비교가 적절합니다.', evidence: ['실험 1', '답변 2'] }], feedback: '다음 실험을 제안합니다.', limitations: ['서버에서 재실행하지 않았습니다.'], createdAt: '2026-10-14T00:00:00Z' };

const initial = ui.aiReviewBodyHtml({ available: true, consentFingerprint: 'routing-1', result: null });
assert.match(initial, /data-oj-ml-ai-consent/);
assert.doesNotMatch(initial, /checked/);
assert.match(initial, /원본 데이터셋·모델 파일은 전송하거나 실행하지 않습니다/);
assert.match(initial, /data-oj-ml-ai-action="enqueue"/);
const unavailable = ui.aiReviewBodyHtml({ available: false });
assert.match(unavailable, /기존 교사 평가는 계속/);
assert.doesNotMatch(unavailable, /data-oj-ml-ai-action="enqueue"|data-oj-ml-ai-action="apply"/);
for (const code of ['OJ_ML_AI_UNAVAILABLE', 'OJ_ML_AI_RUBRIC_REQUIRED', 'ml_ai_routing_required', 'ml_ai_source_unavailable', 'ml_ai_consent_required', 'ml_ai_enqueue_failed']) {
    assert.ok(ui.aiReviewCodeMessage(code));
    assert.match(ui.aiReviewBodyHtml({ available: false, code }), new RegExp(ui.aiReviewCodeMessage(code).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
}
assert.equal(ui.aiReviewCodeMessage('constructor'), '', 'Unknown/prototype property codes must not resolve as message objects.');
const safeError = ui.aiReviewErrorMessage({ code: 'OJ_ML_REVIEW_VERSION_CONFLICT', message: 'private-provider-output', correlationId: 'request-123' });
assert.match(safeError, /다른 곳에서 교사 평가가 변경/);
assert.match(safeError, /request-123/);
assert.doesNotMatch(safeError, /private-provider-output/);
assert.match(ui.aiReviewErrorMessage({ code: 'OJ_ML_AI_ALREADY_APPLIED' }), /학생에게 공개/);
assert.doesNotMatch(ui.aiReviewErrorMessage({ code: 'unknown_code', message: 'private-server-path' }), /private-server-path/);
for (const state of ['queued', 'running', 'needs_review']) {
    const html = ui.aiReviewBodyHtml({ available: true, result: { ...result, state } });
    assert.doesNotMatch(html, /data-oj-ml-ai-action="apply"|data-oj-ml-ai-action="enqueue"/);
    assert.match(html, /data-oj-ml-ai-action="status"/);
}
assert.match(ui.aiReviewBodyHtml({ available: true, result }), /비공개 교사 초안에 반영/);
const levelResult = ui.aiReviewBodyHtml({ available: true, result: { ...result, criteriaScores: [
    { ...result.criteriaScores[0], levelId: 'good', levelLabel: '보통', levelDescription: '비교는 있으나 실험 조건의 설명이 부족함' },
] } });
assert.match(levelResult, /보통/);
assert.match(levelResult, /선택 등급 기준/);
assert.match(levelResult, /실험 조건의 설명이 부족함/);
const invalidLevel = ui.aiReviewBodyHtml({ available: true, result: { ...result, state: 'needs_review', errorCode: 'OJ_ML_AI_RUBRIC_LEVEL_MISMATCH' } });
assert.match(invalidLevel, /루브릭에 없는 점수/);
assert.doesNotMatch(invalidLevel, /data-oj-ml-ai-action="apply"/);
assert.match(ui.aiReviewBodyHtml({ available: true, result: { ...result, state: 'failed' } }), /학생의 0점을 의미하지 않습니다/);
const missingResult = ui.aiReviewBodyHtml({ available: true, result: { ...result, state: 'unavailable' } });
assert.match(missingResult, /AI 결과 조회 불가/);
assert.match(missingResult, /data-oj-ml-ai-action="enqueue"/);
assert.match(missingResult, /data-oj-ml-ai-consent/);
assert.match(missingResult, /사용 요금이 다시 발생/);
assert.match(missingResult, /기존 교사 평가는 유지/);
assert.doesNotMatch(missingResult, /data-oj-ml-ai-action="apply"|checked/);
const interrupted = ui.aiReviewBodyHtml({ available: true, result: { ...result, state: 'failed', errorCode: 'ml_ai_interrupted' } });
assert.match(interrupted, /이미 발생한 사용 요금/);
assert.match(interrupted, /재요청 시 AI 사용 요금이 다시/);
const attack = '<img src=x onerror="pwned()"><script>pwned()</script>';
const escaped = ui.aiReviewBodyHtml({ available: true, result: { ...result, id: attack, feedback: attack,
    criteriaScores: [{ id: attack, score: 8, feedback: attack, evidence: [attack] }], limitations: [attack] } });
assert.doesNotMatch(escaped, /<img|<script/);
assert.match(escaped, /&lt;img/);

const attempt = { id: 'attempt-1', status: 'accepted', attemptNo: 1, artifact: null, evaluation: null, review: null };
assert.match(ui.resultDetailsRowHtml({ attempt, canEvaluate: true }, true), /data-oj-ml-ai-review/);
for (const [mayReview, canEvaluate] of [[false, true], [true, false], [false, false]]) {
    assert.doesNotMatch(ui.resultDetailsRowHtml({ attempt, canEvaluate }, mayReview), /data-oj-ml-ai-review/);
}
for (const status of ['quarantined', 'needs_resubmit', 'system_pending']) {
    assert.doesNotMatch(ui.resultDetailsRowHtml({ attempt: { ...attempt, status }, canEvaluate: true }, true), /data-oj-ml-ai-review/);
}

function fixture() {
    const events = [];
    const note = { textContent: '' }, body = { innerHTML: 'initial body' }, consent = { checked: false };
    const buttons = ['status', 'enqueue', 'apply'].map(action => ({ dataset: { ojMlAiAction: action }, disabled: false }));
    const rowScore = { value: '4', disabled: false }, rowFeedback = { value: '교사가 쓰던 항목 피드백', disabled: false };
    const row = { dataset: { criterionId: 'process' }, querySelector(selector) {
        return selector === '[data-rubric-score]' ? rowScore : selector === '[data-rubric-feedback]' ? rowFeedback : null;
    } };
    const elements = { expectedVersion: { value: '3', disabled: false }, feedback: { value: '교사가 쓰던 총평', disabled: false } };
    const version = { textContent: 'version 3' }, rubricArea = { dispatchEvent(event) { events.push(event.type); } };
    const form = {
        dataset: {}, elements,
        querySelector(selector) { return selector === '[data-rubric-review]' ? rubricArea : selector === '[data-oj-ml-review-version]' ? version : null; },
        querySelectorAll(selector) { return selector === '[data-rubric-score-item]' ? [row] : [...Object.values(elements), rowScore, rowFeedback]; }
    };
    const holder = {
        dataset: { attemptId: 'attempt-1' }, isConnected: true,
        parentElement: { querySelector() { return form; } },
        querySelector(selector) { return ({ '[data-oj-ml-ai-status]': note, '[data-oj-ml-ai-body]': body, '[data-oj-ml-ai-consent]': consent })[selector]; },
        querySelectorAll() { return buttons; }, contains(node) { return buttons.includes(node); },
        addEventListener(type, handler) { this.handler = handler; }
    };
    return { holder, note, body, consent, buttons, form, rowScore, rowFeedback, version, events };
}

const fx = fixture();
const requests = [];
let response = { available: true, code: 'ready', consentFingerprint: 'routing-1', result: null, reviewVersion: 9 };
const api = { async post(path, payload) { requests.push({ path, payload: plain(payload) }); return response; } };
await ui.loadAiReview(fx.holder, api, 'status');
assert.equal(fx.form.elements.feedback.value, '교사가 쓰던 총평');
assert.equal(fx.form.elements.expectedVersion.value, '3', 'Status reads must not silently adopt newer concurrent review versions.');
assert.equal(fx.rowScore.value, '4');
assert.equal(requests[0].path, 'console/attempts/attempt-1/ai-review');
await ui.loadAiReview(fx.holder, api, 'enqueue');
assert.equal(requests.length, 1, 'Unchecked consent must not enqueue.');
fx.consent.checked = true;
response = { ...response, result: { ...result, state: 'queued' } };
await ui.loadAiReview(fx.holder, api, 'enqueue');
assert.deepEqual(requests[1].payload, { action: 'enqueue', consent: { approved: true, routingFingerprint: 'routing-1' } });
assert.equal(fx.form.elements.feedback.value, '교사가 쓰던 총평');
await ui.loadAiReview(fx.holder, api, 'apply');
assert.equal(requests.length, 2, 'Queued jobs cannot be applied.');

const rejectedEnqueue = fixture();
rejectedEnqueue.holder.ojMlAiData = { available: true, consentFingerprint: 'routing-old', result: null };
rejectedEnqueue.consent.checked = true;
await ui.loadAiReview(rejectedEnqueue.holder, { async post() { return { available: false, code: 'ml_ai_consent_required', result: null }; } }, 'enqueue');
assert.match(rejectedEnqueue.note.textContent, /접수를 확인하지 못했습니다/);
assert.match(rejectedEnqueue.note.textContent, /다시 동의하세요/);
assert.doesNotMatch(rejectedEnqueue.note.textContent, /AI 평가를 요청했습니다/);
assert.equal(rejectedEnqueue.form.elements.feedback.value, '교사가 쓰던 총평');

const expiredResult = fixture();
expiredResult.holder.ojMlAiData = { available: true, consentFingerprint: 'routing-new', result: { ...result, state: 'unavailable' } };
let expiredRequests = 0;
const regenerate = { async post(path, payload) {
    expiredRequests++;
    assert.deepEqual(plain(payload), { action: 'enqueue', consent: { approved: true, routingFingerprint: 'routing-new' } });
    return { available: true, result: { ...result, id: 'regenerated', state: 'queued' } };
} };
await ui.loadAiReview(expiredResult.holder, regenerate, 'enqueue');
assert.equal(expiredRequests, 0, 'Regeneration still requires a fresh explicit consent selection.');
expiredResult.consent.checked = true;
await ui.loadAiReview(expiredResult.holder, regenerate, 'enqueue');
assert.equal(expiredRequests, 1);
assert.equal(expiredResult.form.elements.feedback.value, '교사가 쓰던 총평');
assert.equal(expiredResult.form.elements.expectedVersion.value, '3');

const incomplete = fixture();
await ui.loadAiReview(incomplete.holder, { async post() { throw Object.assign(new Error('internal details'), { code: 'OJ_ML_AI_EVIDENCE_INCOMPLETE', correlationId: 'trace-1' }); } }, 'status');
assert.match(incomplete.note.textContent, /생략된 내용/);
assert.match(incomplete.note.textContent, /trace-1/);
assert.doesNotMatch(incomplete.note.textContent, /internal details/);
assert.equal(incomplete.form.elements.feedback.value, '교사가 쓰던 총평');

response = { ...response, result };
await ui.loadAiReview(fx.holder, api, 'status');
const unchangedBody = fx.body.innerHTML;
const saved = { version: 4, status: 'draft', score: 8, maxScore: 10, feedback: 'AI 제안 총평', details: { criteriaScores: [{ id: 'process', score: 8, feedback: 'AI 항목 의견' }] } };
response = { review: saved };
await ui.loadAiReview(fx.holder, api, 'apply');
assert.deepEqual(requests.at(-1).payload, { action: 'apply', evaluationId: 'evaluation-1', inputHash: 'input-hash', expectedVersion: 3 });
assert.equal(fx.form.elements.expectedVersion.value, '4');
assert.equal(fx.form.elements.feedback.value, 'AI 제안 총평');
assert.equal(fx.rowScore.value, '8');
assert.equal(fx.rowFeedback.value, 'AI 항목 의견');
assert.deepEqual(fx.events, ['input']);
assert.equal(fx.body.innerHTML, unchangedBody, 'Applying updates form fields without replacing the workbench or AI evidence.');
assert.match(fx.note.textContent, /비공개/);
assert.equal(fx.buttons[2].disabled, true, 'Repeated apply should be prevented within the loaded workbench.');
assert.equal(fx.form.elements.feedback.disabled, false);
const countAfterApply = requests.length;
await ui.loadAiReview(fx.holder, api, 'apply');
assert.equal(requests.length, countAfterApply, 'A repeated direct apply action must also be guarded.');

const broken = fixture();
broken.holder.ojMlAiData = { available: true, result };
await ui.loadAiReview(broken.holder, { async post() { throw new Error('버전 충돌'); } }, 'apply');
assert.equal(broken.form.elements.feedback.value, '교사가 쓰던 총평');
assert.equal(broken.form.elements.expectedVersion.value, '3');
assert.equal(broken.rowScore.value, '4');
assert.equal(broken.form.elements.feedback.disabled, false);
assert.match(broken.note.textContent, /기존 교사 평가 입력은 유지/);
const denied = fixture();
denied.holder.ojMlAiData = { available: true, result };
browser.window.OJP.confirm = async () => false;
await ui.loadAiReview(denied.holder, { async post() { throw new Error('Cancellation must not call API.'); } }, 'apply');
assert.equal(denied.form.elements.feedback.value, '교사가 쓰던 총평');
browser.window.OJP.confirm = async () => true;

const binding = fixture();
ui.bindAiReview(binding.holder, api);
const handler = binding.holder.handler;
ui.bindAiReview(binding.holder, api);
assert.equal(binding.holder.handler, handler, 'Binding twice must not attach another handler.');
binding.holder.dataset.aiBusy = '1';
const countBefore = requests.length;
await ui.loadAiReview(binding.holder, api, 'status');
assert.equal(requests.length, countBefore, 'An in-flight request must block duplicate requests.');
assert.doesNotMatch(source.slice(source.indexOf('\tfunction aiReviewBodyHtml'), source.indexOf('\tfunction resultDetailsRowHtml')), /setInterval|setTimeout|await refresh\(|\.reset\(/, 'AI state queries must not poll or replace the review workbench.');

// Render and bind the actual result workbench: manual reading/editing never needs the optional AI service.
const frontend = process.env.OJ_ML_UPSTREAM_FRONTEND;
assert(frontend, 'Set OJ_ML_UPSTREAM_FRONTEND to the installed local frontend test runtime.');
const { JSDOM } = createRequire(path.join(frontend, 'package.json'))('jsdom');
const dom = new JSDOM('<!doctype html><body><main id="result-root"></main></body>', {
    runScripts: 'outside-only', url: 'https://school.example/results?assignment_id=4',
});
const flushDom = () => new Promise(resolve => setTimeout(resolve, 5));
try {
    const w = dom.window;
    w.eval(source.slice(0, closing) + 'window.resultTest = { renderConsoleResults, bindAiReview, applyAiReviewToForm };\n' + source.slice(closing));
    const root = w.document.querySelector('main');
    Object.assign(root.dataset, { assignmentId: '4', canReview: '1', canManage: '0' });
    const domRequests = [];
    let resolveAi;
    let failAi = false;
    const domAttempt = { ...attempt, artifact: { originalName: 'student.mlpx', bytes: 1024, sha256: 'verified-hash' },
        receivedAt: '2026-10-15T00:00:00Z', review: { version: 3, status: 'draft', score: 4, maxScore: 10, feedback: '교사 초안' } };
    const domApi = {
        async get(endpoint) {
            domRequests.push({ method: 'GET', endpoint });
            if (endpoint === 'console/report-assignment-options') return { items: [{ id: 4, title: '실험 과제' }], total: 1 };
            if (endpoint.startsWith('console/assignments/4/results?')) return { items: [
                { userId: 22, displayName: '학생', canEvaluate: true, attemptsUsed: 1, attempt: domAttempt },
            ], total: 1, assignment: { id: 4, status: 'published', maxAttempts: 3 } };
            throw new Error('Unexpected GET ' + endpoint);
        },
        async post(endpoint, payload) {
            domRequests.push({ method: 'POST', endpoint, payload: plain(payload) });
            if (endpoint.endsWith('/preview')) return { content: { project: {}, settings: {}, experiments: [], runs: [], notices: [] } };
            if (endpoint.endsWith('/ai-review')) {
                if (failAi) throw Object.assign(new Error('private-server-detail'), { code: 'OJ_ML_AI_UNAVAILABLE' });
                return await new Promise(resolve => { resolveAi = resolve; });
            }
            throw new Error('Unexpected POST ' + endpoint);
        },
    };
    const aiCalls = () => domRequests.filter(call => call.endpoint.endsWith('/ai-review'));
    await w.resultTest.renderConsoleResults(root, domApi);
    const outer = root.querySelector('[data-oj-ml-result-details]');
    const aiHolder = root.querySelector('[data-oj-ml-ai-review]');
    const manual = root.querySelector('[data-oj-ml-review-form]');
    assert(outer && aiHolder && manual);
    assert.equal(aiHolder.open, false);
    assert.equal(aiCalls().length, 0, 'Rendering submissions must not contact the optional AI integration.');
    outer.open = true;
    outer.dispatchEvent(new w.Event('toggle'));
    await flushDom();
    assert.equal(domRequests.filter(call => call.endpoint.endsWith('/preview')).length, 1,
        'Submission reading should load only its verified preview once.');
    assert.equal(aiCalls().length, 0, 'Opening a submission must not query AI status.');
    assert.equal(manual.elements.feedback.value, '교사 초안');
    manual.elements.feedback.value = '계속 작성하던 수동 평가';
    manual.elements.score.value = '6';
    const manualState = () => [manual.elements.feedback.value, manual.elements.score.value,
        manual.elements.expectedVersion.value, manual.elements.feedback.disabled];
    const preservedState = manualState();
    const nested = aiHolder.querySelector('details');
    assert(nested, 'The optional data-scope explainer is a nested disclosure.');
    nested.dispatchEvent(new w.Event('toggle', { bubbles: true }));
    await flushDom();
    assert.equal(aiCalls().length, 0, 'A nested scope disclosure must not trigger the parent AI connection check.');
    w.resultTest.bindAiReview(aiHolder, domApi);
    w.resultTest.bindAiReview(aiHolder, domApi);
    aiHolder.open = true;
    aiHolder.dispatchEvent(new w.Event('toggle'));
    aiHolder.dispatchEvent(new w.Event('toggle'));
    nested.dispatchEvent(new w.Event('toggle', { bubbles: true }));
    await flushDom();
    assert.equal(aiCalls().length, 1, 'Only one direct open request is allowed while status is in flight, even after repeat binding.');
    assert.deepEqual(aiCalls()[0].payload, { action: 'status' }, 'Opening optional AI never starts paid analysis.');
    assert.deepEqual(manualState(), preservedState, 'AI status in flight must not disable or change manual input.');
    resolveAi({ available: false, code: 'OJ_ML_AI_UNAVAILABLE', result: null });
    await flushDom();
    assert.match(aiHolder.textContent, /기존 교사 평가는 계속/);
    assert.equal(aiHolder.dataset.aiLoaded, '1');
    assert.deepEqual(manualState(), preservedState);
    assert.equal(root.querySelector('[data-oj-ml-review-form]'), manual, 'An absent AI plugin must not replace the manual form.');
    aiHolder.open = false;
    aiHolder.dispatchEvent(new w.Event('toggle'));
    aiHolder.open = true;
    aiHolder.dispatchEvent(new w.Event('toggle'));
    nested.dispatchEvent(new w.Event('toggle', { bubbles: true }));
    await flushDom();
    assert.equal(aiCalls().length, 1, 'Reopening loaded optional details must not issue repeated status requests.');
    failAi = true;
    aiHolder.querySelector('[data-oj-ml-ai-action="status"]').click();
    await flushDom();
    assert.equal(aiCalls().length, 2, 'A user may explicitly retry status after the optional service is unavailable.');
    assert.deepEqual(manualState(), preservedState, 'An optional AI connection error must preserve editable manual evaluation.');
    assert.match(aiHolder.querySelector('[data-oj-ml-ai-status]').textContent, /기존 교사 평가 입력은 유지/);
    assert.doesNotMatch(aiHolder.textContent, /private-server-detail/);
    assert.equal(manual.querySelector('button[type="submit"]').disabled, false);
    assert.equal(aiHolder.querySelector('[data-oj-ml-ai-action="status"]').disabled, false);

    // AI applies the grade identifier as well as its exact score; no implicit nearest-grade conversion.
    const levelForm = w.document.createElement('form');
    levelForm.innerHTML = '<fieldset data-rubric-review><div data-rubric-score-item data-criterion-id="process">'
        + '<select data-rubric-level><option value="">선택 안 함</option><option value="good">보통 · 8점</option><option value="low">미흡 · 0점</option></select>'
        + '<input type="hidden" data-rubric-score value="0"><textarea data-rubric-feedback>작성 중 의견</textarea></div></fieldset>'
        + '<textarea name="feedback">작성 중 총평</textarea><input name="expectedVersion" value="2"><p data-oj-ml-review-version></p>';
    w.document.body.append(levelForm);
    let levelEvents = 0;
    levelForm.addEventListener('input', () => levelEvents++);
    w.resultTest.applyAiReviewToForm(levelForm, { ...saved, details: { criteriaScores: [
        { id: 'process', levelId: 'good', score: 8, feedback: '등급에 맞춘 의견' },
    ] } });
    assert.equal(levelForm.querySelector('[data-rubric-level]').value, 'good');
    assert.equal(levelForm.querySelector('[data-rubric-score]').value, '8');
    assert.equal(levelForm.querySelector('[data-rubric-feedback]').value, '등급에 맞춘 의견');
    assert.equal(levelEvents, 1);
    const beforeInvalid = [levelForm.elements.expectedVersion.value, levelForm.elements.feedback.value,
        levelForm.querySelector('[data-rubric-level]').value, levelForm.querySelector('[data-rubric-feedback]').value];
    assert.throws(() => w.resultTest.applyAiReviewToForm(levelForm, { ...saved, version: 9, details: { criteriaScores: [
        { id: 'process', levelId: 'invented', score: 7, feedback: '잘못된 덮어쓰기' },
    ] } }), /선택 등급이 현재 루브릭과 다릅니다/);
    assert.deepEqual([levelForm.elements.expectedVersion.value, levelForm.elements.feedback.value,
        levelForm.querySelector('[data-rubric-level]').value, levelForm.querySelector('[data-rubric-feedback]').value], beforeInvalid);
} finally {
    dom.window.close();
}
console.log('OJ ML AI review UI regression passed.');

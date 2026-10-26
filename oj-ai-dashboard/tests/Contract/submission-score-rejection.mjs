import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/submission-evaluation-review-workbench.js', import.meta.url), 'utf8');
const window = {};
vm.runInNewContext(source, { window, document: { querySelector: () => null }, URLSearchParams });
const adapter = window.OJAISubmissionEvaluationReviewWorkbench;
const { selectedScoreRejection, canRetryScore, retryScoreCommand, scorePending, scoreApproved } = adapter;
const copy = value => JSON.parse(JSON.stringify(value));
const rejection = { review_no: 1, result_id: 'result-A', decision: 'rejected', reason: 'Synthetic rejection <b>reason</b>' };
const detail = {
  evaluation: {
    id: 'evaluation-A', state: 'review_required',
    latest_run: { id: 'run-A', run_no: 1, state: 'succeeded' },
    candidate: { id: 'result-A' }, submission: { is_source_current: true },
  },
  selected_result: { id: 'result-A', items: [{ candidate_points: '7.0000' }] },
  reviews: [rejection], review_precondition: { expected_result_id: 'result-A' },
};
assert.equal(selectedScoreRejection(detail), rejection);
assert.equal(selectedScoreRejection({ ...detail, selected_result: { id: 'result-B' } }), null, 'Rejection must not follow a new AI result.');
assert.equal(selectedScoreRejection({ ...detail, reviews: [{ ...rejection, review_no: 2, decision: 'adjusted' }, rejection] }), null);
assert.equal(selectedScoreRejection(null), null);
assert.equal(canRetryScore(detail), true);
for (const [state, runState, allowed] of [
  ['reviewed', 'succeeded', true], ['failed', 'failed', true], ['cancelled', 'cancelled', true],
  ['queued', 'queued', false], ['running', 'running', false], ['review_required', 'queued', false],
]) {
  const candidate = copy(detail);
  candidate.evaluation.state = state; candidate.evaluation.latest_run.state = runState;
  assert.equal(canRetryScore(candidate), allowed);
}
const stale = copy(detail); stale.evaluation.submission.is_source_current = false;
assert.equal(canRetryScore(stale), false);
assert.throws(() => retryScoreCommand(stale, 'retry-1'), /평가 상태/);
assert.deepEqual(copy(retryScoreCommand(detail, 'retry-1')), {
  command_id: 'retry-1',
  expected: { expected_state: 'review_required', expected_run_id: 'run-A', expected_run_no: 1, expected_latest_result_id: 'result-A' },
  payload: { reason: '교사가 반려한 AI 점수의 재채점을 요청했습니다.' },
});
const historical = copy(detail); historical.selected_result.id = 'older-result';
assert.equal(retryScoreCommand(historical, 'retry-2').expected.expected_latest_result_id, 'result-A', 'CAS must use the latest candidate, not the historical selection.');

class Element {
  constructor(tag, className = '', text = '') { this.tag = tag; this.className = className; this.text = text; this.dataset = {}; this.children = []; this.disabled = false; }
  appendChild(child) { this.children.push(child); return child; }
  querySelector(tag) { return this.children.find(child => child.tag === tag) || this.children.map(child => child.querySelector(tag)).find(Boolean); }
}
const node = (...args) => new Element(...args);
const textOf = element => [element.text, ...element.children.map(textOf)].join(' ');
const buttons = element => [element, ...element.children.flatMap(buttons)].filter(element => element.tag === 'button');
const state = { detail: copy(detail), canAssess: true, canReview: true, submitting: false };
const runtime = vm.createContext({
  state, node, selectedScoreRejection, canRetryScore, scorePending, scoreApproved,
  append: (parent, ...children) => children.forEach(child => parent.appendChild(child)),
  section: title => node('section', '', title),
  button: (label, action, style) => { const value = node('button', style, label); value.dataset.reviewAction = action; return value; },
  latestRunForOperation: (detail, operation) => (detail.runs || []).find(run => run.operation === operation),
  runFailureMessage: adapter.runFailureMessage,
});
vm.runInContext(source.slice(source.indexOf('  function manualScore('), source.indexOf('  function latestFeedback('))
  + source.slice(source.indexOf('  function canAccept('), source.indexOf('  function renderReviewHistory('))
  + source.slice(source.indexOf('  function renderScoreStatus('), source.indexOf('  function renderDetail()')), runtime);
let status = vm.runInContext('renderScoreStatus(state.detail)', runtime);
assert.ok(textOf(status).includes('AI 점수 반려'));
assert.ok(textOf(status).includes(rejection.reason), 'The reason must remain text, never HTML.');
assert.equal(buttons(status)[0].text, 'AI 재채점');
assert.equal(buttons(status)[0].disabled, false);
let actions = vm.runInContext('reviewActions(state.detail)', runtime);
assert.equal(buttons(actions).find(button => button.dataset.reviewAction === 'reject').disabled, true);
assert.equal(buttons(actions).find(button => button.dataset.reviewAction === 'accept').disabled, true);
assert.equal(buttons(actions).find(button => button.dataset.reviewAction === 'adjust').disabled, false);
state.canAssess = false;
assert.equal(buttons(vm.runInContext('renderScoreStatus(state.detail)', runtime)).length, 0);
state.canAssess = true; state.submitting = true;
assert.equal(buttons(vm.runInContext('renderScoreStatus(state.detail)', runtime))[0].disabled, true);
state.submitting = false;
const held = copy(detail);
held.reviews = [];
held.selected_result.abstained = true;
state.detail = held;
status = vm.runInContext('renderScoreStatus(state.detail)', runtime);
assert.match(textOf(status), /AI 판단 보류/);
assert.equal(buttons(status)[0].text, 'AI 재채점', 'A held score can be retried without first rejecting it.');
state.detail = copy(detail);
state.detail.evaluation.state = 'queued'; state.detail.evaluation.latest_run.state = 'queued';
status = vm.runInContext('renderScoreStatus(state.detail)', runtime);
assert.ok(textOf(status).includes('서버 대기열'));
assert.equal(buttons(status).length, 0);
assert.equal(vm.runInContext('reviewActions(state.detail)', runtime), null);
state.detail.evaluation.state = 'failed'; state.detail.evaluation.latest_run.state = 'failed';
assert.ok(textOf(vm.runInContext('renderScoreStatus(state.detail)', runtime)).includes('평가 실패'), 'A failed regrade must stay retryable even with an older result.');
assert.equal(buttons(vm.runInContext('renderScoreStatus(state.detail)', runtime)).length, 1);
state.detail.evaluation.state = 'review_required'; state.detail.evaluation.latest_run.state = 'succeeded';
state.detail.selected_result.id = 'result-B'; state.detail.evaluation.candidate.id = 'result-B';
assert.equal(vm.runInContext('renderScoreStatus(state.detail)', runtime), null);
assert.equal(vm.runInContext('canAccept(state.detail)', runtime), true);
assert.equal(state.detail.reviews.length, 1, 'Old reviews must remain after a new result.');

// Exercise the actual retry handler: one explicit command, no input or history replacement.
const requests = [];
let batchButtonRefreshes = 0;
let finish;
const handlerState = { detail: copy(detail), canAssess: true, submitting: false, selectedType: 'evaluation' };
const handler = vm.createContext({
  state: handlerState, currentContext: () => ({ type: 'group', id: 'group:test', revision: 'rev-test' }),
  canRetryScore, canLeaveDetail: () => true, renderDetail: () => {}, showNotice: () => {},
  updateBatchButton: () => { batchButtonRefreshes += 1; },
  token: () => 'retry:test', scoreInputRefreshCommand: adapter.scoreInputRefreshCommand, retryScoreCommand,
  contextQuery: adapter.contextQuery, encodeURIComponent, fields: { state: { value: '' } },
  request: async (url, options) => { requests.push({ url, options }); return new Promise(resolve => { finish = resolve; }); },
  loadEvaluations: async () => { handlerState.detail.evaluation.state = 'queued'; handlerState.detail.evaluation.latest_run.state = 'queued'; },
  scorePoller: { start: () => requests.push({ polling: true }) },
});
vm.runInContext(source.slice(source.indexOf('  async function retryEvaluation()'), source.indexOf('  function revokeReviewPermission()')), handler);
const first = vm.runInContext('retryEvaluation()', handler);
await vm.runInContext('retryEvaluation()', handler);
assert.equal(requests.length, 1, 'Double clicks must not enqueue duplicate AI work.');
assert.match(requests[0].url, /\/submission-evaluations\/evaluation-A\/commands\/retry\?/);
assert.equal(requests[0].options.method, 'POST');
assert.equal(requests[0].options.idempotencyKey, requests[0].options.body.command_id);
assert.equal(requests[0].options.body.expected.expected_latest_result_id, 'result-A');
finish({ data: { evaluation: { id: 'evaluation-A' } } });
await first;
assert.equal(handlerState.detail.evaluation.state, 'queued');
assert.equal(batchButtonRefreshes, 1, 'Individual grading must restore bulk-button availability.');
assert.deepEqual(handlerState.detail.reviews, [rejection]);
assert.equal(requests.filter(request => request.polling).length, 1);
await vm.runInContext('retryEvaluation()', handler);
assert.equal(requests.filter(request => request.url).length, 1, 'A pending job must not be re-enqueued.');
const reviewSource = source.slice(source.indexOf('  async function submitReview('), source.indexOf('  function openRejectDialog('));
assert.doesNotMatch(reviewSource, /commands\/retry|retryEvaluation\(/, 'Rejecting alone must not transmit student work to AI.');
assert.match(reviewSource, /decision === 'rejected' && selectedScoreRejection\(detail\)/);
assert.match(source, /action === 'retry-evaluation'\) retryEvaluation\(\)/);
console.log('PASS score rejection: result-scoped status, reason, regrade action, no duplicate rejection, CAS request, permission/source/pending guards, double-click protection, retained history and resumed polling.');

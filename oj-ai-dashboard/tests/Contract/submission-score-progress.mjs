import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/submission-evaluation-review-workbench.js', import.meta.url), 'utf8');
const window = {};
vm.runInNewContext(source, { window, document: { querySelector: () => null }, URLSearchParams });
const { createScoreStatusPoller, scorePending, selectedScoreRejection, canRetryScore, scoreApproved } = window.OJAISubmissionEvaluationReviewWorkbench;
for (const state of ['requested', 'queued', 'running']) assert.equal(scorePending({ evaluation: { state } }), true);
for (const state of ['failed', 'cancelled', 'review_required', 'reviewed']) assert.equal(scorePending({ evaluation: { state } }), false);
assert.equal(scorePending(null), false);

function fixture() {
  let sequence = 0;
  const timers = new Map();
  const calls = [];
  const state = { visible: true, dirty: false, modal: false, submitting: false, detail: { evaluation: { id: 'A', state: 'queued' } }, read: null };
  const poller = createScoreStatusPoller({
    schedule: (callback, delay) => { const id = ++sequence; timers.set(id, { callback, delay }); return id; },
    cancel: id => timers.delete(id),
    canPoll: () => state.visible && !state.dirty && !state.modal && !state.submitting && scorePending(state.detail),
    read: async () => { calls.push('read'); return state.read(); },
    apply: detail => { calls.push('apply'); state.detail = detail; },
    onError: () => calls.push('error'),
  });
  async function tick() {
    assert.equal(timers.size, 1, 'Only one read timer may exist.');
    const [id, timer] = [...timers][0];
    timers.delete(id);
    await timer.callback();
  }
  return { state, timers, calls, poller, tick };
}

const f = fixture();
f.state.read = () => ({ evaluation: { id: 'A', state: 'running' } });
f.poller.start();
await f.tick();
assert.equal(f.state.detail.evaluation.state, 'running');
f.state.read = () => ({ evaluation: { id: 'A', state: 'review_required' }, selected_result: { candidate_score: '7.0000' } });
await f.tick();
assert.equal(f.state.detail.selected_result.candidate_score, '7.0000');
assert.equal(f.timers.size, 0, 'Completion must stop polling.');

for (const terminal of ['failed', 'cancelled']) {
  const settled = fixture();
  settled.state.read = () => ({ evaluation: { id: 'A', state: terminal } });
  settled.poller.start(); await settled.tick();
  assert.equal(settled.timers.size, 0);
  assert.equal(settled.state.detail.evaluation.state, terminal);
}

const closing = fixture();
let resolve;
closing.state.read = () => new Promise(done => { resolve = done; });
closing.poller.start();
const oldRead = closing.tick();
closing.poller.stop();
closing.state.detail = { evaluation: { id: 'B', state: 'queued' } };
resolve({ evaluation: { id: 'A', state: 'review_required' } });
await oldRead;
assert.equal(closing.state.detail.evaluation.id, 'B', 'A late response must not replace another selection.');
assert.equal(closing.timers.size, 0, 'Closing the page must invalidate the old read.');
closing.state.read = () => ({ evaluation: { id: 'B', state: 'review_required' } });
closing.poller.start(); await closing.tick();
assert.equal(closing.state.detail.evaluation.state, 'review_required', 'Reopening must fetch the server result without creating a job.');

for (const field of ['dirty', 'modal', 'submitting']) {
  const protectedState = fixture();
  protectedState.state.read = () => ({ evaluation: { state: 'review_required' } });
  protectedState.poller.start();
  protectedState.state[field] = true;
  await protectedState.tick();
  assert.deepEqual(protectedState.calls, [], `${field} must prevent an overwrite.`);
}
for (const [field, value] of [['dirty', true], ['modal', true], ['submitting', true], ['visible', false]]) {
  const inFlight = fixture();
  let finish;
  inFlight.state.read = () => new Promise(done => { finish = done; });
  inFlight.poller.start();
  const pending = inFlight.tick();
  inFlight.state[field] = value;
  finish({ evaluation: { id: 'A', state: 'review_required' } });
  await pending;
  assert.deepEqual(inFlight.calls, ['read'], `${field} changes during a read must prevent an overwrite.`);
  assert.equal(inFlight.timers.size, 0);
}
const hidden = fixture();
hidden.state.visible = false; hidden.poller.start();
assert.equal(hidden.timers.size, 0);
hidden.state.visible = true;
hidden.state.read = () => ({ evaluation: { state: 'review_required' } });
hidden.poller.start(); await hidden.tick();
assert.equal(hidden.state.detail.evaluation.state, 'review_required');

const failed = fixture();
failed.state.read = () => { throw { status: 503 }; };
failed.poller.start(); await failed.tick();
assert.equal([...failed.timers.values()][0].delay, 10000, 'Temporary failures must back off.');
failed.state.read = () => { throw { status: 403 }; };
await failed.tick(); assert.equal(failed.timers.size, 0, 'Lost access must stop reads.');
assert.match(source, /addEventListener\('pageshow', \(\) => scorePoller.start\(\)\)/);
assert.match(source, /addEventListener\('pagehide', \(\) => scorePoller.stop\(\)\)/);
assert.match(source, /addEventListener\('visibilitychange'/);
const readBlock = source.slice(source.indexOf('const scorePoller ='), source.indexOf("document.addEventListener('visibilitychange'"));
assert.doesNotMatch(readBlock, /POST|commands\/|idempotencyKey/);
assert.doesNotMatch(readBlock, /result_id:/, 'Polling must fetch the latest result rather than pin the old candidate.');

// Execute the real detail and metric renderers with a minimal synthetic DOM.
function node(tag, className = '', text = '') {
  return { tag, className, text, dataset: {}, children: [], appendChild(child) { this.children.push(child); return child; } };
}
const textOf = element => [element.text, ...element.children.map(textOf)].join(' ');
const renderState = { detail: null, canAssess: true, submitting: false };
let rendered;
let totalUpdates = 0;
const runtime = vm.createContext({
  state: renderState, workflowStage: 'assessment', scorePending, selectedScoreRejection, canRetryScore, scoreApproved,
  document: { createDocumentFragment: () => node('fragment') }, node,
  append: (parent, ...children) => children.forEach(child => parent.appendChild(child)),
  section: title => node('section', '', title),
  detailHeader: detail => node('header', '', detail.evaluation.state),
  replaceDetail: content => { rendered = content; },
  scoreLabel: (score, maximum) => `${score}/${maximum}`, labelFor: (_labels, value) => value,
  RELEASE_LABELS: {}, officialReview: () => null,
  runFailureMessage: run => run?.failure_message,
  latestRunForOperation: (detail, operation) => (detail.runs || []).find(run => run.operation === operation),
  button: text => node('button', '', text),
  renderResponses: () => node('responses'), latestReviewItems: () => new Map(),
  reviewActions: () => null, renderReviewHistory: () => null,
  updateOfficialTotal: () => { totalUpdates += 1; },
});
const metricsSource = source.slice(source.indexOf('  function metrics(detail)'), source.indexOf('  function renderTableAnswer('));
const detailSource = source.slice(source.indexOf('  function renderScoreStatus('), source.indexOf('  async function loadDetail('));
const manualScoreSource = source.slice(source.indexOf('  function manualScore('), source.indexOf('  function foreignApproval('));
vm.runInContext(manualScoreSource + metricsSource + detailSource, runtime);
for (const [state, message] of [
  ['queued', '평가 작업이 서버 대기열에 등록되어 있습니다.'],
  ['running', '서버에서 AI 평가를 진행하고 있습니다.'],
  ['cancelled', 'AI 평가가 취소되었습니다.'],
  ['failed', 'AI 평가에 실패했습니다. 다시 시도해 주세요.'],
]) {
  renderState.detail = { evaluation: { id: 'A', state } };
  vm.runInContext('renderDetail()', runtime);
  assert.ok(textOf(rendered).includes(message), `${state} must render without a runtime exception.`);
}
renderState.detail = {
  evaluation: { id: 'A', state: 'review_required' },
  selected_result: { candidate_score: 7, maximum_score: 10, overall_confidence: 0.9, summary: 'Synthetic result', items: [] },
};
vm.runInContext('renderDetail()', runtime);
assert.ok(textOf(rendered).includes('7/10'));
assert.ok(textOf(rendered).includes('Synthetic result'));
assert.equal(totalUpdates, 1, 'A completed result must render its score controls.');
assert.ok(!textOf(rendered).includes('서버 대기열'));
renderState.detail.evaluation.state = 'running';
vm.runInContext('renderDetail()', runtime);
assert.ok(textOf(rendered).includes('AI 평가 진행 상태'), 'A re-evaluation must show progress alongside the previous result.');
console.log('PASS score progress: reopen, completion, failure, cancellation, stale response, in-flight edit safety, visibility, backoff, read-only requests, detail rendering.');

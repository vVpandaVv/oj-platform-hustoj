import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile('resources/js/submission-evaluation-review-workbench.js', 'utf8');
const window = {};
vm.runInNewContext(source, { window, document: { querySelector: () => null }, URLSearchParams });
const { workflowBulkReason: reason, workflowBulkCommand: command, createWorkflowBulk: create } = window.OJAISubmissionEvaluationReviewWorkbench;
const clone = value => JSON.parse(JSON.stringify(value));
const hash = 'a'.repeat(64);
const context = { type: 'group', id: 'group:10', revision: 'membership-4' };
function detail(id = 'evaluation-1', reviewed = false) {
  return {
    evaluation: { id, state: reviewed ? 'reviewed' : 'review_required', assignment: { id: 'assignment-1' },
      submission: { is_source_current: true, state: 'submitted', source_revision_id: 'revision-1' },
      candidate: { id: 'result-1' }, priority: { review_needed_count: 0 },
      review: { official_scope: reviewed ? 'this_evaluation' : 'none', official_id: reviewed ? 'official-1' : null }, feedback: { count: 0 } },
    selected_result: { id: 'result-1', abstained: false, safety_flagged: false,
      items: [{ binding_key: hash, decision: 'score', candidate_points: '16', maximum_points: '20', flags: [], abstain_reason: null }] },
    review_precondition: { expected_result_id: 'result-1', expected_result_hash: hash,
      expected_official_review_id: reviewed ? 'official-1' : null, expected_official_review_hash: reviewed ? hash : null, expected_review_no: reviewed ? 1 : 0 },
    reviews: reviewed ? [{ id: 'official-1', is_official: true }] : [], feedback: [], runs: [],
  };
}
assert.equal(reason('accept', detail()), '');
for (const mutate of [
  d => { d.evaluation.state = 'reviewed'; }, d => { d.evaluation.state = 'running'; },
  d => { d.evaluation.submission.is_source_current = false; }, d => { d.evaluation.submission.state = 'reopened'; },
  d => { d.review_precondition.expected_official_review_id = 'existing'; },
  d => { d.evaluation.review.official_id = 'existing'; },
  d => { d.reviews = [{ result_id: 'result-1', review_no: 1, decision: 'rejected' }]; },
  d => { d.reviews = [{ result_id: 'result-1', review_no: 1, decision: 'draft' }]; },
  d => { d.selected_result.items = []; }, d => { d.selected_result.id = 'old-result'; },
  d => { d.evaluation.candidate.id = 'new-result'; }, d => { d.selected_result.abstained = true; },
  d => { d.selected_result.safety_flagged = true; }, d => { d.evaluation.priority.review_needed_count = 1; },
  d => { d.selected_result.items[0].candidate_points = null; }, d => { d.selected_result.items[0].candidate_points = '21'; },
  d => { d.selected_result.items[0].flags = ['teacher_review_required']; },
  d => { d.selected_result.items[0].abstain_reason = 'insufficient_evidence'; },
  d => { d.selected_result.items[0].decision = 'abstain'; },
]) {
  const value = detail(); mutate(value); assert.notEqual(reason('accept', value), '');
}
for (const action of ['feedback', 'note']) {
  assert.equal(reason(action, detail('evaluation-1', true)), '');
  assert.notEqual(reason(action, detail()), '');
  for (const state of ['queued', 'leased', 'running', 'retry_wait']) {
    const d = detail('evaluation-1', true);
    d.runs = [{ state, operation: action === 'feedback' ? 'feedback_draft' : 'special_note_draft' }];
    assert.notEqual(reason(action, d), '');
    d.runs[0].state = 'failed'; assert.equal(reason(action, d), '', 'Failed generation without a draft remains retryable.');
  }
  const body = command(action, detail('evaluation-1', true), `cmd-${action}`);
  assert.deepEqual(clone(body.expected), { expected_official_review_id: 'official-1', expected_official_review_hash: hash });
  assert.deepEqual(clone(body.payload), {});
}
for (const state of ['ai_draft', 'teacher_draft', 'approved', 'rejected', 'stale']) {
  const d = detail('evaluation-1', true); d.feedback = [{ state }];
  assert.notEqual(reason('feedback', d), '', 'Bulk generation preserves every existing revision.');
}
assert.notEqual(reason('note', detail('evaluation-1', true), true), '');
assert.notEqual(reason('unknown', detail()), '');
assert.throws(() => command('unknown', detail(), 'cmd'));

function teacherDetail(id = 'evaluation-1') {
  const d = detail(id);
  d.selected_result.provider_status = 'not_requested';
  d.selected_result.items[0].candidate_points = null;
  d.selected_result.items[0].levels = [{ points: '0' }, { points: '12' }, { points: '20' }];
  d.review_precondition.expected_review_no = 2;
  d.reviews = [{ result_id: 'result-1', review_no: 2, decision: 'draft', score_decisions: { value: { items: [
    { binding_key: hash, source: 'teacher_assigned', official_points: '12.0000', reason_code: 'manual_assessment', reason_note: '근거를 확인함.' },
  ] } } }];
  return d;
}
assert.equal(reason('teacher', teacherDetail()), '');
const teacherBody = command('teacher', teacherDetail(), 'teacher-command');
assert.equal(teacherBody.payload.decision, 'adjusted');
assert.equal(teacherBody.expected.expected_review_no, 2);
assert.deepEqual(clone(teacherBody.payload.item_decisions), teacherDetail().reviews[0].score_decisions.value.items);
assert.notEqual(reason('teacher', detail()), '', 'Never approve an unsaved AI or teacher form as a saved teacher grade.');
assert.notEqual(reason('accept', teacherDetail()), '', 'AI approval must not overwrite saved teacher points.');
const zero = teacherDetail(); zero.reviews[0].score_decisions.value.items[0].official_points = '0';
assert.equal(reason('teacher', zero), '');
assert.equal(command('teacher', zero, 'zero').payload.item_decisions[0].official_points, '0.0000');
const aiSaved = teacherDetail();
aiSaved.selected_result.items[0].candidate_points = '12';
Object.assign(aiSaved.reviews[0].score_decisions.value.items[0], { source: 'ai_accepted', reason_code: null, reason_note: null });
assert.equal(reason('teacher', aiSaved), '');
assert.equal(command('teacher', aiSaved, 'unchanged').payload.decision, 'accepted', 'Unchanged AI points saved by teacher use the accepted server contract.');
const checkedSafety = teacherDetail();
checkedSafety.selected_result.safety_flagged = true;
checkedSafety.selected_result.abstained = true;
checkedSafety.evaluation.priority.review_needed_count = 1;
checkedSafety.selected_result.items[0].flags = ['safety_review_required'];
assert.equal(reason('teacher', checkedSafety), '', 'A complete teacher grade with a saved safety memo can be confirmed without AI.');
checkedSafety.reviews[0].score_decisions.value.items[0].reason_note = null;
assert.notEqual(reason('teacher', checkedSafety), '');
for (const mutate of [
  d => { d.evaluation.state = 'reviewed'; }, d => { d.evaluation.state = 'running'; },
  d => { d.evaluation.submission.is_source_current = false; }, d => { d.evaluation.submission.state = 'reopened'; },
  d => { d.review_precondition.expected_official_review_id = 'existing'; }, d => { d.evaluation.review.official_id = 'existing'; },
  d => { d.selected_result.id = 'old'; }, d => { d.evaluation.candidate.id = 'new'; },
  d => { d.reviews[0].result_id = 'old'; }, d => { d.review_precondition.expected_review_no = 3; },
  d => { d.reviews.push({ review_no: 3, result_id: 'result-1', decision: 'rejected' }); },
  d => { d.reviews.push({ review_no: 3, result_id: 'result-1', decision: 'withdrawn' }); },
  d => { d.reviews[0].score_decisions.value.items = []; },
  d => { d.selected_result.items.push({ ...d.selected_result.items[0], binding_key: 'b'.repeat(64) }); },
  d => { d.reviews[0].score_decisions.value.items[0].binding_key = 'wrong'; },
  d => { d.reviews[0].score_decisions.value.items[0].official_points = null; },
  d => { d.reviews[0].score_decisions.value.items[0].official_points = '21'; },
  d => { d.reviews[0].score_decisions.value.items[0].official_points = '11'; },
  d => { d.reviews[0].score_decisions.value.items[0].source = 'unknown'; },
  d => { d.reviews[0].score_decisions.value.items[0].reason_code = null; },
  d => { Object.assign(d.reviews[0].score_decisions.value.items[0], { reason_code: 'other', reason_note: '' }); },
  d => { Object.assign(d.reviews[0].score_decisions.value.items[0], { source: 'ai_accepted', reason_code: null, reason_note: null }); },
]) { const d = teacherDetail(); mutate(d); assert.notEqual(reason('teacher', d), ''); }
const actionDetail = (action, id) => action === 'teacher' ? teacherDetail(id) : detail(id, action !== 'accept');
const target = (id, action = 'accept') => ({ id, assignmentId: 'assignment-1', revisionId: 'revision-1',
  body: command(action, actionDetail(action, id), `command-${action}-${id}`) });
const receipt = (action, id, body) => ({ data: ['accept', 'teacher'].includes(action)
  ? { evaluation: { id }, result: { id: body.expected.expected_result_id, hash: body.expected.expected_result_hash }, review: { id: 'new-review', decision: body.payload.decision }, official_review: { id: 'new-review' } }
  : { evaluation_id: id, official_review: { id: body.expected.expected_official_review_id, hash: body.expected.expected_official_review_hash }, run: { id: 'run-1', state: 'queued', operation: action === 'feedback' ? 'feedback_draft' : 'special_note_draft' } } });
for (const action of ['accept', 'teacher', 'feedback', 'note']) {
  const requests = [];
  let fail = true; let reads = 0;
  const batch = create({ action, targets: [target('first', action), target('second', action)], context,
    read: async t => { reads += 1; return { detail: actionDetail(action, t.id), hasNote: false }; },
    send: async (path, options) => {
      requests.push(clone({ path, options }));
      if (fail) { fail = false; throw new Error('response lost after commit'); }
      return receipt(action, requests.length < 3 ? 'first' : 'second', options.body);
    }, onProgress() {} });
  await assert.rejects(batch.run(), /response lost/);
  assert.equal(batch.snapshot().results.length, 0);
  await batch.run();
  assert.deepEqual(requests[0], requests[1], 'Uncertain delivery reuses exact command and idempotency key.');
  assert.equal(reads, 2, 'Do not replace pending command with a fresh precondition after uncertain commit.');
  assert.match(requests[0].path, /context_type=group&context_id=group%3A10&context_revision=membership-4/);
  assert.equal(batch.snapshot().done, true);
  assert.equal(batch.snapshot().results.filter(r => r.status === 'succeeded').length, 2);
  await batch.run(); assert.equal(requests.length, 3);
}
for (const mutate of [
  d => { d.reviews[0].score_decisions.value.items[0].official_points = '20'; },
  d => { d.reviews[0].score_decisions.value.items[0].reason_note = '변경된 근거'; },
  d => { d.reviews[0].review_no = 3; d.review_precondition.expected_review_no = 3; },
]) {
  const batch = create({ action: 'teacher', targets: [target('first', 'teacher')], context, onProgress() {},
    read: async () => { const d = teacherDetail('first'); mutate(d); return { detail: d }; },
    send: async () => { assert.fail('Changed saved teacher grade must not be approved from a stale preview.'); } });
  await batch.run(); assert.equal(batch.snapshot().results[0].status, 'skipped');
}
const wrongTeacherReceipt = create({ action: 'teacher', targets: [target('first', 'teacher')], context, onProgress() {},
  read: async () => ({ detail: teacherDetail('first') }), send: async (_, options) => {
    const response = receipt('teacher', 'first', options.body); response.data.review.decision = 'accepted'; return response;
  } });
await assert.rejects(wrongTeacherReceipt.run(), /응답/);
let sent = 0;
const mutations = ['scope', 'source', 'result', 'approved', 'okay', 'conflict', 'invalid', 'missing'];
const mixed = create({ action: 'accept', targets: mutations.map(id => target(id)), context, onProgress() {},
  read: async t => {
    const d = detail(t.id);
    if (t.id === 'scope') d.evaluation.assignment.id = 'different';
    if (t.id === 'source') d.evaluation.submission.source_revision_id = 'different';
    if (t.id === 'result') d.review_precondition.expected_result_hash = 'b'.repeat(64);
    if (t.id === 'approved') d.evaluation.state = 'reviewed';
    return { detail: d };
  }, send: async (path, options) => {
    sent += 1;
    const id = path.split('/')[2];
    if (id !== 'okay') throw Object.assign(new Error('safe failure'), { status: { conflict: 409, invalid: 422, missing: 404 }[id] });
    return receipt('accept', id, options.body);
  } });
await mixed.run();
assert.equal(sent, 4);
assert.deepEqual(clone(mixed.snapshot().results.map(r => r.status)), ['skipped', 'skipped', 'skipped', 'skipped', 'succeeded', 'failed', 'failed', 'failed']);
for (const status of [401, 403, 429, 503]) {
  let sends = 0;
  const batch = create({ action: 'accept', targets: [target('first'), target('second')], context,
    read: async t => ({ detail: detail(t.id) }), onProgress() {},
    send: async () => { sends += 1; throw Object.assign(new Error('denied/unavailable'), { status }); } });
  await assert.rejects(batch.run()); assert.equal(sends, 1); assert.equal(batch.snapshot().done, false);
}
let resolve; let calls = 0;
const stopped = create({ action: 'accept', targets: [target('first'), target('second')], context, onProgress() {},
  read: async t => ({ detail: detail(t.id) }), send: async (_, options) => {
    calls += 1;
    if (calls === 1) return new Promise(done => { resolve = () => done(receipt('accept', 'first', options.body)); });
    return receipt('accept', 'second', options.body);
  } });
const running = stopped.run(); await Promise.resolve(); await stopped.run();
assert.equal(calls, 1);
stopped.stop(); resolve(); await running;
assert.equal(calls, 1); assert.equal(stopped.snapshot().done, false);
await stopped.run(); assert.equal(calls, 2); assert.equal(stopped.snapshot().done, true);
let sendAfterStop = 0;
const stoppedRead = create({ action: 'accept', targets: [target('first')], context, onProgress() {},
  read: async () => { stoppedRead.stop(); return { detail: detail('first') }; }, send: async () => { sendAfterStop += 1; } });
await stoppedRead.run(); assert.equal(sendAfterStop, 0);
const malformed = create({ action: 'accept', targets: [target('first')], context, onProgress() {},
  read: async () => ({ detail: detail('first') }), send: async () => ({ data: {} }) });
await assert.rejects(malformed.run(), /응답/); assert.equal(malformed.snapshot().results.length, 0);
for (const action of ['feedback', 'note']) {
  const failedRun = create({ action, targets: [target('first', action)], context, onProgress() {},
    read: async () => ({ detail: detail('first', true) }), send: async (_, options) => {
      const result = receipt(action, 'first', options.body); result.data.run.state = 'failed'; return result;
    } });
  await failedRun.run();
  assert.equal(failedRun.snapshot().done, true);
  assert.equal(failedRun.snapshot().results[0].status, 'failed', 'Settled failed receipt is not a successful request or an endless network retry.');
}
console.log('PASS bulk review/report: eligibility, safe exclusions, immutable commands, exact retry, partial failure, permission stop, double click and stop/resume.');

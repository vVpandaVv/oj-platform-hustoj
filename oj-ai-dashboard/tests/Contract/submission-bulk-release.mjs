import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const window = {};
vm.runInNewContext(await readFile('resources/js/submission-evaluation-review-workbench.js', 'utf8'), {
  window, document: { querySelector: () => null }, URLSearchParams,
});
const { workflowBulkReason: reason, workflowBulkCommand: command, createWorkflowBulk: create } = window.OJAISubmissionEvaluationReviewWorkbench;
const clone = value => JSON.parse(JSON.stringify(value));
const hash = 'a'.repeat(64);
const context = { type: 'group', id: 'group:1', revision: 'context-r1' };
const options = { scoreVisibility: 'total_and_criteria', feedbackVisibility: 'hidden' };
function detail(id = 'evaluation-1') {
  return { evaluation: { id, state: 'reviewed', context, assignment: { id: 'assignment-1' },
    submission: { is_source_current: true, state: 'submitted', source_revision_id: 'revision-1' },
    candidate: { id: 'result-1' }, review: { official_scope: 'this_evaluation', official_id: 'official-1' }, release: {} },
    selected_result: { id: 'result-1', maximum_score: '20' },
    review_precondition: { expected_result_id: 'result-1', expected_result_hash: hash,
      expected_official_review_id: 'official-1', expected_official_review_hash: hash },
    reviews: [{ id: 'official-1', result_id: 'result-1', decision: 'adjusted', official_score: '0', is_official: true }],
    feedback: [], releases: [], runs: [] };
}
assert.equal(reason('release', detail(), false, options), '');
const body = command('release', detail(), 'release-command-1', options);
assert.deepEqual(clone(body.payload), { feedback_visibility: 'hidden', mode: 'release', score_visibility: 'total_and_criteria' });
assert.equal(body.expected.expected_feedback_revision_id, null);
assert.equal(body.expected.expected_official_review_hash, hash);
assert.equal(body.expected.expected_current_release_id, null);
const feedbackOptions = { scoreVisibility: 'total', feedbackVisibility: 'shown' };
function withFeedback(d = detail()) {
  d.feedback = [{ id: 'feedback-1', hash, state: 'approved', review_id: 'official-1', profile: { value: { source_hash: hash } } }];
  return d;
}
assert.equal(reason('release', withFeedback(), false, feedbackOptions), '');
assert.equal(command('release', withFeedback(), 'with-feedback', feedbackOptions).expected.expected_feedback_revision_hash, hash);
assert.notEqual(reason('release', detail(), false, feedbackOptions), '');
assert.notEqual(reason('release', detail(), false, { ...options, scoreVisibility: 'hidden' }), '');
for (const mutate of [
  d => { d.evaluation.state = 'review_required'; },
  d => { d.evaluation.submission.is_source_current = false; },
  d => { d.evaluation.submission.state = 'reopened'; },
  d => { d.evaluation.review.official_scope = 'other_evaluation'; },
  d => { d.evaluation.review.official_id = 'other'; },
  d => { d.reviews[0].is_official = false; },
  d => { d.reviews[0].decision = 'withdrawn'; },
  d => { d.reviews[0].result_id = 'old-result'; },
  d => { d.reviews[0].official_score = null; },
  d => { d.reviews[0].official_score = '21'; },
  d => { d.review_precondition.expected_official_review_id = 'changed'; },
  d => { d.review_precondition.expected_official_review_hash = 'invalid'; },
  d => { d.evaluation.release.current_id = 'existing'; },
  d => { d.evaluation.release.count = 1; },
  d => { d.releases = [{ state: 'withdrawn' }]; },
]) {
  const d = detail(); mutate(d); assert.notEqual(reason('release', d, false, options), '');
}
for (const state of ['ai_draft', 'teacher_draft', 'stale', 'rejected']) {
  const d = withFeedback(); d.feedback[0].state = state;
  assert.notEqual(reason('release', d, false, feedbackOptions), '');
  assert.equal(reason('release', d, false, options), '', 'A score-only release must not expose unapproved feedback.');
}
const stale = withFeedback(); stale.feedback[0].profile.value.source_hash = 'b'.repeat(64);
assert.notEqual(reason('release', stale, false, feedbackOptions), '');
const target = (id, opts = options, d = detail(id)) => ({ id, assignmentId: 'assignment-1', revisionId: 'revision-1', releaseOptions: opts,
  body: command('release', d, `command-${id}`, opts) });
const receipt = (id, b) => ({ data: { evaluation_id: id,
  official_review: { id: b.expected.expected_official_review_id, hash: b.expected.expected_official_review_hash },
  release: { id: `release-${id}`, hash, projection_hash: hash, release_no: 1, state: 'released', feedback_revision_id: b.expected.expected_feedback_revision_id } } });
const requests = [];
let lost = true;
let reads = 0;
const batch = create({ action: 'release', targets: [target('first'), target('second')], context, onProgress() {},
  read: async t => { reads++; return { detail: detail(t.id) }; }, send: async (path, request) => {
    requests.push(clone({ path, request }));
    if (lost) { lost = false; throw new Error('response lost'); }
    return receipt(path.split('/')[2], request.body);
  } });
await assert.rejects(batch.run(), /lost/);
await batch.run();
assert.equal(reads, 2);
assert.deepEqual(requests[0], requests[1], 'Uncertain delivery must replay the exact release command.');
assert.ok(requests.every(r => r.path.includes('/commands/release?context_type=group&context_id=group%3A1&context_revision=context-r1')));
assert.ok(requests.every(r => r.request.idempotencyKey === r.request.body.command_id));
assert.equal(batch.snapshot().results.filter(r => r.status === 'succeeded').length, 2);
for (const mutate of [
  d => { d.evaluation.assignment.id = 'other'; }, d => { d.evaluation.submission.source_revision_id = 'other'; },
  d => { d.evaluation.context = { ...context, id: 'group:2' }; },
  d => { d.evaluation.context = { ...context, revision: 'new-context' }; },
  d => { d.review_precondition.expected_official_review_hash = 'b'.repeat(64); },
  d => { d.evaluation.release.current_id = 'published'; },
]) {
  const work = create({ action: 'release', targets: [target('first')], context, onProgress() {},
    read: async () => { const d = detail('first'); mutate(d); return { detail: d }; },
    send: async () => assert.fail('Changed or foreign results must not be released.') });
  await work.run(); assert.equal(work.snapshot().results[0].status, 'skipped');
}
for (const mutate of [d => { d.feedback[0].hash = 'b'.repeat(64); }, d => { d.feedback[0].state = 'teacher_draft'; }]) {
  const work = create({ action: 'release', targets: [target('first', feedbackOptions, withFeedback(detail('first')))], context, onProgress() {},
    read: async () => { const d = withFeedback(detail('first')); mutate(d); return { detail: d }; },
    send: async () => assert.fail('Changed feedback must not be disclosed.') });
  await work.run(); assert.equal(work.snapshot().results[0].status, 'skipped');
}
for (const status of [403, 503]) {
  let calls = 0;
  const work = create({ action: 'release', targets: [target('first'), target('second')], context, onProgress() {},
    read: async t => ({ detail: detail(t.id) }), send: async () => { calls++; throw Object.assign(new Error('blocked'), { status }); } });
  await assert.rejects(work.run()); assert.equal(calls, 1); assert.equal(work.snapshot().done, false);
}
const wrongReceipt = create({ action: 'release', targets: [target('first')], context, onProgress() {},
  read: async t => ({ detail: detail(t.id) }), send: async (_, request) => {
    const r = receipt('first', request.body); r.data.release.feedback_revision_id = 'unexpected-feedback'; return r;
  } });
await assert.rejects(wrongReceipt.run(), /응답/);
console.log('PASS bulk release: confirmed zero, feedback approval, disclosure options, history exclusions, context/source drift, exact retries, receipt scope and permission stop.');

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile('resources/js/submission-evaluation-review-workbench.js', 'utf8');
const window = {};
vm.runInNewContext(await readFile('resources/js/learner-labels.js', 'utf8'), { window });
vm.runInNewContext(source, { window, document: { querySelector: () => null }, URLSearchParams });
const { assignmentBatchCommand, createAssignmentBatch, bulkCandidateCount } = window.OJAISubmissionEvaluationReviewWorkbench;
const assignment = { id: 'assignment-a', schedule_version: 7, state: 'open' };
const context = { type: 'group', id: 'group:10', revision: 'r4' };
const cutoff = '2026-09-14 01:00:00';
const copy = value => JSON.parse(JSON.stringify(value));
const candidate = { kind: 'candidate', submissionStatus: 'submitted', roster: { assigned: true, status: 'submitted' } };
assert.equal(bulkCandidateCount([
  candidate, ...['auto_submitted', 'proxy_submitted', 'locked'].map(status => ({ ...candidate, roster: { assigned: true, status } })),
  { ...candidate, kind: 'evaluation' }, { ...candidate, submissionStatus: 'not_submitted' },
  { ...candidate, roster: { assigned: false, status: 'submitted' } },
  { ...candidate, roster: { assigned: true, status: 'reopened' } },
]), 4);
const failedCandidate = { ...candidate, kind: 'evaluation', state: 'failed' };
assert.equal(bulkCandidateCount([failedCandidate]), 1, 'Failed evaluations must enable bulk grading even without new submissions.');
assert.equal(bulkCandidateCount([
  ...['requested', 'queued', 'running', 'review_required', 'reviewed', 'cancelled'].map(state => ({ ...failedCandidate, state })),
  { ...failedCandidate, roster: { assigned: false, status: 'submitted' } },
  { ...failedCandidate, roster: { assigned: true, status: 'reopened' } },
]), 0, 'Active, completed, cancelled and non-official submissions stay excluded.');
assert.deepEqual(copy(assignmentBatchCommand('command-1', assignment, cutoff)), {
  command_id: 'command-1', expected: { expected_schedule_version: 7, expected_state: 'open' },
  payload: { source_cutoff_utc: cutoff, item_cursor: '', limit: 20 },
});
assert.equal(assignmentBatchCommand('command-2', { ...assignment, schedule_version: undefined, schedule: { version: 8 } }, cutoff).expected.expected_schedule_version, 8);
for (const state of ['draft', 'scheduled', 'withdrawn']) assert.throws(() => assignmentBatchCommand('cmd', { ...assignment, state }, cutoff));
assert.throws(() => assignmentBatchCommand('cmd', { ...assignment, schedule_version: 0 }, cutoff));
assert.throws(() => assignmentBatchCommand('cmd', assignment, 'not UTC'));

const envelope = (options, statuses, next = '') => ({
  data: { assignment_id: assignment.id, items: statuses.map((status, i) => ({ status, submission_id: `s${i}`, reason: status === 'failed' ? 'artifact_not_ready' : null })) },
  meta: {
    source_cutoff_utc: cutoff, item_cursor: options.body.payload.item_cursor, next_item_cursor: next,
    counts: Object.fromEntries(['created', 'existing', 'skipped', 'failed', 'total'].map(key => [key, key === 'total' ? statuses.length : statuses.filter(x => x === key).length])),
  },
});
let id = 0;
const updates = [];
const requests = [];
let attempt = 0;
const batch = createAssignmentBatch({ assignment, context, cutoff, makeId: () => `batch-${++id}`, onProgress: p => updates.push(p), send: async (path, options) => {
  requests.push(copy({ path, options }));
  attempt += 1;
  if (attempt === 1) return envelope(options, ['created', 'skipped', 'failed'], 'page2');
  if (attempt === 2) throw new Error('network dropped after server commit');
  return envelope(options, ['existing', 'created']);
} });
await assert.rejects(batch.run(), /network/);
assert.equal(batch.snapshot().running, false);
assert.equal(batch.snapshot().done, false);
assert.equal(batch.snapshot().counts.total, 3);
await batch.run();
assert.deepEqual(requests[1], requests[2], 'Uncertain page must retain the exact body, scope and idempotency key.');
assert.notEqual(requests[0].options.idempotencyKey, requests[1].options.idempotencyKey);
assert.match(requests[0].path, /assignments\/assignment-a\/commands\/evaluate-submissions\?context_type=group&context_id=group%3A10&context_revision=r4$/);
assert.equal(requests[0].options.body.payload.source_cutoff_utc, requests[2].options.body.payload.source_cutoff_utc);
assert.deepEqual(copy(batch.snapshot().counts), { created: 2, existing: 1, skipped: 1, failed: 1, total: 5 });
assert.equal(batch.snapshot().failures.length, 1);
assert.equal(batch.snapshot().done, true);
await batch.run();
assert.equal(requests.length, 3, 'Finished registration must not send again.');

let resolve;
let sends = 0;
const stopped = createAssignmentBatch({ assignment, context, cutoff, makeId: () => 'stop-command', onProgress: () => {}, send: async (_, options) => {
  sends += 1;
  if (sends === 1) return new Promise(done => { resolve = () => done(envelope(options, ['created'], 'page2')); });
  return envelope(options, []);
} });
const running = stopped.run();
await stopped.run();
assert.equal(sends, 1, 'Double click cannot launch concurrent page requests.');
stopped.stop();
resolve();
await running;
assert.equal(sends, 1, 'Stop/context change must not enqueue the next page.');
assert.equal(stopped.snapshot().done, false);
await stopped.run();
assert.equal(stopped.snapshot().done, true);

for (const mutate of [
  e => { e.data.assignment_id = 'another'; },
  e => { e.meta.source_cutoff_utc = '2026-09-15 01:00:00'; },
  e => { e.meta.item_cursor = 'unexpected'; },
  e => { e.meta.counts.created = 50; },
  e => { e.meta.next_item_cursor = 1; },
  e => { e.data.items[0].status = 'unknown'; },
]) {
  const bad = createAssignmentBatch({ assignment, context, cutoff, makeId: () => 'bad-command', onProgress: () => {}, send: async (_, options) => {
    const value = envelope(options, ['created']); mutate(value); return value;
  } });
  await assert.rejects(bad.run(), /응답/);
  assert.equal(bad.snapshot().counts.total, 0, 'Malformed receipts must not count as registered.');
}
let loopCalls = 0;
const loop = createAssignmentBatch({ assignment, context, cutoff, makeId: () => 'loop-command', onProgress: () => {}, send: async (_, options) => {
  loopCalls += 1;
  return envelope(options, [], 'repeated-cursor');
} });
await assert.rejects(loop.run(), /응답/);
assert.equal(loopCalls, 2, 'Repeated cursor must stop, not loop.');

const handler = await readFile('src/Platform/Worksheet_Evaluation_Workbench_Page_Handler.php', 'utf8');
const css = await readFile('resources/css/submission-evaluation-review-workbench.css', 'utf8');
assert.match(handler, /\$can_assess \? '<button[^>]+bulk-grade/);
assert.match(handler, /data-review-action="bulk-grade" hidden disabled/);
assert.match(handler, /<dialog[^>]+data-review-role="batch-dialog"[^>]*>/);
assert.doesNotMatch(handler.match(/<dialog[^>]+data-review-role="batch-dialog"[^>]*>/)[0], /\sopen[\s>]/);
assert.match(handler, /선택한 배포 전체의 미채점 공식 제출/);
assert.match(source, /confirmed\.assignment !== currentAssignment\(\)/);
assert.match(source, /workflowStage !== 'assessment' \|\| !state.canAssess/);
assert.match(source, /state\.rosterCutoff = rosterEnvelope\.meta\?\.server_time_utc/);
assert.match(source, /if \(!hasDetailChanges\(\)\) await loadEvaluations/);
assert.match(css, /__batch ul\s*\{[^}]*max-height: 160px;[^}]*overflow-y: auto;/);
assert.doesNotMatch(source, /localStorage.*batch|sessionStorage.*batch/);
// Execute the real UI handlers with inert DOM controls and a fake REST transport.
const failureList = { children: [], replaceChildren() { this.children = []; }, appendChild(child) { this.children.push(child); } };
const elements = Object.fromEntries(['batchButton', 'batch', 'batchStatus', 'batchFailures', 'batchScope', 'batchTargets', 'batchResume', 'batchStop', 'batchDismiss'].map(key => [key, { hidden: true, disabled: false, textContent: '' }]));
elements.batchFailures.querySelector = () => failureList;
elements.batchDialog = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
let selectedAssignment = { ...assignment, title: 'Assignment A' };
let selectedContext = { ...context, label: 'Group A' };
let dirty = false;
let refreshes = 0;
let resolveUI;
const uiRequests = [];
const uiState = { canAssess: true, submitting: false, rosterReady: true, rosterCutoff: cutoff, items: [{ ...candidate, learner: { display_name: 'Learner A' }, submission: { id: 's0' } }], batch: null, batchConfirmation: null };
const runtime = vm.createContext({
  root: { querySelector: () => null },
  state: uiState, elements, workflowStage: 'assessment', bulkCandidateCount, assignmentBatchCommand, createAssignmentBatch,
  currentAssignment: () => selectedAssignment, currentContext: () => selectedContext,
  canLeaveDetail: () => !dirty, hasDetailChanges: () => dirty,
  showNotice: () => {}, problemMessage: error => error.message,
  token: () => `ui-${++id}`, scorePoller: { stop() {}, start() {} },
  loadEvaluations: async () => { refreshes += 1; },
  loadAssignmentsForContext: async () => {},
  node: (_, __, text) => ({ text }),
  learnerNameNode: (_, identity, suffix) => ({ text: window.OJAILearnerLabels.label(identity, 'display_name') + suffix }),
  request: async (_, options) => {
    uiRequests.push(options);
    return new Promise(done => { resolveUI = statuses => done(envelope(options, statuses)); });
  },
});
vm.runInContext(source.slice(source.indexOf('  function updateBatchButton()'), source.indexOf('  function priorityLabel(')), runtime);
vm.runInContext('updateBatchButton()', runtime);
assert.equal(elements.batchButton.hidden, false);
assert.equal(elements.batchButton.disabled, false);
uiState.canAssess = false;
vm.runInContext('openBatchDialog()', runtime);
assert.equal(elements.batchButton.hidden, true);
assert.equal(elements.batchDialog.open, false);
uiState.canAssess = true;
uiState.rosterReady = false;
vm.runInContext('openBatchDialog()', runtime);
assert.equal(elements.batchDialog.open, false, 'Incomplete roster cannot launch grading.');
uiState.rosterReady = true;
dirty = true;
vm.runInContext('openBatchDialog()', runtime);
assert.equal(elements.batchDialog.open, false, 'Unsaved teacher edits are protected.');
dirty = false;
vm.runInContext('openBatchDialog()', runtime);
assert.equal(elements.batchDialog.open, true);
assert.equal(elements.batchTargets.textContent, '미채점 제출 1명 · 실패 재채점 0명');
uiState.items = [failedCandidate];
vm.runInContext('openBatchDialog()', runtime);
assert.equal(elements.batchButton.disabled, false);
assert.equal(elements.batchTargets.textContent, '미채점 제출 0명 · 실패 재채점 1명');
uiState.items = [{ ...candidate, learner: { display_name: 'Learner A' }, submission: { id: 's0' } }];
assert.equal(uiRequests.length, 0, 'Opening confirmation cannot send answers.');
selectedAssignment = { ...selectedAssignment, id: 'other-assignment' };
vm.runInContext('startBatch()', runtime);
assert.equal(uiRequests.length, 0, 'Changed assignment invalidates confirmation.');
selectedAssignment = { ...assignment, title: 'Assignment A' };
vm.runInContext('openBatchDialog(); startBatch(); startBatch()', runtime);
assert.equal(uiRequests.length, 1);
assert.equal(elements.batchDialog.open, false);
assert.equal(elements.batchButton.disabled, true);
assert.match(elements.batchStatus.textContent, /등록 중/);
dirty = true;
resolveUI(['created', 'failed']);
await new Promise(setImmediate);
assert.match(elements.batchStatus.textContent, /등록 완료.*대기 등록 1명.*등록 실패 1명/);
assert.equal(failureList.children.length, 1);
assert.equal(refreshes, 0, 'Batch completion must not replace in-progress teacher edits.');
assert.equal(uiState.batch.controller.snapshot().done, true);
vm.runInContext('resetBatch()', runtime);
assert.equal(elements.batch.hidden, true);
assert.equal(uiState.batch, null);
assert.equal(elements.batchButton.disabled, false);
console.log('PASS bulk grading: eligibility, scoped commands, pagination, exact retry, partial failure, double click, stop/resume, invalid receipts, actual UI handlers and edit protection.');

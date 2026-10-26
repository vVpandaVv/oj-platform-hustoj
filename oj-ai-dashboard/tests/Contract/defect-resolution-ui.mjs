import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const window = {};
const document = { querySelector: () => null, querySelectorAll: () => [], addEventListener: () => {} };
vm.runInNewContext(await readFile('resources/js/submission-evaluation-review-workbench.js', 'utf8'), { window, document, URLSearchParams });
const api = window.OJAISubmissionEvaluationReviewWorkbench;
const detail = { evaluation: { release: { state: 'released', current_id: 'release', current_hash: 'a'.repeat(64) } },
  review_precondition: { expected_official_review_id: 'review', expected_official_review_hash: 'b'.repeat(64) },
  feedback: [{ id: 'feedback', hash: 'c'.repeat(64), review_id: 'review', state: 'approved', profile: { value: { source_hash: 'b'.repeat(64) } } }] };
assert.equal(api.latestFeedback(detail).state, 'approved');
detail.review_precondition.expected_official_review_hash = 'd'.repeat(64);
assert.equal(api.latestFeedback(detail).state, 'stale');
assert.equal(detail.feedback[0].state, 'approved', 'Historical approval must remain immutable.');
assert.throws(() => api.releaseResultCommand('publish', detail, 'total', 'shown', 'data_correction'), /승인/);
const withdraw = JSON.parse(JSON.stringify(api.withdrawResultCommand('withdraw', detail, 'privacy_request', null)));
assert.deepEqual(withdraw, { command_id: 'withdraw', expected: { expected_current_release_id: 'release', expected_current_release_hash: 'a'.repeat(64) }, payload: { reason_code: 'privacy_request', reason_note: null } });
assert.throws(() => api.withdrawResultCommand('withdraw', detail, 'other', ''), /사유/);
detail.evaluation.release.state = 'withdrawn';
assert.throws(() => api.withdrawResultCommand('withdraw', detail, 'privacy_request', null), /공개 결과/);

vm.runInNewContext(await readFile('resources/js/worksheet-workflow.js', 'utf8'), { window, document, location: { search: '', href: 'https://example.test/oj-console/addons/oj-ai-dashboard/worksheets' }, URL, URLSearchParams });
let calls = 0;
const items = await window.OJAIWorkflow.allPages(async path => {
  calls++;
  const cursor = new URL('https://example.test' + path).searchParams.get('cursor');
  return { data: { items: Array.from({ length: cursor ? 5 : 100 }, (_, i) => ({ id: i + (cursor ? 101 : 1) })) }, meta: { next_cursor: cursor ? '' : 'page-two' } };
}, '/worksheets?limit=100');
assert.equal(items.length, 105);
assert.equal(items.at(-1).id, 105);
assert.equal(calls, 2);
await assert.rejects(() => window.OJAIWorkflow.allPages(async () => ({ data: { items: [] }, meta: { next_cursor: 'repeated' } }), '/worksheets'), /목록/);
console.log('Defect UI contracts passed: stale feedback, withdrawal CAS/reason, historical preservation, 105-row pagination, repeated cursor rejection.');

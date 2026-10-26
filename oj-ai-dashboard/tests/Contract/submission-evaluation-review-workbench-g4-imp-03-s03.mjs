import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const scriptPath = path.join(root, 'resources', 'js', 'submission-evaluation-review-workbench.js');
const stylePath = path.join(root, 'resources', 'css', 'submission-evaluation-review-workbench.css');
const manifestPath = path.join(root, 'src', 'Platform', 'Addon_Manifest.php');
const handlerPath = path.join(root, 'src', 'Platform', 'Worksheet_Evaluation_Workbench_Page_Handler.php');
const [source, style, manifest, handler] = await Promise.all([
  readFile(scriptPath, 'utf8'),
  readFile(stylePath, 'utf8'),
  readFile(manifestPath, 'utf8'),
  readFile(handlerPath, 'utf8'),
]);

const window = {};
const document = { querySelector: () => null };
vm.runInNewContext(source, { window, document, URLSearchParams, console }, { filename: scriptPath });
const adapter = window.OJAISubmissionEvaluationReviewWorkbench;
assert.ok(adapter, 'The pure student review adapter must be exported without a DOM root.');

const bindingFailure = { operation: 'score', state: 'failed', last_error_code: 'output_domain_invalid', last_error_message: '$.items[0].binding_key (unknown or duplicate)' };
assert.match(adapter.runFailureMessage(bindingFailure, 'AI 평가'), /평가 항목.*연결/);
assert.doesNotMatch(adapter.runFailureMessage(bindingFailure, 'AI 평가'), /\$\.items/);
assert.equal(adapter.runFailureMessage({ state: 'running' }, 'AI 평가'), null);
const failedDetail = {
  evaluation: { state: 'failed', submission: { id: 'submission-a', source_revision_id: 'revision-a', is_source_current: true } },
  runs: [bindingFailure],
  source: { bindings: [{ binding_key: 'b'.repeat(64) }], submission_revision: { id: 'revision-a', content_hash: 'a'.repeat(64), is_official: true } },
};
assert.deepEqual(JSON.parse(JSON.stringify(adapter.scoreInputRefreshCommand(failedDetail, 'refresh:1'))), {
  submissionId: 'submission-a',
  body: { command_id: 'refresh:1', expected: { expected_official_revision_id: 'revision-a', expected_official_revision_hash: 'a'.repeat(64) }, payload: {} },
});
assert.equal(adapter.scoreInputRefreshCommand({ ...failedDetail, source: { ...failedDetail.source, bindings: [{ binding_ref: 'B1' }] } }, 'refresh:2'), null);
assert.equal(adapter.scoreInputRefreshCommand({ ...failedDetail, runs: [{ ...bindingFailure, last_error_code: 'routing_stale' }] }, 'refresh:3'), null);
assert.throws(() => adapter.scoreInputRefreshCommand({ ...failedDetail, source: { ...failedDetail.source, submission_revision: { ...failedDetail.source.submission_revision, is_official: false } } }, 'refresh:4'), /최신 제출/);

const evidenceFailure = { ...bindingFailure, last_error_message: '$.items[0].evidence[2].anchor (unknown response, scope mismatch, or quote not found)' };
const evidenceDetail = { ...failedDetail, runs: [evidenceFailure], configuration: { prompt_contract_version: 'student_submission_assessment_prompt.v7' }, source: { ...failedDetail.source, bindings: [{ binding_ref: 'B1' }] } };
assert.match(adapter.runFailureMessage(evidenceFailure, 'AI 평가'), /근거.*연결하지 못/);
assert.doesNotMatch(adapter.runFailureMessage(evidenceFailure, 'AI 평가'), /다른 응답|\$\.items/);
assert.match(adapter.runFailureMessage({ ...evidenceFailure, last_error_message: '$.items[0].evidence[2].quote (not found in response)' }, 'AI 평가'), /인용한 문장/);
assert.match(adapter.runFailureMessage({ ...evidenceFailure, last_error_message: '$.items[0].evidence[2].anchor (response scope mismatch)' }, 'AI 평가'), /다른 응답/);
assert.equal(adapter.scoreInputRefreshCommand(evidenceDetail, 'evidence:1').submissionId, 'submission-a');
assert.equal(adapter.scoreInputRefreshCommand({ ...evidenceDetail, configuration: { prompt_contract_version: 'student_submission_assessment_prompt.v8' } }, 'evidence:8').submissionId, 'submission-a');
for (const version of ['student_submission_assessment_prompt.v10', 'student_submission_assessment_prompt.v9', 'unknown', '']) {
  assert.equal(adapter.scoreInputRefreshCommand({ ...evidenceDetail, configuration: { prompt_contract_version: version } }, 'evidence:2'), null);
}
assert.equal(adapter.scoreInputRefreshCommand({ ...evidenceDetail, runs: [{ ...evidenceFailure, operation: 'feedback_draft' }] }, 'evidence:3'), null);
assert.equal(adapter.scoreInputRefreshCommand({ ...evidenceDetail, evaluation: { ...evidenceDetail.evaluation, state: 'review_required' } }, 'evidence:4'), null);
assert.throws(() => adapter.scoreInputRefreshCommand({ ...evidenceDetail, evaluation: { ...evidenceDetail.evaluation, submission: { ...evidenceDetail.evaluation.submission, is_source_current: false } } }, 'evidence:5'), /최신 제출/);

assert.equal(adapter.normalizeDecimal('2.5'), '2.5000');
assert.equal(adapter.officialTotalLabel([]), '-');
for (const values of [[''], ['7', ''], ['7', ' '], ['7', null], ['7', 'invalid'], ['7', '-1']]) {
  assert.equal(adapter.officialTotalLabel(values), '미확정');
}
assert.equal(adapter.officialTotalLabel(['0', '0']), '0점');
assert.equal(adapter.officialTotalLabel(['7', '3']), '10점');
assert.equal(adapter.officialTotalLabel(['0.0001', '0.0002']), '0.0003점');
assert.equal(adapter.abstainMessage({}), null);
assert.match(adapter.abstainMessage({ abstain_reason: 'insufficient_evidence', flags: ['evidence_limited', 'teacher_review_required'] }), /근거.*보류.*최종 점수/);
assert.match(adapter.abstainMessage({ abstain_reason: 'safety_review', flags: ['evidence_limited', 'teacher_review_required'] }), /안전/);
for (const reason of ['evidence_reference_unknown', 'evidence_scope_mismatch', 'evidence_quote_mismatch', 'evidence_multiple_invalid']) {
  assert.match(adapter.abstainMessage({ abstain_reason: reason }), /AI.*보류/);
}
assert.match(adapter.abstainMessage({ abstain_reason: 'evidence_quote_mismatch' }), /학생 답변의 내용이 부족하다는 뜻은 아닙니다/);
const heldLegacy = {
  ...evidenceDetail,
  configuration: { prompt_contract_version: 'student_submission_assessment_prompt.v8' },
  evaluation: { ...evidenceDetail.evaluation, id: 'eval-a', state: 'review_required', latest_run: { id: 'run-a', run_no: 1, state: 'succeeded' } },
  selected_result: { abstained: true },
};
assert.equal(adapter.scoreInputRefreshCommand(heldLegacy, 'held:1').submissionId, 'submission-a');
assert.equal(adapter.scoreInputRefreshCommand({ ...heldLegacy, configuration: { prompt_contract_version: 'student_submission_assessment_prompt.v9' } }, 'held:2'), null);
assert.equal(adapter.scoreInputRefreshCommand({ ...heldLegacy, selected_result: { abstained: false } }, 'held:3'), null);
assert.equal(adapter.scoreInputRefreshCommand({ ...heldLegacy, evaluation: { ...heldLegacy.evaluation, submission: { ...heldLegacy.evaluation.submission, is_source_current: false } } }, 'held:4'), null);
assert.equal(adapter.normalizeDecimal('0', '10.0000'), '0.0000');
assert.throws(() => adapter.normalizeDecimal('-1'), /점수/);
assert.throws(() => adapter.normalizeDecimal('10.00001'), /점수/);
assert.throws(() => adapter.normalizeDecimal('11', '10.0000'), /배점/);

const context = { type: 'class', id: 'class-a', revision: 'rev-7' };
assert.equal(
  adapter.contextQuery(context, { state: 'review_required', limit: '40' }),
  'context_type=class&context_id=class-a&context_revision=rev-7&state=review_required&limit=40'
);

const precondition = {
  expected_result_id: '01K5RESULT00000000000000000',
  expected_result_hash: 'a'.repeat(64),
  expected_official_review_id: null,
  expected_official_review_hash: null,
  expected_review_no: 0,
};
const adjustedItems = [{
  binding_key: 'b'.repeat(64),
  source: 'teacher_assigned',
  official_points: '8.0000',
  reason_code: 'evidence_checked',
  reason_note: null,
}];
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.reviewCommand('review:command-1', precondition, 'adjusted', adjustedItems, null))),
  {
    command_id: 'review:command-1',
    expected: precondition,
    payload: { decision: 'adjusted', item_decisions: adjustedItems, reason: null },
  }
);
assert.throws(() => adapter.reviewCommand('', precondition, 'accepted'), /검토 요청/);
assert.throws(() => adapter.reviewCommand('review:command-2', precondition, 'unknown'), /검토 결정/);

assert.equal(adapter.responseText({ kind: 'long_text', answer: { text: '서술 응답' } }), '서술 응답');
assert.equal(adapter.responseText({ kind: 'single_choice', answer: { option: { label: '선택 A' } } }), '선택 A');
assert.equal(adapter.responseText({ kind: 'multiple_choice', answer: { options: [{ label: 'A' }, { label: 'B' }] } }), 'A, B');
assert.equal(adapter.responseText({ kind: 'checkbox', answer: { checked: false } }), '아니요');
assert.equal(adapter.responseText({ kind: 'number', answer: { decimal: '2.5', constraints: { unit: 'cm' } } }), '2.5 cm');
assert.equal(adapter.responseText({ kind: 'table', answer: { rows: [{ cells: [] }] } }), '표 1행');
assert.equal(adapter.responseText({ kind: 'file_upload', answer: { artifacts: ['artifact-a'] } }), '첨부 1개');
assert.equal(adapter.responseText({ kind: 'drawing', answer: { preview_artifact: 'artifact-b' } }), '그림 제출');
assert.equal(adapter.responseText({ kind: 'short_text', answer: { value: null } }), '응답 없음');

const submission = {
  id: '01K5SUBMISSION0000000000000',
  official_revision_id: '01K5REVISION0000000000000000',
  official_revision: {
    id: '01K5REVISION0000000000000000',
    content_hash: 'c'.repeat(64),
  },
};
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.evaluateSubmissionCommand('evaluate:command-1', submission))),
  {
    command_id: 'evaluate:command-1',
    expected: {
      expected_official_revision_id: submission.official_revision.id,
      expected_official_revision_hash: submission.official_revision.content_hash,
    },
    payload: {},
  }
);
assert.throws(
  () => adapter.evaluateSubmissionCommand('evaluate:command-2', { ...submission, official_revision_id: 'mismatch' }),
  /공식 제출본/
);

for (const marker of [
  "allPages(request, '/contexts?limit=100')",
  '/submission-evaluations?',
  '/submission-evaluation-candidates?',
  '/submission-evaluation-candidates/',
  "manual ? 'start-manual-assessment' : 'evaluate'",
  '/commands/review?',
  "'X-WP-Nonce'",
  "headers['Idempotency-Key']",
  'review_precondition',
  "submitCandidateEvaluation()",
  "not_evaluated: '평가 전'",
  "submitReview('accepted')",
  "submitReview(adjusted ? 'adjusted' : 'accepted'",
  "submitReview(decision, [], reason)",
  "decision === 'withdrawn'",
  "점수 승인 회수",
  "error?.status === 409",
  "source: 'ai_accepted'",
  "source: 'teacher_assigned'",
  "reason === 'other'",
  "safety_review_required",
  'textContent',
  'replaceChildren',
]) {
  assert.ok(source.includes(marker), `Student review script marker is missing: ${marker}`);
}
assert.ok(!source.includes('.innerHTML'), 'The review UI must not inject HTML from API projections.');
assert.ok(!source.includes('insertAdjacentHTML'), 'The review UI must not append untrusted HTML.');

for (const marker of [
  '.oj-ai-assessment-switch',
  '.oj-ai-submission-review__layout',
  'grid-template-columns: minmax(270px, 320px) minmax(0, 1fr)',
  '.oj-ai-submission-review__criterion',
  '.oj-ai-submission-review__evidence',
  '.oj-ai-submission-review__review-actions',
  '@media (max-width: 760px)',
  '@media (max-width: 500px)',
  'overflow-wrap: anywhere',
]) {
  assert.ok(style.includes(marker), `Student review responsive style marker is missing: ${marker}`);
}
assert.ok(!/linear-gradient|radial-gradient/i.test(style), 'The workbench must not use decorative gradients.');
assert.ok(!/font-size:\s*(?:clamp|min|max)\(/i.test(style), 'Font size must not scale with viewport width.');

for (const asset of [
  'resources/css/submission-evaluation-review-workbench.css',
  'resources/js/submission-evaluation-review-workbench.js',
]) {
  assert.ok(manifest.includes(asset), `Console asset registration is missing: ${asset}`);
}
for (const marker of [
  'data-oj-ai-assessment-shell',
  'data-assessment-tab="submission"',
  'id="oj-ai-submission-review-app"',
  'data-can-assess',
  '<option value="not_evaluated">평가 전</option>',
  'data-review-role="reject-dialog"',
]) {
  assert.ok(handler.includes(marker), `Integrated Page marker is missing: ${marker}`);
}

console.log('G4-IMP-03-S03 UI adapter contract passed: unevaluated submission intake, AI evaluation command, C34 CAS body, response kinds, safe DOM, responsive styling and Console asset registration.');

import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { missingPhpFragments } from '../../scripts/php-source-fragments.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const paths = {
  script: path.join(root, 'resources', 'js', 'submission-evaluation-review-workbench.js'),
  style: path.join(root, 'resources', 'css', 'submission-evaluation-review-workbench.css'),
  handler: path.join(root, 'src', 'Platform', 'Worksheet_Evaluation_Workbench_Page_Handler.php'),
  projector: path.join(root, 'src', 'Submission', 'Assessment', 'Domain', 'Submission_Evaluation_Query_Projector.php'),
  aiRepository: path.join(root, 'src', 'Submission', 'Assessment', 'Infrastructure', 'WordPress_Submission_Feedback_Repository.php'),
  reviewRepository: path.join(root, 'src', 'Submission', 'Assessment', 'Infrastructure', 'WordPress_Submission_Feedback_Review_Repository.php'),
};
const [source, style, handler, projector, aiRepository, reviewRepository] = await Promise.all(
  Object.values(paths).map((file) => readFile(file, 'utf8'))
);

const window = {};
const document = { querySelector: () => null };
vm.runInNewContext(source, { window, document, URLSearchParams, console }, { filename: paths.script });
const adapter = window.OJAISubmissionEvaluationReviewWorkbench;
assert.ok(adapter, 'The feedback command adapter must be available without a DOM root.');

const precondition = {
  expected_result_id: '01K6B00000000000000000001',
  expected_result_hash: '9'.repeat(64),
  expected_official_review_id: '01K6B00000000000000000002',
  expected_official_review_hash: '8'.repeat(64),
  expected_review_no: 1,
};
const latest = {
  id: '01K6B00000000000000000003',
  hash: 'a'.repeat(64),
  revision_no: 2,
  state: 'teacher_draft',
};
const feedback = {
  criterion_feedback: [{
    binding_key: 'b'.repeat(64),
    strength: '근거를 구체적으로 적었습니다.',
    next_step: '사례를 하나 더 연결해 보세요.',
  }],
  next_steps: ['다른 장소와 비교하기'],
  overall_feedback: '관찰 근거와 설명이 잘 연결되었습니다.',
};
const plain = (value) => JSON.parse(JSON.stringify(value));

assert.deepEqual(plain(adapter.generateFeedbackCommand('feedback-generate-1', precondition)), {
  command_id: 'feedback-generate-1',
  expected: {
    expected_official_review_id: precondition.expected_official_review_id,
    expected_official_review_hash: precondition.expected_official_review_hash,
  },
  payload: {},
});
assert.deepEqual(plain(adapter.reviewFeedbackCommand('feedback-direct-1', precondition, null, 'teacher_draft', 'direct', feedback, null)), {
  command_id: 'feedback-direct-1',
  expected: {
    expected_official_review_id: precondition.expected_official_review_id,
    expected_official_review_hash: precondition.expected_official_review_hash,
    expected_feedback_revision_id: null,
    expected_feedback_revision_hash: null,
    expected_feedback_revision_no: 0,
  },
  payload: { decision: 'teacher_draft', feedback, reason: null, source_mode: 'direct' },
});
assert.deepEqual(plain(adapter.reviewFeedbackCommand('feedback-edit-1', precondition, latest, 'teacher_draft', 'edit', feedback, null)), {
  command_id: 'feedback-edit-1',
  expected: {
    expected_official_review_id: precondition.expected_official_review_id,
    expected_official_review_hash: precondition.expected_official_review_hash,
    expected_feedback_revision_id: latest.id,
    expected_feedback_revision_hash: latest.hash,
    expected_feedback_revision_no: latest.revision_no,
  },
  payload: { decision: 'teacher_draft', feedback, reason: null, source_mode: 'edit' },
});
assert.deepEqual(plain(adapter.reviewFeedbackCommand('feedback-reject-1', precondition, latest, 'rejected', 'edit', null, '학생에게 전달하기 전에 다시 작성합니다.')), {
  command_id: 'feedback-reject-1',
  expected: {
    expected_official_review_id: precondition.expected_official_review_id,
    expected_official_review_hash: precondition.expected_official_review_hash,
    expected_feedback_revision_id: latest.id,
    expected_feedback_revision_hash: latest.hash,
    expected_feedback_revision_no: latest.revision_no,
  },
  payload: {
    decision: 'rejected',
    feedback: null,
    reason: '학생에게 전달하기 전에 다시 작성합니다.',
    source_mode: 'edit',
  },
});
assert.deepEqual(plain(adapter.approveFeedbackCommand('feedback-approve-1', precondition, latest)), {
  command_id: 'feedback-approve-1',
  expected: {
    expected_official_review_id: precondition.expected_official_review_id,
    expected_official_review_hash: precondition.expected_official_review_hash,
    expected_feedback_revision_id: latest.id,
    expected_feedback_revision_hash: latest.hash,
    expected_feedback_revision_no: latest.revision_no,
  },
  payload: {},
});
assert.throws(() => adapter.generateFeedbackCommand('', precondition), /피드백 요청/);
assert.throws(
  () => adapter.reviewFeedbackCommand('feedback-edit-2', precondition, null, 'teacher_draft', 'edit', feedback, null),
  /수정할 피드백/
);
assert.throws(
  () => adapter.reviewFeedbackCommand('feedback-reject-2', precondition, latest, 'rejected', 'direct', null, '기각'),
  /다시 작성할 이유/
);
assert.throws(() => adapter.approveFeedbackCommand('feedback-approve-2', precondition, null), /승인할 피드백/);

for (const marker of [
  "path = 'generate-feedback'",
  "path = 'review-feedback'",
  "path = 'approve-feedback'",
  "decision === 'teacher_draft'",
  "sourceMode === 'edit'",
  'expected_feedback_revision_hash',
  "latest?.state === 'approved'",
  "latest?.state === 'teacher_draft'",
  "detail.evaluation?.review?.official_scope !== 'this_evaluation'",
  "data-feedback-field=\"overall\"",
  "data-feedback-action",
  '피드백이 변경되었습니다. 다시 확인해 주세요.',
  '새 피드백을 승인했습니다. 학생 공개는 공개 화면에서 확인하세요.',
  'revokeReviewPermission',
  'textContent',
  'replaceChildren',
]) {
  assert.ok(source.includes(marker), `Feedback workbench marker is missing: ${marker}`);
}
assert.ok(!source.includes('.innerHTML'), 'Feedback content must never be injected with innerHTML.');
assert.ok(!source.includes('insertAdjacentHTML'), 'Feedback content must never use insertAdjacentHTML.');

for (const marker of [
  '.oj-ai-submission-review__feedback-editor',
  '.oj-ai-submission-review__feedback-criterion',
  '.oj-ai-submission-review__feedback-actions',
  '.oj-ai-submission-review__feedback-approved',
  '.oj-ai-submission-review__feedback-history',
  '@media (max-width: 760px)',
  '@media (max-width: 500px)',
]) {
  assert.ok(style.includes(marker), `Feedback responsive style marker is missing: ${marker}`);
}
assert.ok(!/linear-gradient|radial-gradient/i.test(style), 'The feedback workbench must not use decorative gradients.');

for (const marker of [
  'data-feedback-role="reject-dialog"',
  'data-feedback-field="reject-reason"',
  'data-feedback-action="confirm-reject"',
  '피드백 기각',
]) {
  assert.ok(handler.includes(marker), `Feedback Page marker is missing: ${marker}`);
}
for (const forbidden of ['expected_feedback_revision_hash', 'source_mode', 'feedback_schema', 'CAS']) {
  assert.ok(!handler.includes(forbidden), `Internal feedback contract copy leaked into static Page markup: ${forbidden}`);
}

for (const marker of ["'feedback'", "'hash'", "'profile'", "'review_precondition'"]) {
  assert.ok(projector.includes(marker), `Q17 feedback projection marker is missing: ${marker}`);
}
for (const repository of [aiRepository, reviewRepository]) {
  assert.ok(repository.includes("'oj_ai_submission_feedback_revisions'"), 'The literal feedback table is not fixed.');
  assert.ok(!repository.includes('$wpdb->prefix'), 'WordPress table prefix use is forbidden.');
  assert.ok(!repository.includes('wp_oj_ai_'), 'Legacy prefixed feedback table use is forbidden.');
  assert.ok(!repository.includes("'UPDATE ' . self::TABLE_FEEDBACK"), 'Feedback revisions must never be updated.');
  assert.ok(!repository.includes("'DELETE FROM ' . self::TABLE_FEEDBACK"), 'Feedback revisions must never be deleted.');
}
assert.deepEqual(missingPhpFragments(aiRepository, ['insert_or_fail( self::TABLE_FEEDBACK']), [], 'AI feedback must append a revision.');
assert.ok(reviewRepository.includes('self::TABLE_FEEDBACK,'), 'Teacher feedback must append through the fixed revision table.');

console.log('G4-IMP-04-S04 client contract passed: Q17 projection, exact C35-C37 bodies, official-review gate, safe DOM, permission revocation, responsive UI and append-only history.');

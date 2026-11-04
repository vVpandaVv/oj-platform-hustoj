import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/submission-evaluation-review-workbench.js', import.meta.url), 'utf8');
const window = {};
vm.runInNewContext(source, { window, document: { querySelector: () => null }, URLSearchParams });
const { reviewAttentionItems, reviewAttentionReasons, priorityLabel } = window.OJAISubmissionEvaluationReviewWorkbench;
const detail = {
  evaluation: { state: 'review_required', priority: { review_needed_count: 9 } },
  selected_result: { items: [
    { binding_key: 'clear', candidate_points: '0.0000', flags: [] },
    { binding_key: 'flagged', candidate_points: '2', flags: ['teacher_review_required', 'response_ambiguous'] },
    { binding_key: 'held', candidate_points: null, flags: ['evidence_limited', 'teacher_review_required'], abstain_reason: 'evidence_quote_mismatch' },
  ] },
};
const before = JSON.stringify(detail);
assert.deepEqual(Array.from(reviewAttentionItems(detail), item => item.binding_key), ['flagged', 'held'], 'Count criteria, not flags or stale list metadata.');
assert.equal(reviewAttentionItems(null).length, 0);
assert.equal(reviewAttentionItems({ evaluation: detail.evaluation }).length, 0);
assert.equal(reviewAttentionItems({ ...detail, selected_result: { ...detail.selected_result, provider_status: 'not_requested' } }).length, 0);
const approved = { ...detail, evaluation: { state: 'reviewed', review: { official_id: 'review-1' } } };
assert.equal(reviewAttentionItems(approved).length, 0);
assert.equal(reviewAttentionItems({ ...approved, evaluation: { state: 'review_required', review: { official_id: null } } }).length, 2);
assert.equal(reviewAttentionReasons(detail.selected_result.items[0]).length, 0);
assert.match(reviewAttentionReasons(detail.selected_result.items[1]).join(' '), /교사 확인.*응답 확인/);
assert.match(reviewAttentionReasons(detail.selected_result.items[2]).join(' '), /학생 답변이 부족하다는 뜻은 아닙니다/);
assert.match(reviewAttentionReasons({ candidate_points: null, flags: [] })[0], /교사 채점/);
assert.match(reviewAttentionReasons({ candidate_points: '1', flags: ['safety_review_required'] })[0], /사유·메모/);
assert.match(reviewAttentionReasons({ candidate_points: '1', flags: ['artifact_quality_issue'] })[0], /첨부파일 원본/);
assert.match(reviewAttentionReasons({ candidate_points: '1', flags: ['evidence_limited'] })[0], /뒷받침/);
assert.equal(reviewAttentionReasons({ candidate_points: '1', flags: ['teacher_review_required', 'teacher_review_required'] }).length, 1);
const unknown = reviewAttentionReasons({ candidate_points: '1', flags: ['private_internal_code'] }).join(' ');
assert.match(unknown, /추가 확인/);
assert.ok(!unknown.includes('private_internal_code'));
assert.equal(JSON.stringify(detail), before, 'Read-only attention projection must not mutate a result.');
const flaggedRow = { kind: 'evaluation', id: 'evaluation-1', state: 'review_required', candidate: { id: 'result-1', score: '8', maximum_score: '20' }, priority: { review_needed_count: 2 } };
const clearRow = { ...flaggedRow, id: 'evaluation-2', priority: { review_needed_count: 0 } };
assert.equal(priorityLabel(flaggedRow), '확인 필요 · 평가 항목 2개');
assert.equal(priorityLabel(clearRow), '', 'Equal scores do not imply equal attention flags.');
assert.equal(priorityLabel({ ...flaggedRow, state: 'reviewed', review: { official_id: 'review-1' } }), '', 'Approval must hide historical attention in the list.');
assert.equal(priorityLabel({ ...flaggedRow, review: { official_id: null } }), '확인 필요 · 평가 항목 2개');
const current = { ...detail, evaluation: { ...detail.evaluation, id: flaggedRow.id }, selected_result: { ...detail.selected_result, id: flaggedRow.candidate.id } };
assert.equal(priorityLabel({ ...flaggedRow, priority: { review_needed_count: 9 } }, current), '확인 필요 · 평가 항목 2개', 'Selected result details keep the count and displayed reasons aligned.');
assert.equal(priorityLabel(clearRow, current), '', 'Do not apply another student\'s flags.');
assert.equal(priorityLabel(flaggedRow, { ...current, selected_result: { id: 'older-result', items: [] } }), '확인 필요 · 평가 항목 2개', 'Historical selection must not rewrite latest list status.');
assert.equal(priorityLabel(flaggedRow, { ...current, selected_result: { ...current.selected_result, provider_status: 'not_requested' } }), '교사 채점 대기');
console.log('PASS review attention: criteria count, specific guidance, unknown flags, approval/withdrawal, manual grading and immutable results.');

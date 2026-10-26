import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/submission-evaluation-review-workbench.js', import.meta.url), 'utf8');
const window = {};
vm.runInNewContext(source, { window, document: { querySelector: () => null }, URLSearchParams, console });
const { runFailureMessage } = window.OJAISubmissionEvaluationReviewWorkbench;
const failed = { state: 'failed', operation: 'special_note_draft', last_error_code: 'student_note_schema_invalid' };
const legacy = runFailureMessage(failed, 'AI 특기사항 생성');
assert.match(legacy, /세부 원인이 기록되지 않았습니다/);
assert.match(legacy, /기존 점수와 승인 기록은 변경되지 않았습니다/);
assert.doesNotMatch(legacy, /인공지능|student_note_schema_invalid/);
const specific = runFailureMessage({ ...failed, last_error_message: 'AI가 반환한 근거 문구가 제공된 원문과 일치하지 않습니다.' }, 'AI 특기사항 생성');
assert.match(specific, /근거 문구/);
assert.match(specific, /다시 생성/);
assert.doesNotMatch(specific, /세부 원인이 기록되지 않았습니다/);
assert.equal(runFailureMessage({ ...failed, state: 'succeeded' }, 'AI 특기사항 생성'), null);
console.log('Student-note failure UI passed: specific reasons, honest legacy fallback and preserved records.');

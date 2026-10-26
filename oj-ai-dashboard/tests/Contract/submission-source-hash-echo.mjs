import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const window = {};
vm.runInNewContext(await readFile('resources/js/submission-evaluation-review-workbench.js', 'utf8'), { window, document: { querySelector: () => null }, URLSearchParams });
const { runFailureMessage, scoreInputRefreshCommand, retryScoreCommand } = window.OJAISubmissionEvaluationReviewWorkbench;
const run = { operation: 'score', state: 'failed', last_error_code: 'output_schema_invalid', last_error_message: '$.source_hash (not a sha256 hex string)' };
for (const path of [run.last_error_message, '$.source_hash (source mismatch)', '$.evaluation_ref (format)', '$.schema_id|schema_version|evaluation_ref|source_hash']) {
  const message = runFailureMessage({ ...run, last_error_message: path }, 'AI 평가');
  assert.match(message, /원본 제출 확인 정보/);
  assert.match(message, /학생 제출 내용은 변경되지 않았습니다/);
  assert.doesNotMatch(message, /\$\.|sha256|output_schema_invalid/);
}
assert.equal(runFailureMessage({ ...run, state: 'running' }, 'AI 평가'), null);
const detail = {
  evaluation: { id: 'evaluation-a', state: 'failed', latest_run: { id: 'run-a', state: 'failed', run_no: 1 }, submission: { id: 'submission-a', is_source_current: true } },
  source: { bindings: [{ binding_ref: 'B1' }] },
  configuration: { prompt_contract_version: 'student_submission_assessment_prompt.v9' },
  runs: [run],
};
assert.equal(scoreInputRefreshCommand(detail, 'retry-a'), null, 'Hash repair must reuse the stored input rather than expanding/rebuilding it.');
const command = retryScoreCommand(detail, 'retry-a');
assert.equal(command.expected.expected_run_id, 'run-a');
assert.equal(command.expected.expected_state, 'failed');
assert.equal(command.command_id, 'retry-a');
console.log('PASS source hash error UI: readable identity failure, retained failed-run history and explicit retry using unchanged input.');

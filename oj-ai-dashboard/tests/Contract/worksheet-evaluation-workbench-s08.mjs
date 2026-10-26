import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/worksheet-evaluation-workbench.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../resources/css/worksheet-evaluation-workbench.css', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../src/Platform/Worksheet_Evaluation_Workbench_Page_Handler.php', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../../src/Platform/Addon_Manifest.php', import.meta.url), 'utf8');
const windowStub = { crypto: webcrypto };
const documentStub = { querySelector() { return null; } };

vm.runInNewContext(source, {
  Boolean,
  Date,
  Error,
  Intl,
  JSON,
  Map,
  Math,
  Number,
  Object,
  String,
  TypeError,
  URLSearchParams,
  document: documentStub,
  window: windowStub,
});

const adapter = windowStub.OJAIWorksheetEvaluationWorkbench;
assert.ok(adapter, 'The evaluation adapter must be exported before a Page root exists.');
const revision = {
  id: '01HZY3M4N5P6Q7R8S9T0V1W2X3',
  document_hash: 'a'.repeat(64),
};
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.enqueueCommand('command-s08-evaluate', revision))),
  {
    command_id: 'command-s08-evaluate',
    expected: {
      expected_revision_id: revision.id,
      expected_source_hash: revision.document_hash,
    },
    payload: {},
  },
  'C10 must keep the exact revision ID and source hash envelope.',
);

const detail = {
  evaluation: {
    id: '01HZY3M4N5P6Q7R8S9T0V1W2X4',
    state: 'review_required',
    status: { latest_review_no: 2 },
    latest_run: {
      id: '01HZY3M4N5P6Q7R8S9T0V1W2X5',
      run_no: 3,
    },
  },
  result: { id: '01HZY3M4N5P6Q7R8S9T0V1W2X6' },
};
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.controlCommand('command-s08-control', detail))),
  {
    command_id: 'command-s08-control',
    expected: {
      expected_state: 'review_required',
      expected_run_id: detail.evaluation.latest_run.id,
      expected_run_no: 3,
    },
    payload: {},
  },
  'C11/C12 must use the latest run CAS values.',
);

const decisions = [{
  finding_key: 'b'.repeat(64),
  decision: 'dismissed',
  reason_code: 'not_applicable',
  note: '교사가 활동지 맥락을 확인했습니다.',
  resolution_revision_id: null,
  resolution_source_hash: null,
}];
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.reviewCommand('command-s08-review', detail, decisions, true))),
  {
    command_id: 'command-s08-review',
    expected: {
      expected_state: 'review_required',
      expected_result_id: detail.result.id,
      expected_review_no: 2,
    },
    payload: {
      complete_review: true,
      item_decisions: decisions,
    },
  },
  'C33 must keep result/review CAS and the exact cumulative decision list.',
);

for (const marker of [
  '/worksheet-evaluations?',
  '/worksheet-evaluations/${encodeURIComponent(evaluationId)}',
  '/commands/evaluate',
  '/commands/${action}',
  '/commands/review',
  "'X-WP-Nonce'",
  "headers['Idempotency-Key']",
  'expected_source_hash',
  'expected_run_id',
  'const fresh = await request(',
  'worksheet_evaluation_configuration_changed',
  'openNewEvaluation(worksheetId',
  '활동지 자체 평가 작업 설정과 실행 상태를 확인한 뒤 다시 점검해 주세요.',
  'expected_result_id',
  'expected_review_no',
  'resolution_revision_id',
  'resolution_source_hash',
  'external-processing-ack',
  "updateEnqueueState(problemMessage(error, '점검을 요청하지 못했습니다.'), 'error')",
  "state.enqueuePending ? '요청 중' : '점검 시작'",
  'textContent',
]) {
  assert.ok(source.includes(marker), `The evaluation adapter is missing ${marker}.`);
}
assert.equal(source.includes('innerHTML'), false, 'Server and AI text must never be rendered with innerHTML.');

for (const marker of [
  '.oj-ai-evaluation',
  '.oj-ai-evaluation__layout',
  '.oj-ai-evaluation__finding-review',
  'container: oj-ai-evaluation / inline-size',
  'container: oj-ai-evaluation-detail / inline-size',
  '@container oj-ai-evaluation (max-width: 860px)',
  '@container oj-ai-evaluation-detail (max-width: 600px)',
  'grid-template-columns: minmax(220px, 260px) minmax(0, 1fr)',
  'overflow-wrap: anywhere',
  '.oj-ai-evaluation__dialog-status[data-tone="error"]',
]) {
  assert.ok(styles.includes(marker), `The evaluation stylesheet is missing ${marker}.`);
}
assert.equal(styles.includes('linear-gradient'), false, 'The operational workbench must not use decorative gradients.');

for (const marker of [
  'Worksheet_Evaluation_Workbench_Page_Handler',
  'Addon_Capabilities::ASSESS_WORKSHEETS',
  'Addon_Capabilities::REVIEW_ASSESSMENTS',
  'data-consent-recorded',
  'data-settings-url',
  'oj-ai-worksheet-evaluation-app',
]) {
  assert.ok(page.includes(marker), `The evaluation Page handler is missing ${marker}.`);
}
for (const marker of [
  'resources/css/worksheet-evaluation-workbench.css',
  'resources/js/worksheet-evaluation-workbench.js',
  'self::CONSOLE_ASSESSMENTS',
  'new Worksheet_Evaluation_Workbench_Page_Handler(',
]) {
  assert.ok(manifest.includes(marker), `The add-on Manifest is missing ${marker}.`);
}

for (const contents of [source, styles, page, manifest]) {
  assert.equal(contents.includes('$wpdb->prefix'), false, 'The S08 workbench must not introduce a WordPress table prefix expression.');
  assert.equal(contents.includes('wp_oj_ai_'), false, 'The S08 workbench must not introduce a prefixed add-on table name.');
}

console.log('G2-IMP-05-S08 evaluation adapter contract passed: Q09/Q10, C10-C12, C33, nonce, idempotency, safe DOM, consent handoff and responsive UI markers.');

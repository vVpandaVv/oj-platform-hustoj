import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { missingPhpFragments } from '../../scripts/php-source-fragments.mjs';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const source = read('../../resources/js/worksheet-authoring-workbench.js');
const handler = read('../../src/Platform/Worksheet_Workbench_Page_Handler.php');
const styles = read('../../resources/css/worksheet-authoring-workbench.css');
const controller = read('../../src/Worksheet/Authoring/Presentation/Worksheet_Rest_Controller.php');
const approval = read('../../src/Worksheet/Authoring/Application/Approve_Worksheet_Revision.php');
const publication = read('../../src/Worksheet/Authoring/Application/Publish_Worksheet_Revision.php');
const fixture = read('../fixtures/worksheet-workbench-visual.html');
const e2e = read('../../scripts/e2e-worksheet-workbench.mjs');
const windowStub = { crypto: webcrypto, clearTimeout, setTimeout };

vm.runInNewContext(source, {
  Array,
  CustomEvent: class CustomEvent {},
  Date,
  Error,
  Intl,
  JSON,
  Math,
  Number,
  Object,
  Set,
  String,
  Uint8Array,
  URLSearchParams,
  document: { getElementById: () => null },
  window: windowStub,
});

const adapter = windowStub.OJAIWorksheetAuthoringWorkbench;
const revisionId = '01HZY3M4N5P6Q7R8S9T0V1W2X4';
const documentHash = 'a'.repeat(64);
const validationHash = 'b'.repeat(64);
const decisionHash = 'c'.repeat(64);
const plain = (value) => JSON.parse(JSON.stringify(value));

assert.deepEqual(plain(adapter.approvalCommandBody(
  'approve-command', revisionId, documentHash, validationHash, decisionHash
)), {
  command_id: 'approve-command',
  expected: {
    expected_state: 'draft',
    expected_revision_id: revisionId,
    expected_document_hash: documentHash,
    expected_validation_projection_hash: validationHash,
    expected_approval_decision_hash: decisionHash,
  },
  payload: {},
});
assert.deepEqual(plain(adapter.publicationCommandBody(
  'publish-command', revisionId, documentHash, decisionHash
)), {
  command_id: 'publish-command',
  expected: {
    expected_state: 'approved',
    expected_revision_id: revisionId,
    expected_document_hash: documentHash,
    expected_approval_decision_hash: decisionHash,
  },
  payload: {},
});
assert.deepEqual(plain(adapter.lifecycleCommandBody('archive-command', 'active', revisionId, 12)), {
  command_id: 'archive-command',
  expected: {
    expected_state: 'active',
    expected_revision_id: revisionId,
    expected_lock_version: 12,
  },
  payload: {},
});

for (const marker of [
  'data-role="release-controls"',
  'data-action="approve"',
  'data-action="publish"',
  'data-action="archive"',
  'data-action="restore-worksheet"',
]) {
  assert.ok(handler.includes(marker), `The workbench handler is missing ${marker}.`);
  assert.ok(fixture.includes(marker), `The browser fixture is missing ${marker}.`);
}

for (const marker of [
  '/commands/approve',
  '/commands/publish',
  '/commands/${operation}',
  'expected_approval_decision_hash',
  'expected_lock_version',
  'setEditorReadOnly',
]) {
  assert.ok(source.includes(marker), `The workbench adapter is missing ${marker}.`);
}

assert.deepEqual(missingPhpFragments(controller, [
  'approval_readiness_projection',
  "'approval' => $approval",
  "'status' => 'unavailable'",
]), [], 'C05 readiness response must retain exact approval fields.');
assert.ok(approval.includes('public function readiness('), 'C06 must expose its exact policy decision to C05.');
assert.ok(publication.includes('public function readiness('), 'C07 must expose stored C06 evidence to C05.');
assert.ok(!controller.includes('$wpdb->prefix'), 'S05 must not introduce WordPress-prefixed tables.');
assert.ok(styles.includes('.oj-ai-workbench__release'), 'The release controls need a stable layout.');
assert.ok(handler.includes('disabled>교사 검토 승인</button>'), 'Teacher approval must be clearly distinct from AI review.');
assert.ok(source.includes('AI 점검 미실행 · 교사 검토 승인 가능'), 'No-AI approval must be discoverable.');
assert.ok(styles.includes('grid-template-columns: repeat(2, minmax(0, 1fr))'), 'Mobile release controls must use stable tracks.');

for (const marker of [
  '__lastApprovalRequest',
  '__lastPublicationRequest',
  '__lastLifecycleRequest',
  '__lastRestoreRequest',
  'C06 workbench contract drifted',
  'C09 workbench contract drifted',
]) {
  assert.ok(fixture.includes(marker) || e2e.includes(marker), `The S05 browser evidence is missing ${marker}.`);
}

console.log('G2-IMP-06-S05 UI contract passed: C05 readiness, C06/C07/C08/C09 command bodies, responsive controls and recovery states.');

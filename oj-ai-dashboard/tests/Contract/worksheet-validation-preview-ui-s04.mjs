import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/worksheet-authoring-workbench.js', import.meta.url), 'utf8');
const handler = readFileSync(new URL('../../src/Platform/Worksheet_Workbench_Page_Handler.php', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../resources/css/worksheet-authoring-workbench.css', import.meta.url), 'utf8');
const fixture = readFileSync(new URL('../fixtures/worksheet-workbench-visual.html', import.meta.url), 'utf8');
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
assert.ok(adapter, 'The workbench adapter must be exported without a mounted Page root.');
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.validationCommandBody('validate-command', 'draft', revisionId))),
  {
    command_id: 'validate-command',
    expected: { expected_state: 'draft', expected_revision_id: revisionId },
    payload: {},
  },
  'C05 must keep its exact command envelope.'
);
assert.equal(
  adapter.previewRequestPath(revisionId, 'student'),
  `/worksheet-revisions/${revisionId}/preview?projection=student`
);
assert.equal(
  adapter.previewRequestPath(revisionId, 'print'),
  `/worksheet-revisions/${revisionId}/preview?projection=print&profile=student_blank`
);
assert.throws(() => adapter.previewRequestPath('invalid', 'student'), /다시 불러와/);
assert.throws(() => adapter.previewRequestPath(revisionId, 'teacher'), /종류를 확인/);
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.validationSummary({ status: 'ready', release_ready: true }))),
  { ready: true, title: '검증 완료', message: '내용을 확인했습니다.', tone: 'ready' }
);
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.validationSummary({
    status: 'blocked',
    release_ready: false,
    blockers: [{ code: 'worksheet_private_projection_leak' }],
  }))),
  { ready: false, title: '확인 필요', message: '공개할 수 없는 정보가 있습니다.', tone: 'blocked' }
);

for (const marker of [
  'data-action="validate"',
  'data-action="preview-open"',
  'data-role="validation-result"',
  'data-role="preview-shell"',
  'data-action="preview-student"',
  'data-action="preview-print"',
]) {
  assert.ok(handler.includes(marker), `The Page handler is missing ${marker}.`);
  assert.ok(fixture.includes(marker), `The visual fixture is missing ${marker}.`);
}

for (const marker of [
  '/commands/validate',
  'preview?projection=student',
  'preview?projection=print&profile=student_blank',
  'oj_ai.student_document',
  'oj_ai.worksheet_print',
  'replaceChildren',
]) {
  assert.ok(source.includes(marker), `The browser adapter is missing ${marker}.`);
}

const previewRenderer = source.slice(
  source.indexOf('function previewTextNode'),
  source.indexOf('function projectionDocument')
);
for (const forbidden of ['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'grading', 'correct_answer', 'teacher_note', 'rubric_ref']) {
  assert.ok(!previewRenderer.includes(forbidden), `The public preview renderer must not consume ${forbidden}.`);
}
for (const marker of ['position: fixed', 'z-index: 2147483000', 'width: min(720px, calc(100vw - 48px))', '@media (max-width: 760px)', '@media print']) {
  assert.ok(styles.includes(marker), `The preview layout is missing ${marker}.`);
}
for (const marker of ['__lastValidationRequest', "__lastPreviewKind = 'student'", "__lastPreviewKind = 'print'"]) {
  assert.ok(fixture.includes(marker), `The browser fixture is missing ${marker}.`);
}

console.log('G2-IMP-04-S04 validation and preview UI contract passed: C05, Q08 student/print, static safe renderer and responsive shell.');

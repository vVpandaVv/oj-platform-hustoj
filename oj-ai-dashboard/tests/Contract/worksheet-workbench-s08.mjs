import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/worksheet-authoring-workbench.js', import.meta.url), 'utf8');
const responseFieldSource = readFileSync(
  new URL('../../resources/plate/whole-editor/src/components/ResponseFieldElement.tsx', import.meta.url),
  'utf8'
);
const windowStub = {
  crypto: webcrypto,
  clearTimeout,
  setTimeout,
};
const documentStub = {
  getElementById() {
    return null;
  },
};
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
  document: documentStub,
  window: windowStub,
});

const adapter = windowStub.OJAIWorksheetAuthoringWorkbench;
assert.ok(adapter, 'The workbench adapter must be exported before a Page root exists.');
assert.deepEqual(
  Array.from(adapter.RESPONSE_KINDS),
  [
    'short_text',
    'long_text',
    'single_choice',
    'multiple_choice',
    'checkbox',
    'number',
    'table',
    'image_upload',
    'file_upload',
    'drawing',
  ],
  'The editor must expose the frozen 10 response kinds.'
);

const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
let idSequence = 1n;
function publicId() {
  let value = idSequence;
  idSequence += 1n;
  let encoded = '';
  while (value > 0n) {
    encoded = alphabet[Number(value % 32n)] + encoded;
    value /= 32n;
  }
  return encoded.padStart(26, '0');
}


const stagedAssetId = publicId();
const stagedImage = {
  id: stagedAssetId,
  original_name: '관찰 사진.png',
  mime_type: 'image/png',
  url: 'blob:worksheet-preview',
};
const imageNode = adapter.assetNodeFromReceipt(stagedImage);
assert.equal(imageNode.type, 'img');
assert.equal(imageNode.asset_id, stagedAssetId);
assert.equal(imageNode.alt, stagedImage.original_name);
assert.equal(imageNode.url, stagedImage.url);
assert.deepEqual(
  Array.from(adapter.collectDocumentAssetIds({ nodes: [imageNode] })),
  [stagedAssetId],
  'The save adapter must collect only referenced staged receipts.'
);
const recoveryImage = adapter.stripAssetPreviewUrls({ nodes: [imageNode] });
assert.equal(recoveryImage.nodes[0].url, undefined, 'Recovery data must not persist volatile blob URLs.');
const duplicateBody = adapter.duplicateCommandBody(
  'duplicate-command',
  'active',
  publicId()
);
assert.deepEqual(
  JSON.parse(JSON.stringify(duplicateBody)),
  {
    command_id: 'duplicate-command',
    expected: {
      expected_state: 'active',
      expected_revision_id: duplicateBody.expected.expected_revision_id,
    },
    payload: {},
  },
  'C03 must keep its exact command envelope and empty payload.'
);
function responseNode(kind) {
  const response = {
    type: 'oj_ai_response',
    node_id: publicId(),
    response_id: publicId(),
    kind,
    label: `${kind} 응답`,
    required: false,
    children: [{ text: '' }],
  };
  if (kind === 'single_choice' || kind === 'multiple_choice') {
    response.constraints = {
      options: [
        { option_id: publicId(), value: 'choice_1', label: '선택 1' },
        { option_id: publicId(), value: 'choice_2', label: '선택 2' },
      ],
    };
  }
  if (kind === 'table') {
    response.constraints = {
      row_mode: 'fixed',
      rows: [
        { row_id: publicId(), label: '행 1' },
        { row_id: publicId(), label: '행 2' },
      ],
      columns: [
        { column_id: publicId(), label: '내용', cell_type: 'short_text' },
        { column_id: publicId(), label: '확인', cell_type: 'checkbox' },
      ],
    };
  }
  if (kind === 'image_upload') {
    response.constraints = {
      mime_types: ['image/jpeg', 'image/png'],
      max_files: 1,
      max_bytes: 10485760,
      alt_required: true,
    };
  }
  if (kind === 'file_upload') {
    response.constraints = {
      mime_types: ['application/pdf'],
      max_files: 1,
      max_bytes: 10485760,
    };
  }
  if (kind === 'drawing') {
    response.constraints = {
      stroke_schema_version: 'excalidraw_v1',
      preview_mime_type: 'image/png',
      aspect_ratio: '1.414',
      max_strokes: 5000,
    };
  }
  return response;
}

const responseKinds = Array.from(adapter.RESPONSE_KINDS);
function collectByType(nodes, type) {
  const found = [];
  const visit = (node) => {
    if (!node || typeof node !== 'object') return;
    if (node.type === type) found.push(node);
    if (Array.isArray(node.children)) node.children.forEach(visit);
  };
  (Array.isArray(nodes) ? nodes : []).forEach(visit);
  return found;
}

const teacherDocument = {
  schema_id: 'oj_ai.worksheet_document',
  schema_version: '1.0.0',
  editor_profile: 'worksheet_plate@1',
  document_id: publicId(),
  locale: 'ko_KR',
  title: 'S08 활동지',
  nodes: [
    { type: 'h1', node_id: publicId(), children: [{ text: 'S08 활동지', bold: true }] },
    { type: 'p', node_id: publicId(), children: [{ text: '질문에 답하세요.' }] },
    ...responseKinds.map(responseNode),
  ],
  settings: {
    student_navigation: 'document',
    print_profile: 'worksheet_a4@1',
  },
};

const plateSchema = adapter.teacherDocumentToPlateSchema(teacherDocument);
assert.equal(plateSchema.engine, 'plate');
assert.equal(plateSchema.title, teacherDocument.title);
assert.equal(
  collectByType(plateSchema.plate_value, 'hws_response').length,
  10,
  'Q07 must load all 10 response kinds into Plate.'
);
assert.equal(
  plateSchema.plate_value.filter((node) => node.type === 'hws_question').length,
  10,
  'Legacy flat responses must load as distinct question blocks.'
);

const roundTrip = adapter.plateSchemaToTeacherDocument(
  plateSchema,
  teacherDocument,
  'S08 활동지 수정',
  'ko_KR'
);
assert.equal(roundTrip.schema_id, 'oj_ai.worksheet_document');
assert.equal(roundTrip.schema_version, '1.0.0');
assert.equal(roundTrip.editor_profile, 'worksheet_plate@1');
assert.equal(roundTrip.document_id, teacherDocument.document_id);
assert.equal(roundTrip.title, 'S08 활동지 수정');
assert.equal(roundTrip.nodes.filter((node) => node.type === 'h1').length, 1);

const roundTripResponses = collectByType(roundTrip.nodes, 'oj_ai_response');
assert.deepEqual(roundTripResponses.map((node) => node.kind), responseKinds);
assert.ok(
  !JSON.stringify(roundTrip).includes('hws_response'),
  'C02 must never receive the legacy Plate response type.'
);
assert.equal(roundTripResponses.find((node) => node.kind === 'single_choice').constraints.options.length, 2);
assert.equal(roundTripResponses.find((node) => node.kind === 'multiple_choice').constraints.options.length, 2);
assert.equal(roundTripResponses.find((node) => node.kind === 'table').constraints.rows.length, 2);
assert.equal(roundTripResponses.find((node) => node.kind === 'table').constraints.columns.length, 2);
assert.deepEqual(
  Array.from(roundTripResponses.find((node) => node.kind === 'file_upload').constraints.mime_types),
  ['application/pdf']
);
assert.equal(roundTripResponses.find((node) => node.kind === 'drawing').constraints.preview_mime_type, 'image/png');

const tableSchema = structuredClone(plateSchema);
tableSchema.plate_value.push({
  type: 'table', colSizes: [180, 420], children: [
    { type: 'tr', children: ['Item', 'Description'].map(text => ({ type: 'td', children: [{ type: 'p', children: [{ text }] }] })) },
    { type: 'tr', children: ['One', 'A detailed answer'].map(text => ({ type: 'td', children: [{ type: 'p', children: [{ text }] }] })) },
  ],
});
const saveTable = schema => adapter.plateSchemaToTeacherDocument(schema, teacherDocument, teacherDocument.title, 'ko_KR');
const sizedDocument = saveTable(tableSchema);
const sizedTable = collectByType(sizedDocument.nodes, 'table')[0];
assert.deepEqual(Array.from(sizedTable.column_widths), [180, 420]);
const reopenedTable = collectByType(adapter.teacherDocumentToPlateSchema(sizedDocument).plate_value, 'table')[0];
assert.deepEqual(Array.from(reopenedTable.colSizes), [180, 420], 'Column widths must survive reopening.');
tableSchema.plate_value.at(-1).colSizes = [180.4, 419.8];
assert.deepEqual(Array.from(collectByType(saveTable(tableSchema).nodes, 'table')[0].column_widths), [180, 420]);
tableSchema.plate_value.at(-1).colSizes = [null, 0];
assert.deepEqual(Array.from(collectByType(saveTable(tableSchema).nodes, 'table')[0].column_widths), [120, 120]);
tableSchema.plate_value.at(-1).colSizes = [12, 1800];
assert.deepEqual(Array.from(collectByType(saveTable(tableSchema).nodes, 'table')[0].column_widths), [40, 1600]);
tableSchema.plate_value.at(-1).colSizes = ['100px', 200];
assert.throws(() => saveTable(tableSchema), /./, 'CSS strings must not enter canonical widths.');
delete tableSchema.plate_value.at(-1).colSizes;
assert.equal(collectByType(saveTable(tableSchema).nodes, 'table')[0].column_widths, undefined, 'Old tables must retain their canonical shape.');
tableSchema.plate_value.at(-1).widthPercent = 80;
tableSchema.plate_value.at(-1).colSizes = [3000, 7000];
const relativeTable = collectByType(saveTable(tableSchema).nodes, 'table')[0];
assert.equal(relativeTable.width_percent, 80);
assert.equal(relativeTable.column_widths, undefined);
assert.deepEqual(Array.from(relativeTable.column_ratios), [3000, 7000]);
const reopenedRelative = collectByType(adapter.teacherDocumentToPlateSchema(saveTable(tableSchema)).plate_value, 'table')[0];
assert.equal(reopenedRelative.widthPercent, 80);
assert.deepEqual(Array.from(reopenedRelative.colSizes), [3000, 7000]);
tableSchema.plate_value.at(-1).colSizes = [1, 2];
const normalizedRatios = Array.from(collectByType(saveTable(tableSchema).nodes, 'table')[0].column_ratios);
assert.equal(normalizedRatios.reduce((sum, ratio) => sum + ratio, 0), 10000);
for (const invalid of [0, 101, 80.5, '80', null]) {
  tableSchema.plate_value.at(-1).widthPercent = invalid;
  assert.throws(() => saveTable(tableSchema));
}
delete tableSchema.plate_value.at(-1).widthPercent;
tableSchema.plate_value.at(-1).colSizes = [180, 420];
assert.equal(collectByType(saveTable(tableSchema).nodes, 'table')[0].column_ratios, undefined, 'Switching to px must remove relative properties.');

const insertedSchema = JSON.parse(JSON.stringify(plateSchema));
insertedSchema.plate_value.push({
  type: 'hws_question',
  question_id: 'tmp_10000000-0000-4000-8000-000000000001',
  children: [
    { type: 'h2', children: [{ text: '하나의 문항과 두 개의 답안' }] },
    {
      type: 'hws_response',
      kind: 'single_choice',
      label: '첫 번째 답안',
      required: true,
      options: ['하나', '둘'],
      children: [{ text: '' }],
    },
    {
      type: 'hws_response',
      kind: 'long_text',
      label: '두 번째 답안',
      required: false,
      children: [{ text: '' }],
    },
  ],
});
const withInserted = adapter.plateSchemaToTeacherDocument(
  insertedSchema,
  teacherDocument,
  teacherDocument.title,
  teacherDocument.locale
);
const insertedQuestion = withInserted.nodes.at(-1);
const insertedResponses = collectByType([insertedQuestion], 'oj_ai_response');
assert.equal(insertedQuestion.type, 'callout');
assert.equal(insertedQuestion.tone, 'notice');
assert.equal(insertedQuestion.icon, 'idea');
assert.equal(insertedResponses.length, 2, 'One question must preserve both independently configured responses.');
assert.deepEqual(insertedResponses.map((node) => node.label), ['첫 번째 답안', '두 번째 답안']);
assert.deepEqual(insertedResponses.map((node) => node.kind), ['single_choice', 'long_text']);
assert.match(insertedQuestion.node_id, /^tmp_[0-9a-f-]{36}$/);
assert.match(insertedResponses[0].node_id, /^tmp_[0-9a-f-]{36}$/);
assert.match(insertedResponses[0].response_id, /^tmp_[0-9a-f-]{36}$/);
assert.ok(insertedResponses[0].constraints.options.every((option) => /^tmp_[0-9a-f-]{36}$/.test(option.option_id)));

const temporaryMap = {
  [insertedQuestion.node_id]: publicId(),
  [insertedResponses[0].node_id]: publicId(),
  [insertedResponses[0].response_id]: publicId(),
};
const mapped = adapter.applyTemporaryIdMap(withInserted, temporaryMap);
const mappedResponses = collectByType([mapped.nodes.at(-1)], 'oj_ai_response');
assert.equal(mapped.nodes.at(-1).node_id, temporaryMap[insertedQuestion.node_id]);
assert.equal(mappedResponses[0].node_id, temporaryMap[insertedResponses[0].node_id]);
assert.equal(mappedResponses[0].response_id, temporaryMap[insertedResponses[0].response_id]);
assert.equal(withInserted.nodes.at(-1).node_id, insertedQuestion.node_id, 'Temporary ID mapping must not mutate its input.');

function verifyWithPhp(documentValue, label) {
  const executable = process.env.OJAI_PHP || 'php';
  const verifier = fileURLToPath(new URL('../fixtures/worksheet-workbench-document-verifier.php', import.meta.url));
  const result = spawnSync(executable, [verifier], {
    encoding: 'utf8',
    input: JSON.stringify(documentValue),
  });
  assert.equal(result.status, 0, `${label} was rejected by PHP: ${result.stderr}`);
  return JSON.parse(result.stdout);
}

const verifiedRoundTrip = verifyWithPhp(roundTrip, 'The 10-kind round trip');
assert.equal(verifiedRoundTrip.statistics.response_count, 10);
assert.equal(Object.keys(verifiedRoundTrip.temporary_id_map).length, 0);
const verifiedInserted = verifyWithPhp(withInserted, 'The newly inserted question with two responses');
assert.equal(verifiedInserted.statistics.response_count, 12);
assert.ok(Object.keys(verifiedInserted.temporary_id_map).length >= 7);

// The editor's list toolbar/markdown shortcuts build @platejs/list-classic's
// real ul>li>lic block tree (never the older flat "paragraph + listStyleType"
// shape), so the canonicalizer must accept that shape for bulleted, numbered
// (including a custom start number) and nested lists without throwing
// "목록은 목록 블록으로 다시 넣어 주세요." (list_profile_unsupported).
function licNode(text) {
  return { type: 'lic', children: [{ text }] };
}
function liNode(text, extraChildren = []) {
  return { type: 'li', children: [licNode(text), ...extraChildren] };
}
const listPlateValue = [
  { type: 'h1', children: [{ text: '목록 테스트' }] },
  {
    type: 'ul',
    children: [liNode('사과'), liNode('바나나')],
  },
  {
    type: 'ol',
    start: 5,
    children: [liNode('다섯'), liNode('여섯')],
  },
  {
    type: 'ul',
    children: [
      liNode('부모 항목', [
        { type: 'ul', children: [liNode('자식 항목')] },
      ]),
    ],
  },
];
const listDocument = adapter.plateSchemaToTeacherDocument(
  listPlateValue,
  teacherDocument,
  '목록 테스트',
  'ko_KR'
);
const bulletedList = listDocument.nodes.find((node) => node.type === 'ul' && collectByType(node.children, 'li').some((li) => collectByType(li.children, 'p').some((p) => p.children[0]?.text === '사과')));
assert.ok(bulletedList, 'A bulleted list must survive canonicalization as a ul node.');
assert.equal(bulletedList.children.length, 2, 'The bulleted list must keep both list items.');
assert.equal(bulletedList.children[0].type, 'li');
assert.equal(bulletedList.children[0].children[0].type, 'p');
assert.equal(bulletedList.children[0].children[0].children[0].text, '사과');
assert.equal(bulletedList.children[1].children[0].children[0].text, '바나나');

const numberedList = listDocument.nodes.find((node) => node.type === 'ol');
assert.ok(numberedList, 'A numbered list must survive canonicalization as an ol node.');
assert.equal(numberedList.start, 5, 'A non-default numbered-list start value must be preserved.');
assert.equal(numberedList.children[0].children[0].children[0].text, '다섯');

const nestedList = listDocument.nodes.find((node) => node.type === 'ul' && collectByType(node.children, 'p').some((p) => p.children[0]?.text === '부모 항목'));
assert.ok(nestedList, 'A nested list must survive canonicalization.');
const nestedParentItem = nestedList.children[0];
assert.equal(nestedParentItem.type, 'li');
assert.equal(nestedParentItem.children[0].type, 'p');
assert.equal(nestedParentItem.children[0].children[0].text, '부모 항목');
const nestedSublist = nestedParentItem.children.find((child) => child.type === 'ul');
assert.ok(nestedSublist, 'A list item must keep its nested sublist.');
assert.equal(nestedSublist.children[0].children[0].children[0].text, '자식 항목');

assert.throws(
  () => adapter.plateSchemaToTeacherDocument(
    [
      { type: 'h1', children: [{ text: '목록 테스트' }] },
      { type: 'p', listStyleType: 'disc', indent: 1, children: [{ text: '레거시 목록' }] },
    ],
    teacherDocument,
    '목록 테스트',
    'ko_KR'
  ),
  (error) => error instanceof adapter.WorkbenchError && error.code === 'list_profile_unsupported',
  'The legacy flat listStyleType shape must still be rejected as a defensive fallback.'
);

for (const marker of [
  '/worksheets/${encodeURIComponent(state.worksheetId)}',
  '/worksheet-revisions/${encodeURIComponent(revisionId)}',
  '/commands/save-draft',
  'Idempotency-Key',
  'oj_ai_worksheet_recovery_v1:',
  'showConflict(error, localDraft)',
  '저장 중',
  '저장됨',
  '/commands/stage-asset',
  '/commands/import-template',
  '/commands/duplicate',
  'staged_asset_receipt_ids: stagedReceiptIds',
  "new CustomEvent('ojai-plate-asset-insert'",
  "window.addEventListener('ojai-plate-response-grading-change'",
  'settings.body instanceof window.FormData',
  'duplicateCommandBody(commandToken',
  'recovery.staged_assets.length === 0',
  'achievement_links: achievementLinks',
  'achievement_links: clone(state.achievementLinks)',
  'temporaryMap[link.response_id]',
  '/curriculums?limit=${encodeURIComponent(String(catalogPageLimit))}',
  '/standards?limit=${encodeURIComponent(String(catalogPageLimit))}',
  'settings.learning_goals',
  "control.dataset.field === 'learning-goal-target'",
  "control.dataset.field === 'learning-goal-edit'",
  'renderAssessmentMap()',
  'assessment_bindings: normalizeAssessmentBindings(assessmentBindings)',
  'hasExplicitAssessmentBindings(state.currentDocument)',
  "control.dataset.field === 'assessment-enabled'",
  "control.dataset.field === 'assessment-target'",
  'achievement-add-target',
  "control.dataset.field === 'achievement-target'",
]) {
  assert.ok(source.includes(marker), `The S08/S09 workbench marker is missing: ${marker}`);
}
assert.ok(
  responseFieldSource.includes("new CustomEvent('ojai-plate-response-grading-change'"),
  'The Plate response field must publish grading changes to the workbench.'
);
console.log('G2-IMP-02-S08/S09 browser adapter contract passed: Q05/Q07, 10 response kinds, C02 canonical IDs, recovery and conflict states.');

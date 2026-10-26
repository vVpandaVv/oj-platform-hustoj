import { access, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const errors = [];

async function read(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

async function exists(relativePath) {
  try {
    await access(path.join(root, relativePath));
    return true;
  } catch {
    return false;
  }
}

function expect(content, pattern, label) {
  const ok = pattern instanceof RegExp ? pattern.test(content) : content.includes(pattern);
  if (!ok) {
    errors.push(`${label} missing`);
  } else {
    console.log(`[OK] ${label}`);
  }
}

async function expectFile(relativePath, label) {
  if (!await exists(relativePath)) {
    errors.push(`${label} missing`);
  } else {
    console.log(`[OK] ${label}`);
  }
}

const readme = await read('resources/plate/whole-editor/README.md');
expect(readme, 'HARNESS: plate-whole-editor-runtime', 'Plate runtime harness marker');
for (const layer of ['PlateProvider', 'EditorShell', 'Toolbar', 'FloatingToolbar', 'SlashMenu', 'ContextMenu', 'PortalRoot']) {
  expect(readme, layer, `Plate layer ${layer}`);
}

const editorSource = await read('resources/plate/whole-editor/src/components/PlateTemplateEditor.tsx');
expect(editorSource, 'usePlateEditor', 'Whole editor usePlateEditor source');
expect(editorSource, '<Plate', 'Whole editor Plate component source');
expect(editorSource, 'EditorContainer', 'Whole editor shell source');
expect(editorSource, 'ojai-plate-template-load', 'External template load event');
for (const kind of ['single_choice', 'multiple_choice', 'checkbox', 'number', 'image_upload', 'file_upload', 'drawing']) {
  expect(editorSource, kind, `Response insert kind ${kind}`);
}

const responseSource = await read('resources/plate/whole-editor/src/components/ResponseFieldElement.tsx');
expect(responseSource, 'contentEditable={false}', 'Plate void response editor controls');
expect(responseSource, 'hws-response-node__student-table', 'Student table preview');
expect(responseSource, 'tableCellPreview', 'Table cell input preview');
expect(responseSource, 'image_upload', 'Image upload response type');
expect(responseSource, 'file_upload', 'File upload response type');

const addonManifest = await read('src/Platform/Addon_Manifest.php');
expect(addonManifest, 'plate-template-builder.js', 'OJ Console Plate script asset');
expect(addonManifest, 'plate-template-builder.css', 'OJ Console Plate stylesheet asset');
const workbenchPage = await read('src/Platform/Worksheet_Workbench_Page_Handler.php');
expect(workbenchPage, 'ws-plate-template-builder-root', 'OJ Console Plate root');
expect(workbenchPage, 'ws-builder-schema', 'OJ Console builder schema field');
expect(workbenchPage, 'ws-render-schema', 'OJ Console render schema field');
const authoringWorkbench = await read('resources/js/worksheet-authoring-workbench.js');
expect(authoringWorkbench, 'ojai-plate-template-load', 'OJ Console Plate load config');

expect(authoringWorkbench, 'ws-builder-schema', 'Workbench reads Plate schema');
expect(authoringWorkbench, 'plate_value', 'Workbench sends Plate value');
expect(authoringWorkbench, '/commands/save-draft', 'Workbench save command');

const css = await read('resources/css/worksheet-authoring-workbench.css');
expect(css, '.oj-ai-workbench__workspace', 'Current workspace CSS selector');
expect(css, '.oj-ai-workbench__canvas', 'Current editor canvas CSS selector');

const plateService = await read('src/Worksheet/Plate_Document_Service.php');
expect(plateService, 'teacher_schema', 'Server teacher schema generator');
expect(plateService, 'student_schema', 'Server student schema generator');
expect(plateService, 'grading_schema', 'Server grading schema generator');
expect(plateService, 'hws_response', 'Server accepts original Plate response node');
expect(plateService, 'question_id', 'Stable question id support');
expect(plateService, 'block_id', 'Stable block id support');

const restController = await read('src/Worksheet/Authoring/Presentation/Worksheet_Rest_Controller.php');
for (const route of ['/worksheets', '/worksheet-revisions', '/commands/save-draft', '/commands/duplicate', '/commands/archive', '/commands/restore']) {
  expect(restController, route, `Worksheet REST route ${route}`);
}

const packageJson = await read('package.json');
expect(packageJson, '"build:plate"', 'Plate build npm script');
expect(packageJson, '"e2e:criteria-first"', 'Current Plate visual e2e npm script');

await expectFile('resources/assets/builder/plate-template-builder.js', 'Built Plate script');
await expectFile('resources/assets/builder/plate-template-builder.css', 'Built Plate stylesheet');

const plateE2e = await read('scripts/e2e-criteria-first-workflow.mjs');
expect(plateE2e, 'resources/assets/builder/plate-template-builder.js', 'Plate visual fixture real script');
expect(plateE2e, '[data-slate-editor]', 'Plate visual editor assertion');
expect(plateE2e, 'canNavigate', 'Saved and unsaved navigation assertions');
expect(plateE2e, 'add-question', 'Current question insertion assertion');

if (errors.length > 0) {
  for (const error of errors) {
    console.error(error);
  }
  process.exit(1);
}

console.log('Plate editor integration checks passed.');

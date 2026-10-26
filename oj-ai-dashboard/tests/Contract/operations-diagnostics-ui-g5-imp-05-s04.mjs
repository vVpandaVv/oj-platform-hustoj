import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const files = {
  renderer: await readFile(path.join(root, 'resources/js/operations-diagnostics.js'), 'utf8'),
  settings: await readFile(path.join(root, 'resources/js/assessment-configuration-settings.js'), 'utf8'),
  styles: await readFile(path.join(root, 'resources/css/operations-diagnostics.css'), 'utf8'),
  fixture: await readFile(path.join(root, 'tests/fixtures/assessment-configuration-settings-visual.html'), 'utf8'),
};

const errors = [];
const requireMarkers = (name, source, markers) => {
  markers.forEach((marker) => {
    if (!source.includes(marker)) errors.push(`${name} is missing ${marker}`);
  });
};

requireMarkers('renderer', files.renderer, [
  "'oj_ai.diagnostics@1.0.0'",
  "['bridge', 'schema', 'queue', 'storage', 'ai_routing']",
  "`${root.dataset.restRoot}/diagnostics`",
  "method: 'GET'",
  "credentials: 'same-origin'",
  "cache: 'no-store'",
  "headers['X-WP-Nonce']",
  "aggregateStatus(checks) !== value.status",
  "root.dataset.access = access",
  "clearRows()",
  "'시스템 상태를 볼 수 없습니다.'",
  "'AI 작업 배정 열기'",
  "normalizeAiTasks(item.tasks)",
  "aiTaskList(item.tasks)",
  "AI 평가 작업 상태",
  "'플랫폼 상태 열기'",
  "'관리자 확인 필요'",
  "'다시 확인'",
]);
requireMarkers('settings tabs', files.settings, [
  "['policy', 'rubric', 'review-rule'].includes(name)",
  'app.hidden = !configurationVisible',
  'elements.subjectSwitch.hidden = !configurationVisible',
  'elements.refresh.hidden = !configurationVisible',
]);
requireMarkers('responsive styles', files.styles, [
  '.oj-ai-diagnostics__row',
  'grid-template-columns: 34px minmax(0, 1fr) minmax(150px, auto)',
  '@media (max-width: 700px)',
  '@media (max-width: 390px)',
  'overflow-wrap: anywhere',
  ".oj-ai-diagnostics__tasks",
  "grid-template-columns: minmax(0, 1fr) auto",
  'letter-spacing: 0',
]);
requireMarkers('visual fixture', files.fixture, [
  'data-settings-tab="system"',
  'id="oj-ai-operations-diagnostics"',
  'window.__diagnosticsPermissionRevoked',
  'window.__diagnosticsMode',
  '../../resources/js/operations-diagnostics.js',
  '../../resources/css/operations-diagnostics.css',
]);

for (const forbidden of ['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'eval(', 'new Function(', 'item.code', 'item.details', 'item.issues', 'item.path', 'item.metrics']) {
  if (files.renderer.includes(forbidden)) errors.push(`renderer uses forbidden source: ${forbidden}`);
}
for (const forbidden of ['$wpdb->prefix', 'wp_oj_ai_', 'provider_key', 'model_key', 'original_name', 'storage_key', 'sha256']) {
  if (files.renderer.includes(forbidden)) errors.push(`renderer embeds forbidden internal field: ${forbidden}`);
}

if (errors.length > 0) {
  console.error('G5-IMP-05-S04 UI source contract failed:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log('G5-IMP-05-S04 UI source contract passed: exact five checks, allowlisted actions, DOM-safe rendering, fail-closed revocation and responsive settings integration.');

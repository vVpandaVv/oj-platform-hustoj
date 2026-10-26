import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (file) => readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const handler = read('src/Platform/External_Ai_Consent_Settings_Page_Handler.php');
const manifest = read('src/Platform/Addon_Manifest.php');
const icons = read('src/Platform/Addon_Icon.php');
const sprite = read('resources/icons/lucide.svg');
const client = read('resources/js/data-management-settings.js');
const styles = read('resources/css/data-management-settings.css');
const evaluation = read('resources/js/worksheet-evaluation-workbench.js');
const submissionReview = read('resources/js/submission-evaluation-review-workbench.js');

for (const marker of [
  'can_manage_data',
  'Addon_Capabilities::MANAGE_MIGRATIONS',
  "current_user_can( 'delete_plugins' )",
  'data-settings-tab="data"',
  '데이터는 자동으로 삭제되지 않습니다.',
  '플러그인을 비활성화하거나 제거해도 활동지와 평가 결과는 보존됩니다.',
  '이전 데이터 가져오기',
  '모든 데이터 영구 삭제',
  '최근 백업을 확인했습니다.',
  '<strong>영구 삭제</strong>를 입력하세요.',
]) {
  assert.ok(handler.includes(marker), `Settings data management surface is missing ${marker}.`);
}

for (const marker of [
  "SOURCE_PROFILE = 'oj_ai_dashboard_wpdb_prefixed@0.1.2'",
  "'/migration-runs/commands/preview'",
  "'/migration-runs/commands/approve'",
  '/commands/execute',
  "'/purge-jobs/commands/preview'",
  "'/purge-jobs/commands/approve'",
  "confirmation: { phrase: '영구 삭제' }",
  "evidence_digest: evidence",
  "expected: { preview_hash: job.preview_hash, state: 'approved' }",
  "expected: { plan_hash: run.plan_hash, source_fingerprint: run.source.fingerprint, state: 'approved' }",
]) {
  assert.ok(client.includes(marker), `Data management client is missing ${marker}.`);
}
assert.equal(client.includes('innerHTML'), false, 'Data management must use safe DOM APIs.');
assert.ok(client.includes("elements.purgePhrase?.value.trim() === '영구 삭제'"), 'Purge must require the exact confirmation phrase.');
assert.ok(client.includes('state.migration.idempotencyKey'), 'Migration preview and approval must share signed idempotency evidence.');
assert.ok(client.includes('state.purge.idempotencyKey'), 'Purge preview and approval must share signed idempotency evidence.');

for (const marker of [
  'resources/css/data-management-settings.css',
  'resources/js/data-management-settings.js',
]) {
  assert.ok(manifest.includes(marker), `Settings asset bundle is missing ${marker}.`);
}
assert.ok(styles.includes('@media (max-width: 620px)'), 'Data management needs a mobile layout.');
assert.equal(styles.includes('linear-gradient'), false, 'Operational data management must not use decorative gradients.');

for (const marker of ['database', 'download', 'refresh-cw', 'settings', 'trash', 'x']) {
  assert.ok(icons.includes(`'${marker}'`), `Shared icon allowlist is missing ${marker}.`);
  assert.ok(sprite.includes(`id="${marker}"`), `Lucide sprite is missing ${marker}.`);
}
assert.ok(manifest.includes('resources/icons/lucide.svg') === false, 'The sprite is referenced by markup and must not be enqueued as a script or stylesheet.');

assert.equal(evaluation.includes('${evidence.anchor_type} · ${evidence.field}'), false, 'Evaluation UI must not expose internal evidence paths.');
assert.ok(evaluation.includes("response: '응답칸을 확인하세요.'"), 'Evaluation UI needs a complete user-facing response evidence message.');
assert.ok(evaluation.includes("'기타 평가 항목'"), 'Unknown dimension keys need a user-facing fallback.');
assert.ok(evaluation.includes("'기타 사유'"), 'Unknown review reason keys need a user-facing fallback.');
assert.ok(evaluation.includes("iconButton('refresh-cw'"), 'Evaluation refresh must use the shared Lucide sprite.');
assert.ok(submissionReview.includes("iconButton('refresh-cw'"), 'Submission review refresh must use the shared Lucide sprite.');

for (const source of [handler, evaluation, submissionReview]) {
  assert.equal(/&#(?:9881|8635|215);|↻|×|✕/.test(source), false, 'Platform-integrated UI still contains a character icon.');
}

console.log('Data management, safe evaluation wording, and Lucide UI source contract passed.');

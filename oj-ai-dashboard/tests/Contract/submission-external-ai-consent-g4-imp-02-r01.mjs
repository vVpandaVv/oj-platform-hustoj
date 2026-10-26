import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { missingPhpFragments } from '../../scripts/php-source-fragments.mjs';

const source = readFileSync(new URL('../../resources/js/external-ai-consent-settings.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../resources/css/external-ai-consent-settings.css', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../src/Platform/External_Ai_Consent_Settings_Page_Handler.php', import.meta.url), 'utf8');
const controller = readFileSync(new URL('../../src/Submission/Assessment/Presentation/Submission_External_Ai_Consent_Rest_Controller.php', import.meta.url), 'utf8');
const repository = readFileSync(new URL('../../src/Submission/Assessment/Infrastructure/WordPress_Submission_External_Ai_Consent_Repository.php', import.meta.url), 'utf8');
const consentAdapter = readFileSync(new URL('../../src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Execution_Consent.php', import.meta.url), 'utf8');
const composition = readFileSync(new URL('../../src/Core/Composition_Root.php', import.meta.url), 'utf8');
const deactivation = readFileSync(new URL('../../src/Core/Deactivation.php', import.meta.url), 'utf8');

const windowStub = { crypto: webcrypto };
const documentStub = { querySelectorAll() { return []; } };
vm.runInNewContext(source, { Boolean, Date, Error, Intl, JSON, Math, Number, Object, String, TypeError, document: documentStub, window: windowStub });
const adapter = windowStub.OJAIExternalAiConsentSettings;
assert.ok(adapter, 'Consent command helpers must remain available without a rendered Page.');
const missing = { consent: { state: 'missing', consent_hash: null }, routing: { routing_revision: 'routing-2', profile_fingerprint: 'b'.repeat(64) } };
assert.deepEqual(JSON.parse(JSON.stringify(adapter.approveCommand('command-g4-r01', missing))), {
  command_id: 'command-g4-r01',
  expected: { expected_state: 'missing', expected_consent_hash: null, expected_routing_revision: 'routing-2', expected_profile_fingerprint: 'b'.repeat(64) },
  payload: { acknowledged_external_processing: true },
});

for (const marker of [
  "'/settings/submission-external-ai-consent'",
  "'/settings/submission-external-ai-consent/commands/'",
  "document.querySelectorAll('[data-consent-root]')",
  "headers['X-WP-Nonce']",
  "headers['Idempotency-Key']",
  'textContent',
]) assert.ok(source.includes(marker), `Submission consent adapter is missing ${marker}.`);
const requestBlock = source.slice(source.indexOf('async function request'), source.indexOf('const response = await window.fetch'));
assert.ok(
  requestBlock.indexOf("headers['X-WP-Nonce']") >= 0
    && requestBlock.indexOf("headers['X-WP-Nonce']") < requestBlock.indexOf("if (method !== 'GET')"),
  'Cookie-authenticated submission-consent GET requests must send the wp_rest nonce before method branching.',
);
assert.equal(source.includes('innerHTML'), false, 'Consent projection must use safe DOM writes.');

for (const marker of [
  'data-consent-tab="worksheet"',
  'data-consent-tab="submission"',
  'oj-ai-submission-external-consent-app',
  '/settings/submission-external-ai-consent',
  'submission_external_ai_consent',
  '학생이 입력한 답변',
  '제출한 자료의 텍스트',
  '평가 정책과 루브릭',
  '활동지 채점 내용',
]) assert.ok(page.includes(marker), `Settings Page is missing ${marker}.`);

for (const marker of ['.oj-ai-consent-settings__scope', '[data-consent-panel][hidden]', 'grid-template-columns: repeat(2, minmax(0, 1fr))']) {
  assert.ok(styles.includes(marker), `Submission consent styles are missing ${marker}.`);
}
assert.equal(styles.includes('linear-gradient'), false, 'Operational consent UI must not use decorative gradients.');

assert.deepEqual(missingPhpFragments(controller, [
  "public const ROUTE_STATUS = '/settings/submission-external-ai-consent'",
  "public const ROUTE_APPROVE = '/settings/submission-external-ai-consent/commands/approve'",
  "public const ROUTE_REVOKE = '/settings/submission-external-ai-consent/commands/revoke'",
  "public const ACTION_READ = 'submission_external_ai.consent.read'",
  "array( 'submission_external_ai_consent' => $status )",
]), [], 'Q38/C78/C79 controller PHP contract changed.');

assert.deepEqual(missingPhpFragments(repository, [
  "private const TABLE_OUTBOX = 'oj_ai_outbox_events'",
  "private const TABLE_AUDIT = 'oj_ai_audit_events'",
  "private const AGGREGATE_TYPE = 'submission_external_ai_consent'",
  "private const AGGREGATE_PUBLIC_ID = '00000000000000000000000002'",
  "private const LOCK_OPTION = 'oj_ai_dashboard_submission_external_ai_consent_lock'",
]), [], 'Submission consent ledger PHP contract changed.');
for (const marker of [
  'START TRANSACTION',
  'previous_event_hash',
]) assert.ok(repository.includes(marker), `Submission consent ledger is missing ${marker}.`);
assert.equal(repository.includes('legacy_record'), false, 'Submission consent must not use an implicit option fallback.');
assert.equal(repository.includes('synchronize_legacy_cache'), false, 'Submission consent must have one authoritative ledger.');

for (const marker of ['callable $loader', 'Closure::fromCallable', 'Submission_External_Ai_Consent_Record::from_array']) {
  assert.ok(consentAdapter.includes(marker), `Worker consent adapter is missing ${marker}.`);
}
for (const forbidden of ['get_option', 'public const OPTION', '?callable $loader']) {
  assert.equal(consentAdapter.includes(forbidden), false, `Worker consent adapter must not contain ${forbidden}.`);
}

assert.deepEqual(missingPhpFragments(composition, [
  'new WordPress_Submission_External_Ai_Consent_Repository(',
  'new Submission_External_Ai_Consent_Rest_Module(',
  'new Submission_Evaluation_Worker_Module(',
  'static fn () => $submission_external_ai_consent_repository->current_record()',
]), [], 'Submission consent composition PHP contract changed.');
assert.ok(deactivation.includes('Submission_Evaluation_Worker_Module::clear();'), 'Deactivation must clear the submission evaluation worker.');

for (const contents of [source, styles, page, controller, repository, consentAdapter, composition, deactivation]) {
  assert.equal(contents.includes('$wpdb->prefix'), false, 'G4 R01 must not introduce a WordPress table prefix expression.');
  assert.equal(contents.includes('wp_oj_ai_'), false, 'G4 R01 must not introduce a prefixed add-on table name.');
}
assert.equal(repository.includes('CodingPanda\\OjPlatform\\Kernel'), false, 'Consent ledger must not use platform internals.');

console.log('G4-IMP-02-R01 consent UI contract passed: dual target switch, Q38/C78/C79, safe DOM, isolated ledger, explicit worker loader and deactivation.');

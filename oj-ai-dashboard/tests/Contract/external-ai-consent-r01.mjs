import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { missingPhpFragments } from '../../scripts/php-source-fragments.mjs';

const source = readFileSync(new URL('../../resources/js/external-ai-consent-settings.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../resources/css/external-ai-consent-settings.css', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../src/Platform/External_Ai_Consent_Settings_Page_Handler.php', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../../src/Platform/Addon_Manifest.php', import.meta.url), 'utf8');
const evaluation = readFileSync(new URL('../../resources/js/worksheet-evaluation-workbench.js', import.meta.url), 'utf8');
const repository = readFileSync(new URL('../../src/Worksheet/Evaluation/Infrastructure/WordPress_External_Ai_Consent_Repository.php', import.meta.url), 'utf8');
const composition = readFileSync(new URL('../../src/Core/Composition_Root.php', import.meta.url), 'utf8');

const windowStub = { crypto: webcrypto };
const documentStub = { querySelector() { return null; } };
vm.runInNewContext(source, {
  Boolean,
  Date,
  Error,
  Intl,
  JSON,
  Math,
  Object,
  String,
  TypeError,
  document: documentStub,
  window: windowStub,
});

const adapter = windowStub.OJAIExternalAiConsentSettings;
assert.ok(adapter, 'The consent adapter must be exported before the settings root exists.');
const missing = {
  consent: { state: 'missing', consent_hash: null },
  routing: {
    routing_revision: 'routing-1',
    profile_fingerprint: 'a'.repeat(64),
  },
};
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.approveCommand('command-r01-approve', missing))),
  {
    command_id: 'command-r01-approve',
    expected: {
      expected_state: 'missing',
      expected_consent_hash: null,
      expected_routing_revision: 'routing-1',
      expected_profile_fingerprint: 'a'.repeat(64),
    },
    payload: { acknowledged_external_processing: true },
  },
  'C76 must carry exact consent and routing CAS evidence.',
);

const approved = {
  consent: { state: 'approved', consent_hash: 'b'.repeat(64) },
  routing: { available: true },
};
assert.deepEqual(
  JSON.parse(JSON.stringify(adapter.revokeCommand('command-r01-revoke', approved))),
  {
    command_id: 'command-r01-revoke',
    expected: {
      expected_state: 'approved',
      expected_consent_hash: 'b'.repeat(64),
    },
    payload: {},
  },
  'C77 must carry exact consent CAS evidence and an empty payload.',
);

for (const marker of [
  '/settings/external-ai-consent',
  '/settings/external-ai-consent/commands/',
  "headers['X-WP-Nonce']",
  "headers['Idempotency-Key']",
  'acknowledged_external_processing',
  'expected_routing_revision',
  'expected_profile_fingerprint',
  'textContent',
  "window.confirm('외부 AI 처리를 중단할까요?')",
  "['ArrowLeft', 'ArrowRight', 'Home', 'End']",
  "selectScope('worksheet')",
]) {
  assert.ok(source.includes(marker), `The consent settings adapter is missing ${marker}.`);
}
const requestBlock = source.slice(source.indexOf('async function request'), source.indexOf('const response = await window.fetch'));
assert.ok(
  requestBlock.indexOf("headers['X-WP-Nonce']") >= 0
    && requestBlock.indexOf("headers['X-WP-Nonce']") < requestBlock.indexOf("if (method !== 'GET')"),
  'Cookie-authenticated consent GET requests must send the wp_rest nonce before method branching.',
);
assert.equal(source.includes('innerHTML'), false, 'Consent status must not be rendered with innerHTML.');

for (const marker of [
  '.oj-ai-consent-settings',
  '.oj-ai-consent-settings__content',
  '.oj-ai-consent-settings__actions',
  '@media (max-width: 700px)',
  'grid-template-columns: repeat(2, minmax(0, 1fr))',
  'overflow-wrap: anywhere',
  ':focus-visible',
]) {
  assert.ok(styles.includes(marker), `The consent settings stylesheet is missing ${marker}.`);
}
assert.equal(styles.includes('linear-gradient'), false, 'The operational settings Page must not use decorative gradients.');

for (const marker of [
  'External_Ai_Consent_Settings_Page_Handler',
  'Addon_Capabilities::MANAGE_POLICIES',
  'oj-ai-external-consent-app',
  'data-rest-root',
  'data-rest-nonce',
  '전송 항목',
  '승인 철회',
  'id="oj-ai-consent-tab-worksheet"',
  'role="tabpanel" aria-labelledby="',
]) {
  assert.ok(page.includes(marker), `The consent settings Page handler is missing ${marker}.`);
}

for (const marker of [
  'resources/css/external-ai-consent-settings.css',
  'resources/js/external-ai-consent-settings.js',
  'self::CONSOLE_SETTINGS',
  'new External_Ai_Consent_Settings_Page_Handler(',
  'Addon_Capabilities::MANAGE_POLICIES',
]) {
  assert.ok(manifest.includes(marker), `The add-on Manifest is missing ${marker}.`);
}

for (const marker of [
  "request('/settings/external-ai-consent')",
  "consentState === 'approved'",
  'loadConsentStatus()',
]) {
  assert.ok(evaluation.includes(marker), `The evaluation workbench is missing live consent status marker ${marker}.`);
}

assert.deepEqual(missingPhpFragments(repository, [
  "private const TABLE_OUTBOX = 'oj_ai_outbox_events'",
  "private const TABLE_AUDIT = 'oj_ai_audit_events'",
]), [], 'Consent table constants must match independent of formatting.');
for (const marker of [
  'START TRANSACTION',
  'previous_event_hash',
  'idempotency_key',
]) {
  assert.ok(repository.includes(marker), `The consent repository is missing ${marker}.`);
}
assert.ok(composition.includes('current_record()'), 'The evaluation worker must consume the authoritative consent ledger.');

for (const contents of [source, styles, page, manifest, evaluation, repository, composition]) {
  assert.equal(contents.includes('$wpdb->prefix'), false, 'R01 must not introduce a WordPress table prefix expression.');
  assert.equal(contents.includes('wp_oj_ai_'), false, 'R01 must not introduce a prefixed add-on table name.');
}
assert.equal(repository.includes('OJ\\Platform\\'), false, 'The consent repository must not use OJ Platform internals.');

console.log('G2-IMP-05-R01 consent UI contract passed: Q37/C76/C77 envelopes, nonce, idempotency, safe DOM, settings Page and worker ledger handoff.');

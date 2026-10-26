import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { missingPhpFragments } from '../../scripts/php-source-fragments.mjs';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const repository = read('src/Assessment/Configuration/Infrastructure/WordPress_Assessment_Configuration_Repository.php');
const profile = read('src/Assessment/Configuration/Domain/Assessment_Configuration_Profile.php');
const controller = read('src/Assessment/Configuration/Presentation/Assessment_Configuration_Rest_Controller.php');
const handler = execFileSync(process.env.OJAI_PHP || 'php', [fileURLToPath(new URL('../fixtures/render-workflow-page.php', import.meta.url)), 'settings'], { encoding: 'utf8' });
const script = read('resources/js/assessment-configuration-settings.js');
const styles = read('resources/css/assessment-configuration-settings.css');
const manifest = read('src/Platform/Addon_Manifest.php');
const qualityReader = read('src/Worksheet/Evaluation/Infrastructure/WordPress_Worksheet_Evaluation_Configuration.php');
const publicationReader = read('src/Worksheet/Authoring/Infrastructure/WordPress_Worksheet_Publication_Configuration.php');

for (const source of [repository, controller]) {
  assert.equal(source.includes('$wpdb->prefix'), false, 'Configuration runtime must not derive prefixed tables.');
  assert.equal(/wp_oj_ai_/i.test(source), false, 'Legacy prefixed configuration candidates are forbidden.');
}

assert.deepEqual(missingPhpFragments(repository, [
  "private const TABLE_AUDIT  = 'oj_ai_audit_events'",
  "private const TABLE_OUTBOX = 'oj_ai_outbox_events'",
]), [], 'Configuration table constants must match independent of formatting.');
for (const marker of [
  'START TRANSACTION',
  'previous_event_hash',
  'idempotency_storage_key',
  'seed_defaults',
]) {
  assert.ok(repository.includes(marker), `Configuration repository is missing ${marker}.`);
}
assert.ok(profile.includes('$this->policy( $subject_type, $value, $strict )'), 'Stored policy projection must honor relaxed historical validation.');
assert.ok(profile.includes('assert_activatable_revision'), 'Configuration activation must retain current-contract validation.');
assert.ok(repository.includes('$this->profile->assert_activatable_revision( $target )'), 'Repository activation must enforce the current contract before writes.');


for (const marker of [
  'ROUTE_POLICIES', 'ROUTE_POLICY_REVISIONS', 'ROUTE_POLICY_ACTIVATE',
  'ROUTE_RUBRICS', 'ROUTE_RUBRIC_REVISIONS', 'ROUTE_RUBRIC_ACTIVATE',
  'ROUTE_REVIEW_RULES', 'ROUTE_REVIEW_RULE_REVISIONS', 'ROUTE_REVIEW_RULE_ACTIVATE',
  'Addon_Capabilities::MANAGE_POLICIES',
]) {
  assert.ok(controller.includes(marker), `Configuration REST controller is missing ${marker}.`);
}

for (const marker of [
  '평가 정책', '평가 기준', '검토·승인', 'AI 연결', '시스템 상태',
  'oj-ai-assessment-configuration-app', 'oj-ai-external-consent-app',
  'aria-controls="oj-ai-settings-panel-policy"', 'aria-labelledby="oj-ai-settings-tab-policy"',
  'data-worksheet-prompt-contract', 'data-worksheet-input-schema', 'data-worksheet-output-schema',
]) {
  assert.ok(handler.includes(marker), `Settings Page is missing ${marker}.`);
}

for (const marker of [
  "policy: Object.freeze({ list: '/settings/policies'",
  "rubric: Object.freeze({ list: '/settings/rubrics'",
  "review_rule: Object.freeze({ list: '/settings/review-rules'",
  'createRevisionCommand', 'activateRevisionCommand', 'data-config-item',
  "['ArrowLeft', 'ArrowRight', 'Home', 'End']", 'moveTabFocus',
  'worksheetPolicyContract', 'policyUpdateRequired', 'policyUsesActiveReferences', 'currentWorksheetPolicy',
]) {
  assert.ok(script.includes(marker), `Settings client is missing ${marker}.`);
}
const requestBlock = script.slice(script.indexOf('async function request'), script.indexOf('const response = await window.fetch'));
assert.ok(
  requestBlock.indexOf("headers['X-WP-Nonce']") >= 0
    && requestBlock.indexOf("headers['X-WP-Nonce']") < requestBlock.indexOf("if (method !== 'GET')"),
  'Cookie-authenticated configuration GET requests must send the wp_rest nonce before method branching.',
);
assert.equal(script.includes('innerHTML'), false, 'Settings client must use DOM APIs instead of HTML injection.');
assert.equal(styles.includes('linear-gradient'), false, 'Operational settings UI must not use decorative gradients.');
assert.ok(styles.includes('@media (max-width: 760px)'), 'Settings UI needs a mobile layout.');
assert.ok(styles.includes(':focus-visible'), 'Settings UI needs an explicit keyboard focus indicator.');
assert.ok(manifest.includes('resources/js/assessment-configuration-settings.js'), 'Manifest must load the settings client.');
assert.ok(manifest.includes('resources/css/assessment-configuration-settings.css'), 'Manifest must load the settings stylesheet.');
assert.ok(qualityReader.includes("array( 'active', 'retired' )"), 'Worksheet-quality policy pins must remain readable after default replacement.');
assert.ok(publicationReader.includes("array( 'active', 'retired' )"), 'Publication policy pins must remain readable after default replacement.');

console.log('Assessment configuration settings UI/source contract passed.');
console.log('- intuitive five-tab Console settings UI');
console.log('- Q25-Q27 and C55-C60 client wiring');
console.log('- immutable pinned retired revision readability');

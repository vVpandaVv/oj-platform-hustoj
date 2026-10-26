<?php
/**
 * Real WordPress/MariaDB integration. Creates a NEW explicitly test-only database.
 * Never point this at a production database/account. No database is dropped.
 * OJ_ML_TEST_WP_PATH, _DB_HOST, _DB_USER, _DB_PASSWORD, _DB_NAME, _STORAGE required.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
function requireEnv(string $key): string {
    $value = getenv('OJ_ML_TEST_' . $key);
    if (!is_string($value) || $value === '') { throw new RuntimeException('Missing test environment: ' . $key); }
    return $value;
}
$dbName = requireEnv('DB_NAME');
if (!preg_match('/^release_[a-z0-9_]+_oj_ml_test$/D', $dbName)) { throw new RuntimeException('Refusing non-test database'); }
$host = requireEnv('DB_HOST');
[$server, $port] = array_pad(explode(':', $host), 2, '3306');
if (!in_array($server, ['127.0.0.1', 'localhost', 'mysql'], true)) { throw new RuntimeException('Test DB must be local or CI service'); }
$connection = new mysqli($server, requireEnv('DB_USER'), requireEnv('DB_PASSWORD'), '', (int) $port);
$connection->query('CREATE DATABASE ' . $dbName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$connection->close();
define('ABSPATH', rtrim(requireEnv('WP_PATH'), '/\\') . '/');
define('DB_NAME', $dbName); define('DB_USER', requireEnv('DB_USER'));
define('DB_PASSWORD', requireEnv('DB_PASSWORD')); define('DB_HOST', $host);
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
define('WP_INSTALLING', true); define('WP_DEBUG', false);
define('WP_HTTP_BLOCK_EXTERNAL', true); define('DISABLE_WP_CRON', true);
define('WP_HOME', 'http://127.0.0.1:33781'); define('WP_SITEURL', WP_HOME);
define('AUTH_KEY', bin2hex(random_bytes(32))); define('LOGGED_IN_KEY', bin2hex(random_bytes(32)));
define('NONCE_KEY', bin2hex(random_bytes(32))); define('SECURE_AUTH_KEY', bin2hex(random_bytes(32)));
define('OJ_ML_PLAYGROUNDS_PRIVATE_DIR', requireEnv('STORAGE'));
$table_prefix = 'mltest_';
$_SERVER['HTTP_HOST'] = '127.0.0.1:33781';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/';
require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install('ML Release Integration', 'release_admin', 'release@example.invalid', false, '', bin2hex(random_bytes(16)));
$plugin = getenv('OJ_ML_TEST_PLUGIN_PATH') ?: dirname(__DIR__, 2);
$plugin = realpath($plugin) ?: throw new RuntimeException('Test plugin path missing');
require_once $plugin . '/oj-ml-playgrounds.php';
use OJML\Playgrounds\Database\Installer;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Database\SchemaUpgrade;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Ops\Lease;
use OJML\Playgrounds\Ops\Options;
use OJML\Playgrounds\Ops\Privacy;
use OJML\Playgrounds\Ops\StorageMaintenance;
use OJML\Playgrounds\Ops\InspectionWorker;
use OJML\Playgrounds\Inspection\MlpxInspector;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use CodingPanda\OjPlatform\AddonApi\V1 as Api;

$checks = 0;
function check(bool $passed, string $label): void {
    global $checks;
    if (!$passed) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); throw new RuntimeException('FAIL: ' . $label); }
    $checks++; echo 'PASS: ' . $label . PHP_EOL;
}
function rejects(callable $call, string $code): void {
    try { $call(); } catch (Problem $error) { check($error->problemCode() === $code, 'reject ' . $code); return; }
    throw new RuntimeException('Expected failure: ' . $code);
}
Installer::install();
check(get_option(Installer::DB_VERSION_OPTION) === '4', 'fresh schema version 4');
SchemaUpgrade::verify($wpdb);
wp_set_current_user(1);
$healthResponse = rest_do_request(new WP_REST_Request('GET', '/oj-ml/v1/console/health'));
check($healthResponse->get_status() === 200 && isset($healthResponse->get_data()['data']['health']['checks']), 'independent REST health without OJ runtime');
$blockedSettings = rest_do_request(new WP_REST_Request('POST', '/oj-ml/v1/console/settings'));
check($blockedSettings->get_status() === 403, 'operator settings require nonce');
wp_set_current_user(0);
$blockedHealth = rest_do_request(new WP_REST_Request('GET', '/oj-ml/v1/console/health'));
check($blockedHealth->get_status() === 401, 'anonymous health denied');
$owner = $wpdb->get_var("SELECT payload_json FROM oj_ml_events WHERE event_type = 'installation.bound'");
check(str_contains((string) $owner, 'mltest_'), 'installation owner binding');
$repo = new Repository($wpdb);
$student = wp_insert_user(['user_login' => 'student_one', 'user_email' => 'student_one@example.invalid',
    'user_pass' => bin2hex(random_bytes(16)), 'display_name' => '학생 하나', 'role' => 'subscriber']);
check(is_int($student), 'real WordPress learner');
$policy = ['allowedFormatVersions' => [2], 'allowedTaskTypes' => ['classification'], 'allowedDataTypes' => ['tabular'],
    'allowedAlgorithms' => [], 'maxArtifactBytes' => 26214400, 'maxRows' => 10000, 'maxFeatures' => 100,
    'allowLateSubmission' => false, 'requireIntegrityManifest' => false];
$draft = $repo->createAssignment(['title' => '통합 시험', 'description' => '개인별 재제출 검증', 'task_type' => 'classification',
    'context_type' => 'class', 'context_id' => 'integration-class', 'context_revision' => 'r1', 'roster_revision' => 'r1',
    'policy' => $policy, 'rubric' => [], 'opens_at' => null, 'due_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    'max_attempts' => 1, 'created_by' => 1, 'targets' => [['type' => 'class', 'key' => 'integration-class']], 'learners' => [$student]]);
$assignment = $repo->publishAssignment($draft['id'], 1, 1);
$id = $assignment['id'];
$first = $repo->createAttempt($id, $student, 'key-one', '0.12.1', 2)['attempt'];
$same = $repo->createAttempt($id, $student, 'key-one', '0.12.1', 2);
check(!$same['created'] && $same['attempt']['uuid'] === $first['uuid'], 'idempotent start');
$store = new PrivateArtifactStore();
$tmp = tmpfile(); fwrite($tmp, 'invalid MLPX fixture');
$saved = $store->putUploadedFile(stream_get_meta_data($tmp)['uri'], 26214400);
$artifact = ['uuid' => $saved->uuid, 'storage_key' => $saved->storageKey, 'original_name' => 'fixture.mlpx',
    'sha256' => $saved->sha256, 'bytes' => $saved->bytes, 'mime_type' => $saved->mimeType];
$received = $repo->attachArtifact($first['uuid'], $student, $artifact);
check($received['status'] === 'quarantined' && $received['received_at'] !== null, 'durable receipt before inspection');
check((int) $wpdb->get_var("SELECT COUNT(*) FROM oj_ml_events WHERE event_type = 'attempt.submitted'") === 1, 'atomic receipt audit');
$duplicate = $repo->attachArtifact($first['uuid'], $student, $artifact);
check($duplicate['artifact_id'] === $received['artifact_id'], 'idempotent receipt');
$worker = new InspectionWorker($repo, $store, new MlpxInspector());
$inspected = $worker->process($first['uuid'], 1, wp_generate_uuid4());
check($inspected['status'] === 'needs_resubmit', 'worker rejects invalid archive without execution');
$beforeReconsider = $repo->getAttemptResult($first['uuid']);
$reconsiderKey = wp_generate_uuid4();
$reconsidered = $repo->queueReconsideration($first['uuid'], 1, $beforeReconsider['evaluation']['id'], '서버 문제 확인 후 원본 재검토', $reconsiderKey, wp_generate_uuid4());
check($reconsidered['created'] && $reconsidered['attempt']['status'] === 'system_pending'
    && $reconsidered['attempt']['evaluation']['id'] === $beforeReconsider['evaluation']['id'], 'reconsideration queues inspection and preserves prior evaluation');
check($reconsidered['attempt']['artifact']['sha256'] === $beforeReconsider['artifact']['sha256']
    && $reconsidered['attempt']['received_at'] === $beforeReconsider['received_at']
    && $repo->attemptsUsed($id, $student) === 1 && hash_file('sha256', $store->pathFor($saved->storageKey)) === $saved->sha256,
    'reconsideration preserves original bytes receipt and learner allowance');
$reconsiderReplay = $repo->queueReconsideration($first['uuid'], 1, $beforeReconsider['evaluation']['id'], '서버 문제 확인 후 원본 재검토', $reconsiderKey, wp_generate_uuid4());
check(!$reconsiderReplay['created'] && (int) $wpdb->get_var("SELECT COUNT(*) FROM oj_ml_events WHERE event_type='reconsideration.queued'") === 1, 'reconsideration idempotency returns the existing request');
rejects(fn () => $repo->queueReconsideration($first['uuid'], 1, $beforeReconsider['evaluation']['id'], '다른 사유', $reconsiderKey, wp_generate_uuid4()), 'OJ_ML_RECONSIDER_IDEMPOTENCY_CONFLICT');
$worker->process($first['uuid'], 1, wp_generate_uuid4());
$afterReconsider = $repo->getAttemptResult($first['uuid']);
check($afterReconsider['status'] === 'needs_resubmit' && $afterReconsider['evaluation']['id'] > $beforeReconsider['evaluation']['id'], 'reconsideration rechecks the file without overriding rejection');
check(!$repo->queueReconsideration($first['uuid'], 1, $beforeReconsider['evaluation']['id'], '서버 문제 확인 후 원본 재검토', $reconsiderKey, wp_generate_uuid4())['created'], 'completed reconsideration still replays idempotently');
rejects(fn () => $repo->queueReconsideration($first['uuid'], 1, $beforeReconsider['evaluation']['id'], '오래된 검사', wp_generate_uuid4(), wp_generate_uuid4()), 'OJ_ML_RECONSIDER_VERSION_CONFLICT');
$wpdb->suppress_errors();
$wpdb->query("CREATE TRIGGER ml_fixture_deny_reconsider BEFORE INSERT ON oj_ml_events FOR EACH ROW BEGIN IF NEW.event_type = 'reconsideration.queued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture queued audit failure'; END IF; END");
$auditBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM oj_ml_events WHERE event_type='reconsideration.requested'");
rejects(fn () => $repo->queueReconsideration($first['uuid'], 1, $afterReconsider['evaluation']['id'], '롤백 검증', wp_generate_uuid4(), wp_generate_uuid4()), 'OJ_ML_EVENT_SAVE_FAILED');
check($repo->getAttemptResult($first['uuid'])['status'] === 'needs_resubmit'
    && (int) $wpdb->get_var("SELECT COUNT(*) FROM oj_ml_events WHERE event_type='reconsideration.requested'") === $auditBefore,
    'queued audit failure rolls back reconsideration status and requested audit');
$wpdb->query('DROP TRIGGER ml_fixture_deny_reconsider');
rejects(fn () => $repo->createAttempt($id, $student, 'key-two', '0.12.1', 2), 'OJ_ML_ATTEMPT_LIMIT_REACHED');
$allowance = $repo->grantAllowance($id, $student, 0, 3, gmdate('Y-m-d H:i:s', time() + 7200), '교사 승인', 1, wp_generate_uuid4());
check((int) $allowance['override_revision'] === 1 && (int) $allowance['max_attempts_override'] === 3, 'individual allowance');
rejects(fn () => $repo->grantAllowance($id, $student, 0, 4, null, 'stale', 1, wp_generate_uuid4()), 'OJ_ML_REVISION_CONFLICT');
$second = $repo->createAttempt($id, $student, 'key-two', '0.12.1', 2)['attempt'];
check($second['attempt_no'] === 2, 'resubmission after allowance');
$saved2 = $store->putUploadedFile(stream_get_meta_data($tmp)['uri'], 26214400);
$repo->attachArtifact($second['uuid'], $student, ['uuid' => $saved2->uuid, 'storage_key' => $saved2->storageKey,
    'original_name' => 'review-fixture.mlpx', 'sha256' => $saved2->sha256, 'bytes' => $saved2->bytes, 'mime_type' => $saved2->mimeType]);
// Repository transition fixture, not an inspector acceptance claim.
$repo->completeInspection($second['id'], 'accepted', 'accepted', [], [], null);
$repo->appendReview($second['id'], 1, 0, ['status' => 'draft', 'score' => 80, 'max_score' => 100, 'feedback' => '교사 초안']);
check($repo->getAttemptResult($second['uuid'], $student)['review'] === null, 'private draft hidden from learner');
$repo->releaseReview($second['id'], 1, 1, wp_generate_uuid4());
$repo->appendReview($second['id'], 1, 2, ['status' => 'draft', 'score' => 90, 'max_score' => 100, 'feedback' => '비공개 수정']);
check($repo->getAttemptResult($second['uuid'], $student)['review']['feedback'] === '교사 초안', 'released version remains visible while new draft is private');
rejects(fn () => $repo->releaseReview($second['id'], 1, 1), 'OJ_ML_REVIEW_VERSION_CONFLICT');
// Downgrade only the shape of this disposable fixture, then test real 3 → 4 DDL.
$wpdb->query('ALTER TABLE oj_ml_reviews DROP COLUMN details_json');
update_option(Installer::DB_VERSION_OPTION, '3');
rejects(fn () => Installer::maybeUpgrade(), 'OJ_ML_MIGRATION_REQUIRED');
Installer::install();
check(get_option(Installer::DB_VERSION_OPTION) === '4'
    && $repo->getAttemptResult($second['uuid'], $student)['review']['score'] === 80.0
    && $repo->getAttemptResult($second['uuid'], $student)['review']['details'] === null,
    'populated schema 3 to 4 upgrade preserves legacy published score without inventing rubric details');
$history = $repo->listAttemptHistory($id, $student, 1, 20, true);
check($history['total'] === 2 && count($history['items']) === 2, 'all attempt history');
check($repo->listResults($id, 1, 20, null, null, '학생 하나')['total'] === 1
    && $repo->listResults($id, 1, 20, null, null, "x%' OR 1=1 --")['total'] === 0, 'parameterized learner search');
Options::save(['maintenanceMode' => true]);
check(($worker->recover()['maintenance'] ?? false) === true, 'maintenance pauses recovery');
wp_set_current_user($student);
$permission = \OJML\Playgrounds\Rest\Permissions::learnerMutation(new WP_REST_Request('POST'));
check(is_wp_error($permission) && $permission->get_error_code() === 'OJ_ML_MAINTENANCE', 'maintenance blocks student mutations');
Options::save(['maintenanceMode' => false]);
rejects(fn () => Options::save(['maxArtifactBytes' => 999999999]), 'OJ_ML_SETTING_INVALID');
$wpdb->query($wpdb->prepare("UPDATE oj_ml_attempts SET status='quarantined',inspection_retries=4,updated_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE),next_inspection_at=NULL WHERE id=%d", $first['id']));
check(in_array($first['uuid'], $repo->pendingAttempts(), true), 'pending inspection is recoverable');
$worker->recover();
$wpdb->query($wpdb->prepare("UPDATE oj_ml_attempts SET status='quarantined',updated_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE),next_inspection_at=NULL WHERE id=%d", $first['id']));
check(!in_array($first['uuid'], $repo->pendingAttempts(), true), 'five failed inspections stop automatic retries');
check(count($repo->listPublishedAssignmentsForLearner($student, 1, 0)) === 1
    && count($repo->listPublishedAssignmentsForLearner($student, 1, 1)) === 0, 'SQL learner pagination');

// Exercise the real service/repository/presenter with a bounded public-API Context fixture.
// This is not a claim of school OJ login or roster end-to-end validation.
$platform = getenv('OJ_PLATFORM_TEST_ROOT') ?: dirname(__DIR__, 3) . '/oj-integration-rebuild/oj-platform';
if (!is_file($platform . '/src/Kernel/Autoloader.php')) { throw new RuntimeException('Public OJ API source is required for service integration'); }
require_once $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
$contexts = new class($student) implements Api\AddonLearningContextGatewayV1, Api\AddonAssignmentRosterGatewayV1 {
    public array $teachers = [1];
    public bool $learnerActive = true;
    public function __construct(private readonly int $learner) {}
    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 { throw new RuntimeException('Unused fixture method'); }
    public function listLearningContexts(Api\AddonLearningContextQueryV1 $query): Api\AddonContextPageV1 { throw new RuntimeException('Unused fixture method'); }
    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 { throw new RuntimeException('Unused fixture method'); }
    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1 {
        if ($request->contextId() !== 'template-fixture' || !in_array($request->actorUserId(), [...$this->teachers, $this->learner], true)) {
            throw new RuntimeException('Unexpected fixture Context request');
        }
        return new Api\AddonContextAccessDecisionV1(true, '', new Api\AddonContextSnapshotV1(
            'class', 'template-fixture', '양식 통합 수업', $this->learnerActive ? 'active' : 'inactive', 'ctx-r1', in_array($request->actorUserId(), $this->teachers, true) ? 'teacher' : 'learner'
        ), 'corr-template');
    }
    public function listAssignmentLearners(Api\AddonAssignmentRosterQueryV1 $query): Api\AddonAssignmentLearnerPageV1 {
        return new Api\AddonAssignmentLearnerPageV1(true, [new Api\AddonAssignmentLearnerV1($this->learner, 'member-r1')], '', 'roster-r1', null);
    }
};
$ai = new class implements Api\AddonAiTaskGatewayV1 {
    public function canRoute(string $taskKey): Api\AddonAiTaskRouteDecisionV1 { throw new RuntimeException('Unused fixture method'); }
    public function snapshot(string $taskKey): Api\AddonAiTaskRoutingSnapshotV1 { throw new RuntimeException('Unused fixture method'); }
    public function execute(Api\AddonAiTaskRequestV1 $request): Api\AddonAiTaskResultV1 { throw new RuntimeException('Unused fixture method'); }
};
$runtimeSnapshot = new Api\AddonRuntimeSnapshotV1(
    \OJML\Playgrounds\Platform\Addon_Integration::ADDON_ID, OJ_ML_PLAYGROUNDS_VERSION,
    \OJML\Playgrounds\Platform\Addon_Integration::PLATFORM_RANGE, '2.0.0-dev.99',
    \OJML\Playgrounds\Platform\Addon_Integration::ADDON_API_RANGE, '1.1.0', 'accepted', '', 'catalog-r1',
    ['capability' => 6, 'console_page' => 3, 'public_page' => 3, 'console_menu' => 3, 'public_menu' => 1, 'asset_bundle' => 1, 'ai_task' => 0], 'corr-template'
);
$runtimeContext = new class($runtimeSnapshot, $contexts, $ai) implements Api\AddonRuntimeContextV1 {
    public function __construct(private readonly Api\AddonRuntimeSnapshotV1 $state, private readonly Api\AddonContextGatewayV1 $gateway, private readonly Api\AddonAiTaskGatewayV1 $ai) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 { return $this->state; }
    public function contexts(): Api\AddonContextGatewayV1 { return $this->gateway; }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { return $this->ai; }
};
$runtime = new \OJML\Playgrounds\Platform\Addon_Runtime_Bridge();
$runtime->connect($runtimeContext);
$service = new \OJML\Playgrounds\Application\AssignmentService($repo, new \OJML\Playgrounds\Application\ContextService($runtime));
$template = ['title' => '탐구 포트폴리오', 'markdown' => "## 문제 정의 {#topic}\n해결할 문제를 설명하세요.\n\n## 성찰 {#reflection}\n새롭게 배운 점을 적으세요.\n"];
$templateDraft = $service->createAssignment([
    'contextType' => 'class', 'contextId' => 'template-fixture', 'title' => '양식 저장 통합 시험', 'taskType' => 'classification',
    'policy' => ['portfolioTemplate' => $template, 'maxRows' => 500],
    'rubric' => ['teacherNote' => '학생 DTO 비공개'],
], 1);
check($repo->getAssignment($templateDraft['id'])['policy']['portfolioTemplate'] === $template, 'template service creation persists Unicode Markdown through SQL');
$templatePublished = $service->publishAssignment($templateDraft['id'], $templateDraft['revision'], 1);
$templateLearner = (new \OJML\Playgrounds\Rest\Presenter())->assignment($service->getForLearner($templateDraft['id'], $student));
check($templateLearner['submissionPolicy']['portfolioTemplate'] === $template && !isset($templateLearner['rubric']), 'authorized learner service and presenter expose form but not private rubric');
$templateClone = $service->duplicateAssignment($templateDraft['id'], 1);
check($templateClone['status'] === 'draft' && $templateClone['policy']['portfolioTemplate'] === $template
    && $templateClone['policy']['maxRows'] === 500 && $templateClone['rubric'] === $templateDraft['rubric'], 'assignment duplication preserves template and existing policy/rubric');
rejects(fn () => $service->updateAssignment($templateDraft['id'], [
    'expectedRevision' => $templatePublished['revision'], 'policy' => ['portfolioTemplate' => null],
], 1), 'OJ_ML_ASSIGNMENT_IMMUTABLE');
check($repo->getAssignment($templateDraft['id'])['policy']['portfolioTemplate'] === $template, 'rejected published template edit leaves stored form unchanged');

// Manual criteria are authoritative at the real service boundary; no model is run.
$rubricDefinition = ['version' => 1, 'criteria' => [
    ['id' => 'data', 'title' => '데이터 이해', 'description' => '근거와 한계', 'maxScore' => 25],
    ['id' => 'reflection', 'title' => '실험 해석', 'description' => '비교와 성찰', 'maxScore' => 75.25],
]];
$rubricDraft = $service->updateAssignment($templateClone['id'], ['expectedRevision' => $templateClone['revision'], 'rubric' => $rubricDefinition], 1);
$rubricAssignment = $service->publishAssignment($rubricDraft['id'], $rubricDraft['revision'], 1);
$rubricLearner = (new \OJML\Playgrounds\Rest\Presenter())->assignment($service->getForLearner($rubricAssignment['id'], $student));
check(($rubricLearner['rubric']['criteria'][0]['title'] ?? '') === '데이터 이해', 'published manual rubric definition visible to authorized learner');
rejects(fn () => $service->updateAssignment($rubricAssignment['id'], ['expectedRevision' => $rubricAssignment['revision'], 'rubric' => []], 1), 'OJ_ML_ASSIGNMENT_IMMUTABLE');
$rubricAttempt = $repo->createAttempt($rubricAssignment['id'], $student, 'rubric-attempt-one', '0.12.1', 2)['attempt'];
$savedRubric = $store->putUploadedFile(stream_get_meta_data($tmp)['uri'], 26214400);
$repo->attachArtifact($rubricAttempt['uuid'], $student, ['uuid' => $savedRubric->uuid, 'storage_key' => $savedRubric->storageKey,
    'original_name' => 'manual-rubric-fixture.mlpx', 'sha256' => $savedRubric->sha256, 'bytes' => $savedRubric->bytes, 'mime_type' => $savedRubric->mimeType]);
// Deliberate repository fixture transition: this does not claim the fake bytes passed inspection.
$repo->completeInspection($rubricAttempt['id'], 'accepted', 'accepted', [], [], null);
$rubricScores = [['id' => 'reflection', 'score' => 70.12, 'feedback' => '비교 근거를 보완하세요.'], ['id' => 'data', 'score' => 20.01, 'feedback' => '출처 설명이 명확합니다.']];
$rubricReview = $service->review($rubricAttempt['uuid'], 1, ['expectedVersion' => 0, 'score' => 999999, 'maxScore' => 999999, 'criteriaScores' => $rubricScores, 'feedback' => '교사 루브릭 평가'], wp_generate_uuid4());
check($rubricReview['score'] === 90.13 && $rubricReview['max_score'] === 100.25
    && $rubricReview['details']['rubricSnapshot']['criteria'][1]['maxScore'] == 75.25,
    'service computes criteria totals and persists rubric snapshot through SQL ignoring forged totals');
check($repo->getAttemptResult($rubricAttempt['uuid'], $student)['review'] === null, 'draft structured review stays private');
rejects(fn () => $service->review($rubricAttempt['uuid'], 1, ['expectedVersion' => 1, 'criteriaScores' => [$rubricScores[0]]], wp_generate_uuid4()), 'OJ_ML_RUBRIC_SCORES_INVALID');
rejects(fn () => $service->review($rubricAttempt['uuid'], 1, ['expectedVersion' => 1, 'criteriaScores' => [$rubricScores[0], $rubricScores[0]]], wp_generate_uuid4()), 'OJ_ML_RUBRIC_SCORES_INVALID');
rejects(fn () => $service->review($rubricAttempt['uuid'], 1, ['expectedVersion' => 1, 'criteriaScores' => [array_replace($rubricScores[0], ['score' => 80]), $rubricScores[1]]], wp_generate_uuid4()), 'OJ_ML_RUBRIC_SCORE_RANGE');
rejects(fn () => $service->review($rubricAttempt['uuid'], 1, ['expectedVersion' => 1, 'score' => 90, 'maxScore' => 100], wp_generate_uuid4()), 'OJ_ML_RUBRIC_SCORES_REQUIRED');
$rubricReleased = $service->releaseReview($rubricAttempt['uuid'], 1, 1, wp_generate_uuid4());
$rubricNewScores = $rubricScores; $rubricNewScores[0]['score'] = 60; $rubricNewScores[0]['feedback'] = '이 피드백은 아직 비공개';
$service->review($rubricAttempt['uuid'], 1, ['expectedVersion' => 2, 'criteriaScores' => $rubricNewScores, 'feedback' => '비공개 재검토'], wp_generate_uuid4());
$publicRubricReview = (new \OJML\Playgrounds\Rest\Presenter())->attempt($repo->getAttemptResult($rubricAttempt['uuid'], $student))['review'];
check($publicRubricReview['version'] === 2 && $publicRubricReview['score'] === 90.13
    && $publicRubricReview['details']['criteriaScores'][1]['feedback'] === '비교 근거를 보완하세요.',
    'new draft cannot replace released criterion scores or feedback for learner');
$rubricResultRows = $repo->listResults($rubricAssignment['id'], 1, 20);
check($rubricResultRows['items'][0]['attempt']['review']['version'] === 3
    && $rubricResultRows['items'][0]['attempt']['released_review']['details']['criteriaScores'][1]['score'] === 70.12,
    'batched teacher result hydration keeps latest draft and released snapshot independently');
$wpdb->query("CREATE TRIGGER ml_fixture_deny_review BEFORE INSERT ON oj_ml_events FOR EACH ROW BEGIN IF NEW.event_type = 'review.saved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture review audit failure'; END IF; END");
rejects(fn () => $service->review($rubricAttempt['uuid'], 1, ['expectedVersion' => 3, 'criteriaScores' => $rubricNewScores], wp_generate_uuid4()), 'OJ_ML_EVENT_SAVE_FAILED');
check($repo->getAttemptResult($rubricAttempt['uuid'])['review']['version'] === 3, 'audit failure rolls back structured review version and snapshot');
$wpdb->query('DROP TRIGGER ml_fixture_deny_review');

// Real REST registration and options/event transactions for personal form copies.
$libraryRoute = new \OJML\Playgrounds\Rest\TemplateLibraryRoute($repo);
$libraryRoute->register();
$manageCapability = \OJML\Playgrounds\Security\Addon_Capabilities::wordpress(\OJML\Playgrounds\Security\Addon_Capabilities::MANAGE);
get_userdata(1)->add_cap($manageCapability);
$otherTeacher = wp_insert_user(['user_login' => 'teacher_two', 'user_email' => 'teacher_two@example.invalid',
    'user_pass' => bin2hex(random_bytes(16)), 'display_name' => '교사 둘', 'role' => 'subscriber']);
get_userdata($otherTeacher)->add_cap($manageCapability);
function libraryRequest(string $method, string $suffix = '', ?array $body = null, bool $nonce = true): WP_REST_Response {
    $request = new WP_REST_Request($method, '/oj-ml/v1/console/template-library' . $suffix);
    if ($nonce) { $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest')); }
    if ($body !== null) { $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($body)); }
    return rest_do_request($request);
}
wp_set_current_user(1);
$libraryId = wp_generate_uuid4();
$libraryBody = ['id' => $libraryId, 'template' => $template, 'requirements' => ['requiredSectionIds' => ['topic'], 'minAnswerChars' => 3]];
check(libraryRequest('POST', '', $libraryBody, false)->get_status() === 403, 'template library mutations require nonce');
$librarySaved = libraryRequest('POST', '', $libraryBody);
check($librarySaved->get_status() === 200 && count($librarySaved->get_data()['data']['items']) === 1
    && $librarySaved->get_data()['data']['items'][0]['requirements']['requiredSectionIds'] === ['topic'], 'template library saves normalized form with required question policy');
check((int) $wpdb->get_var("SELECT COUNT(*) FROM oj_ml_events WHERE event_type='template_library.saved'") === 1
    && ($librarySaved->get_headers()['Cache-Control'] ?? '') === 'no-store, private', 'template library save is audited and private-cache disabled');
$libraryDuplicate = libraryRequest('POST', '', $libraryBody);
check($libraryDuplicate->get_status() === 200 && count($libraryDuplicate->get_data()['data']['items']) === 1
    && (int) $wpdb->get_var("SELECT COUNT(*) FROM oj_ml_events WHERE event_type='template_library.saved'") === 1, 'template save request ID is idempotent without duplicate audit');
$changedBody = $libraryBody; $changedBody['template']['title'] = '변경된 양식';
check(libraryRequest('POST', '', $changedBody)->get_status() === 409, 'reused template request ID with different content is rejected');
$primaryLibraryKey = \OJML\Playgrounds\Rest\TemplateLibraryRoute::key(1);
wp_set_current_user($otherTeacher);
check(libraryRequest('GET')->get_data()['data']['items'] === [], 'another teacher cannot list first teacher personal forms');
check(libraryRequest('DELETE', '/' . $libraryId)->get_status() === 200 && count(get_option($primaryLibraryKey, [])) === 1,
    'another teacher delete cannot mutate first teacher form');
wp_set_current_user($student);
check(libraryRequest('GET')->get_status() === 403 && libraryRequest('POST', '', $libraryBody)->get_status() === 403, 'learner lacks personal teacher library access');
wp_set_current_user(0);
check(libraryRequest('GET')->get_status() === 401, 'anonymous template library denied');
wp_set_current_user(1);
for ($libraryIndex = 1; $libraryIndex < 20; $libraryIndex++) {
    $nextBody = $libraryBody; $nextBody['id'] = wp_generate_uuid4();
    if (libraryRequest('POST', '', $nextBody)->get_status() !== 200) { throw new RuntimeException('Library fixture fill failed at ' . $libraryIndex); }
}
$overflowBody = $libraryBody; $overflowBody['id'] = wp_generate_uuid4();
check(count(libraryRequest('GET')->get_data()['data']['items']) === 20 && libraryRequest('POST', '', $overflowBody)->get_status() === 409, 'teacher library enforces 20 form capacity');
check(libraryRequest('DELETE', '/' . $libraryId)->get_status() === 200 && count(get_option($primaryLibraryKey, [])) === 19,
    'teacher can delete own form');
check($repo->getAssignment($templateDraft['id'])['policy']['portfolioTemplate'] === $template,
    'deleting reusable library copy cannot change published assignment snapshot');
$storedLibrary = get_option($primaryLibraryKey, []);
$wpdb->query("CREATE TRIGGER ml_fixture_deny_library BEFORE INSERT ON oj_ml_events FOR EACH ROW BEGIN IF NEW.event_type LIKE 'template_library.%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture library audit failure'; END IF; END");
check(libraryRequest('POST', '', $overflowBody)->get_status() === 500, 'template save returns audit failure');
check(get_option($primaryLibraryKey, []) === $storedLibrary
    && maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $primaryLibraryKey))) === $storedLibrary,
    'template save audit failure rolls back database option and evicts stale object cache');
check(libraryRequest('DELETE', '/' . $storedLibrary[0]['id'])->get_status() === 500 && get_option($primaryLibraryKey, []) === $storedLibrary,
    'template delete audit failure rolls back option and cache');
wp_set_current_user($otherTeacher);
$otherLibraryKey = \OJML\Playgrounds\Rest\TemplateLibraryRoute::key($otherTeacher);
check(libraryRequest('POST', '', $libraryBody)->get_status() === 500 && get_option($otherLibraryKey, []) === []
    && $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $otherLibraryKey)) === null,
    'first template insert audit failure also clears negative and positive caches');
$wpdb->query('DROP TRIGGER ml_fixture_deny_library');
check(libraryRequest('POST', '', $libraryBody)->get_status() === 200, 'template save recovers after audit failure without manual cache reset');
$wpdb->query("ALTER TABLE {$wpdb->options} ENGINE=MyISAM");
try {
    $unsupportedBody = $libraryBody; $unsupportedBody['id'] = wp_generate_uuid4();
    check(libraryRequest('POST', '', $unsupportedBody)->get_status() === 503
        && count(get_option(\OJML\Playgrounds\Rest\TemplateLibraryRoute::key($otherTeacher), [])) === 1,
        'nontransactional WordPress options table blocks template mutation before any change');
} finally { $wpdb->query("ALTER TABLE {$wpdb->options} ENGINE=InnoDB"); }
$libraryExport = (new Privacy())->export('teacher_two@example.invalid');
check(count(array_filter($libraryExport['data'], static fn (array $item): bool => $item['group_id'] === 'oj-ml-template-library')) === 1,
    'WordPress privacy export includes only own reusable form');
check((new Privacy())->erase('teacher_two@example.invalid')['items_retained'] === true && count(get_option($otherLibraryKey, [])) === 1,
    'privacy retention hold also protects reusable forms');
wp_set_current_user($student);

// Exact canonical CSV bytes live only in private storage and are assignment-authorized.
$contexts->teachers[] = $otherTeacher;
$datasetContexts = new \OJML\Playgrounds\Application\ContextService($runtime);
$datasetService = new \OJML\Playgrounds\Application\TeachingDatasetService($repo, new \OJML\Playgrounds\Storage\TeachingAssetStore($store), $datasetContexts);
$datasetAssignments = new \OJML\Playgrounds\Application\AssignmentService($repo, $datasetContexts, $datasetService);
$datasetRoute = new \OJML\Playgrounds\Rest\TeachingDatasetRoute($datasetService);
$datasetRoute->register();
$learnerCapability = \OJML\Playgrounds\Security\Addon_Capabilities::wordpress(\OJML\Playgrounds\Security\Addon_Capabilities::USE);
get_userdata($student)->add_cap($learnerCapability);
get_userdata($otherTeacher)->add_cap($learnerCapability);
$csvInput = "feature,label\r\n1,A\r\n2,B\r\n";
$csvNormalized = \OJML\Playgrounds\Domain\TeachingDataset::normalize($csvInput);
$csvTmp = tmpfile(); fwrite($csvTmp, $csvInput); $csvTmpPath = stream_get_meta_data($csvTmp)['uri'];
$uploadFixture = static fn (bool $uploaded, string $path): bool => $uploaded || $path === $csvTmpPath;
add_filter('oj_ml_playgrounds_is_uploaded_file', $uploadFixture, 10, 2);
wp_set_current_user(1);
$uploadRequest = new WP_REST_Request('POST', '/oj-ml/v1/console/datasets');
$uploadRequest->set_file_params(['dataset' => ['error' => UPLOAD_ERR_OK, 'name' => 'lesson.csv', 'tmp_name' => $csvTmpPath, 'size' => strlen($csvInput)]]);
check(rest_do_request($uploadRequest)->get_status() === 403, 'CSV upload REST requires nonce before reading fixture file');
$uploadRequest->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
$csvResponse = rest_do_request($uploadRequest);
$csvDto = $csvResponse->get_data()['data']['dataset'] ?? [];
check($csvResponse->get_status() === 201 && $csvDto['sha256'] === hash('sha256', $csvNormalized['content'])
    && $csvDto['bytes'] === strlen($csvNormalized['content']) && $csvDto['rowCount'] === 2 && $csvDto['columnCount'] === 2,
    'CSV upload validates and persists server-canonicalized exact hash through real REST');
check(array_intersect(['storageKey', 'ownerId', 'audit'], array_keys($csvDto)) === []
    && $datasetService->listForTeacher(1)['usage']['retainedCount'] === 1 && $datasetService->listForTeacher($otherTeacher)['items'] === [],
    'CSV library DTO hides internal storage and is isolated by teacher');
$csvDraft = $datasetAssignments->createAssignment(['contextType' => 'class', 'contextId' => 'template-fixture', 'title' => '지정 CSV 실습', 'taskType' => 'classification',
    'policy' => ['datasetResource' => ['id' => $csvDto['id'], 'storageKey' => '../../forged', 'sha256' => str_repeat('b', 64)],
        'requiredDatasetSha256' => str_repeat('b', 64), 'requiredDatasetPath' => 'dataset/forged.csv', 'portfolioTemplate' => $template,
        'portfolioRequirements' => ['requiredSectionIds' => ['topic'], 'minAnswerChars' => 3]]], 1);
check($csvDraft['policy']['requiredDatasetSha256'] === $csvDto['sha256'] && $csvDraft['policy']['requiredDatasetPath'] === 'dataset/data.csv'
    && $csvDraft['policy']['datasetResource'] === $csvDto, 'assignment service resolves authoritative dataset identity instead of request forged metadata');
$csvPublished = $datasetAssignments->publishAssignment($csvDraft['id'], $csvDraft['revision'], 1);
$csvClone = $datasetAssignments->duplicateAssignment($csvPublished['id'], $otherTeacher);
check($csvClone['created_by'] === $otherTeacher && $csvClone['policy']['datasetResource'] === $csvDto,
    'another authorized teacher may clone existing assignment reference without owning CSV');
rejects(fn () => $datasetAssignments->createAssignment(['contextType' => 'class', 'contextId' => 'template-fixture', 'title' => '직접 가로참조', 'taskType' => 'classification',
    'policy' => ['datasetResource' => ['id' => $csvDto['id']]]], $otherTeacher), 'OJ_ML_DATASET_NOT_FOUND');
$learnerCsvAssignment = (new \OJML\Playgrounds\Rest\Presenter())->assignment($datasetAssignments->getForLearner($csvPublished['id'], $student));
check($learnerCsvAssignment['submissionPolicy']['datasetResource'] === $csvDto
    && !isset($learnerCsvAssignment['submissionPolicy']['datasetResource']['storageKey']), 'authorized learner DTO exposes exact public dataset metadata only');
$downloadedCsv = $datasetService->downloadForLearner($csvPublished['id'], $student);
check($downloadedCsv['content'] === $csvNormalized['content'] && $downloadedCsv['sha256'] === $csvDto['sha256'], 'authorized learner downloads canonical original bytes');
rejects(fn () => $datasetService->downloadForLearner($csvPublished['id'], $otherTeacher), 'OJ_ML_DATASET_NOT_FOUND');
$contexts->learnerActive = false;
rejects(fn () => $datasetService->downloadForLearner($csvPublished['id'], $student), 'OJ_ML_CONTEXT_INACTIVE');
$contexts->learnerActive = true;
$wpdb->query($wpdb->prepare('UPDATE oj_ml_assignments SET opens_at=%s WHERE id=%d', gmdate('Y-m-d H:i:s', time() + 3600), $csvPublished['id']));
rejects(fn () => $datasetService->downloadForLearner($csvPublished['id'], $student), 'OJ_ML_ASSIGNMENT_NOT_OPEN');
$wpdb->query($wpdb->prepare('UPDATE oj_ml_assignments SET opens_at=NULL WHERE id=%d', $csvPublished['id']));
wp_set_current_user($student);
$csvGet = new WP_REST_Request('GET', '/oj-ml/v1/assignments/' . $csvPublished['id'] . '/dataset');
$csvDownloadResponse = rest_do_request($csvGet);
$csvHeaders = $csvDownloadResponse->get_headers();
check($csvDownloadResponse->get_status() === 200 && $csvDownloadResponse->get_data() === null
    && $csvHeaders['Content-Type'] === 'text/csv; charset=utf-8' && $csvHeaders['X-Dataset-SHA256'] === $csvDto['sha256']
    && (int) $csvHeaders['Content-Length'] === $csvDto['bytes'] && $csvHeaders['X-Content-Type-Options'] === 'nosniff'
    && $csvHeaders['Cache-Control'] === 'no-store, private' && str_contains($csvHeaders['Content-Disposition'], 'attachment;'),
    'real CSV REST response keeps data out of JSON and sets private attachment security headers');
ob_start(); $servedCsv = $datasetRoute->serveBinary(false, $csvDownloadResponse, $csvGet, rest_get_server()); $rawCsv = ob_get_clean();
check($servedCsv && $rawCsv === $csvNormalized['content'], 'CSV HTTP pre-serve hook emits exact bytes once without JSON quoting');
ob_start(); $servedAgain = $datasetRoute->serveBinary(false, $csvDownloadResponse, $csvGet, rest_get_server()); $rawAgain = ob_get_clean();
check(!$servedAgain && $rawAgain === '', 'CSV response cannot replay stale binary bytes');
$csvHead = new WP_REST_Request('HEAD', '/oj-ml/v1/assignments/' . $csvPublished['id'] . '/dataset');
$headResponse = rest_do_request($csvHead);
ob_start(); $headServed = $datasetRoute->serveBinary(false, $headResponse, $csvHead, rest_get_server()); $headBytes = ob_get_clean();
check($headResponse->get_status() === 200 && $headServed && $headBytes === '', 'CSV HEAD sends no response body');
$enveloped = new WP_REST_Request('GET', '/oj-ml/v1/assignments/' . $csvPublished['id'] . '/dataset');
$enveloped->set_param('_envelope', '1');
check(rest_do_request($enveloped)->get_status() === 400, 'CSV envelope transformation is rejected');
wp_set_current_user(0);
check(rest_do_request($csvGet)->get_status() === 401, 'anonymous CSV download rejected by REST permissions');
wp_set_current_user(1);
$hiddenCsv = $datasetService->hide(1, $csvDto['id'], wp_generate_uuid4());
check($hiddenCsv['retained'] && $datasetService->listForTeacher(1)['items'] === []
    && $datasetService->downloadForLearner($csvPublished['id'], $student)['content'] === $csvNormalized['content'],
    'hiding CSV removes it from new selection but retains existing authorized downloads');
$hiddenClone = $datasetAssignments->duplicateAssignment($csvPublished['id'], $otherTeacher);
check($hiddenClone['policy']['datasetResource'] === $csvDto, 'authorized cloned source can preserve hidden dataset reference');
check(count(array_filter((new Privacy())->export('release@example.invalid')['data'],
    static fn (array $item): bool => $item['group_id'] === 'oj-ml-teaching-datasets')) === 1, 'WordPress privacy export documents retained teacher CSV');
function lessonArchive(string $csv, array $answers): string {
    $entries = [
        'manifest.json' => wp_json_encode(['formatVersion' => 2, 'appVersion' => '0.12.1', 'projectId' => wp_generate_uuid4(),
            'name' => 'CSV 필수 답변 검사', 'createdAt' => '2026-09-07T00:00:00Z', 'updatedAt' => '2026-09-07T00:00:00Z',
            'dataType' => 'tabular', 'taskType' => 'classification', 'kind' => 'machineLearning']),
        'settings.json' => wp_json_encode(['data' => ['dataset' => ['path' => 'dataset/data.csv', 'hasHeader' => true, 'encoding' => 'utf-8'],
            'features' => ['feature'], 'target' => 'label']]),
        'runs.json' => wp_json_encode(['experiments' => []]),
        'portfolio/document.json' => wp_json_encode(['version' => 1, 'template' => ['sections' => [
            ['id' => 'topic', 'title' => '문제 정의'], ['id' => 'reflection', 'title' => '성찰']]], 'answers' => $answers]),
        'dataset/data.csv' => $csv,
    ];
    $hashes = []; foreach ($entries as $name => $content) { $hashes[$name] = hash('sha256', $content); }
    ksort($hashes, SORT_STRING); $canonical = '';
    foreach ($hashes as $name => $hash) { $canonical .= $name . "\n" . $hash . "\n"; }
    $entries['hashes.json'] = wp_json_encode(['algorithm' => 'sha256', 'entries' => $hashes, 'contentHash' => hash('sha256', $canonical)]);
    $path = tempnam(dirname(requireEnv('STORAGE')), 'mlpx-rc3-');
    if (!is_string($path)) { throw new RuntimeException('Could not allocate bounded lesson archive fixture'); }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Could not open lesson archive fixture'); }
    foreach ($entries as $name => $content) { if (!$zip->addFromString($name, $content)) { throw new RuntimeException('Could not add lesson fixture entry'); } }
    $zip->close(); return $path;
}
$lessonInspector = new MlpxInspector();
foreach ([
    [$csvNormalized['content'], ['topic' => '분류 문제를 해결합니다.'], 'accepted', null, 'complete portfolio with pinned CSV passes nonexecuting preflight'],
    [$csvNormalized['content'], ['topic' => '  '], 'rejected', 'OJ_ML_POLICY_PORTFOLIO_REQUIRED', 'blank required answer fails real MLPX inspection'],
    [$csvNormalized['content'], ['topic' => '짧음'], 'rejected', 'OJ_ML_POLICY_PORTFOLIO_REQUIRED', 'short required answer fails real MLPX inspection'],
    [str_replace('1,A', '9,A', $csvNormalized['content']), ['topic' => '분류 문제를 해결합니다.'], 'rejected', 'OJ_ML_POLICY_DATASET_HASH', 'changed CSV fails pinned hash despite self-consistent MLPX hashes'],
] as [$lessonCsv, $lessonAnswers, $expectedStatus, $expectedCode, $label]) {
    $archivePath = lessonArchive($lessonCsv, $lessonAnswers);
    try {
        $inspection = $lessonInspector->inspect($archivePath, $csvPublished['policy']);
        check($inspection->status() === $expectedStatus && ($expectedCode === null || in_array($expectedCode, array_column($inspection->findings(), 'code'), true)),
            $label . ($inspection->status() !== $expectedStatus ? ' actual=' . $inspection->status() . ' code=' . $inspection->firstFailureCode() : ''));
    } finally { unlink($archivePath); }
}
remove_filter('oj_ml_playgrounds_is_uploaded_file', $uploadFixture, 10); fclose($csvTmp);
wp_set_current_user($student);

// Exercise additive upgrade against a populated v2-shaped schema.
$wpdb->query('ALTER TABLE oj_ml_assignment_learners DROP COLUMN max_attempts_override, DROP COLUMN due_at_override, DROP COLUMN override_revision');
$wpdb->query('ALTER TABLE oj_ml_attempts DROP INDEX inspection_queue, DROP COLUMN inspection_retries, DROP COLUMN next_inspection_at');
update_option(Installer::DB_VERSION_OPTION, '2');
rejects(fn () => Installer::maybeUpgrade(), 'OJ_ML_MIGRATION_REQUIRED');
Installer::install();
check(get_option(Installer::DB_VERSION_OPTION) === '4' && $repo->listAttemptHistory($id, $student, 1, 20, true)['total'] === 2, 'v2-shaped additive upgrade retains attempts');
check($repo->getAttemptResult($first['uuid'])['artifact']['sha256'] === $saved->sha256, 'upgrade preserves artifact hash');
check($repo->getAttemptResult($rubricAttempt['uuid'], $student)['review']['details'] === $rubricReleased['details'], 'additive upgrade preserves released rubric snapshot exactly');
Installer::install();
check((int) $wpdb->get_var("SELECT COUNT(*) FROM oj_ml_events WHERE event_type = 'installation.bound'") === 1, 'repeat upgrade idempotent');
$wpdb->suppress_errors();
$wpdb->query("CREATE TRIGGER ml_fixture_deny_event BEFORE INSERT ON oj_ml_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture audit failure'");
$beforeRevision = $repo->getAssignment($id)['revision'];
rejects(fn () => $repo->updatePublishedAssignmentOperations($id, $beforeRevision, ['title' => 'must roll back'], 1), 'OJ_ML_EVENT_SAVE_FAILED');
check($repo->getAssignment($id)['title'] === '통합 시험' && $repo->getAssignment($id)['revision'] === $beforeRevision, 'audit failure rolls back mutation');
$wpdb->query('DROP TRIGGER ml_fixture_deny_event');
$wpdb->query('RENAME TABLE oj_ml_assignments TO oj_ml_assignments_fixture');
rejects(fn () => $repo->getAssignment($id), 'OJ_ML_DATABASE_READ_FAILED');
$wpdb->query('RENAME TABLE oj_ml_assignments_fixture TO oj_ml_assignments');
$prefix = $wpdb->prefix; $wpdb->prefix = 'other_';
rejects(fn () => SchemaUpgrade::assertOwnership(), 'OJ_ML_DATABASE_OWNER_MISMATCH');
$wpdb->prefix = $prefix;

$maintenance = new StorageMaintenance($wpdb, $store);
$report = $maintenance->reconcile();
check($report['missing'] === 0 && $report['corrupt'] === 0, 'filesystem/database integrity reconciliation');
check($maintenance->retention(true)['held'] === true, 'retention hold is safe default');
$closed = $repo->transitionAssignment($id, $repo->getAssignment($id)['revision'], 'closed', 1);
$archived = $repo->transitionAssignment($id, $closed['revision'], 'archived', 1);
$restored = $repo->transitionAssignment($id, $archived['revision'], 'closed', 1);
$reopened = $repo->transitionAssignment($id, $restored['revision'], 'published', 1);
check($reopened['status'] === 'published', 'close/archive/restore/reopen lifecycle');
$wpdb->query('UPDATE oj_ml_artifacts SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)');
Options::save(['retentionDays' => 7, 'retentionHold' => false]);
check(count($maintenance->retention()['candidates']) === 0, 'retention excludes reopened assignments');
$repo->transitionAssignment($id, $reopened['revision'], 'closed', 1);
$dry = $maintenance->retention();
check(count($dry['candidates']) === 2 && $dry['deleted'] === 0 && is_file($store->pathFor($saved->storageKey)), 'retention dry-run never removes files');
$pruned = $maintenance->retention(true);
check($pruned['deleted'] === 2 && $repo->getAttemptResult($second['uuid'], $student)['review']['score'] === 80.0, 'retention preserves grades and metadata');
Options::save(['retentionHold' => true]);
$privacy = new Privacy();
check($privacy->erase('student_one@example.invalid')['items_retained'] === true, 'privacy hold is safe default');
check(count($privacy->export('student_one@example.invalid')['data']) >= 2, 'WordPress personal-data export');

$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST); $other->suppress_errors();
$original = $wpdb; $lock = Lease::acquire('test-exclusive');
$wpdb = $other;
rejects(fn () => Lease::acquire('test-exclusive'), 'OJ_ML_SERVER_BUSY');
$wpdb = $original; $lock->release();
$wpdb = $other; $lock2 = Lease::acquire('test-exclusive'); $lock2->release(); $wpdb = $original;
check(true, 'advisory lock releases across connections');

// Deletions are confined to this newly created fixture database and storage root.
Options::save(['retentionHold' => false, 'allowPersonalErasure' => true]);
$attemptCountBeforeTeacherErasure = (int) $wpdb->get_var('SELECT COUNT(*) FROM oj_ml_attempts');
$teacherErased = $privacy->erase('teacher_two@example.invalid');
check($teacherErased['items_removed'] && $teacherErased['items_retained'] && get_option($otherLibraryKey, false) === false
    && count(get_option($primaryLibraryKey, [])) === 19 && (int) $wpdb->get_var('SELECT COUNT(*) FROM oj_ml_attempts') === $attemptCountBeforeTeacherErasure,
    'confirmed teacher erasure removes own forms only while retaining cloned assignment authorship and other users records');
$primaryTeacherErased = $privacy->erase('release@example.invalid');
check($primaryTeacherErased['items_removed'] && $primaryTeacherErased['items_retained']
    && get_option($primaryLibraryKey, false) === false && $repo->getAssignment($templateDraft['id'])['policy']['portfolioTemplate'] === $template,
    'teacher form erasure preserves institution-owned published assignment snapshot and authorship');
check($datasetService->downloadForLearner($csvPublished['id'], $student)['sha256'] === $csvDto['sha256']
    && \OJML\Playgrounds\Application\TeachingDatasetService::privacyForUser(1)['retained'], 'teacher privacy erasure reports retained CSV without breaking learner assignment reference');
$erased = $privacy->erase('student_one@example.invalid');
check($erased['done'] && $erased['items_removed'] && !$erased['items_retained'], 'confirmed learner erasure');
check((int) $wpdb->get_var('SELECT COUNT(*) FROM oj_ml_attempts') === 0
    && (int) $wpdb->get_var('SELECT COUNT(*) FROM oj_ml_artifacts') === 0, 'erasure removes only fixture learner records');
check(!is_file($store->pathFor($saved->storageKey)), 'erasure removes original file after tombstone');
fclose($tmp);
echo json_encode(['checks' => $checks, 'plugin' => OJ_ML_PLAYGROUNDS_VERSION, 'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
    'database' => $wpdb->get_var('SELECT VERSION()'), 'fixtureDatabase' => DB_NAME], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();
require_once __DIR__ . '/Support/SqlWpdb.php';
require_once __DIR__ . '/Support/ReleaseFixture.php';
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

use OjPrimm\Application\ActivityManagement;
use OjPrimm\Application\ClassSettingsService;
use OjPrimm\Application\GenerationService;
use OjPrimm\Application\LessonExecutor;
use OjPrimm\Application\ProblemPreparationAutomation;
use OjPrimm\Generation\CurriculumLessonGenerator;
use OjPrimm\Generation\LessonSchema;
use OjPrimm\Generation\TransientGenerationException;
use OjPrimm\Storage\WpdbGenerationStore;
use OjPrimm\Storage\WpdbManagementStore;
use OjPrimm\Storage\WpdbRepository;
use OjPrimmTests\Platform;
use OjPrimmTests\SqlWpdb;

$GLOBALS['primm_integration_options'] = [];
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['primm_integration_options'][$name] ?? $default; }
function update_option(string $name, mixed $value, bool $autoload = false): bool { $GLOBALS['primm_integration_options'][$name] = $value; return true; }
function current_user_can(string $capability): bool { return false; }
$checks = 0;
function integrationCheck(bool $condition, string $label): void { global $checks; ++$checks; if (! $condition) { throw new RuntimeException($label); } }
function integrationRejects(callable $work, string $label): void { try { $work(); } catch (DomainException) { integrationCheck(true, $label); return; } integrationCheck(false, $label); }

final class IntegratedGenerator implements CurriculumLessonGenerator
{
    public int $calls = 0; public bool $oneTest = false; public bool $transient = false; public array $keys = []; public array $scopes = []; public bool $available = true;
    public function availability(): array { return ['available' => $this->available, 'message' => 'fixture AI', 'languages' => ['unsupported-ai-metadata']]; }
    public function generate(int $actor, array $problem, string $language, string $kind, string $key): array
    {
        ++$this->calls; $this->keys[] = $key;
        if ($this->transient) { throw new TransientGenerationException('AI 요청 처리 중', 0, 'addon_ai_request_pending'); }
        $lesson = \OjPrimmTests\lesson(); $lesson['verification'] = ['status' => 'not_executed', 'note' => LessonSchema::NOTE];
        if ($this->oneTest) { array_pop($lesson['stages'][3]['tests']); }
        return LessonSchema::validateNormalized($lesson, $language, $kind);
    }
    public function repair(int $actor, array $problem, array $lesson, array $report, string $key): array { throw new RuntimeException('This fixture must not request AI code repair.'); }
    public function generateForProfile(int $actor, array $problem, string $language, string $kind, array $profile, string $key): array { return $this->generate($actor, $problem, $language, $kind, $key); }
    public function repairForProfile(int $actor, array $problem, array $lesson, array $report, array $profile, string $key): array { return $this->repair($actor, $problem, $lesson, $report, $key); }
    public function regenerate(int $actor, array $problem, array $lesson, string $scope, array $profile, string $key): array
    {
        ++$this->calls; $this->scopes[] = $scope;
        foreach ($lesson['stages'] as &$stage) { if ($scope === 'all' || $stage['key'] === $scope) { $stage['prompt'] .= ' 새 버전의 질문입니다.'; } } unset($stage);
        return LessonSchema::validateNormalized($lesson, $lesson['language'], $lesson['support']['kind']);
    }
}
final class IntegratedExecutor implements LessonExecutor
{
    public int $submissions = 0; public bool $wrong = false;
    public function availability(): array { return ['available' => true, 'languages' => ['python'], 'message' => 'fixture OJ']; }
    public function execute(int $actor, string $language, string $source, string $input, string $key, ?string $ticket): array
    {
        if ($ticket === null) { ++$this->submissions; }
        $output = str_contains($source, 'b = 5') ? "7\n" : "3\n";
        if (str_contains($source, 'input().split()')) {
            $parts = array_map('intval', preg_split('/\s+/', trim($input)));
            if (str_contains($source, 'if value > 0:')) { $parts = array_map(static fn (int $value): int => max(0, $value), $parts); }
            $output = array_sum($parts) . "\n";
        }
        return ['status' => 'completed', 'ticket' => $ticket ?? hash('sha256', $key), 'verdict' => 'executed', 'actual_output' => $this->wrong ? 'WRONG' : $output, 'diagnostics' => ''];
    }
}
function finishIntegrated(GenerationService $generation, int $id): array
{
    for ($step = 0; $step < 80; ++$step) { $job = $generation->processForTeacher($id); if (in_array($job['status'], ['ready', 'failed', 'retry_wait'], true)) { return $job; } }
    throw new RuntimeException('Integrated generation exceeded the bounded fixture steps.');
}

$db = SqlWpdb::create(); $records = new WpdbRepository($db, true); $managementStore = new WpdbManagementStore($db, $records);
$platform = new Platform(); $jobs = new WpdbGenerationStore($db);
$manager = new ActivityManagement($records, $managementStore, $platform, $jobs);
$generator = new IntegratedGenerator(); $executor = new IntegratedExecutor();
$settings = new ClassSettingsService($platform); $factoryCalls = 0;
$factory = static function (array $job, array $lesson, array $quality) use ($manager, $db, &$factoryCalls): array {
    ++$factoryCalls; integrationCheck($db->pdo->inTransaction(), 'Activity factory runs within the generation completion transaction.');
    return $manager->acceptGenerated($job, $lesson, $quality);
};
$generation = new GenerationService($jobs, $records, $platform, $generator, $executor, $settings, $factory);
$input = static fn (string $title): array => ['context_type' => 'class', 'context_id' => 'class:7', 'target_problem_id' => 100,
    'title' => $title, 'request_key' => 'integrated:' . hash('sha256', $title)];
$count = static fn (): int => (int) $db->get_var('SELECT COUNT(*) FROM test_oj_primm_activities');

$activeJob = $generation->start($input('verified-active'));
integrationCheck($count() === 0, 'Queued generation does not expose an incomplete activity.');
$active = finishIntegrated($generation, $activeJob['id']); $original = $records->findActivity($active['activity_id']);
integrationCheck($active['status'] === 'ready' && $active['activity_status'] === 'active' && $original['status'] === 'active', 'Verified suitable material follows the default auto-publish policy.');
integrationCheck($original['lesson']['verification']['status'] === 'verified' && $original['lesson']['verification']['case_count'] === 3
    && $original['quality']['baseline_failures'] === 2 && $original['quality']['status'] === 'passed', 'All reference cases pass and the unchanged example fails the Modify behavior before publication.');
integrationCheck($original['content_version'] === 1 && $original['revision'] === 1 && $count() === 1, 'A completed job creates one managed immutable content version.');
integrationCheck($db->get_var('SELECT status FROM test_oj_primm_generations WHERE id=' . $active['id']) === 'ready' && !$db->pdo->inTransaction(), 'Activity insertion and ready state commit with no open transaction.');
integrationCheck($active['usage']['ai_calls'] === 1 && $active['usage']['execution_count'] === 5, 'Integrated usage counts one AI request and all reference/baseline executions.');
integrationCheck($original['template_origin'] === false && $original['generation_kind'] === 'ai', 'Ordinary AI generation records an explicit non-template provenance boundary.');
$beforeCalls = $generator->calls; $beforeFactory = $factoryCalls;
$generation->processForTeacher($active['id']);
integrationCheck($generator->calls === $beforeCalls && $factoryCalls === $beforeFactory && $count() === 1, 'Reloading a ready job does not rerun AI, judge or content insertion.');

$generator->oneTest = true;
$review = finishIntegrated($generation, $generation->start($input('quality-review'))['id']);
integrationCheck($review['activity_status'] === 'review' && $review['quality_summary']['reasons'] !== [] && $records->findActivity($review['activity_id'])['lesson']['verification']['status'] === 'verified', 'Execution success with insufficient semantic evidence is retained as a verified teacher-review activity.');
integrationRejects(fn () => $manager->publish($review['activity_id'], ['expected_revision' => 1]), 'Semantic review requires explicit acknowledgement before publishing.');
$generator->oneTest = false;
$settings->save('class', 'class:7', ['auto_publish' => false]);
$private = finishIntegrated($generation, $generation->start($input('verified-private'))['id']);
integrationCheck($private['activity_status'] === 'draft' && $private['quality_summary']['status'] === 'passed', 'An explicit auto_publish=false class setting keeps otherwise suitable verified material private.');

$edited = $manager->edit($original['id'], ['expected_revision' => 1, 'request_key' => 'integrated-edit-0001', 'patch' => ['title' => '편집한 질문', 'lesson' => $original['lesson']]]);
$draftId = $edited['activity']['id']; $beforeValidationCount = $count(); $beforeValidationAi = $generator->calls;
integrationCheck($edited['state'] === 'draft' && $edited['lesson']['verification']['status'] === 'not_executed', 'Editing creates a new unverified draft and invalidates any supplied certificate.');
$validation = $generation->validateActivity($draftId, ['request_key' => 'integrated-validation-0001']);
integrationCheck($jobs->find($validation['id'])['draft_activity_id'] === $draftId && $jobs->find($validation['id'])['draft_revision'] === 1, 'Validation-only generation is bound to the existing draft revision.');
$validated = finishIntegrated($generation, $validation['id']); $updatedDraft = $records->findActivity($draftId);
integrationCheck($validated['activity_id'] === $draftId && $count() === $beforeValidationCount && $updatedDraft['revision'] === 2, 'Revalidation updates the same draft atomically rather than creating an unrelated content version.');
integrationCheck($generator->calls === $beforeValidationAi && $updatedDraft['lesson']['verification']['status'] === 'verified' && $updatedDraft['status'] === 'draft', 'Manual revalidation calls no AI and respects the class publication setting.');
integrationCheck($records->findActivity($original['id']) === $original, 'Draft revalidation never changes the original published version or its proof.');
$settings->save('class', 'class:7', ['auto_publish' => true]);
$publishValidation = $generation->validateActivity($draftId, ['request_key' => 'integrated-validation-0002']);
$publishedDraft = finishIntegrated($generation, $publishValidation['id']);
integrationCheck($publishedDraft['activity_id'] === $draftId && $publishedDraft['activity_status'] === 'active' && $records->findActivity($draftId)['revision'] === 3, 'A later successful validation may publish that same draft under the updated class policy.');

$publicValidation = $generation->validateActivity($original['id'], ['request_key' => 'integrated-validation-public']);
integrationCheck(!isset($jobs->find($publicValidation['id'])['draft_activity_id']), 'Revalidating already published material uses a new-version path.');
$newPublic = finishIntegrated($generation, $publicValidation['id']);
integrationCheck($newPublic['activity_id'] !== $original['id'] && $records->findActivity($newPublic['activity_id'])['parent_activity_id'] === $original['id'], 'Published content remains immutable even when explicitly revalidated.');

$partial = $generation->regenerate($original['id'], ['scope' => 'predict', 'request_key' => 'integrated-regenerate-predict']);
$partialReady = finishIntegrated($generation, $partial['id']); $partialActivity = $records->findActivity($partialReady['activity_id']);
integrationCheck($partialActivity['parent_activity_id'] === $original['id'] && $generator->scopes === ['predict'], 'Partial regeneration is recorded as a new version of the requested source activity.');
integrationCheck($partialActivity['lesson']['demo'] === $original['lesson']['demo'] && $partialActivity['lesson']['stages'][3] === $original['lesson']['stages'][3]
    && $partialActivity['lesson']['stages'][0]['prompt'] !== $original['lesson']['stages'][0]['prompt'], 'Only the requested stage changes while the generation pipeline validates the preserved code again.');

$atomicJob = $generation->start($input('atomic-ready-failure'));
for ($step = 0; $step < 80; ++$step) { $state = $generation->processForTeacher($atomicJob['id']); if ($state['phase'] === 'publishing') { break; } }
$beforeAtomic = $count();
$db->pdo->exec("CREATE TRIGGER reject_ready BEFORE UPDATE OF status ON test_oj_primm_generations WHEN NEW.status='ready' BEGIN SELECT RAISE(ABORT,'injected ready failure'); END");
$atomicFailed = $generation->processForTeacher($atomicJob['id']);
integrationCheck($atomicFailed['status'] === 'failed' && $count() === $beforeAtomic && $jobs->find($atomicJob['id'])['activity_id'] === null, 'A failed ready update rolls back the actual managed activity insert and its unique request record.');
integrationCheck(!$db->pdo->inTransaction(), 'Completion rollback and terminal failure recording leave no nested transaction open.');
$db->pdo->exec('DROP TRIGGER reject_ready'); $generation->retry($atomicJob['id']);
integrationCheck(finishIntegrated($generation, $atomicJob['id'])['status'] === 'ready' && $count() === $beforeAtomic + 1, 'A deliberate retry after rollback produces exactly one activity.');

$backoff = $generation->start($input('durable-backoff')); $generator->transient = true;
$deferred = $generation->processForTeacher($backoff['id']); $saved = $jobs->find($backoff['id']);
integrationCheck($deferred['status'] === 'retry_wait' && $saved['last_failure']['code'] === 'addon_ai_request_pending' && $saved['usage']['ai_calls'] === 1, 'Typed retryable AI outcomes persist their code, usage reservation and durable backoff.');
$generator->transient = false; $db->update('test_oj_primm_generations', ['next_attempt_at' => '2000-01-01 00:00:00'], ['id' => $backoff['id']]);
$reloaded = new GenerationService(new WpdbGenerationStore($db), new WpdbRepository($db, true), $platform, $generator, $executor, $settings, $factory);
integrationCheck(finishIntegrated($reloaded, $backoff['id'])['status'] === 'ready' && $generator->keys[count($generator->keys) - 1] === $generator->keys[count($generator->keys) - 2], 'A fresh worker resumes the durable job using the original AI idempotency key.');

$staleDraft = $manager->edit($original['id'], ['expected_revision' => 1, 'request_key' => 'integrated-stale-draft', 'patch' => ['title' => 'stale revision']]);
$staleValidation = $generation->validateActivity($staleDraft['activity']['id'], ['request_key' => 'integrated-stale-validation']);
for ($step = 0; $step < 80; ++$step) { $state = $generation->processForTeacher($staleValidation['id']); if ($state['phase'] === 'publishing') { break; } }
$managementStore->update($staleDraft['activity']['id'], 1, ['title' => '다른 교사의 변경']); $beforeStale = $count();
integrationCheck($generation->processForTeacher($staleValidation['id'])['status'] === 'failed' && $count() === $beforeStale
    && $records->findActivity($staleDraft['activity']['id'])['lesson']['verification']['status'] === 'not_executed', 'A draft changed during validation cannot receive stale proof or spawn an orphan activity.');
integrationRejects(fn () => $generation->start($input('unsupported-template') + ['language' => 'java', 'use_template' => true]), 'Template validation still respects the OJ supported-language set.');

$settings->save('class', 'class:7', ['auto_prepare' => true]);
$automation = new ProblemPreparationAutomation($settings, $generation, $platform);
integrationCheck($automation->handleAssignment(['class_id' => 7, 'actor_user_id' => 11, 'problem_ids' => [100]]) === [], 'Class-assignment automation rejects a forged actor rather than impersonating the teacher.');
integrationCheck($automation->handleAssignment(['class_id' => 8, 'actor_user_id' => 10, 'problem_ids' => [100]]) === [], 'Class assignment retries only a class that enabled automatic preparation.');
$prepared = $automation->handleAssignment(['class_id' => 7, 'actor_user_id' => 10, 'problem_ids' => [100, 100], 'changed_at' => '2026-10-25 01:00:00']);
integrationCheck(count($prepared) === 1 && $jobs->find($prepared[0]['id'])['context_id'] === 'class:7', 'The assignment hook prepares each problem once in the assigned class.');
$samePrepared = $automation->handleAssignment(['class_id' => 7, 'actor_user_id' => 10, 'problem_ids' => [100], 'changed_at' => '2026-10-25 02:00:00']);
integrationCheck($samePrepared[0]['id'] === $prepared[0]['id'], 'Repeated assignment events converge on the same problem-content fingerprint.');
$log = get_option(ProblemPreparationAutomation::LOG_OPTION, []);
integrationCheck(($log['counts']['auto_prepare_enqueued'] ?? 0) >= 2 && !str_contains(json_encode($log, JSON_THROW_ON_ERROR), 'actor_user_id'), 'Automation logs contain safe code/count/time summaries rather than people or teaching content.');

$raceActivity = $managementStore->version(null, \OjPrimmTests\activity(\OjPrimmTests\lesson()), 'withdraw-race-source');
$learner = new Platform(); $learner->actor = 20;
$racePlatform = new class($learner, static function () use ($managementStore, $raceActivity): void { $managementStore->update($raceActivity['id'], 1, ['status' => 'withdrawn']); }) implements \OjPrimm\Application\PlatformAccess {
    public int $checks = 0;
    public function __construct(private readonly Platform $base, private readonly \Closure $withdraw) {}
    public function actorId(): int { return $this->base->actorId(); }
    public function assertRole(string $role): void { $this->base->assertRole($role); }
    public function contexts(bool $teaching): array { return $this->base->contexts($teaching); }
    public function authorize(string $type, string $id, string $operation, ?int $actor = null): array {
        $value = $this->base->authorize($type, $id, $operation, $actor);
        if ($operation === 'participate' && ++$this->checks === 2) { ($this->withdraw)(); }
        return $value;
    }
    public function searchProblems(string $search): array { return []; }
    public function assertSupport(int $id, string $kind, string $type, string $context): void {}
    public function problem(int $id, string $type, string $context, ?int $submission = null): array { return $this->base->problem($id, $type, $context, $submission); }
    public function problemUrl(int $id, string $type, string $context): string { return '/problem'; }
};
$raceService = new \OjPrimm\Application\PrimmService($records, $racePlatform, $managementStore);
$eventsBeforeRace = (int) $db->get_var('SELECT COUNT(*) FROM test_oj_primm_events');
integrationRejects(fn () => $raceService->progress($raceActivity['id'], ['stage' => 'predict', 'expected_version' => 0, 'responses' => ['predict' => '3'], 'advance' => true]), 'Withdrawing immediately after the final service authorization still blocks progress inside the repository transaction.');
integrationCheck($records->findActivity($raceActivity['id'])['status'] === 'withdrawn' && $records->findAttempt($raceActivity['id'], 20) === null
    && (int) $db->get_var('SELECT COUNT(*) FROM test_oj_primm_events') === $eventsBeforeRace, 'A withdrawal race cannot create a first prediction, attempt or event after publication was revoked.');
integrationRejects(fn () => $records->saveAttempt($private['activity_id'], 21, 0, [], ['stage' => 'predict', 'type' => 'draft_saved', 'payload' => []], 'forged-draft-write'), 'Production storage also rejects direct progress writes on unpublished drafts.');
integrationRejects(fn () => $records->saveAttempt(9999999, 21, 0, [], ['stage' => 'predict', 'type' => 'draft_saved', 'payload' => []], 'orphan-write'), 'No attempt can be inserted for an activity removed before the write transaction.');

$generator->available = false; $availability = $generation->availability();
integrationCheck(!$availability['available'] && $availability['execution_available'] && $availability['execution_languages'] === ['python']
    && $availability['languages'] === ['python'], 'OJ execution availability and supported languages remain accurate even without AI, overriding unrelated AI metadata.');
$generator->available = true;
$expiredInput = $input('absolute-job-expiry'); $expiredJob = $generation->start($expiredInput);
$db->update('test_oj_primm_generations', ['created_at' => gmdate('Y-m-d H:i:s', time() - 86401)], ['id' => $expiredJob['id']]);
$callsBeforeExpiry = $generator->calls; $factoryBeforeExpiry = $factoryCalls;
$expired = $generation->processForTeacher($expiredJob['id']);
integrationCheck($expired['status'] === 'failed' && $expired['error_code'] === 'generation_expired' && $expired['is_expired'] && !$expired['can_retry']
    && !$expired['can_resume'] && $generator->calls === $callsBeforeExpiry && $factoryCalls === $factoryBeforeExpiry, 'A job older than 24 hours is fenced before any AI call or publication and cannot advertise remote retries.');
integrationCheck($expired['new_request']['kind'] === 'create' && $expired['new_request']['context_id'] === 'class:7' && $expired['new_request']['target_problem_id'] === 100,
    'The expired owner receives only safe inputs for an explicit new request after page reload.');
integrationRejects(fn () => $generation->retry($expiredJob['id']), 'Manual retry cannot reuse an expired AI ledger key.');
integrationRejects(fn () => $generation->resume($expiredJob['id']), 'Resume cannot reactivate an expired generation pipeline.');
$replacement = $generation->start($expiredInput); $duplicateReplacement = $generation->start($expiredInput);
integrationCheck($replacement['id'] !== $expiredJob['id'] && $duplicateReplacement['id'] === $replacement['id']
    && $jobs->find($replacement['id'])['supersedes_job_id'] === $expiredJob['id'], 'A deliberate new creation replaces expired work while repeated clicks converge on one fresh job.');
$generation->cancel($replacement['id']);
$expiredRunning = $generation->start($input('expire-a-running-lease')); $oldLease = str_repeat('e', 48);
$jobs->claim($expiredRunning['id'], $oldLease, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 300));
$db->update('test_oj_primm_generations', ['created_at' => gmdate('Y-m-d H:i:s', time() - 86401)], ['id' => $expiredRunning['id']]);
$generation->processForTeacher($expiredRunning['id']);
integrationRejects(fn () => $jobs->checkpoint($expiredRunning['id'], $oldLease, ['phase' => 'publishing']), 'Expiration revokes an in-flight lease so an old worker cannot publish later.');
$db->update('test_oj_primm_generations', ['created_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)], ['id' => $active['id']]);
integrationCheck($generation->processForTeacher($active['id'])['status'] === 'ready' && $generator->calls === $callsBeforeExpiry,
    'Completed cached material remains readable without re-executing an expired remote request.');

$settings->save('class', 'class:7', ['auto_publish' => true]);
$aiBeforeTemplate = $generator->calls;
$templateJob = $generation->start($input('template-provenance') + ['use_template' => true, 'template_origin' => false]);
$templateReady = finishIntegrated($generation, $templateJob['id']); $templateActivity = $records->findActivity($templateReady['activity_id']);
integrationCheck($templateActivity['template_origin'] === true && $templateActivity['generation_kind'] === 'template'
    && $templateActivity['status'] === 'review' && $generator->calls === $aiBeforeTemplate, 'Template creation persists server provenance, ignores browser provenance and requests review without AI calls.');
$templateDraft = $manager->edit($templateActivity['id'], ['expected_revision' => 1, 'request_key' => 'template-unacknowledged-edit', 'patch' => ['title' => '제목만 편집한 기본 예제']]);
$templateValidation = $generation->validateActivity($templateDraft['activity']['id'], ['request_key' => 'template-unacknowledged-validation', 'template_origin' => false]);
integrationCheck($jobs->find($templateValidation['id'])['template_origin'] === true && $jobs->find($templateValidation['id'])['validation_only'], 'Revalidation snapshots trusted template lineage rather than accepting a browser origin override.');
$templateValidated = finishIntegrated($generation, $templateValidation['id']); $templateDraftId = $templateDraft['activity']['id'];
$reviewedDraft = $records->findActivity($templateDraftId);
integrationCheck($templateValidated['activity_id'] === $templateDraftId && $reviewedDraft['template_origin'] === true
    && $reviewedDraft['quality']['status'] === 'needs_review' && $reviewedDraft['status'] === 'review'
    && $reviewedDraft['lesson']['verification']['status'] === 'verified' && $generator->calls === $aiBeforeTemplate
    && $templateValidated['usage']['ai_calls'] === 0, 'Even a title-only edit of an unacknowledged template remains in review after complete OJ revalidation, using zero AI calls.');
integrationRejects(fn () => $manager->publish($templateDraftId, ['expected_revision' => 2]), 'Mechanically verified template descendants still require explicit teacher acknowledgement.');
integrationRejects(fn () => $manager->edit($templateDraftId, ['expected_revision' => 2, 'patch' => ['template_origin' => false]]), 'Lesson edit requests cannot alter server provenance metadata.');
$manager->publish($templateActivity['id'], ['expected_revision' => 1, 'acknowledge_review' => true]);
$acknowledgedDraft = $manager->edit($templateActivity['id'], ['expected_revision' => 2, 'request_key' => 'template-acknowledged-edit', 'patch' => ['title' => '검토한 원본의 새 편집']]);
$acknowledgedValidation = finishIntegrated($generation, $generation->validateActivity($acknowledgedDraft['activity']['id'], ['request_key' => 'template-acknowledged-validation'])['id']);
integrationCheck($acknowledgedValidation['activity_status'] === 'review' && $generator->calls === $aiBeforeTemplate,
    'Acknowledging an older published version does not acknowledge the next edited template version.');
$templateClone = $manager->cloneActivity($templateActivity['id'], ['context_type' => 'class', 'context_id' => 'class:7', 'title' => '재사용한 기본 예제', 'request_key' => 'template-provenance-clone']);
integrationCheck($templateClone['activity']['template_origin'] === true && $templateClone['activity']['quality']['status'] === 'needs_review', 'Reusing a template copies its review provenance into the new draft.');

foreach (['predict', 'modify'] as $scope) {
    $partialTemplate = finishIntegrated($generation, $generation->regenerate($templateActivity['id'], ['scope' => $scope, 'request_key' => 'template-partial-' . $scope])['id']);
    $partialTemplateActivity = $records->findActivity($partialTemplate['activity_id']);
    integrationCheck($partialTemplateActivity['template_origin'] === true && $partialTemplateActivity['status'] === 'review'
        && $partialTemplateActivity['quality']['status'] === 'needs_review', 'Partial ' . $scope . ' AI regeneration cannot clear the template-derived introduction or remaining teaching content.');
}
$fullTemplate = finishIntegrated($generation, $generation->regenerate($templateActivity['id'], ['scope' => 'all', 'request_key' => 'template-full-ai-regeneration'])['id']);
$fullAiActivity = $records->findActivity($fullTemplate['activity_id']);
integrationCheck($fullAiActivity['template_origin'] === false && $fullAiActivity['generation_kind'] === 'ai' && $fullAiActivity['status'] === 'active'
    && $fullAiActivity['quality']['status'] === 'passed', 'A complete AI regeneration records an explicit new origin boundary and can follow the ordinary auto-publish policy.');
$fullAiDraft = $manager->edit($fullAiActivity['id'], ['expected_revision' => 1, 'request_key' => 'full-ai-descendant-edit', 'patch' => ['title' => '전체 AI 생성 뒤 편집']]);
$aiBeforeBoundaryValidation = $generator->calls;
$fullAiValidation = finishIntegrated($generation, $generation->validateActivity($fullAiDraft['activity']['id'], ['request_key' => 'full-ai-descendant-validation'])['id']);
integrationCheck($fullAiValidation['activity_status'] === 'active' && $records->findActivity($fullAiValidation['activity_id'])['template_origin'] === false
    && $generator->calls === $aiBeforeBoundaryValidation, 'Later edits and zero-AI revalidation stop at the full AI boundary instead of rediscovering a template ancestor.');

// Recreate 1.0.0 metadata: template activities were incorrectly labelled AI, and validation jobs had no origin flag.
$legacyData = $templateActivity; unset($legacyData['id'], $legacyData['template_origin']);
$legacyData['generation_kind'] = 'ai'; $legacyData['quality'] = ['status' => 'passed', 'reasons' => []];
$legacyTemplate = $managementStore->version(null, $legacyData, 'legacy-template-no-origin');
$legacyValidationJob = $jobs->createOrFind(['creator_user_id' => 10, 'fingerprint' => hash('sha256', 'legacy-validation'),
    'validation_only' => true, 'scope' => 'all', 'seed_lesson' => $legacyData['lesson'], 'parent_activity_id' => $legacyTemplate['id']]);
$legacyChildData = $legacyData; $legacyChildData['generation_id'] = $legacyValidationJob['id'];
$legacyChild = $managementStore->version($legacyTemplate, $legacyChildData, 'legacy-validated-child-no-origin');
$db->update('test_oj_primm_generations', ['status' => 'ready', 'activity_id' => $legacyChild['id']], ['id' => $legacyValidationJob['id']]);
$legacyView = $manager->manage($legacyChild['id']);
integrationCheck($legacyView['activity']['template_origin'] === true && $legacyView['activity']['quality']['status'] === 'needs_review',
    'A legacy validation descendant recovers template provenance through its own generation ledger and parent activity chain.');
integrationRejects(fn () => $manager->publish($legacyChild['id'], ['expected_revision' => 1]), 'Legacy quality=passed metadata cannot bypass provenance review through manual publishing.');
$legacyDraft = $manager->edit($legacyChild['id'], ['expected_revision' => 1, 'request_key' => 'legacy-template-edit', 'patch' => ['title' => '기존 1.0.0 자료 편집']]);
integrationCheck($records->findActivity($legacyDraft['activity']['id'])['template_origin'] === true, 'Editing a legacy record persists the recovered server origin before removing its old generation pointer.');
$aiBeforeLegacyValidation = $generator->calls;
$legacyPending = $generation->validateActivity($legacyDraft['activity']['id'], ['request_key' => 'legacy-template-revalidation']);
for ($step = 0; $step < 80; ++$step) { $state = $generation->processForTeacher($legacyPending['id']); if ($state['phase'] === 'publishing') { break; } }
$oldCheckpoint = json_decode($db->get_var('SELECT data_json FROM test_oj_primm_generations WHERE id=' . $legacyPending['id']), true, 32, JSON_THROW_ON_ERROR);
unset($oldCheckpoint['template_origin']); $oldCheckpoint['pipeline']['quality'] = ['status' => 'passed', 'reasons' => []];
$db->update('test_oj_primm_generations', ['data_json' => json_encode($oldCheckpoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => $legacyPending['id']]);
$legacyValidated = finishIntegrated($generation, $legacyPending['id']);
integrationCheck($legacyValidated['activity_status'] === 'review' && $legacyValidated['quality_summary']['status'] === 'needs_review'
    && $generator->calls === $aiBeforeLegacyValidation && $legacyValidated['usage']['ai_calls'] === 0, 'An old publishing checkpoint without origin metadata or its review gate is fenced again before zero-AI publication.');
$legacyAiData = $original; unset($legacyAiData['id'], $legacyAiData['template_origin']);
$legacyAi = $managementStore->version(null, $legacyAiData, 'legacy-ordinary-ai-no-origin');
integrationCheck($manager->manage($legacyAi['id'])['activity']['template_origin'] === false, 'A legacy ordinary AI generation remains distinguishable from a template without requiring unnecessary review.');
$orphanData = $legacyData; $orphanData['generation_id'] = 9999999;
$orphan = $managementStore->version(null, $orphanData, 'legacy-missing-generation-ledger');
integrationCheck($manager->manage($orphan['id'])['activity']['template_origin'] === true, 'A missing legacy generation ledger is treated conservatively when provenance can no longer be established.');
$fallbackGeneration = new GenerationService($jobs, $records, $platform, $generator, $executor, $settings);
$fallbackReady = finishIntegrated($fallbackGeneration, $fallbackGeneration->start($input('template-fallback-factory') + ['use_template' => true])['id']);
$fallbackActivity = $records->findActivity($fallbackReady['activity_id']);
integrationCheck($fallbackActivity['template_origin'] === true && $fallbackActivity['generation_kind'] === 'template'
    && $fallbackActivity['quality']['status'] === 'needs_review' && $fallbackActivity['status'] === 'review', 'The standalone generation factory also persists provenance and keeps verified templates in review.');
$publicTeacher = new \OjPrimm\Application\PrimmService($records, $platform, $managementStore, null, $jobs);
$publicLearnerPlatform = new Platform(); $publicLearnerPlatform->actor = 20;
$publicLearner = new \OjPrimm\Application\PrimmService($records, $publicLearnerPlatform, $managementStore, null, $jobs);
$templateStudentView = $publicLearner->activity($templateActivity['id']);
integrationCheck($templateStudentView['activity']['generation_kind'] === 'template' && $templateStudentView['demo']['verification']['status'] === 'verified'
    && !array_key_exists('template_origin', $templateStudentView['activity']) && !array_key_exists('generation_id', $templateStudentView['activity']), 'A verified template is publicly labelled template without exposing its private provenance or generation ledger.');
integrationCheck($publicLearner->activity($original['id'])['activity']['generation_kind'] === 'ai'
    && $publicLearner->activity($fullAiActivity['id'])['activity']['generation_kind'] === 'ai', 'Ordinary and fully regenerated AI materials retain their AI authoring label for students.');
// Preserve the actual pre-fix state: a legacy validation descendant had been automatically published with no origin metadata.
$managementStore->update($legacyChild['id'], 1, ['status' => 'active']);
integrationCheck($publicLearner->activity($legacyChild['id'])['activity']['generation_kind'] === 'template', 'An already-published 1.0.0 descendant restores its template label from the ledger and parent chain during student reads.');
$teacherActivityList = array_column($publicTeacher->teacherActivities('class', 'class:7', '', 100)['activities'], null, 'id');
$studentActivityList = array_column($publicLearner->studentActivities(), null, 'id');
integrationCheck($teacherActivityList[$legacyChild['id']]['generation_kind'] === 'template'
    && $studentActivityList[$legacyChild['id']]['generation_kind'] === 'template'
    && $studentActivityList[$original['id']]['generation_kind'] === 'ai', 'Teacher and student summary lists use the same recovered public authoring provenance as the activity page.');
$manualActivity = $publicTeacher->saveActivity(['context_type' => 'class', 'context_id' => 'class:7', 'target_problem_id' => 100, 'demo_code' => 'condition']);
integrationCheck($publicLearner->activity($manualActivity['id'])['activity']['generation_kind'] === 'template', 'The older fixed introductory examples are also labelled template without claiming AI authorship.');
fwrite(STDOUT, 'PRIMM generation-management integration: ' . $checks . " checks passed (production repositories over SQLite; fake AI/OJ).\n");

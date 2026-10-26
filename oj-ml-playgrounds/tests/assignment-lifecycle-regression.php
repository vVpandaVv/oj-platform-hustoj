<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Application\AssignmentService;
use OJML\Playgrounds\Domain\AssignmentStatus;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Rest\Presenter;

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value));
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string
    {
        return $value;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return false;
    }
}

$root = dirname(__DIR__);
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . DIRECTORY_SEPARATOR);
}
require_once $root . '/src/Domain/Problem.php';
require_once $root . '/src/Domain/AssignmentStatus.php';
require_once $root . '/src/Domain/AttemptStatus.php';
require_once $root . '/src/Application/AssignmentService.php';
require_once $root . '/src/Security/Addon_Capabilities.php';
require_once $root . '/src/Http/ArtifactDownload.php';
require_once $root . '/src/Rest/Presenter.php';

/** @param mixed $expected @param mixed $actual */
function lifecycleAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true)
        );
    }
}

function lifecycleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable():mixed $callback */
function lifecycleAssertProblem(callable $callback, string $code, int $status, string $message): void
{
    try {
        $callback();
    } catch (Problem $problem) {
        lifecycleAssertSame($code, $problem->problemCode(), $message . ' (problem code)');
        lifecycleAssertSame($status, $problem->httpStatus(), $message . ' (HTTP status)');
        return;
    }
    throw new RuntimeException($message . ' (exception was not raised)');
}

$reflection = new ReflectionClass(AssignmentService::class);
$service = $reflection->newInstanceWithoutConstructor();
$studentStatus = $reflection->getMethod('studentStatus');
$studentStatus->setAccessible(true);
$include = $reflection->getMethod('shouldIncludeForLearner');
$include->setAccessible(true);
$skipStaleContext = $reflection->getMethod('shouldSkipStaleContextProblem');
$skipStaleContext->setAccessible(true);
$publishedChanges = $reflection->getMethod('normalizePublishedChanges');
$publishedChanges->setAccessible(true);
$now = new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC'));

$base = [
    'status' => AssignmentStatus::PUBLISHED,
    'opens_at' => '2026-09-01 11:00:00',
    'due_at' => '2026-09-01 13:00:00',
    'policy' => ['allowLateSubmission' => false],
];
lifecycleAssertSame('scheduled', $studentStatus->invoke($service, array_replace($base, [
    'opens_at' => '2026-09-01 12:30:00',
]), $now), 'A future opening time must derive scheduled.');
lifecycleAssertSame('open', $studentStatus->invoke($service, $base, $now), 'An active published assignment must derive open.');
lifecycleAssertSame('open', $studentStatus->invoke($service, array_replace($base, [
    'due_at' => '2026-09-01 12:00:00',
]), $now), 'The exact due-time boundary must remain open, matching submission checks.');
lifecycleAssertSame('late_open', $studentStatus->invoke($service, array_replace($base, [
    'due_at' => '2026-09-01 11:59:59',
    'policy' => ['allowLateSubmission' => true],
]), $now), 'An overdue assignment with late submissions enabled must derive late_open.');
lifecycleAssertSame('expired', $studentStatus->invoke($service, array_replace($base, [
    'due_at' => '2026-09-01 11:59:59',
]), $now), 'An overdue assignment without late submissions must derive expired.');
lifecycleAssertSame('closed', $studentStatus->invoke($service, array_replace($base, [
    'status' => AssignmentStatus::CLOSED,
]), $now), 'A closed assignment must derive closed regardless of dates.');
lifecycleAssertSame('archived', $studentStatus->invoke($service, array_replace($base, [
    'status' => AssignmentStatus::ARCHIVED,
]), $now), 'An archived assignment must derive archived regardless of dates.');

foreach (['expired', 'closed'] as $historicalStatus) {
    lifecycleAssertSame(false, $include->invoke($service, [
        'student_status' => $historicalStatus,
        'has_submission_history' => false,
    ]), $historicalStatus . ' without a received artifact must be omitted from the learner list.');
    lifecycleAssertSame(true, $include->invoke($service, [
        'student_status' => $historicalStatus,
        'has_submission_history' => true,
    ]), $historicalStatus . ' with a received artifact must remain available for result access.');
}
foreach ([false, true] as $hasHistory) {
    lifecycleAssertSame(false, $include->invoke($service, [
        'student_status' => 'archived',
        'has_submission_history' => $hasHistory,
    ]), 'Archived assignments must never be exposed through learner assignment routes.');
}
foreach ([403, 404, 409] as $staleStatus) {
    lifecycleAssertSame(true, $skipStaleContext->invoke(
        $service,
        new Problem('OJ_ML_CONTEXT_STALE', 'stale', $staleStatus)
    ), 'Stale learner/report context failures must be isolated per assignment.');
}
lifecycleAssertSame(false, $skipStaleContext->invoke(
    $service,
    new Problem('OJ_ML_CONTEXT_FAILURE', 'failure', 500)
), 'Server failures must still escape learner/report list processing.');

foreach (['scheduled', 'open', 'late_open'] as $currentStatus) {
    lifecycleAssertSame(true, $include->invoke($service, [
        'student_status' => $currentStatus,
        'has_submission_history' => false,
    ]), $currentStatus . ' must remain visible before the first attempt.');
}

$current = [
    'task_type' => 'classification',
    'max_attempts' => 2,
    'opens_at' => '2026-09-01 09:00:00',
    'due_at' => '2026-09-02 09:00:00',
];
$safeChanges = $publishedChanges->invoke($service, [
    'title' => '수정된 과제',
    'description' => '<p>안내 수정</p>',
    'dueAt' => '2026-09-03T09:00:00Z',
    'maxAttempts' => 3,
], $current);
lifecycleAssertSame('수정된 과제', $safeChanges['title'], 'Published title edits must be normalized.');
lifecycleAssertSame('<p>안내 수정</p>', $safeChanges['description'], 'Published description edits must be normalized.');
lifecycleAssertSame('2026-09-03 09:00:00', $safeChanges['due_at'], 'Published due-time edits must normalize to UTC.');
lifecycleAssertSame(3, $safeChanges['max_attempts'], 'Published max attempts may increase.');
lifecycleAssertProblem(
    static fn () => $publishedChanges->invoke($service, [], $current),
    'OJ_ML_ASSIGNMENT_UPDATE_EMPTY',
    400,
    'Published operational edits must reject an empty payload.'
);
lifecycleAssertProblem(
    static fn () => $publishedChanges->invoke($service, ['maxAttempts' => 1], $current),
    'OJ_ML_ASSIGNMENT_MAX_ATTEMPTS_DECREASE_FORBIDDEN',
    409,
    'Published max attempts must never decrease.'
);
foreach (['taskType', 'opensAt', 'policy', 'rubric', 'contextType', 'contextId'] as $forbidden) {
    lifecycleAssertProblem(
        static fn () => $publishedChanges->invoke($service, [$forbidden => []], $current),
        'OJ_ML_ASSIGNMENT_IMMUTABLE',
        409,
        'Published core field must remain immutable: ' . $forbidden
    );
}
lifecycleAssertProblem(
    static fn () => $publishedChanges->invoke($service, ['dueAt' => '2026-08-31T09:00:00Z'], $current),
    'OJ_ML_ASSIGNMENT_DATES_INVALID',
    400,
    'A published due time must remain after its immutable opening time.'
);

$presented = (new Presenter())->assignment([
    'id' => 1,
    'title' => '과제',
    'description' => '',
    'task_type' => 'classification',
    'status' => AssignmentStatus::PUBLISHED,
    'opens_at' => null,
    'due_at' => null,
    'max_attempts' => 2,
    'revision' => 3,
    'created_at' => '2026-09-01 00:00:00',
    'updated_at' => '2026-09-01 00:00:00',
    'published_at' => '2026-09-01 00:00:00',
    'student_status' => 'late_open',
    'policy' => [],
]);
lifecycleAssertSame('late_open', $presented['studentStatus'] ?? null, 'REST presentation must expose studentStatus.');

$privateFailureSentinel = 'PRIVATE-MLPX-PATH-SENTINEL';
$feedbackSentinel = str_repeat('F', 50000);
$attemptFixture = [
    'uuid' => '00000000-0000-4000-8000-000000000099',
    'assignment_id' => 1,
    'attempt_no' => 2,
    'status' => 'rejected',
    'policy_revision' => 3,
    'client_app_version' => '0.12.1',
    'format_version' => 2,
    'progress' => ['phase' => 'done'],
    'failure' => [
        'code' => 'OJ_ML_INSPECTION_FAILED',
        'details' => [
            'entry' => 'datasets/private.csv',
            'storageKey' => $privateFailureSentinel,
        ],
    ],
    'late' => false,
    'received_at' => '2026-09-01 00:10:00',
    'created_at' => '2026-09-01 00:00:00',
    'updated_at' => '2026-09-01 00:10:00',
    'review' => [
        'version' => 1,
        'status' => 'released',
        'score' => 8,
        'max_score' => 10,
        'feedback' => $feedbackSentinel,
        'rubric_revision' => 3,
        'created_at' => '2026-09-01 00:20:00',
        'released_at' => '2026-09-01 00:30:00',
        'reviewer_id' => 7,
    ],
];
$presentedAttempt = (new Presenter())->attempt($attemptFixture);
lifecycleAssertSame(
    ['code' => 'OJ_ML_INSPECTION_FAILED'],
    $presentedAttempt['failure'],
    'Attempt failure DTOs must expose only a sanitized stable code.'
);
lifecycleAssert(
    !str_contains((string) json_encode($presentedAttempt['failure']), $privateFailureSentinel)
        && !array_key_exists('details', $presentedAttempt['failure']),
    'Attempt failure DTOs must never expose inspector paths, storage keys, or detail context.'
);
lifecycleAssertSame($feedbackSentinel, $presentedAttempt['review']['feedback'] ?? null,
    'Full attempt detail must retain released review feedback.');

$listItem = (new Presenter())->learnerAssignmentListItem([
    'id' => 1,
    'title' => '과제',
    'description' => '',
    'task_type' => 'classification',
    'status' => AssignmentStatus::PUBLISHED,
    'opens_at' => null,
    'due_at' => null,
    'max_attempts' => 2,
    'revision' => 3,
    'created_at' => '2026-09-01 00:00:00',
    'updated_at' => '2026-09-01 00:00:00',
    'published_at' => '2026-09-01 00:00:00',
    'student_status' => 'open',
    'policy' => [],
    'attempts_used' => 2,
    'active_attempt' => $attemptFixture,
    'latest_attempt' => $attemptFixture,
    'latest_attempt_summary' => ['uuid' => $attemptFixture['uuid'], 'received_at' => $attemptFixture['received_at']],
]);
lifecycleAssertSame(
    ['id' => $attemptFixture['uuid'], 'receivedAt' => '2026-09-01T00:10:00+00:00'],
    $listItem['latestAttempt'] ?? null,
    'Learner assignment list DTOs need only the result-link attempt summary.'
);
lifecycleAssert(
    !array_key_exists('attemptsUsed', $listItem)
        && !array_key_exists('activeAttempt', $listItem)
        && !str_contains((string) json_encode($listItem), $feedbackSentinel)
        && !str_contains((string) json_encode($listItem), $privateFailureSentinel),
    'Learner assignment lists must not serialize detailed attempts, reviews, or failure context.'
);
$summary = (new Presenter())->assignmentSummary($presented + [
    'description' => '비공개 설명',
    'policy' => ['secret' => true],
]);
lifecycleAssertSame(['id' => 1, 'title' => '과제', 'status' => AssignmentStatus::PUBLISHED], $summary,
    'Report assignment summaries must expose only id, title, and status.');


$repositorySource = file_get_contents($root . '/src/Database/Repository.php');
lifecycleAssert(is_string($repositorySource), 'Repository source must be readable.');
lifecycleAssert(
    str_contains($repositorySource, 'updatePublishedAssignmentOperations(')
        && str_contains($repositorySource, "['title', 'description', 'due_at', 'max_attempts']")
        && str_contains($repositorySource, "['id' => \$assignmentId, 'revision' => \$expectedRevision, 'status' => AssignmentStatus::PUBLISHED]")
        && str_contains($repositorySource, "'assignment.operations_updated'")
        && str_contains($repositorySource, 'hasSubmissionHistory('),
    'Repository must enforce the published allowlist, revision guard, audit event, and received submission history.'
);
lifecycleAssert(
    str_contains($repositorySource, "if (\$current['status'] !== AssignmentStatus::DRAFT)")
        && str_contains($repositorySource, 'OJ_ML_ASSIGNMENT_HAS_ATTEMPTS'),
    'Hard deletion must remain limited to attempt-free drafts.'
);

$assignmentSource = file_get_contents($root . '/src/Application/AssignmentService.php');
lifecycleAssert(is_string($assignmentSource), 'AssignmentService source must be readable.');
lifecycleAssert(
    str_contains($assignmentSource, '[AssignmentStatus::PUBLISHED, AssignmentStatus::CLOSED]')
        && str_contains($assignmentSource, 'shouldSkipStaleContextProblem($problem)')
        && str_contains($assignmentSource, 'authorizedTeacherAssignments(')
        && str_contains($assignmentSource, "'manage',")
        && str_contains($assignmentSource, "'report',")
        && str_contains($assignmentSource, 'assertAssignmentAccess($actorId, $assignment, $operation)')
        && str_contains($assignmentSource, 'MAX_AUTHORIZED_ASSIGNMENT_SCAN')
        && str_contains($assignmentSource, "=== AssignmentStatus::DRAFT")
        && str_contains($assignmentSource, "positiveInt(\$expectedRevision, 'expectedRevision')")
        && str_contains($assignmentSource, 'deleteAssignment($assignmentId, $expectedRevision, $actorId)'),
    'Service must isolate stale items, authorize manage/report independently, bound scans, filter drafts, and validate deletes.'
);
lifecycleAssert(
    str_contains($repositorySource, "['id' => \$assignmentId, 'revision' => \$expectedRevision, 'status' => AssignmentStatus::DRAFT]")
        && str_contains($repositorySource, 'if ($deleted !== 1)'),
    'Draft deletion must require an exact status/revision match and exactly one deleted assignment row.'
);

$reportListStart = strpos($assignmentSource, 'public function reportAssignments(');
$teacherListStart = strpos($assignmentSource, 'public function teacherAssignments(');
$teacherResultsStart = strpos($assignmentSource, 'public function teacherResults(');
$teacherResultStart = strpos($assignmentSource, 'public function teacherResult(', ($teacherResultsStart ?: 0) + 1);
lifecycleAssert(
    $teacherListStart !== false && $reportListStart !== false && $teacherResultsStart !== false && $teacherResultStart !== false,
    'Report service boundaries must be discoverable.'
);
$teacherListBlock = substr($assignmentSource, $teacherListStart, $reportListStart - $teacherListStart);
$reportListBlock = substr($assignmentSource, $reportListStart, $teacherResultsStart - $reportListStart);
$teacherResultsBlock = substr($assignmentSource, $teacherResultsStart, $teacherResultStart - $teacherResultsStart);
lifecycleAssert(
    str_contains($teacherListBlock, "'manage'")
        && str_contains($teacherListBlock, 'true')
        && str_contains($reportListBlock, "'report'")
        && str_contains($reportListBlock, 'false'),
    'Manage and report collections must use independent operation-aware authorization paths.'
);
lifecycleAssert(
    str_contains($assignmentSource, '$authorizationKey')
        && str_contains($assignmentSource, 'array_key_exists($authorizationKey, $authorization)')
        && str_contains($assignmentSource, 'OJ_ML_ASSIGNMENT_SCAN_LIMIT'),
    'Teacher assignment authorization must be request-memoized and fail closed at the scan cap.'
);
lifecycleAssert(
    str_contains($teacherResultsBlock, "teacherAssignment(\$assignmentId, \$actorId, 'report')")
        && str_contains($teacherResultsBlock, "=== AssignmentStatus::DRAFT")
        && str_contains($teacherResultsBlock, "OJ_ML_ASSIGNMENT_NOT_FOUND"),
    'Direct teacher result lists must reject draft assignment IDs.'
);
lifecycleAssert(
    str_contains($repositorySource, 'AssignmentStatus::DRAFT => []'),
    'Draft assignments must not transition into permanent archived orphans.'
);

fwrite(STDOUT, "OJ ML assignment lifecycle regression passed.\n");


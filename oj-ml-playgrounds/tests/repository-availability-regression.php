<?php

declare(strict_types=1);

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\AssignmentStatus;
use OJML\Playgrounds\Domain\AttemptStatus;
use OJML\Playgrounds\Domain\Problem;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return '90000000-0000-4000-8000-000000000001';
    }
}

if (!class_exists('wpdb')) {
    class wpdb
    {
        public int $insert_id = 0;

        /** @var array<string,mixed> */
        public array $assignmentRow;

        /** @var array<string,mixed> */
        public array $attemptRow;

        /** @var list<string> */
        public array $preparedQueries = [];

        /** @var list<string> */
        public array $queries = [];

        /** @var list<array{table:string,where:array<string,mixed>}> */
        public array $deleteCalls = [];

        /** @var array<string,int|false> */
        public array $deleteResults = [];

        public int $artifactInsertCount = 0;
        public int $attemptCount = 0;

        public function __construct()
        {
            $this->assignmentRow = [
                'id' => 3,
                'title' => '과제',
                'description' => '',
                'task_type' => 'classification',
                'context_type' => 'class',
                'context_id' => 'class-1',
                'context_revision' => 'ctx-1',
                'roster_revision' => 'roster-1',
                'status' => AssignmentStatus::PUBLISHED,
                'policy_json' => '{"allowLateSubmission":false}',
                'rubric_json' => '[]',
                'opens_at' => '2026-08-01 00:00:00',
                'due_at' => '2099-09-01 00:00:00',
                'max_attempts' => 2,
                'revision' => 3,
                'created_by' => 5,
                'created_at' => '2026-08-01 00:00:00',
                'updated_at' => '2026-08-01 00:00:00',
                'published_at' => '2026-08-01 00:00:00',
            ];
            $this->attemptRow = [
                'id' => 11,
                'uuid' => '00000000-0000-4000-8000-000000000011',
                'assignment_id' => 3,
                'user_id' => 7,
                'attempt_no' => 1,
                'status' => AttemptStatus::UPLOADING,
                'idempotency_key' => null,
                'artifact_id' => null,
                'policy_revision' => 3,
                'client_app_version' => '0.12.1',
                'format_version' => 2,
                'progress_json' => null,
                'last_progress_at' => null,
                'failure_code' => null,
                'failure_json' => null,
                'late' => 0,
                'received_at' => null,
                'created_at' => '2026-09-01 00:00:00',
                'updated_at' => '2026-09-01 00:00:00',
            ];
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            $this->preparedQueries[] = $query;
            return $query;
        }

        /** @return array<string,mixed>|null */
        public function get_row(string $query, mixed $output = null): ?array
        {
            if (str_contains($query, 'FROM oj_ml_assignments')) {
                return $this->assignmentRow;
            }
            if (str_contains($query, 'SELECT assignment_id FROM oj_ml_attempts')) {
                return ['assignment_id' => (int) $this->attemptRow['assignment_id']];
            }
            if (str_contains($query, 'oj_ml_artifacts') || str_contains($query, 'oj_ml_evaluations') || str_contains($query, 'oj_ml_reviews')) {
                return null;
            }
            if (str_contains($query, 'FROM oj_ml_attempts')) {
                return $this->attemptRow;
            }
            return null;
        }

        /** @return list<array<string,mixed>> */
        public function get_results(string $query, mixed $output = null): array
        {
            return [];
        }

        /** @return list<int> */
        public function get_col(string $query): array
        {
            return [];
        }

        public function get_var(string $query): int
        {
            return $this->attemptCount;
        }

        public function query(string $query): int|false
        {
            $this->queries[] = $query;
            return 1;
        }

        /** @param array<string,mixed> $data @param list<string> $formats */
        public function insert(string $table, array $data, array $formats): int|false
        {
            if ($table === 'oj_ml_artifacts') {
                $this->artifactInsertCount++;
                $this->insert_id = 71;
            }
            return 1;
        }

        /**
         * @param array<string,mixed> $data
         * @param array<string,mixed> $where
         * @param list<string> $formats
         * @param list<string> $whereFormats
         */
        public function update(string $table, array $data, array $where, array $formats, array $whereFormats): int|false
        {
            if ($table === 'oj_ml_attempts') {
                foreach ($data as $key => $value) {
                    $this->attemptRow[$key] = $value;
                }
            }
            return 1;
        }

        /** @param array<string,mixed> $where @param list<string> $formats */
        public function delete(string $table, array $where, array $formats): int|false
        {
            $this->deleteCalls[] = ['table' => $table, 'where' => $where];
            return $this->deleteResults[$table] ?? 1;
        }
    }
}

$root = dirname(__DIR__);
require_once $root . '/src/Domain/Problem.php';
require_once $root . '/src/Domain/AssignmentStatus.php';
require_once $root . '/src/Domain/AttemptStatus.php';
require_once $root . '/src/Database/Tables.php';
require_once $root . '/src/Database/Repository.php';

/** @param mixed $expected @param mixed $actual */
function availabilityAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true));
    }
}

function availabilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable():mixed $callback */
function availabilityAssertProblem(callable $callback, string $code, int $status, string $message): void
{
    try {
        $callback();
    } catch (Problem $problem) {
        availabilityAssertSame($code, $problem->problemCode(), $message . ' (problem code)');
        availabilityAssertSame($status, $problem->httpStatus(), $message . ' (HTTP status)');
        return;
    }
    throw new RuntimeException($message . ' (exception was not raised)');
}

/** @param list<string> $queries */
function availabilityQueryIndex(array $queries, string $needle): int
{
    foreach ($queries as $index => $query) {
        if (str_contains($query, $needle)) {
            return $index;
        }
    }
    return -1;
}

/** @return array<string,mixed> */
function availabilityArtifact(): array
{
    return [
        'uuid' => '00000000-0000-4000-8000-000000000071',
        'storage_key' => 'private/artifact.mlpx',
        'original_name' => 'artifact.mlpx',
        'sha256' => str_repeat('a', 64),
        'bytes' => 1024,
        'mime_type' => 'application/zip',
    ];
}

$lateDatabase = new wpdb();
$lateDatabase->assignmentRow['due_at'] = '2000-01-01 00:00:00';
$lateDatabase->assignmentRow['policy_json'] = '{"allowLateSubmission":true}';
$lateRepository = new Repository($lateDatabase);
$attached = $lateRepository->attachArtifact((string) $lateDatabase->attemptRow['uuid'], 7, availabilityArtifact());
availabilityAssertSame(true, $attached['late'] ?? null, 'Repository must recompute and persist late status under the assignment lock.');
availabilityAssertSame(1, $lateDatabase->artifactInsertCount, 'An allowed late submission must create exactly one artifact row.');
$referenceLock = availabilityQueryIndex($lateDatabase->preparedQueries, 'SELECT assignment_id FROM oj_ml_attempts');
$assignmentLock = availabilityQueryIndex($lateDatabase->preparedQueries, 'FROM oj_ml_assignments WHERE id = %d FOR UPDATE');
$attemptLock = availabilityQueryIndex($lateDatabase->preparedQueries, 'FROM oj_ml_attempts WHERE uuid = %s AND user_id = %d FOR UPDATE');
availabilityAssert(
    $referenceLock >= 0 && $assignmentLock > $referenceLock && $attemptLock > $assignmentLock,
    'Artifact attachment must discover the assignment, then lock assignment before attempt.'
);

$closedDatabase = new wpdb();
$closedDatabase->assignmentRow['status'] = AssignmentStatus::CLOSED;
$closedRepository = new Repository($closedDatabase);
availabilityAssertProblem(
    static fn () => $closedRepository->attachArtifact((string) $closedDatabase->attemptRow['uuid'], 7, availabilityArtifact()),
    'OJ_ML_ASSIGNMENT_CLOSED',
    409,
    'A close/archive race must reject attachment before creating an artifact record.'
);
availabilityAssertSame(0, $closedDatabase->artifactInsertCount, 'Closed assignment rejection must happen before artifact insertion.');
availabilityAssertSame('ROLLBACK', end($closedDatabase->queries), 'Closed assignment attachment must roll back.');

$expiredDatabase = new wpdb();
$expiredDatabase->assignmentRow['due_at'] = '2000-01-01 00:00:00';
$expiredRepository = new Repository($expiredDatabase);
availabilityAssertProblem(
    static fn () => $expiredRepository->attachArtifact((string) $expiredDatabase->attemptRow['uuid'], 7, availabilityArtifact()),
    'OJ_ML_ASSIGNMENT_CLOSED',
    409,
    'A shortened due date with late submission disabled must reject attachment.'
);
availabilityAssertSame(0, $expiredDatabase->artifactInsertCount, 'Expired assignment rejection must happen before artifact insertion.');

$deleteDatabase = new wpdb();
$deleteDatabase->assignmentRow['status'] = AssignmentStatus::DRAFT;
$deleteRepository = new Repository($deleteDatabase);
$deleteRepository->deleteAssignment(3, 3);
$assignmentDeletes = array_values(array_filter(
    $deleteDatabase->deleteCalls,
    static fn (array $call): bool => $call['table'] === 'oj_ml_assignments'
));
availabilityAssertSame(1, count($assignmentDeletes), 'Draft deletion must delete exactly one assignment row.');
availabilityAssertSame([
    'id' => 3,
    'revision' => 3,
    'status' => AssignmentStatus::DRAFT,
], $assignmentDeletes[0]['where'], 'Final draft deletion must guard id, revision, and status.');

$staleDeleteDatabase = new wpdb();
$staleDeleteDatabase->assignmentRow['status'] = AssignmentStatus::DRAFT;
$staleDeleteDatabase->assignmentRow['revision'] = 4;
$staleDeleteRepository = new Repository($staleDeleteDatabase);
availabilityAssertProblem(
    static fn () => $staleDeleteRepository->deleteAssignment(3, 3),
    'OJ_ML_REVISION_CONFLICT',
    409,
    'A stale draft delete revision must fail before deleting child rows.'
);
availabilityAssertSame([], $staleDeleteDatabase->deleteCalls, 'A stale revision must not delete any rows.');

$childFailureDatabase = new wpdb();
$childFailureDatabase->assignmentRow['status'] = AssignmentStatus::DRAFT;
$childFailureDatabase->deleteResults['oj_ml_assignment_targets'] = false;
$childFailureRepository = new Repository($childFailureDatabase);
availabilityAssertProblem(
    static fn () => $childFailureRepository->deleteAssignment(3, 3),
    'OJ_ML_ASSIGNMENT_DELETE_FAILED',
    500,
    'A child-row delete failure must roll back the draft deletion.'
);
availabilityAssertSame('ROLLBACK', end($childFailureDatabase->queries), 'A child delete failure must roll back.');
availabilityAssertSame(1, count($childFailureDatabase->deleteCalls), 'A child delete failure must stop before parent deletion.');

$zeroDeleteDatabase = new wpdb();
$zeroDeleteDatabase->assignmentRow['status'] = AssignmentStatus::DRAFT;
$zeroDeleteDatabase->deleteResults['oj_ml_assignments'] = 0;
$zeroDeleteRepository = new Repository($zeroDeleteDatabase);
availabilityAssertProblem(
    static fn () => $zeroDeleteRepository->deleteAssignment(3, 3),
    'OJ_ML_REVISION_CONFLICT',
    409,
    'A zero-row final delete must not be reported as success.'
);
availabilityAssertSame('ROLLBACK', end($zeroDeleteDatabase->queries), 'A zero-row final delete must roll back child deletions.');

$draftTransitionDatabase = new wpdb();
$draftTransitionDatabase->assignmentRow['status'] = AssignmentStatus::DRAFT;
$draftTransitionRepository = new Repository($draftTransitionDatabase);
availabilityAssertProblem(
    static fn () => $draftTransitionRepository->transitionAssignment(3, 3, AssignmentStatus::ARCHIVED, 5),
    'OJ_ML_ASSIGNMENT_STATUS_TRANSITION_INVALID',
    409,
    'Draft assignments must be published or revision-guard deleted, never archived into an orphan state.'
);

$resultListDatabase = new wpdb();
$resultListRepository = new Repository($resultListDatabase);
$resultListRepository->listResults(3, 1, 20);
$submittedJoinQueries = array_values(array_filter(
    $resultListDatabase->preparedQueries,
    static fn (string $query): bool => str_contains($query, 'SELECT a2.id FROM oj_ml_attempts a2')
));
availabilityAssertSame(2, count($submittedJoinQueries), 'Count and page queries must share the latest-submitted join.');
foreach ($submittedJoinQueries as $query) {
    availabilityAssert(
        str_contains($query, 'a2.status <> %s')
            && str_contains($query, 'a2.artifact_id IS NOT NULL')
            && str_contains($query, 'a2.received_at IS NOT NULL')
            && str_contains($query, 'ORDER BY a2.id DESC'),
        'Teacher result lists must select the latest received artifact, not a newer unsubmitted/cancelled attempt.'
    );
}

$submissionSource = file_get_contents($root . '/src/Application/SubmissionService.php');
availabilityAssert(is_string($submissionSource), 'SubmissionService source must be readable.');
availabilityAssert(
    str_contains($submissionSource, '$late = (bool) ($attached[\'late\'] ?? false);')
        && str_contains($submissionSource, '$this->storage->deleteOrphan($stored->storageKey);'),
    'SubmissionService must use repository-computed late state and retain orphan cleanup on attachment rejection.'
);

fwrite(STDOUT, "OJ ML repository availability regression passed.\n");

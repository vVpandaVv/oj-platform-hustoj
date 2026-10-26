<?php

declare(strict_types=1);

use OJML\Playgrounds\Database\Repository;
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

if (!class_exists('wpdb')) {
    class wpdb
    {
        public int $insert_id = 0;

        /** @var array<string,mixed> */
        public array $row;

        /** @var array<string,mixed> */
        public array $lastWhere = [];

        public string $lastPreparedQuery = '';

        /** @var list<string> */
        public array $preparedQueries = [];

        /** @var list<mixed> */
        public array $lastPreparedArguments = [];

        /** @var list<array<string,mixed>> */
        public array $resultRows = [];

        public int $getResultsCalls = 0;

        public string $raceMode = 'immutable';

        public function __construct()
        {
            $this->row = [
                'id' => 1,
                'uuid' => '00000000-0000-4000-8000-000000000001',
                'assignment_id' => 3,
                'user_id' => 7,
                'attempt_no' => 1,
                'status' => AttemptStatus::IN_PROGRESS,
                'idempotency_key' => null,
                'artifact_id' => null,
                'policy_revision' => 1,
                'client_app_version' => null,
                'format_version' => null,
                'progress_json' => null,
                'last_progress_at' => null,
                'failure_code' => null,
                'failure_json' => null,
                'late' => 0,
                'received_at' => null,
                'created_at' => '2026-08-31 00:00:00',
                'updated_at' => '2026-08-31 00:00:00',
            ];
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            $this->lastPreparedQuery = $query;
            $this->preparedQueries[] = $query;
            $this->lastPreparedArguments = $arguments;
            return $query;
        }

        /** @return array<string,mixed> */
        public function get_row(string $query, mixed $output = null): ?array
        {
            if (str_contains($query, 'oj_ml_artifacts') || str_contains($query, 'oj_ml_evaluations') || str_contains($query, 'oj_ml_reviews')) {
                return null;
            }
            return $this->row;
        }

        /** @return list<array<string,mixed>> */
        public function get_results(string $query, mixed $output = null): array
        {
            $this->getResultsCalls++;
            return $this->resultRows;
        }

        /**
         * Simulate an update that waited behind another request. The other
         * request either attached an artifact or saved newer active progress.
         *
         * @param array<string,mixed> $data
         * @param array<string,mixed> $where
         * @param list<string> $format
         * @param list<string> $whereFormat
         */
        public function update(string $table, array $data, array $where, array $format, array $whereFormat): int|false
        {
            $this->lastWhere = $where;
            if ($this->raceMode === 'immutable') {
                $this->row['status'] = AttemptStatus::QUARANTINED;
                $this->row['artifact_id'] = 77;
            } else {
                $this->row['status'] = AttemptStatus::UPLOADING;
                $this->row['artifact_id'] = null;
            }
            return 0;
        }
    }
}

require_once dirname(__DIR__) . '/src/Domain/Problem.php';
require_once dirname(__DIR__) . '/src/Domain/AssignmentStatus.php';
require_once dirname(__DIR__) . '/src/Domain/AttemptStatus.php';
require_once dirname(__DIR__) . '/src/Database/Tables.php';
require_once dirname(__DIR__) . '/src/Database/Repository.php';

/** @param mixed $actual */
function ojMlRepositoryAssert(bool $condition, string $message, mixed $actual = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($actual === null ? '' : ' actual=' . var_export($actual, true)));
    }
}

$uuid = '00000000-0000-4000-8000-000000000001';
$database = new wpdb();
$repository = new Repository($database);
$immutableCode = null;
try {
    $repository->updateProgress($uuid, 7, ['phase' => 'uploading', 'reportedAt' => '2026-08-31T00:00:01Z']);
} catch (Problem $problem) {
    $immutableCode = $problem->problemCode();
}
ojMlRepositoryAssert(
    ($database->lastWhere['status'] ?? null) === AttemptStatus::IN_PROGRESS,
    'Progress update must compare the previously read active status.',
    $database->lastWhere
);
ojMlRepositoryAssert(
    $immutableCode === 'OJ_ML_ATTEMPT_IMMUTABLE',
    'An artifact attachment race must fail immutable instead of restoring an active status.',
    $immutableCode
);

$conflictDatabase = new wpdb();
$conflictDatabase->raceMode = 'active_conflict';
$conflictRepository = new Repository($conflictDatabase);
$conflictCode = null;
try {
    $conflictRepository->updateProgress($uuid, 7, ['phase' => 'working', 'reportedAt' => '2026-08-31T00:00:02Z']);
} catch (Problem $problem) {
    $conflictCode = $problem->problemCode();
}
ojMlRepositoryAssert(
    $conflictCode === 'OJ_ML_PROGRESS_CONFLICT',
    'A newer active progress write must return a retryable conflict.',
    $conflictCode
);

$listingDatabase = new wpdb();
$listingRepository = new Repository($listingDatabase);
$listingRepository->listPublishedAssignmentsForLearner(42, 999);
ojMlRepositoryAssert(
    str_contains($listingDatabase->lastPreparedQuery, 'INNER JOIN oj_ml_assignment_learners l'),
    'Learner assignment lookup must join the active roster before limiting rows.',
    $listingDatabase->lastPreparedQuery
);
ojMlRepositoryAssert(
    str_contains($listingDatabase->lastPreparedQuery, 'l.removed_at IS NULL'),
    'Learner assignment lookup must exclude removed roster members.'
);
ojMlRepositoryAssert(
    str_contains($listingDatabase->lastPreparedQuery, 'a.status IN (%s,%s)')
        && str_contains($listingDatabase->lastPreparedQuery, 'EXISTS (')
        && str_contains($listingDatabase->lastPreparedQuery, 'ah.status <> %s')
        && str_contains($listingDatabase->lastPreparedQuery, 'ah.artifact_id IS NOT NULL')
        && str_contains($listingDatabase->lastPreparedQuery, 'ah.received_at IS NOT NULL')
        && str_contains($listingDatabase->lastPreparedQuery, 'AS has_submission_history')
        && str_contains($listingDatabase->lastPreparedQuery, 'HAVING ((a.status = %s'),
    'Learner assignment lookup must project only published/closed history from the same received-artifact predicate.',
    $listingDatabase->lastPreparedQuery
);
$currentPriority = strpos($listingDatabase->lastPreparedQuery, 'ORDER BY CASE WHEN (a.status = %s');
$limitPosition = strpos($listingDatabase->lastPreparedQuery, 'LIMIT %d');
ojMlRepositoryAssert(
    is_int($currentPriority) && is_int($limitPosition) && $currentPriority < $limitPosition,
    'Current assignments must be prioritized before the bounded learner query limit.',
    $listingDatabase->lastPreparedQuery
);
ojMlRepositoryAssert(
    $listingDatabase->lastPreparedArguments === [
        'cancelled', 42, 'published', 'closed',
        'published', '%"allowLateSubmission":true%',
        'published', '%"allowLateSubmission":true%',
        'published', 'closed', 500, 0,
    ],
    'Learner assignment lookup must bind current/history status, late policy, cancellation, ordering, and limit.',
    $listingDatabase->lastPreparedArguments
);

$summaryDatabase = new wpdb();
$summaryRepository = new Repository($summaryDatabase);
ojMlRepositoryAssert(
    $summaryRepository->latestSubmittedAttemptSummariesForLearner(42, []) === []
        && $summaryDatabase->preparedQueries === []
        && $summaryDatabase->getResultsCalls === 0,
    'An empty learner summary batch must not execute SQL.'
);
$summaryDatabase->resultRows = [
    [
        'assignment_id' => '3',
        'uuid' => '00000000-0000-4000-8000-000000000003',
        'received_at' => '2026-08-31 03:00:00',
    ],
    [
        'assignment_id' => '4',
        'uuid' => '00000000-0000-4000-8000-000000000004',
        'received_at' => '2026-08-31 04:00:00',
    ],
];
$summaries = $summaryRepository->latestSubmittedAttemptSummariesForLearner(42, [3, 4, 3, 0]);
ojMlRepositoryAssert(
    array_keys($summaries) === [3, 4]
        && ($summaries[4]['uuid'] ?? null) === '00000000-0000-4000-8000-000000000004'
        && ($summaries[4]['received_at'] ?? null) === '2026-08-31 04:00:00',
    'Learner result summaries must be keyed by assignment ID.',
    $summaries
);
ojMlRepositoryAssert(
    $summaryDatabase->getResultsCalls === 1
        && count($summaryDatabase->preparedQueries) === 1
        && str_contains($summaryDatabase->lastPreparedQuery, 'SELECT a.assignment_id,a.uuid,a.received_at')
        && str_contains($summaryDatabase->lastPreparedQuery, 'a.assignment_id IN (%d,%d)')
        && str_contains($summaryDatabase->lastPreparedQuery, 'a2.status <> %s')
        && str_contains($summaryDatabase->lastPreparedQuery, 'a2.artifact_id IS NOT NULL')
        && str_contains($summaryDatabase->lastPreparedQuery, 'a2.received_at IS NOT NULL')
        && str_contains($summaryDatabase->lastPreparedQuery, 'ORDER BY a2.attempt_no DESC,a2.id DESC LIMIT 1')
        && !str_contains($summaryDatabase->lastPreparedQuery, 'SELECT *')
        && !str_contains($summaryDatabase->lastPreparedQuery, 'progress_json')
        && !str_contains($summaryDatabase->lastPreparedQuery, 'failure_json')
        && !str_contains($summaryDatabase->lastPreparedQuery, 'oj_ml_artifacts')
        && !str_contains($summaryDatabase->lastPreparedQuery, 'oj_ml_evaluations')
        && !str_contains($summaryDatabase->lastPreparedQuery, 'oj_ml_reviews'),
    'Learner result summaries must use one narrow latest-submitted projection query.',
    $summaryDatabase->preparedQueries
);
ojMlRepositoryAssert(
    $summaryDatabase->lastPreparedArguments === [42, 3, 4, AttemptStatus::CANCELLED],
    'Learner result summary batches must bind one user, unique positive assignments, and cancellation status.',
    $summaryDatabase->lastPreparedArguments
);

$submittedDatabase = new wpdb();
$submittedDatabase->row['artifact_id'] = 71;
$submittedDatabase->row['received_at'] = '2026-08-31 01:00:00';
$submittedRepository = new Repository($submittedDatabase);
$submitted = $submittedRepository->latestSubmittedAttempt(3, 7);
ojMlRepositoryAssert(
    is_array($submitted) && ($submitted['uuid'] ?? null) === $uuid,
    'Latest submitted lookup must return the submitted attempt instead of a newer active attempt.'
);
ojMlRepositoryAssert(
    count(array_filter(
        $submittedDatabase->preparedQueries,
        static fn (string $query): bool => str_contains($query, 'artifact_id IS NOT NULL')
            && str_contains($query, 'received_at IS NOT NULL')
            && str_contains($query, 'ORDER BY attempt_no DESC,id DESC')
    )) === 1,
    'Latest submitted lookup must use the shared received-artifact definition.',
    $submittedDatabase->preparedQueries
);

$assignmentSource = file_get_contents(dirname(__DIR__) . '/src/Application/AssignmentService.php');
ojMlRepositoryAssert(is_string($assignmentSource), 'AssignmentService release source must be readable.');
$learnerListStart = strpos($assignmentSource, 'public function listForLearner(');
$learnerDetailStart = strpos($assignmentSource, 'public function getForLearner(');
$learnerListBlock = $learnerListStart !== false && $learnerDetailStart !== false
    ? substr($assignmentSource, $learnerListStart, $learnerDetailStart - $learnerListStart)
    : '';
ojMlRepositoryAssert(
    str_contains($assignmentSource, 'listPublishedAssignmentsForLearner($userId, 201,')
        && str_contains($learnerListBlock, 'latestSubmittedAttemptSummariesForLearner(')
        && str_contains($learnerListBlock, 'decorateForLearnerList(')
        && !str_contains($learnerListBlock, 'attemptsUsed(')
        && !str_contains($learnerListBlock, 'activeAttempt(')
        && !str_contains($learnerListBlock, 'latestSubmittedAttempt($assignmentId')
        && str_contains($assignmentSource, 'latestSubmittedAttempt($assignmentId, $userId)'),
    'Learner lists must use one lean summary batch while detail decoration retains full attempts.'
);
$routesSource = file_get_contents(dirname(__DIR__) . '/src/Rest/Routes.php');
ojMlRepositoryAssert(
    is_string($routesSource)
        && str_contains($routesSource, 'learnerAssignmentListItem($item)')
        && str_contains($routesSource, 'getForLearner((int) $request['),
    'Only learner collection routes may use the lean list presenter.'
);
ojMlRepositoryAssert(
    str_contains($assignmentSource, 'OJ_ML_ASSIGNMENT_POLICY_TASK_TYPE_MISMATCH'),
    'The existing task-type policy invariant must be preserved in the release.'
);

fwrite(STDOUT, "OJ ML repository state regression passed.\n");



<?php

declare(strict_types=1);

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\AssignmentStatus;
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
        return '10000000-0000-4000-8000-000000000001';
    }
}

if (!class_exists('wpdb')) {
    class wpdb
    {
        public int $insert_id = 0;

        /** @var array<string,mixed> */
        public array $row;

        /** @var array<string,mixed> */
        public array $lastUpdate = [];

        /** @var array<string,mixed> */
        public array $lastWhere = [];

        /** @var array<string,mixed> */
        public array $lastInsert = [];

        public string $lastInsertTable = '';
        public string $lastPreparedQuery = '';
        public bool $hasSubmission = false;

        public function __construct()
        {
            $this->row = [
                'id' => 9,
                'title' => '기존 과제',
                'description' => '',
                'task_type' => 'classification',
                'context_type' => 'class',
                'context_id' => 'class-1',
                'context_revision' => 'ctx-1',
                'roster_revision' => 'roster-1',
                'status' => AssignmentStatus::PUBLISHED,
                'policy_json' => '{"allowLateSubmission":false}',
                'rubric_json' => '[]',
                'opens_at' => '2026-09-01 09:00:00',
                'due_at' => '2026-09-02 09:00:00',
                'max_attempts' => 2,
                'revision' => 3,
                'created_by' => 5,
                'created_at' => '2026-08-31 00:00:00',
                'updated_at' => '2026-09-01 00:00:00',
                'published_at' => '2026-09-01 00:00:00',
            ];
        }

        public function query(string $query): int|false
        {
            return 1;
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            $this->lastPreparedQuery = $query;
            return $query;
        }

        /** @return array<string,mixed> */
        public function get_row(string $query, mixed $output = null): array
        {
            return $this->row;
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
            return $this->hasSubmission ? 1 : 0;
        }

        /**
         * @param array<string,mixed> $data
         * @param array<string,mixed> $where
         * @param list<string> $formats
         * @param list<string> $whereFormats
         */
        public function update(string $table, array $data, array $where, array $formats, array $whereFormats): int|false
        {
            $this->lastUpdate = $data;
            $this->lastWhere = $where;
            foreach ($data as $key => $value) {
                $this->row[$key] = $value;
            }
            return 1;
        }

        /** @param array<string,mixed> $data @param list<string> $formats */
        public function insert(string $table, array $data, array $formats): int|false
        {
            $this->lastInsertTable = $table;
            $this->lastInsert = $data;
            return 1;
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
function operationsAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true)
        );
    }
}

function operationsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable():mixed $callback */
function operationsAssertProblem(callable $callback, string $code, int $status, string $message): void
{
    try {
        $callback();
    } catch (Problem $problem) {
        operationsAssertSame($code, $problem->problemCode(), $message . ' (problem code)');
        operationsAssertSame($status, $problem->httpStatus(), $message . ' (HTTP status)');
        return;
    }
    throw new RuntimeException($message . ' (exception was not raised)');
}

$database = new wpdb();
$repository = new Repository($database);
$updated = $repository->updatePublishedAssignmentOperations(9, 3, [
    'title' => '운영 수정',
    'due_at' => '2026-09-03 09:00:00',
    'max_attempts' => 3,
], 77);
operationsAssertSame('운영 수정', $updated['title'], 'Published operational title must update.');
operationsAssertSame(4, $updated['revision'], 'Published operational update must increment the revision.');
operationsAssertSame([
    'id' => 9,
    'revision' => 3,
    'status' => AssignmentStatus::PUBLISHED,
], $database->lastWhere, 'Published operational update must use optimistic status and revision guards.');
operationsAssertSame('oj_ml_events', $database->lastInsertTable, 'Published operational update must append an audit event.');
operationsAssertSame('assignment.operations_updated', $database->lastInsert['event_type'] ?? null, 'Audit event type must be stable.');
operationsAssertSame(77, $database->lastInsert['actor_user_id'] ?? null, 'Audit event must retain the teacher actor.');

operationsAssertProblem(
    static fn () => $repository->updatePublishedAssignmentOperations(9, 4, [], 77),
    'OJ_ML_ASSIGNMENT_UPDATE_EMPTY',
    400,
    'Repository must reject empty published operational updates.'
);
operationsAssertProblem(
    static fn () => $repository->updatePublishedAssignmentOperations(9, 4, ['max_attempts' => 2], 77),
    'OJ_ML_ASSIGNMENT_MAX_ATTEMPTS_DECREASE_FORBIDDEN',
    409,
    'Repository must reject a published max-attempt decrease.'
);
operationsAssertProblem(
    static fn () => $repository->updatePublishedAssignmentOperations(9, 4, ['policy' => []], 77),
    'OJ_ML_ASSIGNMENT_IMMUTABLE',
    409,
    'Repository must reject published core-policy changes.'
);
operationsAssertProblem(
    static fn () => $repository->updateAssignment(9, 4, ['title' => '우회']),
    'OJ_ML_ASSIGNMENT_IMMUTABLE',
    409,
    'The draft update path must not mutate a published assignment.'
);

$database->hasSubmission = false;
operationsAssertSame(false, $repository->hasSubmissionHistory(9, 22), 'An unsubmitted attempt must not count as result history.');
$database->hasSubmission = true;
operationsAssertSame(true, $repository->hasSubmissionHistory(9, 22), 'A received artifact must count as result history.');
operationsAssert(
    str_contains($database->lastPreparedQuery, 'artifact_id IS NOT NULL')
        && str_contains($database->lastPreparedQuery, 'received_at IS NOT NULL'),
    'Submission history must require both an attached artifact and a received timestamp.'
);

fwrite(STDOUT, "OJ ML published assignment operations regression passed.\n");


<?php

declare(strict_types=1);

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\AttemptStatus;

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
        /** @var list<array{query:string,args:list<mixed>}> */
        public array $preparedCalls = [];

        /** @var list<string> */
        public array $executedQueries = [];

        public int $getRowCalls = 0;

        public function prepare(string $query, mixed ...$arguments): string
        {
            $this->preparedCalls[] = ['query' => $query, 'args' => $arguments];
            return $query;
        }

        public function get_var(string $query): int
        {
            $this->executedQueries[] = $query;
            return 100;
        }

        /** @return list<array<string,mixed>> */
        public function get_results(string $query, mixed $output = null): array
        {
            $this->executedQueries[] = $query;
            if (str_contains($query, 'SELECT l.user_id AS roster_user_id')) {
                $rows = [];
                for ($id = 1; $id <= 100; $id++) {
                    $rows[] = [
                        'roster_user_id' => $id,
                        'attempts_used' => 1,
                        'id' => $id,
                        'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
                        'assignment_id' => 77,
                        'user_id' => $id,
                        'attempt_no' => 1,
                        'status' => AttemptStatus::GRADED,
                        'idempotency_key' => null,
                        'artifact_id' => $id,
                        'policy_revision' => 1,
                        'client_app_version' => '0.12.1',
                        'format_version' => 2,
                        'progress_json' => '{"phase":"done"}',
                        'last_progress_at' => '2026-09-01 00:00:00',
                        'failure_code' => null,
                        'failure_json' => null,
                        'late' => 0,
                        'received_at' => '2026-09-01 00:00:00',
                        'created_at' => '2026-09-01 00:00:00',
                        'updated_at' => '2026-09-01 00:00:00',
                    ];
                }
                return $rows;
            }
            if (str_contains($query, 'FROM oj_ml_artifacts')) {
                $rows = [];
                for ($id = 1; $id <= 100; $id++) {
                    $rows[] = [
                        'id' => $id,
                        'uuid' => sprintf('10000000-0000-4000-8000-%012d', $id),
                        'attempt_id' => $id,
                        'original_name' => 'submission.mlpx',
                        'sha256' => str_repeat('a', 64),
                        'bytes' => '1024',
                        'mime_type' => 'application/vnd.ojml.mlpx+zip',
                        'created_at' => '2026-09-01 00:00:00',
                    ];
                }
                return $rows;
            }
            if (str_contains($query, 'FROM oj_ml_evaluations')) {
                $rows = [];
                for ($id = 1; $id <= 100; $id++) {
                    $rows[] = [
                        'id' => $id,
                        'attempt_id' => $id,
                        'status' => 'passed',
                        'evaluator' => 'server',
                        'evaluator_version' => '1',
                        'evaluator_digest' => null,
                        'findings_json' => '[]',
                        'facts_json' => '{"rowCount":10}',
                        'created_at' => '2026-09-01 00:00:00',
                        'completed_at' => '2026-09-01 00:00:01',
                    ];
                }
                return $rows;
            }
            if (str_contains($query, 'FROM oj_ml_reviews')) {
                $rows = [];
                for ($id = 1; $id <= 100; $id++) {
                    $rows[] = [
                        'id' => 1000 + $id,
                        'attempt_id' => $id,
                        'evaluation_id' => $id,
                        'reviewer_id' => 8,
                        'version' => 2,
                        'status' => 'draft',
                        'score' => '9.00',
                        'max_score' => '10.00',
                        'feedback' => 'DRAFT-REVIEW-SENTINEL',
                        'rubric_revision' => 1,
                        'created_at' => '2026-09-01 00:00:04',
                        'released_at' => null,
                    ];
                    $rows[] = [
                        'id' => $id,
                        'attempt_id' => $id,
                        'evaluation_id' => $id,
                        'reviewer_id' => 7,
                        'version' => 1,
                        'status' => 'released',
                        'score' => '8.00',
                        'max_score' => '10.00',
                        'feedback' => '좋습니다.',
                        'rubric_revision' => 1,
                        'created_at' => '2026-09-01 00:00:02',
                        'released_at' => '2026-09-01 00:00:03',
                    ];
                }
                return $rows;
            }
            return [];
        }

        public function get_row(string $query, mixed $output = null): ?array
        {
            $this->getRowCalls++;
            throw new RuntimeException('Teacher result pages must not execute per-attempt get_row queries.');
        }
    }
}

require_once dirname(__DIR__) . '/src/Domain/Problem.php';
require_once dirname(__DIR__) . '/src/Domain/AssignmentStatus.php';
require_once dirname(__DIR__) . '/src/Domain/AttemptStatus.php';
require_once dirname(__DIR__) . '/src/Database/Tables.php';
require_once dirname(__DIR__) . '/src/Database/Repository.php';

/** @param mixed $actual */
function listPerformanceAssert(bool $condition, string $message, mixed $actual = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($actual === null ? '' : ' actual=' . var_export($actual, true)));
    }
}

$database = new wpdb();
$result = (new Repository($database))->listResults(77, 1, 100, AttemptStatus::GRADED, null);

listPerformanceAssert(
    $result['total'] === 100 && count($result['items']) === 100,
    'Teacher result pagination must retain its response total and 100-row page.',
    $result
);
listPerformanceAssert(
    $database->getRowCalls === 0 && count($database->executedQueries) === 5,
    'A full 100-row result page must use five constant queries: count, rows, artifacts, evaluations, reviews.',
    $database->executedQueries
);
foreach (['oj_ml_artifacts', 'oj_ml_evaluations', 'oj_ml_reviews'] as $table) {
    listPerformanceAssert(
        count(array_filter(
            $database->executedQueries,
            static fn (string $query): bool => str_contains($query, $table)
        )) === 1,
        'Each result decoration table must be loaded exactly once per page: ' . $table,
        $database->executedQueries
    );
}
$firstAttempt = $result['items'][0]['attempt'] ?? null;
listPerformanceAssert(
    is_array($firstAttempt)
        && ($firstAttempt['artifact']['bytes'] ?? null) === 1024
        && ($firstAttempt['evaluation']['facts']['rowCount'] ?? null) === 10
        && ($firstAttempt['review']['status'] ?? null) === 'draft'
        && ($firstAttempt['review']['feedback'] ?? null) === 'DRAFT-REVIEW-SENTINEL'
        && ($firstAttempt['released_review']['status'] ?? null) === 'released'
        && ($firstAttempt['released_review']['feedback'] ?? null) === '좋습니다.',
    'One bulk review query must preserve both the latest review and the latest released review.',
    $firstAttempt
);
$reviewQueries = array_values(array_filter(
    $database->executedQueries,
    static fn (string $query): bool => str_contains($query, 'oj_ml_reviews')
));
listPerformanceAssert(
    count($reviewQueries) === 1
        && str_contains($reviewQueries[0], 'SELECT r2.id')
        && str_contains($reviewQueries[0], 'SELECT r3.id')
        && str_contains($reviewQueries[0], "r3.status = 'released'"),
    'Latest-any and latest-released reviews must share one bulk query.',
    $reviewQueries
);
$listQueries = array_values(array_filter(
    $database->preparedCalls,
    static fn (array $call): bool => str_contains($call['query'], 'oj_ml_assignment_learners')
));
listPerformanceAssert(
    count($listQueries) === 2
        && str_contains($listQueries[0]['query'], 'a2.status <> %s')
        && str_contains($listQueries[0]['query'], 'a2.artifact_id IS NOT NULL')
        && str_contains($listQueries[0]['query'], 'a2.received_at IS NOT NULL')
        && str_contains($listQueries[0]['query'], 'ORDER BY a2.id DESC LIMIT 1')
        && str_contains($listQueries[0]['query'], 'a.status = %s')
        && in_array(AttemptStatus::GRADED, $listQueries[0]['args'], true),
    'Bulk decoration must not alter latest-submitted selection or the requested status filter.',
    $listQueries
);

fwrite(STDOUT, "OJ ML repository list performance regression passed.\n");

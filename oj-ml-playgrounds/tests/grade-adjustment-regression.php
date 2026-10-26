<?php

declare(strict_types=1);

// Application, repository, rubric, AI readiness and REST projection are real.
// Only the SQL transport and external Context authorization are test doubles.
namespace OJML\Playgrounds\Application {
    final class ContextService
    {
        public bool $allowed = true;
        public function assertAssignmentAccess(int $actor, array $assignment, string $operation, ?int $learner = null): void
        {
            if (!$this->allowed || $actor !== 7 || $learner !== 22 || !in_array($operation, ['view', 'evaluate'], true)) {
                throw new \OJML\Playgrounds\Domain\Problem('OJ_ML_CONTEXT_ACCESS_DENIED', '권한 없음', 403);
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/bootstrap.php';

    use OJML\Playgrounds\Application\AiReviewDraft;
    use OJML\Playgrounds\Application\AssignmentService;
    use OJML\Playgrounds\Application\ContextService;
    use OJML\Playgrounds\Application\EvidenceReadiness;
    use OJML\Playgrounds\Database\Repository;
    use OJML\Playgrounds\Domain\Problem;
    use OJML\Playgrounds\Domain\Rubric;
    use OJML\Playgrounds\Rest\Presenter;

    define('ARRAY_A', 'ARRAY_A');
    function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
    function wp_kses_post(string $value): string { return strip_tags($value); }

    final class wpdb
    {
        public string $last_error = '';
        public int $insert_id = 0;
        public array $rows = [];
        public array $queries = [];
        public bool $failEvent = false;
        private ?array $transactionRows = null;

        public function prepare(string $query, mixed ...$values): string
        {
            if (count($values) === 1 && is_array($values[0])) { $values = $values[0]; }
            $index = 0;
            return preg_replace_callback('/%[dsf]/', static function (array $match) use ($values, &$index): string {
                $value = $values[$index++];
                return $match[0] === '%s' ? "'" . str_replace("'", "''", (string) $value) . "'" : (string) $value;
            }, $query);
        }

        public function get_row(string $query, mixed $output = null): ?array
        {
            $this->queries[] = $query;
            if (str_contains($query, 'FROM oj_ml_reviews')) {
                $rows = $this->rows['oj_ml_reviews'];
                if (str_contains($query, "status = 'released'")) {
                    $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'released'));
                }
                usort($rows, static fn (array $a, array $b): int => $b['version'] <=> $a['version']);
                return $rows[0] ?? null;
            }
            if (str_contains($query, 'FROM oj_ml_attempts')) {
                if (preg_match('/user_id = ([0-9]+)/', $query, $match) === 1 && (int) $match[1] !== 22) { return null; }
                return $this->rows['oj_ml_attempts'][0];
            }
            if (str_contains($query, 'FROM oj_ml_assignments')) { return $this->rows['oj_ml_assignments'][0]; }
            if (str_contains($query, 'FROM oj_ml_artifacts') || str_contains($query, 'FROM oj_ml_evaluations')) { return null; }
            throw new \RuntimeException('Unexpected row query: ' . $query);
        }

        public function get_results(string $query, mixed $output = null): array
        {
            if (str_contains($query, 'FROM oj_ml_assignment_targets')) { return []; }
            throw new \RuntimeException('Unexpected list query: ' . $query);
        }

        public function get_col(string $query): array
        {
            if (str_contains($query, 'FROM oj_ml_assignment_learners')) { return [22]; }
            throw new \RuntimeException('Unexpected column query: ' . $query);
        }

        public function query(string $query): int
        {
            $this->queries[] = $query;
            if ($query === 'START TRANSACTION') { $this->transactionRows = $this->rows; }
            elseif ($query === 'COMMIT') { $this->transactionRows = null; }
            elseif ($query === 'ROLLBACK') { $this->rows = $this->transactionRows ?? $this->rows; $this->transactionRows = null; }
            else { throw new \RuntimeException('Unexpected mutation query: ' . $query); }
            return 1;
        }

        public function insert(string $table, array $data, array $formats): int|false
        {
            if (!in_array($table, ['oj_ml_reviews', 'oj_ml_events'], true)) { throw new \RuntimeException('Unexpected insert: ' . $table); }
            if ($table === 'oj_ml_events' && $this->failEvent) { return false; }
            $data['id'] = ++$this->insert_id;
            $this->rows[$table][] = $data;
            return 1;
        }

        public function update(string $table, array $data, array $where, array $formats, array $whereFormats): int
        {
            if ($table !== 'oj_ml_attempts' || $where !== ['id' => 11]) { throw new \RuntimeException('Unexpected update.'); }
            $this->rows[$table][0] = array_replace($this->rows[$table][0], $data);
            return 1;
        }
    }

    function gradeAssert(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    function gradeReject(callable $callback, string $code): void
    {
        try { $callback(); } catch (Problem $problem) {
            gradeAssert($problem->problemCode() === $code, 'Expected ' . $code . ', got ' . $problem->problemCode());
            return;
        }
        throw new \RuntimeException('Expected rejection: ' . $code);
    }

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $rubric = ['version' => 2, 'criteria' => [[
        'id' => 'analysis', 'title' => '실험 분석', 'description' => '선택 특성과 전처리의 근거를 평가합니다.', 'maxScore' => 100,
        'evidenceRequirements' => ['datasetDescription', 'datasetStatistics'],
        'levels' => [
            ['id' => 'a', 'label' => '충분', 'description' => '설정과 실험을 연결한 근거가 충분합니다.', 'score' => 100],
            ['id' => 'b', 'label' => '보완', 'description' => '설명 일부는 있지만 조건 비교를 보완해야 합니다.', 'score' => 70.5],
            ['id' => 'c', 'label' => '미제시', 'description' => '선택 이유를 제시하지 않았습니다.', 'score' => 0],
        ],
    ]]];
    $uuid = '11111111-1111-4111-8111-111111111111';
    $database = new wpdb();
    $database->rows = [
        'oj_ml_assignments' => [[
            'id' => 3, 'title' => '실습 평가', 'description' => '', 'task_type' => 'classification',
            'context_type' => 'group', 'context_id' => 'g-1', 'status' => 'published', 'created_by' => 7,
            'max_attempts' => 3, 'revision' => 1, 'policy_json' => '{}', 'rubric_json' => json_encode($rubric),
        ]],
        'oj_ml_attempts' => [[
            'id' => 11, 'uuid' => $uuid, 'assignment_id' => 3, 'user_id' => 22, 'attempt_no' => 1,
            'policy_revision' => 1, 'artifact_id' => null, 'format_version' => 2, 'status' => 'accepted',
            'late' => 0, 'progress_json' => null, 'failure_code' => null, 'failure_json' => null,
            'idempotency_key' => null, 'received_at' => '2026-10-23 00:00:00',
        ]], 'oj_ml_reviews' => [], 'oj_ml_events' => [],
    ];
    $contexts = new ContextService();
    $service = new AssignmentService(new Repository($database), $contexts);
    $presenter = new Presenter();
    $scoreA = [['id' => 'analysis', 'levelId' => 'a', 'score' => 100, 'feedback' => '교사 확인으로 충족 판단']];
    $scoreB = [['id' => 'analysis', 'levelId' => 'b', 'score' => 70.5, 'feedback' => '실험 조건 비교 보완']];

    $draftA = $service->review($uuid, 7, ['expectedVersion' => 0, 'criteriaScores' => $scoreA,
        'score' => 999, 'status' => 'released', 'feedback' => '첫 평가'], 'grade-a');
    gradeAssert($draftA['status'] === 'draft' && $draftA['score'] === 100.0 && $draftA['version'] === 1,
        'Teacher-selected grade determines the stored score; browser total/status cannot publish it.');
    gradeAssert($service->ownResult($uuid, 22)['review'] === null, 'Initial grade remains private until release.');
    $releasedA = $service->releaseReview($uuid, 7, 1, 'release-a');
    gradeAssert($releasedA['version'] === 2 && $releasedA['details'] === $draftA['details'], 'Explicit release copies the exact selected grade and rubric snapshot.');
    gradeAssert($service->ownResult($uuid, 22)['review']['score'] === 100.0, 'Student can see released grade A.');
    $savedRowsA = $database->rows['oj_ml_reviews'];

    // The external AI cannot grade this input, but the authorized teacher may
    // inspect the original and choose a grade independently of AI availability.
    $readiness = EvidenceReadiness::assess($rubric, []);
    gradeAssert($readiness['status'] === 'needs_review', 'The fixture must exercise a missing-evidence AI review.');
    gradeReject(static fn () => AiReviewDraft::build(
        ['rubric' => $rubric, 'artifact' => ['sha256' => str_repeat('a', 64)]],
        ['state' => 'ready', 'run_id' => 'untrusted-run', 'input_hash' => str_repeat('b', 64), 'source_sha256' => str_repeat('a', 64),
            'criteria' => [['id' => 'analysis', 'score' => 100, 'feedback' => 'provider claim']], 'total_score' => 100, 'max_score' => 100],
        'untrusted-run', str_repeat('b', 64), $readiness
    ), 'OJ_ML_AI_REVIEW_REQUIRED');
    $draftB = $service->review($uuid, 7, ['expectedVersion' => 2, 'criteriaScores' => $scoreB, 'feedback' => '교사 재검토'], 'grade-b');
    gradeAssert($draftB['version'] === 3 && $draftB['score'] === 70.5 && $draftB['status'] === 'draft',
        'An authorized manual adjustment must remain possible while AI requires teacher review.');
    gradeAssert(array_slice($database->rows['oj_ml_reviews'], 0, 2) === $savedRowsA, 'Grade adjustments append history and never overwrite prior review rows.');

    // New objects force the real repository to reconstruct the grade selection
    // from persisted details_json, rather than reusing a browser/request object.
    $fresh = new AssignmentService(new Repository($database), $contexts);
    $teacher = $fresh->teacherResult($uuid, 7);
    $student = $fresh->ownResult($uuid, 22);
    gradeAssert($teacher['review']['version'] === 3 && $teacher['review']['details']['criteriaScores'][0]['levelId'] === 'b'
        && $teacher['review']['details']['rubricSnapshot'] === Rubric::normalize($rubric), 'Fresh teacher reads restore selected B and its exact rubric descriptions.');
    gradeAssert($teacher['released_review']['version'] === 2 && $student['review']['version'] === 2
        && $student['review']['details']['criteriaScores'][0]['levelId'] === 'a', 'Saving B must leave the prior released A visible to the student.');
    gradeAssert($presenter->review($teacher['review'], true)['details']['criteriaScores'][0]['levelId'] === 'b'
        && $presenter->review($teacher['review'])['details'] === null,
        'REST projection exposes draft B only to the teacher, not a learner.');

    $beforeConflict = $database->rows;
    gradeReject(fn () => $fresh->review($uuid, 7, ['expectedVersion' => 2, 'criteriaScores' => $scoreA], 'stale-edit'), 'OJ_ML_REVIEW_VERSION_CONFLICT');
    gradeReject(fn () => $fresh->releaseReview($uuid, 7, 2, 'stale-release'), 'OJ_ML_REVIEW_VERSION_CONFLICT');
    gradeAssert($database->rows === $beforeConflict, 'Stale save/release must not replace B or change student-visible A.');
    $wrongScore = $scoreB; $wrongScore[0]['score'] = 70;
    gradeReject(fn () => $fresh->review($uuid, 7, ['expectedVersion' => 3, 'criteriaScores' => $wrongScore], 'off-grade'), 'OJ_ML_RUBRIC_LEVEL_SCORE_MISMATCH');
    gradeAssert($database->rows === $beforeConflict, 'Grade points cannot be adjusted independently of the selected rubric level.');
    $database->failEvent = true;
    gradeReject(fn () => $fresh->review($uuid, 7, ['expectedVersion' => 3, 'criteriaScores' => $scoreA], 'failed-audit'), 'OJ_ML_EVENT_SAVE_FAILED');
    gradeAssert($database->rows === $beforeConflict, 'Audit failure must roll back the review row and attempt status together.');
    $database->failEvent = false;
    $contexts->allowed = false;
    gradeReject(fn () => $fresh->review($uuid, 7, ['expectedVersion' => 3, 'criteriaScores' => $scoreA], 'revoked'), 'OJ_ML_CONTEXT_ACCESS_DENIED');
    gradeAssert($database->rows === $beforeConflict, 'Revoked teacher access must not adjust a grade.');
    $contexts->allowed = true;

    $releasedB = $fresh->releaseReview($uuid, 7, 3, 'release-b');
    $studentB = (new AssignmentService(new Repository($database), $contexts))->ownResult($uuid, 22);
    gradeAssert($releasedB['version'] === 4 && $releasedB['details'] === $draftB['details']
        && $studentB['review']['score'] === 70.5 && $studentB['review']['details']['criteriaScores'][0]['levelId'] === 'b',
        'Only explicit publication replaces the student-visible grade with the persisted B selection.');
    gradeAssert(array_column($database->rows['oj_ml_events'], 'event_type') === ['review.saved', 'review.released', 'review.saved', 'review.released'],
        'Successful save/publication events must correspond exactly to the four durable versions.');
    gradeAssert(count(array_filter($database->queries, static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE'))) >= 4,
        'Real repository writes must retain row-lock queries around version checks.');
    gradeReject(fn () => $fresh->ownResult($uuid, 23), 'OJ_ML_ATTEMPT_NOT_FOUND');
    restore_error_handler();
    fwrite(STDOUT, "OJ ML persisted grade adjustment / release isolation / optimistic conflict / manual AI independence regression passed.\n");
}

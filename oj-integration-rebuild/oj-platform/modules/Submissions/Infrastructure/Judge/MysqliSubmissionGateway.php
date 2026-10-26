<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\PreparedSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionDetail;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSearch;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;
use DomainException;
use RuntimeException;

final class MysqliSubmissionGateway implements SubmissionGateway, \CodingPanda\OjPlatform\Modules\Submissions\Contract\FilteredSubmissionGateway, \CodingPanda\OjPlatform\Modules\Submissions\Contract\AcceptedSubmissionTimeGateway
{
    private const REQUIRED_SOLUTION_COLUMNS = [
        'solution_id',
        'problem_id',
        'user_id',
        'time',
        'memory',
        'in_date',
        'result',
        'language',
        'ip',
        'contest_id',
        'valid',
        'num',
        'code_length',
    ];

    private const OPTIONAL_SOLUTION_COLUMNS = [
        'nick',
        'judgetime',
        'pass_rate',
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $columnCache = [];

    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connector
    ) {
    }

    public function search(SubmissionSearch $search): array
    {
        $connection = $this->readConnection();
        try {
            $columns = $this->solutionColumns($connection);
            [$where, $types, $values] = $this->where($search, $columns);
            $values[] = $search->limit();
            $values[] = $search->offset();
            $rows = $this->rows(
                $connection,
                'SELECT ' . $this->summaryColumns($columns)
                    . ' FROM `solution`' . $where
                    . ' ORDER BY `solution_id` DESC LIMIT ? OFFSET ?',
                $types . 'ii',
                $values
            );

            return array_values(array_filter(array_map(
                [$this, 'tryHydrateSummary'],
                $rows
            )));
        } finally {
            $connection->close();
        }
    }

    public function count(SubmissionSearch $search): int
    {
        $connection = $this->readConnection();
        try {
            $columns = $this->solutionColumns($connection);
            [$where, $types, $values] = $this->where($search, $columns);
            $row = $this->row(
                $connection,
                'SELECT COUNT(*) AS `total` FROM `solution`' . $where,
                $types,
                $values
            );

            return (int) ($row['total'] ?? 0);
        } finally {
            $connection->close();
        }
    }

    public function earliestAcceptedTimes(array $userLogins, array $problemIds, ?string $from, ?string $until, int $maximum): array
    {
        if ($userLogins === [] || $problemIds === []) { return []; }
        $connection = $this->readConnection();
        try {
            [$where, $types, $values] = $this->where(new SubmissionSearch(
                resultCode: 4, valid: true, userLogins: $userLogins, problemIds: $problemIds,
                submittedFrom: $from, submittedUntil: $until
            ), $this->solutionColumns($connection));
            $values[] = max(1, min(5000, $maximum));
            return array_map(static fn (array $row): array => [
                'user_login' => (string) $row['user_login'], 'problem_id' => (int) $row['problem_id'],
                'accepted_at' => (string) $row['accepted_at'],
            ], $this->rows($connection,
                'SELECT `user_id` AS `user_login`, `problem_id`, MIN(`in_date`) AS `accepted_at` FROM `solution`'
                . $where . ' GROUP BY `user_id`, `problem_id` ORDER BY `user_id`, `problem_id` LIMIT ?',
                $types . 'i', $values));
        } finally { $connection->close(); }
    }

    public function findSummary(
        SubmissionId $submissionId
    ): ?SubmissionSummary {
        $connection = $this->readConnection();
        try {
            $columns = $this->solutionColumns($connection);
            $row = $this->row(
                $connection,
                'SELECT ' . $this->summaryColumns($columns)
                    . ' FROM `solution` WHERE `solution_id` = ? LIMIT 1',
                'i',
                [$submissionId->value()]
            );

            return $row === null ? null : $this->tryHydrateSummary($row);
        } finally {
            $connection->close();
        }
    }

    public function findSummaries(array $submissionIds): array
    {
        return $this->loadSummaries($submissionIds);
    }

    public function findFilteredSummaries(array $ids, \CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery $query): array
    {
        return $this->loadSummaries($ids, $query);
    }

    private function loadSummaries(array $submissionIds, ?\CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery $query = null): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (SubmissionId $submissionId): int => $submissionId->value(),
            $submissionIds
        )));
        if ($ids === []) {
            return [];
        }
        $connection = $this->readConnection();
        try {
            $columns = $this->solutionColumns($connection);
            [$where, $types, $values] = $query === null ? ['', '', []] : $this->where(new SubmissionSearch(
                null, null, $query->resultCode(), null, $query->valid(), '', 100, 0,
                $query->userLogins(), $query->problemIds(), $query->contestId(), $query->submittedFrom(), $query->submittedUntil()
            ), $columns);
            $rows = $this->rows(
                $connection,
                'SELECT ' . $this->summaryColumns($columns)
                    . ' FROM `solution` WHERE `solution_id` IN ('
                    . implode(', ', array_fill(0, count($ids), '?'))
                    . ') AND `problem_id` > 0' . ($where === '' ? '' : ' AND ' . substr($where, 7)) . ' ORDER BY `solution_id` DESC',
                str_repeat('i', count($ids)) . $types,
                [...$ids, ...$values]
            );
            $summaries = [];
            foreach ($rows as $row) {
                $summary = $this->tryHydrateSummary($row);
                if ($summary !== null) {
                    $summaries[$summary->submissionId()->value()] = $summary;
                }
            }

            return $summaries;
        } finally {
            $connection->close();
        }
    }

    public function findDetail(SubmissionId $submissionId): ?SubmissionDetail
    {
        $connection = $this->readConnection();
        try {
            $columns = $this->solutionColumns($connection);
            $this->tableColumns(
                $connection,
                'source_code',
                ['solution_id', 'source']
            );
            $this->tableColumns(
                $connection,
                'source_code_user',
                ['solution_id', 'source']
            );
            $this->tableColumns(
                $connection,
                'compileinfo',
                ['solution_id', 'error']
            );
            $this->tableColumns(
                $connection,
                'runtimeinfo',
                ['solution_id', 'error']
            );
            $row = $this->row(
                $connection,
                'SELECT ' . $this->summaryColumns($columns, 's')
                    . ', COALESCE(`user_source`.`source`, \'\') AS `user_source`'
                    . ', COALESCE(`judge_source`.`source`, \'\') AS `judge_source`'
                    . ', COALESCE(`compile`.`error`, \'\') AS `compile_error`'
                    . ', COALESCE(`runtime`.`error`, \'\') AS `runtime_error`'
                    . ' FROM `solution` AS `s`'
                    . ' LEFT JOIN `source_code_user` AS `user_source`'
                    . ' ON `user_source`.`solution_id` = `s`.`solution_id`'
                    . ' LEFT JOIN `source_code` AS `judge_source`'
                    . ' ON `judge_source`.`solution_id` = `s`.`solution_id`'
                    . ' LEFT JOIN `compileinfo` AS `compile`'
                    . ' ON `compile`.`solution_id` = `s`.`solution_id`'
                    . ' LEFT JOIN `runtimeinfo` AS `runtime`'
                    . ' ON `runtime`.`solution_id` = `s`.`solution_id`'
                    . ' WHERE `s`.`solution_id` = ? LIMIT 1',
                'i',
                [$submissionId->value()]
            );
            $summary = $row === null ? null : $this->tryHydrateSummary($row);
            if ($summary === null) {
                return null;
            }

            return new SubmissionDetail(
                $summary,
                (string) ($row['user_source'] ?? ''),
                (string) ($row['judge_source'] ?? ''),
                (string) ($row['compile_error'] ?? ''),
                (string) ($row['runtime_error'] ?? '')
            );
        } finally {
            $connection->close();
        }
    }

    public function createPrepared(
        PreparedSubmission $submission
    ): SubmissionId {
        $connection = $this->writeConnection();
        try {
            $columns = $this->solutionColumns($connection);
            $data = [
                'problem_id' => $submission->problemId(),
                'user_id' => $submission->userLogin(),
                'time' => 0,
                'memory' => 0,
                'result' => 2,
                'language' => $submission->languageId(),
                'ip' => $submission->ipAddress(),
                'contest_id' => $submission->contestId(),
                'valid' => 1,
                'num' => $submission->contestProblemNumber(),
                'code_length' => $submission->codeLength(),
            ];
            if (in_array('nick', $columns, true)) {
                $data['nick'] = $submission->nickname();
            }
            $statement = $this->insert(
                $connection,
                'solution',
                $data,
                ['in_date' => 'CURRENT_TIMESTAMP']
            );
            $statement->close();
            $solutionId = (int) $connection->insert_id;
            if ($solutionId < 1) {
                throw new RuntimeException('준비된 제출 번호를 확인하지 못했습니다.');
            }

            return new SubmissionId($solutionId);
        } finally {
            $connection->close();
        }
    }

    public function storeSources(
        SubmissionId $submissionId,
        string $userSource,
        string $judgeSource
    ): void {
        $connection = $this->writeConnection();
        try {
            $this->tableColumns(
                $connection,
                'source_code_user',
                ['solution_id', 'source']
            );
            $this->tableColumns(
                $connection,
                'source_code',
                ['solution_id', 'source']
            );
            $userStatement = $this->insert(
                $connection,
                'source_code_user',
                [
                    'solution_id' => $submissionId->value(),
                    'source' => $userSource,
                ]
            );
            $userStatement->close();
            $judgeStatement = $this->insert(
                $connection,
                'source_code',
                [
                    'solution_id' => $submissionId->value(),
                    'source' => $judgeSource,
                ]
            );
            $judgeStatement->close();
        } finally {
            $connection->close();
        }
    }

    public function sourcesMatch(
        SubmissionId $submissionId,
        string $userSha256,
        string $judgeSha256,
        int $userBytes,
        int $judgeBytes
    ): bool {
        $connection = $this->readConnection();
        try {
            $user = $this->row(
                $connection,
                'SELECT `source` FROM `source_code_user`'
                    . ' WHERE `solution_id` = ? LIMIT 1',
                'i',
                [$submissionId->value()]
            );
            $judge = $this->row(
                $connection,
                'SELECT `source` FROM `source_code`'
                    . ' WHERE `solution_id` = ? LIMIT 1',
                'i',
                [$submissionId->value()]
            );
            $userSource = is_array($user) ? (string) ($user['source'] ?? '') : '';
            $judgeSource = is_array($judge) ? (string) ($judge['source'] ?? '') : '';

            return strlen($userSource) === $userBytes
                && strlen($judgeSource) === $judgeBytes
                && hash_equals($userSha256, hash('sha256', $userSource))
                && hash_equals($judgeSha256, hash('sha256', $judgeSource));
        } finally {
            $connection->close();
        }
    }

    public function publish(SubmissionId $submissionId): void
    {
        $connection = $this->writeConnection();
        try {
            $affected = $this->execute(
                $connection,
                'UPDATE `solution` SET `result` = 0'
                    . ' WHERE `solution_id` = ? AND `result` = 2 AND `valid` = 1',
                'i',
                [$submissionId->value()]
            );
            if ($affected !== 1) {
                throw new RuntimeException('준비된 제출을 채점 대기 상태로 공개하지 못했습니다.');
            }
        } finally {
            $connection->close();
        }
    }

    public function discardPrepared(SubmissionId $submissionId): void
    {
        $connection = $this->writeConnection();
        try {
            $this->execute(
                $connection,
                'DELETE FROM `source_code_user` WHERE `solution_id` = ?',
                'i',
                [$submissionId->value()]
            );
            $this->execute(
                $connection,
                'DELETE FROM `source_code` WHERE `solution_id` = ?',
                'i',
                [$submissionId->value()]
            );
            $this->execute(
                $connection,
                'DELETE FROM `solution`'
                    . ' WHERE `solution_id` = ? AND `result` = 2',
                'i',
                [$submissionId->value()]
            );
        } finally {
            $connection->close();
        }
    }

    public function markRecoveryRequired(SubmissionId $submissionId): void
    {
        $connection = $this->writeConnection();
        try {
            $this->execute(
                $connection,
                'UPDATE `solution` SET `valid` = 0, `result` = 14'
                    . ' WHERE `solution_id` = ?',
                'i',
                [$submissionId->value()]
            );
        } finally {
            $connection->close();
        }
    }

    public function latestSubmissionTimestamp(string $userLogin): ?int
    {
        $connection = $this->readConnection();
        try {
            $this->solutionColumns($connection);
            $row = $this->row(
                $connection,
                'SELECT UNIX_TIMESTAMP(MAX(`in_date`)) AS `submitted_at`'
                    . ' FROM `solution` WHERE `user_id` = ?',
                's',
                [$userLogin]
            );
            $timestamp = $row['submitted_at'] ?? null;

            return $timestamp === null || $timestamp === ''
                ? null
                : (int) $timestamp;
        } finally {
            $connection->close();
        }
    }

    public function hasAccepted(
        string $userLogin,
        int $problemId,
        SubmissionContext $context
    ): bool {
        $connection = $this->readConnection();
        try {
            $this->solutionColumns($connection);
            $clauses = [
                '`user_id` = ?',
                '`problem_id` = ?',
                '`result` = 4',
                '`valid` = 1',
            ];
            $types = 'si';
            $values = [$userLogin, $problemId];
            if ($context->contestId() > 0) {
                $clauses[] = '`contest_id` = ?';
                $types .= 'i';
                $values[] = $context->contestId();
            } else {
                $clauses[] = '`contest_id` = 0';
            }
            $row = $this->row(
                $connection,
                'SELECT `solution_id` FROM `solution` WHERE '
                    . implode(' AND ', $clauses) . ' LIMIT 1',
                $types,
                $values
            );

            return $row !== null;
        } finally {
            $connection->close();
        }
    }

    public function rejudge(SubmissionId $submissionId): int
    {
        $connection = $this->writeConnection();
        try {
            $this->solutionColumns($connection);
            $row = $this->row(
                $connection,
                'SELECT `result`, `valid` FROM `solution`'
                    . ' WHERE `solution_id` = ? LIMIT 1',
                'i',
                [$submissionId->value()]
            );
            if ($row === null) {
                throw new DomainException('재채점할 제출을 찾을 수 없습니다.');
            }
            if ((int) ($row['valid'] ?? 0) !== 1) {
                throw new DomainException('무효 처리된 제출은 복원한 뒤 재채점해 주세요.');
            }
            $this->execute(
                $connection,
                'UPDATE `solution` SET `result` = 1, `time` = 0, `memory` = 0'
                    . ' WHERE `solution_id` = ? AND `valid` = 1',
                'i',
                [$submissionId->value()]
            );

            return (int) ($row['result'] ?? 0);
        } finally {
            $connection->close();
        }
    }

    public function setValid(
        SubmissionId $submissionId,
        bool $valid
    ): bool {
        $connection = $this->writeConnection();
        try {
            $this->solutionColumns($connection);
            $row = $this->row(
                $connection,
                'SELECT `valid` FROM `solution`'
                    . ' WHERE `solution_id` = ? LIMIT 1',
                'i',
                [$submissionId->value()]
            );
            if ($row === null) {
                throw new DomainException('처리할 제출을 찾을 수 없습니다.');
            }
            if (((int) ($row['valid'] ?? 0) === 1) === $valid) {
                return false;
            }
            $affected = $this->execute(
                $connection,
                'UPDATE `solution` SET `valid` = ? WHERE `solution_id` = ?',
                'ii',
                [$valid ? 1 : 0, $submissionId->value()]
            );
            if ($affected !== 1) {
                throw new RuntimeException('제출 유효 상태를 변경하지 못했습니다.');
            }

            return true;
        } finally {
            $connection->close();
        }
    }

    private function readConnection(): \mysqli
    {
        $settings = $this->settings->current();
        if ($settings === null) {
            throw new RuntimeException('Judge DB 연결 설정을 먼저 완료해 주세요.');
        }

        return $this->connector->read($settings);
    }

    private function writeConnection(): \mysqli
    {
        $settings = $this->settings->current();
        if ($settings === null) {
            throw new RuntimeException('Judge DB 연결 설정을 먼저 완료해 주세요.');
        }

        return $this->connector->write($settings);
    }

    /**
     * @return list<string>
     */
    private function solutionColumns(\mysqli $connection): array
    {
        return $this->tableColumns(
            $connection,
            'solution',
            self::REQUIRED_SOLUTION_COLUMNS
        );
    }

    /**
     * @param list<string> $required
     * @return list<string>
     */
    private function tableColumns(
        \mysqli $connection,
        string $table,
        array $required
    ): array {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $result = $connection->query('SHOW COLUMNS FROM `' . $table . '`');
        if (! $result instanceof \mysqli_result) {
            throw new RuntimeException('Judge ' . $table . ' 테이블을 확인하지 못했습니다.');
        }
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        $result->free();
        $missing = array_values(array_diff($required, $columns));
        if ($missing !== []) {
            throw new RuntimeException(
                'Judge ' . $table . ' 테이블에 필수 열이 없습니다: '
                . implode(', ', $missing)
            );
        }
        $this->columnCache[$table] = $columns;

        return $columns;
    }

    /**
     * @param list<string> $columns
     */
    private function summaryColumns(
        array $columns,
        string $alias = ''
    ): string {
        $prefix = $alias === '' ? '' : '`' . $alias . '`.';
        $selected = [];
        foreach (self::REQUIRED_SOLUTION_COLUMNS as $column) {
            $selected[] = $prefix . '`' . $column . '` AS `' . $column . '`';
        }
        foreach (self::OPTIONAL_SOLUTION_COLUMNS as $column) {
            if (in_array($column, $columns, true)) {
                $selected[] = $prefix . '`' . $column . '` AS `' . $column . '`';
                continue;
            }
            $selected[] = match ($column) {
                'nick' => $prefix . '`user_id` AS `nick`',
                'judgetime' => $prefix . '`in_date` AS `judgetime`',
                default => 'NULL AS `' . $column . '`',
            };
        }

        return implode(', ', $selected);
    }

    /**
     * @param list<string> $columns
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private function where(
        SubmissionSearch $search,
        array $columns
    ): array {
        $clauses = ['`problem_id` > 0'];
        $types = '';
        $values = [];
        if ($search->userLogin() !== null) {
            $clauses[] = '`user_id` = ?';
            $types .= 's';
            $values[] = $search->userLogin();
        }
        if ($search->userLogins() !== []) {
            $logins = $search->userLogins();
            $clauses[] = '`user_id` IN ('
                . implode(', ', array_fill(0, count($logins), '?')) . ')';
            $types .= str_repeat('s', count($logins));
            array_push($values, ...$logins);
        }
        if ($search->problemId() !== null) {
            $clauses[] = '`problem_id` = ?';
            $types .= 'i';
            $values[] = $search->problemId();
        }
        if ($search->problemIds() !== []) {
            $problemIds = $search->problemIds();
            $clauses[] = '`problem_id` IN ('
                . implode(', ', array_fill(0, count($problemIds), '?')) . ')';
            $types .= str_repeat('i', count($problemIds));
            array_push($values, ...$problemIds);
        }
        if ($search->contestId() !== null) {
            $clauses[] = '`contest_id` = ?';
            $types .= 'i';
            $values[] = $search->contestId();
        }
        if ($search->submittedFrom() !== null) {
            $clauses[] = '`in_date` >= ?';
            $types .= 's';
            $values[] = $search->submittedFrom();
        }
        if ($search->submittedUntil() !== null) {
            $clauses[] = '`in_date` <= ?';
            $types .= 's';
            $values[] = $search->submittedUntil();
        }
        if ($search->resultCode() !== null) {
            $clauses[] = '`result` = ?';
            $types .= 'i';
            $values[] = $search->resultCode();
        }
        if ($search->languageId() !== null) {
            $clauses[] = '`language` = ?';
            $types .= 'i';
            $values[] = $search->languageId();
        }
        if ($search->valid() !== null) {
            $clauses[] = '`valid` = ?';
            $types .= 'i';
            $values[] = $search->valid() ? 1 : 0;
        }
        if ($search->keyword() !== '') {
            if (ctype_digit($search->keyword()) && strlen($search->keyword()) <= 18) {
                $clauses[] = '(`solution_id` = ? OR `problem_id` = ?)';
                $types .= 'ii';
                $values[] = (int) $search->keyword(); $values[] = (int) $search->keyword();
            } else {
            $keyword = '%' . $search->keyword() . '%';
            $parts = [
                '`user_id` LIKE ?',
            ];
            $values[] = $keyword;
            $types .= 's';
            if (in_array('nick', $columns, true)) {
                $parts[] = '`nick` LIKE ?';
                $values[] = $keyword;
                $types .= 's';
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        return [
            $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
            $types,
            $values,
        ];
    }

    /**
     * Some historical `solution` rows predate this platform (direct HUSTOJ
     * imports, manual DB edits) and can carry a problem_id/language outside
     * what SubmissionSummary's invariants allow. A single such row must not
     * take down an entire ranking/dashboard/list request, so callers that
     * process many rows at once should call tryHydrateSummary() and skip
     * nulls instead of calling this directly.
     *
     * @param array<string, mixed> $row
     */
    private function hydrateSummary(array $row): SubmissionSummary
    {
        return new SubmissionSummary(
            new SubmissionId((int) ($row['solution_id'] ?? 0)),
            (int) ($row['problem_id'] ?? 0),
            (string) ($row['user_id'] ?? ''),
            (string) ($row['nick'] ?? $row['user_id'] ?? ''),
            max(0, (int) ($row['language'] ?? 0)),
            (int) ($row['result'] ?? 0),
            max(0, (int) ($row['time'] ?? 0)),
            max(0, (int) ($row['memory'] ?? 0)),
            (string) ($row['in_date'] ?? ''),
            (string) ($row['judgetime'] ?? ''),
            max(0, (int) ($row['contest_id'] ?? 0)),
            (int) ($row['num'] ?? -1),
            null,
            null,
            (int) ($row['valid'] ?? 0) === 1,
            (float) ($row['pass_rate'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function tryHydrateSummary(array $row): ?SubmissionSummary
    {
        try {
            return $this->hydrateSummary($row);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param list<mixed> $values
     * @return array<string, mixed>|null
     */
    private function row(
        \mysqli $connection,
        string $sql,
        string $types = '',
        array $values = []
    ): ?array {
        $rows = $this->rows($connection, $sql, $types, $values);

        return $rows[0] ?? null;
    }

    /**
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
    private function rows(
        \mysqli $connection,
        string $sql,
        string $types = '',
        array $values = []
    ): array {
        $statement = $this->statement($connection, $sql, $types, $values);
        $result = $statement->get_result();
        if (! $result instanceof \mysqli_result) {
            $statement->close();
            throw new RuntimeException('Judge 제출 데이터를 읽지 못했습니다.');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $statement->close();

        return $rows;
    }

    /**
     * @param array<string, int|string|null> $data
     * @param array<string, string> $expressions
     */
    private function insert(
        \mysqli $connection,
        string $table,
        array $data,
        array $expressions = []
    ): \mysqli_stmt {
        foreach ($expressions as $column => $expression) {
            if (
                preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1
                || $expression !== 'CURRENT_TIMESTAMP'
            ) {
                throw new \LogicException(
                    'Judge INSERT 표현식이 올바르지 않습니다.'
                );
            }
        }
        $columns = array_merge(array_keys($data), array_keys($expressions));
        $values = array_values($data);
        $placeholders = array_fill(0, count($data), '?');
        array_push($placeholders, ...array_values($expressions));

        return $this->statement(
            $connection,
            'INSERT INTO `' . $table . '` (`'
                . implode('`, `', $columns)
                . '`) VALUES ('
                . implode(', ', $placeholders)
                . ')',
            $this->types($values),
            $values
        );
    }

    /**
     * @param list<mixed> $values
     */
    private function execute(
        \mysqli $connection,
        string $sql,
        string $types = '',
        array $values = []
    ): int {
        $statement = $this->statement($connection, $sql, $types, $values);
        $affected = $statement->affected_rows;
        $statement->close();

        return $affected;
    }

    /**
     * @param list<mixed> $values
     */
    private function statement(
        \mysqli $connection,
        string $sql,
        string $types,
        array $values
    ): \mysqli_stmt {
        $statement = $connection->prepare($sql);
        if (! $statement instanceof \mysqli_stmt) {
            throw new RuntimeException('Judge 제출 명령을 준비하지 못했습니다.');
        }
        if ($values !== []) {
            $references = [];
            foreach ($values as $index => $value) {
                $values[$index] = $value;
                $references[$index] = &$values[$index];
            }
            if (! $statement->bind_param($types, ...$references)) {
                $statement->close();
                throw new RuntimeException('Judge 제출 명령 값을 연결하지 못했습니다.');
            }
        }
        if (! $statement->execute()) {
            $message = $statement->error;
            $statement->close();
            throw new RuntimeException(
                'Judge 제출 명령을 실행하지 못했습니다.'
                    . ($message === '' ? '' : ' ' . $message)
            );
        }

        return $statement;
    }

    /**
     * @param list<mixed> $values
     */
    private function types(array $values): string
    {
        return implode('', array_map(
            static fn (mixed $value): string => is_int($value) ? 'i' : 's',
            $values
        ));
    }
}

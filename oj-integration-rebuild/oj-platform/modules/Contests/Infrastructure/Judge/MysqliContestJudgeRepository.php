<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\Contests\Application\ContestJudgeRepository;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestSaveCommand;
use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblem;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use RuntimeException;
use Throwable;

final class MysqliContestJudgeRepository implements ContestJudgeRepository, \CodingPanda\OjPlatform\Modules\Contests\Contract\ContestHomeRepository
{
    /** @var array<string, list<string>> */
    private array $columnCache = [];

    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connector
    ) {
    }

    public function find(int $contestId): ?Contest
    {
        $connection = $this->readConnection();
        try {
            $this->contestColumns($connection);
            $row = $this->contestRow($connection, $contestId);

            return $row === null ? null : Contest::fromRow($row);
        } finally {
            $connection->close();
        }
    }

    public function all(): array
    {
        $connection = $this->readConnection();
        try {
            $this->contestColumns($connection);

            return array_map(
                [Contest::class, 'fromRow'],
                $this->rows(
                    $connection,
                    'SELECT contest_id, title, start_time, end_time, defunct,'
                        . ' description, private FROM contest'
                        . ' ORDER BY contest_id DESC'
                )
            );
        } finally {
            $connection->close();
        }
    }

    public function homeCandidates(string $now, ?int $beforeId, int $limit): array
    {
        $connection = $this->readConnection();
        try {
            $this->contestColumns($connection);
            $sql = 'SELECT contest_id, title, start_time, end_time, defunct, description, private FROM contest'
                . " WHERE defunct = 'N' AND end_time >= ?";
            $values = [$now]; $types = 's';
            if ($beforeId !== null) { $sql .= ' AND contest_id < ?'; $values[] = $beforeId; $types .= 'i'; }
            $sql .= ' ORDER BY contest_id DESC LIMIT ?'; $values[] = max(1, min(100, $limit)); $types .= 'i';
            return array_map([Contest::class, 'fromRow'], $this->rows($connection, $sql, $types, $values));
        } finally { $connection->close(); }
    }

    public function problems(int $contestId): array
    {
        $connection = $this->readConnection();
        try {
            $this->contestProblemColumns($connection);
            $rows = $this->problemRows($connection, $contestId);

            return array_map(
                static fn (array $row): ContestProblem => new ContestProblem(
                    (int) ($row['problem_id'] ?? 0),
                    (int) ($row['num'] ?? -1),
                    100.0
                ),
                $rows
            );
        } finally {
            $connection->close();
        }
    }

    public function save(
        ContestSaveCommand $command,
        string $ownerLogin
    ): int {
        $connection = $this->writeConnection();
        $contestId = $command->contestId();
        $created = false;
        $locked = false;
        $beforeContest = null;
        $beforeProblems = [];

        try {
            $this->contestColumns($connection);
            $this->contestProblemColumns($connection);
            $this->lockTables($connection);
            $locked = true;

            if ($contestId > 0) {
                $beforeContest = $this->contestSnapshot($connection, $contestId);
                if ($beforeContest === null) {
                    throw new RuntimeException('수정할 Judge 대회를 찾지 못했습니다.');
                }
                $beforeProblems = $this->problemRows($connection, $contestId);
                $this->updateContest(
                    $connection,
                    $contestId,
                    $command,
                    $ownerLogin,
                    'Y'
                );
            } else {
                $contestId = $this->insertContest(
                    $connection,
                    $command,
                    $ownerLogin
                );
                $created = true;
            }

            $this->replaceProblems($connection, $contestId, $command->problemIds());
            $this->assertProblemMapping(
                $connection,
                $contestId,
                $command->problemIds()
            );
            $this->execute(
                $connection,
                'UPDATE contest SET defunct = ? WHERE contest_id = ?',
                'si',
                ['N', $contestId]
            );

            return $contestId;
        } catch (Throwable $error) {
            try {
                if ($created && $contestId > 0) {
                    $this->deleteContest($connection, $contestId);
                } elseif (
                    $contestId > 0
                    && is_array($beforeContest)
                ) {
                    $this->restoreContest(
                        $connection,
                        $contestId,
                        $beforeContest,
                        $beforeProblems
                    );
                }
            } catch (Throwable $restoreError) {
                throw new RuntimeException(
                    'Judge 대회 저장을 원복하지 못했습니다. 운영 확인이 필요합니다.',
                    0,
                    $restoreError
                );
            }

            throw $error;
        } finally {
            if ($locked) {
                try {
                    $this->unlockTables($connection);
                } catch (Throwable) {
                }
            }
            $connection->close();
        }
    }

    public function setArchived(int $contestId, bool $archived): void
    {
        $connection = $this->writeConnection();
        try {
            $this->execute(
                $connection,
                'UPDATE contest SET defunct = ? WHERE contest_id = ?',
                'si',
                [$archived ? 'Y' : 'N', $contestId]
            );
        } finally {
            $connection->close();
        }
    }

    public function remove(int $contestId): void
    {
        $connection = $this->writeConnection();
        $locked = false;
        try {
            if (! $connection->query(
                'LOCK TABLES contest WRITE, contest_problem WRITE, solution READ'
            )) {
                throw new RuntimeException('Judge 대회 삭제 잠금을 얻지 못했습니다.');
            }
            $locked = true;
            if ($this->row(
                $connection,
                'SELECT solution_id FROM solution'
                    . ' WHERE contest_id = ? LIMIT 1',
                'i',
                [$contestId]
            ) !== null) {
                throw new RuntimeException(
                    '제출이 존재하는 Judge 대회는 삭제할 수 없습니다.'
                );
            }
            $this->deleteContest($connection, $contestId);
        } finally {
            if ($locked) {
                try {
                    $this->unlockTables($connection);
                } catch (Throwable) {
                }
            }
            $connection->close();
        }
    }

    private function insertContest(
        \mysqli $connection,
        ContestSaveCommand $command,
        string $ownerLogin
    ): int {
        $this->execute(
            $connection,
            'INSERT INTO contest'
                . ' (title, start_time, end_time, defunct, description,'
                . ' private, langmask, password, user_id)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'sssssiiss',
            [
                $command->title(),
                $command->startsAt(),
                $command->endsAt(),
                'Y',
                $command->description(),
                $command->visibility() === 'public' ? 0 : 1,
                0,
                '',
                $ownerLogin,
            ]
        );
        $contestId = (int) $connection->insert_id;
        if ($contestId < 1) {
            throw new RuntimeException('생성된 대회 번호를 확인하지 못했습니다.');
        }

        return $contestId;
    }

    private function updateContest(
        \mysqli $connection,
        int $contestId,
        ContestSaveCommand $command,
        string $ownerLogin,
        string $defunct
    ): void {
        $this->execute(
            $connection,
            'UPDATE contest SET title = ?, start_time = ?, end_time = ?,'
                . ' defunct = ?, description = ?, private = ?, user_id = ?'
                . ' WHERE contest_id = ?',
            'sssssisi',
            [
                $command->title(),
                $command->startsAt(),
                $command->endsAt(),
                $defunct,
                $command->description(),
                $command->visibility() === 'public' ? 0 : 1,
                $ownerLogin,
                $contestId,
            ]
        );
    }

    /**
     * @param list<int> $problemIds
     */
    private function replaceProblems(
        \mysqli $connection,
        int $contestId,
        array $problemIds
    ): void {
        $this->execute(
            $connection,
            'DELETE FROM contest_problem WHERE contest_id = ?',
            'i',
            [$contestId]
        );
        foreach ($problemIds as $number => $problemId) {
            $this->execute(
                $connection,
                'INSERT INTO contest_problem (contest_id, problem_id, num)'
                    . ' VALUES (?, ?, ?)',
                'iii',
                [$contestId, $problemId, $number]
            );
        }
    }

    /**
     * @param list<int> $problemIds
     */
    private function assertProblemMapping(
        \mysqli $connection,
        int $contestId,
        array $problemIds
    ): void {
        $rows = $this->problemRows($connection, $contestId);
        if (count($rows) !== count($problemIds)) {
            throw new RuntimeException('Judge 대회 문제 수를 검증하지 못했습니다.');
        }
        foreach ($problemIds as $number => $problemId) {
            $row = $rows[$number] ?? null;
            if (
                ! is_array($row)
                || (int) ($row['problem_id'] ?? 0) !== $problemId
                || (int) ($row['num'] ?? -1) !== $number
            ) {
                throw new RuntimeException('Judge 대회 문제 순서를 검증하지 못했습니다.');
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contestRow(\mysqli $connection, int $contestId): ?array
    {
        return $this->row(
            $connection,
            'SELECT contest_id, title, start_time, end_time, defunct,'
                . ' description, private FROM contest'
                . ' WHERE contest_id = ? LIMIT 1',
            'i',
            [$contestId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contestSnapshot(
        \mysqli $connection,
        int $contestId
    ): ?array {
        return $this->row(
            $connection,
            'SELECT title, start_time, end_time, defunct, description,'
                . ' private, langmask, password, user_id FROM contest'
                . ' WHERE contest_id = ? LIMIT 1',
            'i',
            [$contestId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function problemRows(\mysqli $connection, int $contestId): array
    {
        return $this->rows(
            $connection,
            'SELECT problem_id, num FROM contest_problem'
                . ' WHERE contest_id = ? ORDER BY num, problem_id',
            'i',
            [$contestId]
        );
    }

    /**
     * @param array<string, mixed> $contest
     * @param list<array<string, mixed>> $problems
     */
    private function restoreContest(
        \mysqli $connection,
        int $contestId,
        array $contest,
        array $problems
    ): void {
        $this->execute(
            $connection,
            'DELETE FROM contest_problem WHERE contest_id = ?',
            'i',
            [$contestId]
        );
        foreach ($problems as $problem) {
            $this->execute(
                $connection,
                'INSERT INTO contest_problem (contest_id, problem_id, num)'
                    . ' VALUES (?, ?, ?)',
                'iii',
                [
                    $contestId,
                    (int) ($problem['problem_id'] ?? 0),
                    (int) ($problem['num'] ?? -1),
                ]
            );
        }
        $this->execute(
            $connection,
            'UPDATE contest SET title = ?, start_time = ?, end_time = ?,'
                . ' defunct = ?, description = ?, private = ?, langmask = ?,'
                . ' password = ?, user_id = ? WHERE contest_id = ?',
            'sssssiissi',
            [
                (string) ($contest['title'] ?? ''),
                (string) ($contest['start_time'] ?? ''),
                (string) ($contest['end_time'] ?? ''),
                (string) ($contest['defunct'] ?? 'N'),
                (string) ($contest['description'] ?? ''),
                (int) ($contest['private'] ?? 0),
                (int) ($contest['langmask'] ?? 0),
                (string) ($contest['password'] ?? ''),
                (string) ($contest['user_id'] ?? ''),
                $contestId,
            ]
        );
    }

    private function deleteContest(\mysqli $connection, int $contestId): void
    {
        $this->execute(
            $connection,
            'DELETE FROM contest_problem WHERE contest_id = ?',
            'i',
            [$contestId]
        );
        $this->execute(
            $connection,
            'DELETE FROM contest WHERE contest_id = ?',
            'i',
            [$contestId]
        );
    }

    private function lockTables(\mysqli $connection): void
    {
        if (! $connection->query(
            'LOCK TABLES contest WRITE, contest_problem WRITE'
        )) {
            throw new RuntimeException('Judge 대회 테이블 잠금을 얻지 못했습니다.');
        }
    }

    private function unlockTables(\mysqli $connection): void
    {
        if (! $connection->query('UNLOCK TABLES')) {
            throw new RuntimeException('Judge 대회 테이블 잠금을 해제하지 못했습니다.');
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
    private function contestColumns(\mysqli $connection): array
    {
        return $this->tableColumns(
            $connection,
            'contest',
            [
                'contest_id',
                'title',
                'start_time',
                'end_time',
                'defunct',
                'description',
                'private',
                'langmask',
                'password',
                'user_id',
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function contestProblemColumns(\mysqli $connection): array
    {
        return $this->tableColumns(
            $connection,
            'contest_problem',
            ['contest_id', 'problem_id', 'num']
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
            throw new RuntimeException(
                'Judge ' . $table . ' 테이블을 확인하지 못했습니다.'
            );
        }
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string) ($row['Field'] ?? '');
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
     * @param list<mixed> $values
     * @return array<string, mixed>|null
     */
    private function row(
        \mysqli $connection,
        string $sql,
        string $types = '',
        array $values = []
    ): ?array {
        return $this->rows($connection, $sql, $types, $values)[0] ?? null;
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
            throw new RuntimeException('Judge 대회 데이터를 읽지 못했습니다.');
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
            throw new RuntimeException('Judge 대회 명령을 준비하지 못했습니다.');
        }
        if ($values !== []) {
            $references = [];
            foreach ($values as $index => $value) {
                $references[$index] = &$values[$index];
            }
            if (! $statement->bind_param($types, ...$references)) {
                $statement->close();
                throw new RuntimeException('Judge 대회 명령 값을 연결하지 못했습니다.');
            }
        }
        if (! $statement->execute()) {
            $message = $statement->error;
            $statement->close();
            throw new RuntimeException(
                'Judge 대회 명령을 실행하지 못했습니다.'
                    . ($message === '' ? '' : ' ' . $message)
            );
        }

        return $statement;
    }
}

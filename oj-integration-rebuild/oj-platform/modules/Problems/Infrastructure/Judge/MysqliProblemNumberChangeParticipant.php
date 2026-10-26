<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Judge;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberMap;
use DomainException;
use RuntimeException;
use Throwable;

final class MysqliProblemNumberChangeParticipant implements ProblemNumberChangeParticipant
{
    private const REFERENCES = [
        'solution' => 'problem_id',
        'contest_problem' => 'problem_id',
        'problem' => 'problem_id',
    ];

    private const MAX_TEMPORARY_ID = 2147483647;

    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnector $connector
    ) {
    }

    public function key(): string
    {
        return 'judge';
    }

    public function inspect(ProblemNumberMap $mapping): array
    {
        $connection = $this->connector->read($this->connectionSettings());
        try {
            $tables = $this->availableTables($connection);
            $this->assertNoForeignKeys($connection, $tables);
            $this->assertMovable($connection, $tables, $mapping);
            $counts = [];
            foreach ($tables as $table => $column) {
                $counts[$table] = $this->countForIds(
                    $connection,
                    $table,
                    $column,
                    $mapping->sourceIds()
                );
            }

            return $counts;
        } finally {
            $connection->close();
        }
    }

    public function move(ProblemNumberMap $mapping): void
    {
        $connection = $this->connector->write($this->connectionSettings());
        $locked = false;
        try {
            $tables = $this->availableTables($connection);
            $this->assertNoForeignKeys($connection, $tables);
            $this->lockTables($connection, array_keys($tables));
            $locked = true;
            $this->assertMovable($connection, $tables, $mapping);
            $temporary = $this->temporaryMapping(
                $connection,
                $tables,
                $mapping
            );
            $operations = [];
            try {
                $this->movePairs(
                    $connection,
                    $tables,
                    $temporary,
                    $operations
                );
                $temporaryToTarget = [];
                foreach ($mapping->pairs() as $source => $target) {
                    $temporaryToTarget[$temporary[$source]] = $target;
                }
                $this->movePairs(
                    $connection,
                    $tables,
                    $temporaryToTarget,
                    $operations
                );
            } catch (Throwable $error) {
                try {
                    $this->revertOperations($connection, $operations);
                } catch (Throwable $recoveryError) {
                    throw new RuntimeException(
                        'Judge 문제 번호 변경과 자동 복구가 모두 실패했습니다: '
                            . $recoveryError->getMessage(),
                        0,
                        $error
                    );
                }

                throw $error;
            }
        } finally {
            if ($locked) {
                try {
                    $connection->query('UNLOCK TABLES');
                } catch (Throwable) {
                }
            }
            $connection->close();
        }
    }

    public function verifyMoved(ProblemNumberMap $mapping): void
    {
        $connection = $this->connector->read($this->connectionSettings());
        try {
            $tables = $this->availableTables($connection);
            $sourceOnly = array_values(array_diff(
                $mapping->sourceIds(),
                $mapping->targetIds()
            ));
            foreach ($tables as $table => $column) {
                if (
                    $sourceOnly !== []
                    && $this->countForIds(
                        $connection,
                        $table,
                        $column,
                        $sourceOnly
                    ) > 0
                ) {
                    throw new RuntimeException(
                        'Judge DB에 이전 문제 번호 참조가 남아 있습니다.'
                    );
                }
            }
            if (
                $this->countForIds(
                    $connection,
                    'problem',
                    'problem_id',
                    $mapping->targetIds()
                ) !== $mapping->count()
            ) {
                throw new RuntimeException('Judge DB의 새 문제 번호를 확인하지 못했습니다.');
            }
        } finally {
            $connection->close();
        }
    }

    /** @return array<string, string> */
    private function availableTables(\mysqli $connection): array
    {
        $available = [];
        foreach (self::REFERENCES as $table => $column) {
            $statement = $connection->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                    . ' AND COLUMN_NAME = ?'
            );
            if (! $statement instanceof \mysqli_stmt) {
                throw new RuntimeException('Judge DB 스키마 검사 명령을 준비하지 못했습니다.');
            }
            try {
                $statement->bind_param('ss', $table, $column);
                $statement->execute();
                $statement->bind_result($count);
                $statement->fetch();
                if ((int) $count > 0) {
                    $available[$table] = $column;
                }
            } finally {
                $statement->close();
            }
        }
        if (! isset($available['problem'])) {
            throw new DomainException('Judge DB problem.problem_id 열을 찾을 수 없습니다.');
        }

        return $available;
    }

    /** @param array<string, string> $tables */
    private function assertMovable(
        \mysqli $connection,
        array $tables,
        ProblemNumberMap $mapping
    ): void {
        $sourceProblemCount = $this->countForIds(
            $connection,
            'problem',
            'problem_id',
            $mapping->sourceIds()
        );
        if ($sourceProblemCount !== $mapping->count()) {
            throw new DomainException('변경할 원본 문제 중 존재하지 않는 번호가 있습니다.');
        }
        $externalTargets = array_values(array_diff(
            $mapping->targetIds(),
            $mapping->sourceIds()
        ));
        foreach ($tables as $table => $column) {
            if (
                $externalTargets !== []
                && $this->countForIds(
                    $connection,
                    $table,
                    $column,
                    $externalTargets
                ) > 0
            ) {
                throw new DomainException(
                    '새 문제 번호에 기존 Judge 문제 또는 참조가 있어 충돌합니다.'
                );
            }
        }
        if (
            isset($tables['solution'])
            && $this->pendingSolutions($connection, $mapping->sourceIds()) > 0
        ) {
            throw new DomainException(
                '채점 대기 또는 실행 중인 제출이 있어 번호를 변경할 수 없습니다.'
            );
        }
    }

    /** @param array<string, string> $tables */
    private function assertNoForeignKeys(
        \mysqli $connection,
        array $tables
    ): void {
        foreach (array_keys($tables) as $table) {
            $statement = $connection->prepare(
                'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                    . ' AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
            );
            if (! $statement instanceof \mysqli_stmt) {
                throw new RuntimeException('Judge DB 외래 키 검사를 준비하지 못했습니다.');
            }
            $column = self::REFERENCES[$table];
            try {
                $statement->bind_param('ss', $table, $column);
                $statement->execute();
                $statement->bind_result($count);
                $statement->fetch();
                if ((int) $count > 0) {
                    throw new DomainException(
                        'Judge 문제 번호 외래 키가 있어 안전한 단계 변경을 수행할 수 없습니다.'
                    );
                }
            } finally {
                $statement->close();
            }
        }
    }

    /**
     * @param array<string, string> $tables
     * @return array<int, int>
     */
    private function temporaryMapping(
        \mysqli $connection,
        array $tables,
        ProblemNumberMap $mapping
    ): array {
        $reserved = array_fill_keys(array_merge(
            $mapping->sourceIds(),
            $mapping->targetIds()
        ), true);
        $temporary = [];
        $candidate = self::MAX_TEMPORARY_ID;
        foreach ($mapping->sourceIds() as $source) {
            while ($candidate > 0) {
                if (! isset($reserved[$candidate])) {
                    $occupied = false;
                    foreach ($tables as $table => $column) {
                        if ($this->countForIds(
                            $connection,
                            $table,
                            $column,
                            [$candidate]
                        ) > 0) {
                            $occupied = true;
                            break;
                        }
                    }
                    if (! $occupied) {
                        break;
                    }
                }
                --$candidate;
            }
            if ($candidate < 1) {
                throw new RuntimeException('문제 번호 변경용 임시 번호를 확보하지 못했습니다.');
            }
            $temporary[$source] = $candidate;
            $reserved[$candidate] = true;
            --$candidate;
        }

        return $temporary;
    }

    /**
     * @param list<string> $tables
     */
    private function lockTables(\mysqli $connection, array $tables): void
    {
        $sql = 'LOCK TABLES ' . implode(', ', array_map(
            static fn (string $table): string => '`' . $table . '` WRITE',
            $tables
        ));
        if ($connection->query($sql) === false) {
            throw new RuntimeException('Judge 문제 번호 변경 잠금을 얻지 못했습니다.');
        }
    }

    /**
     * @param array<string, string> $tables
     * @param array<int, int> $pairs
     * @param list<array{table: string, column: string, from: int, to: int, affected: int}> $operations
     */
    private function movePairs(
        \mysqli $connection,
        array $tables,
        array $pairs,
        array &$operations
    ): void {
        foreach ($tables as $table => $column) {
            foreach ($pairs as $source => $target) {
                $affected = $this->executeUpdate(
                    $connection,
                    $table,
                    $column,
                    $source,
                    $target,
                    $table === 'problem'
                );
                if ($affected > 0) {
                    $operations[] = [
                        'table' => $table,
                        'column' => $column,
                        'from' => $source,
                        'to' => $target,
                        'affected' => $affected,
                    ];
                }
            }
        }
    }

    /**
     * @param list<array{table: string, column: string, from: int, to: int, affected: int}> $operations
     */
    private function revertOperations(
        \mysqli $connection,
        array $operations
    ): void {
        foreach (array_reverse($operations) as $operation) {
            $affected = $this->executeUpdate(
                $connection,
                $operation['table'],
                $operation['column'],
                $operation['to'],
                $operation['from'],
                false
            );
            if ($affected !== $operation['affected']) {
                throw new RuntimeException(
                    'Judge 문제 번호 자동 복구 건수가 원래 변경 건수와 다릅니다.'
                );
            }
        }
    }

    /** @param list<int> $ids */
    private function countForIds(
        \mysqli $connection,
        string $table,
        string $column,
        array $ids
    ): int {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $connection->prepare(sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE `%s` IN (%s)',
            $table,
            $column,
            $placeholders
        ));
        if (! $statement instanceof \mysqli_stmt) {
            throw new RuntimeException('Judge DB 문제 번호 참조 검사를 준비하지 못했습니다.');
        }
        try {
            $types = str_repeat('i', count($ids));
            $values = array_values($ids);
            $this->bindIntegers($statement, $types, $values);
            $statement->execute();
            $statement->bind_result($count);
            $statement->fetch();

            return (int) $count;
        } finally {
            $statement->close();
        }
    }

    /** @param list<int> $ids */
    private function pendingSolutions(\mysqli $connection, array $ids): int
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM `solution` WHERE `problem_id` IN ('
                . $placeholders . ') AND `result` BETWEEN 0 AND 3'
        );
        if (! $statement instanceof \mysqli_stmt) {
            throw new RuntimeException('Judge 채점 대기 검사를 준비하지 못했습니다.');
        }
        try {
            $types = str_repeat('i', count($ids));
            $values = array_values($ids);
            $this->bindIntegers($statement, $types, $values);
            $statement->execute();
            $statement->bind_result($count);
            $statement->fetch();

            return (int) $count;
        } finally {
            $statement->close();
        }
    }

    private function executeUpdate(
        \mysqli $connection,
        string $table,
        string $column,
        int $source,
        int $target,
        bool $required
    ): int {
        $statement = $connection->prepare(sprintf(
            'UPDATE `%s` SET `%s` = ? WHERE `%s` = ?',
            $table,
            $column,
            $column
        ));
        if (! $statement instanceof \mysqli_stmt) {
            throw new RuntimeException('Judge DB 문제 번호 변경을 준비하지 못했습니다.');
        }
        try {
            $statement->bind_param('ii', $target, $source);
            if (! $statement->execute()) {
                throw new RuntimeException('Judge DB 문제 번호 참조를 변경하지 못했습니다.');
            }
            $affected = $statement->affected_rows;
            if ($required && $affected !== 1) {
                throw new RuntimeException('Judge 문제 번호 원본을 찾지 못했습니다.');
            }

            return $affected;
        } finally {
            $statement->close();
        }
    }

    private function connectionSettings(): JudgeConnectionSettings
    {
        $settings = $this->settings->current();
        if (! $settings instanceof JudgeConnectionSettings) {
            throw new DomainException('Judge DB 연결 설정을 먼저 완료해 주세요.');
        }

        return $settings;
    }

    /** @param list<int> $values */
    private function bindIntegers(
        \mysqli_stmt $statement,
        string $types,
        array &$values
    ): void {
        $references = [];
        foreach ($values as $index => &$value) {
            $references[$index] = &$value;
        }
        unset($value);
        if (! $statement->bind_param($types, ...$references)) {
            throw new RuntimeException('Judge DB 문제 번호 값을 연결하지 못했습니다.');
        }
    }
}

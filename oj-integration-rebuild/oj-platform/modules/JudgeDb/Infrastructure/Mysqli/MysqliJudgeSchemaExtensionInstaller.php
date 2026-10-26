<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeReadinessReport;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeSchemaExtensionInstaller;
use RuntimeException;

final class MysqliJudgeSchemaExtensionInstaller implements JudgeSchemaExtensionInstaller
{
    private const TABLE = 'problem';

    /**
     * @var array<string, string>
     */
    private const COLUMN_DEFINITIONS = [
        'bann' => 'LONGTEXT NULL',
        'front' => 'MEDIUMTEXT NULL',
        'body' => 'MEDIUMTEXT NULL',
        'rear' => 'MEDIUMTEXT NULL',
        'langmask' => 'INT NOT NULL DEFAULT 0',
    ];

    public function __construct(
        private readonly JudgeConnector $connections
    ) {
    }

    public function canProvision(JudgeReadinessReport $report): bool
    {
        if (
            $report->missingRequiredTables() !== []
            || $report->missingCompatibilityTables() !== []
            || $report->invalidColumnTypes() !== []
            || $report->errors() !== []
        ) {
            return false;
        }

        $missing = $report->missingRequiredColumns();
        foreach ($missing as $table => $columns) {
            if ($table !== self::TABLE) {
                return false;
            }
            foreach ($columns as $column) {
                if (! array_key_exists($column, self::COLUMN_DEFINITIONS)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function pendingColumns(JudgeReadinessReport $report): array
    {
        $columns = array_values(array_intersect(
            $report->missingRequiredColumns()[self::TABLE] ?? [],
            array_keys(self::COLUMN_DEFINITIONS)
        ));
        sort($columns);

        return $columns;
    }

    public function provision(JudgeConnectionSettings $settings): array
    {
        $connection = $this->connections->write($settings);
        try {
            $existing = $this->columns($connection);
            $clauses = [];
            $installed = [];
            foreach (self::COLUMN_DEFINITIONS as $column => $definition) {
                if (isset($existing[$column])) {
                    continue;
                }
                $clauses[] = 'ADD COLUMN `' . $column . '` ' . $definition;
                $installed[] = $column;
            }
            if ($clauses === []) {
                return [];
            }

            if (! $connection->query(
                'ALTER TABLE `' . self::TABLE . '` ' . implode(', ', $clauses)
            )) {
                throw new RuntimeException(
                    'Judge 문제 확장 필드를 생성하지 못했습니다. '
                    . '쓰기 계정의 ALTER 권한을 확인해 주세요.'
                );
            }

            $after = $this->columns($connection);
            foreach (array_keys(self::COLUMN_DEFINITIONS) as $column) {
                if (! isset($after[$column])) {
                    throw new RuntimeException(
                        'Judge 문제 확장 필드 생성 결과를 확인하지 못했습니다: '
                        . $column
                    );
                }
            }
            sort($installed);

            return $installed;
        } finally {
            $connection->close();
        }
    }

    /**
     * @return array<string, true>
     */
    private function columns(\mysqli $connection): array
    {
        $result = $connection->query('SHOW COLUMNS FROM `' . self::TABLE . '`');
        if (! $result instanceof \mysqli_result) {
            throw new RuntimeException('Judge problem 테이블 구조를 확인하지 못했습니다.');
        }

        $columns = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $name = strtolower(trim((string) ($row['Field'] ?? '')));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
        $result->free();

        return $columns;
    }
}

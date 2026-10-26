<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Infrastructure\Mysqli;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeProfileDefinition;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeReadinessReport;
use RuntimeException;
use Throwable;

final class MysqliJudgeConnectionProbe implements JudgeConnectionProbe
{
    public function __construct(
        private readonly JudgeProfileDefinition $profile,
        private readonly MysqliJudgeConnector $connections
    ) {
    }

    public function inspect(JudgeConnectionSettings $settings): JudgeReadinessReport
    {
        if (! $settings->utcStorageConfirmed()) {
            return $this->failure('judge_timestamp_utc_confirmation_required');
        }
        if (! extension_loaded('mysqli')) {
            return $this->failure('mysqli_extension_missing');
        }

        $readConnection = null;
        try {
            $readConnection = $this->connections->read($settings);
            $tables = $this->tableNames($readConnection);
            $engines = $this->tableEngines($readConnection);
            $columns = $this->tableColumns($readConnection, $tables);
        } catch (Throwable) {
            return $this->failure('judge_read_connection_failed');
        } finally {
            if ($readConnection instanceof \mysqli) {
                $readConnection->close();
            }
        }

        if (! $settings->usesSingleAccount()) {
            $writeConnection = null;
            try {
                $writeConnection = $this->connections->write($settings);
                $result = $writeConnection->query('SELECT 1');
                if ($result === false) {
                    throw new RuntimeException('Judge 쓰기 연결 확인에 실패했습니다.');
                }
                if (is_object($result) && method_exists($result, 'free')) {
                    $result->free();
                }
            } catch (Throwable) {
                return $this->failure('judge_write_connection_failed');
            } finally {
                if ($writeConnection instanceof \mysqli) {
                    $writeConnection->close();
                }
            }
        }

        $missingRequired = array_values(array_diff(
            $this->profile->requiredTables(),
            $tables
        ));
        $missingCompatibility = array_values(array_diff(
            $this->profile->requiredCompatibilityTables(),
            $tables
        ));
        $presentOptional = array_values(array_intersect(
            $this->profile->optionalTables(),
            $tables
        ));
        $presentIgnored = array_values(array_intersect(
            $this->profile->ignoredTables(),
            $tables
        ));
        [$missingColumns, $invalidTypes] = $this->validateColumns($columns);
        $errors = $this->validateEngines($engines, $tables);

        sort($missingRequired);
        sort($missingCompatibility);
        sort($presentOptional);
        sort($presentIgnored);
        ksort($engines);

        return new JudgeReadinessReport(
            $missingRequired,
            $missingCompatibility,
            $missingColumns,
            $invalidTypes,
            $presentOptional,
            $presentIgnored,
            $engines,
            $errors
        );
    }

    /**
     * @return list<string>
     */
    private function tableNames(\mysqli $connection): array
    {
        $result = $connection->query('SHOW TABLES');
        if (! $result instanceof \mysqli_result) {
            throw new RuntimeException('Judge 테이블 목록을 읽지 못했습니다.');
        }

        $tables = [];
        while (($row = $result->fetch_row()) !== null) {
            $table = strtolower(trim((string) ($row[0] ?? '')));
            if ($table !== '') {
                $tables[] = $table;
            }
        }
        $result->free();

        return array_values(array_unique($tables));
    }

    /**
     * @return array<string, string>
     */
    private function tableEngines(\mysqli $connection): array
    {
        $result = $connection->query('SHOW TABLE STATUS');
        if (! $result instanceof \mysqli_result) {
            throw new RuntimeException('Judge 테이블 엔진 정보를 읽지 못했습니다.');
        }

        $engines = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $name = strtolower(trim((string) ($row['Name'] ?? '')));
            if ($name !== '') {
                $engines[$name] = trim((string) ($row['Engine'] ?? ''));
            }
        }
        $result->free();

        return $engines;
    }

    /**
     * @param list<string> $tables
     * @return array<string, array<string, string>>
     */
    private function tableColumns(\mysqli $connection, array $tables): array
    {
        $required = array_merge(
            $this->profile->requiredTables(),
            $this->profile->requiredCompatibilityTables()
        );
        $available = array_fill_keys($tables, true);
        $columns = [];

        foreach ($required as $table) {
            if (! isset($available[$table])) {
                continue;
            }
            $result = $connection->query(sprintf(
                'SHOW COLUMNS FROM `%s`',
                $table
            ));
            if (! $result instanceof \mysqli_result) {
                throw new RuntimeException(
                    'Judge 필수 열 정보를 읽지 못했습니다: ' . $table
                );
            }
            while (($row = $result->fetch_assoc()) !== null) {
                $column = strtolower(trim((string) ($row['Field'] ?? '')));
                if ($column !== '') {
                    $columns[$table][$column] = strtolower(trim(
                        (string) ($row['Type'] ?? '')
                    ));
                }
            }
            $result->free();
        }

        return $columns;
    }

    /**
     * @param array<string, array<string, string>> $columns
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    private function validateColumns(array $columns): array
    {
        $missing = [];
        $invalid = [];

        foreach ($this->profile->requiredColumns() as $table => $requirements) {
            if (! isset($columns[$table])) {
                continue;
            }
            foreach ($requirements as $column => $acceptedTypes) {
                if (! isset($columns[$table][$column])) {
                    $missing[$table][] = $column;
                    continue;
                }
                $rawType = $columns[$table][$column];
                preg_match('/^[a-z]+/', $rawType, $matches);
                $baseType = $matches[0] ?? '';
                if (! in_array($baseType, $acceptedTypes, true)) {
                    $invalid[] = sprintf(
                        '%s.%s:%s',
                        $table,
                        $column,
                        $rawType
                    );
                }
            }
        }

        ksort($missing);
        foreach ($missing as &$tableColumns) {
            sort($tableColumns);
        }
        unset($tableColumns);
        sort($invalid);

        return [$missing, $invalid];
    }

    /**
     * @param array<string, string> $engines
     * @param list<string> $tables
     * @return list<string>
     */
    private function validateEngines(array $engines, array $tables): array
    {
        $available = array_fill_keys($tables, true);
        $accepted = $this->profile->compatibleEngines();
        $errors = [];

        foreach (array_merge(
            $this->profile->requiredTables(),
            $this->profile->requiredCompatibilityTables()
        ) as $table) {
            if (! isset($available[$table])) {
                continue;
            }
            $engine = strtolower(trim($engines[$table] ?? ''));
            if ($engine === '') {
                $errors[] = 'judge_table_engine_unknown:' . $table;
                continue;
            }
            if (! in_array($engine, $accepted, true)) {
                $errors[] = sprintf(
                    'judge_table_engine_unsupported:%s:%s',
                    $table,
                    $engines[$table]
                );
            }
        }

        sort($errors);

        return $errors;
    }

    private function failure(string $error): JudgeReadinessReport
    {
        return new JudgeReadinessReport(
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [$error]
        );
    }
}

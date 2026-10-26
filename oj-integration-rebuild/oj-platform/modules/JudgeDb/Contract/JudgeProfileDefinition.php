<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use InvalidArgumentException;

final class JudgeProfileDefinition implements RegistryDefinition
{
    /**
     * @var list<string>
     */
    private readonly array $requiredTableNames;

    /**
     * @var list<string>
     */
    private readonly array $optionalTableNames;

    /**
     * @var list<string>
     */
    private readonly array $compatibilityTableNames;

    /**
     * @var list<string>
     */
    private readonly array $requiredCompatibilityTableNames;

    /**
     * @var list<string>
     */
    private readonly array $ignoredTableNames;

    /**
     * @var list<string>
     */
    private readonly array $compatibleEngineNames;

    /**
     * @var array<string, array<string, list<string>>>
     */
    private readonly array $requiredColumnDefinitions;

    /**
     * @var array<string, string>
     */
    private readonly array $runtimeConstraintValues;

    /**
     * @var array<string, mixed>
     */
    private readonly array $defaultConnectionValues;

    /**
     * @param list<string> $requiredTables
     * @param list<string> $requiredCompatibilityTables
     * @param list<string> $optionalTables
     * @param list<string> $compatibilityTables
     * @param list<string> $ignoredTables
     * @param list<string> $compatibleEngines
     * @param array<string, array<string, list<string>>> $requiredColumns
     * @param array<string, string> $runtimeConstraints
     * @param array<string, mixed> $connectionDefaults
     */
    public function __construct(
        private readonly string $profileKey,
        private readonly string $contractVersion,
        private readonly string $databaseName,
        array $requiredTables,
        array $requiredCompatibilityTables,
        array $optionalTables,
        array $compatibilityTables,
        array $ignoredTables,
        array $compatibleEngines,
        array $requiredColumns,
        array $runtimeConstraints,
        array $connectionDefaults
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->profileKey) !== 1) {
            throw new InvalidArgumentException('Judge 프로필 키 형식이 올바르지 않습니다.');
        }

        if (trim($this->contractVersion) === '' || $this->databaseName !== 'jol') {
            throw new InvalidArgumentException('Judge 계약 버전과 데이터베이스 이름이 올바르지 않습니다.');
        }

        $groups = [
            $requiredTables,
            $requiredCompatibilityTables,
            $optionalTables,
            $compatibilityTables,
            $ignoredTables,
        ];

        foreach ($groups as $tables) {
            foreach ($tables as $table) {
                if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $table) !== 1) {
                    throw new InvalidArgumentException('Judge 테이블 이름 형식이 올바르지 않습니다.');
                }
            }
        }

        $allTables = array_merge(...$groups);
        if (count(array_unique($allTables)) !== count($allTables)) {
            throw new InvalidArgumentException('Judge 테이블 분류가 서로 겹칩니다.');
        }

        if (count($requiredTables) !== 8) {
            throw new InvalidArgumentException('기본 채점 필수 테이블은 정확히 8개여야 합니다.');
        }

        if ($requiredCompatibilityTables !== ['source_code_user']) {
            throw new InvalidArgumentException('제출 호환 필수 테이블 계약이 올바르지 않습니다.');
        }

        $normalizedEngines = array_values(array_unique(array_map(
            static fn (mixed $engine): string => strtolower(trim((string) $engine)),
            $compatibleEngines
        )));
        if ($normalizedEngines === [] || in_array('', $normalizedEngines, true)) {
            throw new InvalidArgumentException('Judge 호환 엔진 계약이 올바르지 않습니다.');
        }

        $normalizedColumns = [];
        foreach (array_merge($requiredTables, $requiredCompatibilityTables) as $table) {
            $columns = $requiredColumns[$table] ?? null;
            if (! is_array($columns) || $columns === []) {
                throw new InvalidArgumentException(
                    'Judge 필수 열 계약이 없는 테이블이 있습니다: ' . $table
                );
            }
            foreach ($columns as $column => $types) {
                if (
                    preg_match('/^[a-z][a-z0-9_]*$/', (string) $column) !== 1
                    || ! is_array($types)
                    || $types === []
                ) {
                    throw new InvalidArgumentException('Judge 필수 열 형식이 올바르지 않습니다.');
                }
                $normalizedTypes = [];
                foreach ($types as $type) {
                    $normalizedType = strtolower(trim((string) $type));
                    if (preg_match('/^[a-z]+$/', $normalizedType) !== 1) {
                        throw new InvalidArgumentException(
                            'Judge 필수 열 타입 형식이 올바르지 않습니다.'
                        );
                    }
                    $normalizedTypes[] = $normalizedType;
                }
                $normalizedColumns[$table][(string) $column] = array_values(
                    array_unique($normalizedTypes)
                );
            }
        }

        if (
            ($runtimeConstraints['OJ_SIM_ENABLE'] ?? null) !== '0'
            || ($runtimeConstraints['OJ_TURBO_MODE'] ?? null) !== '0'
        ) {
            throw new InvalidArgumentException('사용하지 않는 Judge 확장 기능은 꺼져 있어야 합니다.');
        }

        $this->requiredTableNames = array_values($requiredTables);
        $this->requiredCompatibilityTableNames = array_values(
            $requiredCompatibilityTables
        );
        $this->optionalTableNames = array_values($optionalTables);
        $this->compatibilityTableNames = array_values($compatibilityTables);
        $this->ignoredTableNames = array_values($ignoredTables);
        $this->compatibleEngineNames = $normalizedEngines;
        $this->requiredColumnDefinitions = $normalizedColumns;
        $this->runtimeConstraintValues = $runtimeConstraints;
        $this->defaultConnectionValues = $connectionDefaults;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            'default',
            (string) ($definition['contract_version'] ?? ''),
            (string) ($definition['database_name'] ?? ''),
            array_values((array) ($definition['required_tables'] ?? [])),
            array_values((array) ($definition['required_compatibility_tables'] ?? [])),
            array_values((array) ($definition['optional_tables'] ?? [])),
            array_values((array) ($definition['compatibility_tables'] ?? [])),
            array_values((array) ($definition['ignored_tables'] ?? [])),
            array_values((array) ($definition['compatible_engines'] ?? [])),
            (array) ($definition['required_columns'] ?? []),
            (array) ($definition['runtime_constraints'] ?? []),
            (array) ($definition['connection_defaults'] ?? [])
        );
    }

    public function key(): string
    {
        return $this->profileKey;
    }

    public function owner(): ModuleId
    {
        return new ModuleId('judge_db');
    }

    public function contractVersion(): string
    {
        return $this->contractVersion;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    /**
     * @return list<string>
     */
    public function requiredTables(): array
    {
        return $this->requiredTableNames;
    }

    /**
     * @return list<string>
     */
    public function requiredCompatibilityTables(): array
    {
        return $this->requiredCompatibilityTableNames;
    }

    /**
     * @return list<string>
     */
    public function optionalTables(): array
    {
        return $this->optionalTableNames;
    }

    /**
     * @return list<string>
     */
    public function compatibilityTables(): array
    {
        return $this->compatibilityTableNames;
    }

    /**
     * @return list<string>
     */
    public function ignoredTables(): array
    {
        return $this->ignoredTableNames;
    }

    /**
     * @return list<string>
     */
    public function compatibleEngines(): array
    {
        return $this->compatibleEngineNames;
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    public function requiredColumns(): array
    {
        return $this->requiredColumnDefinitions;
    }

    /**
     * @return array<string, string>
     */
    public function runtimeConstraints(): array
    {
        return $this->runtimeConstraintValues;
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionDefaults(): array
    {
        return $this->defaultConnectionValues;
    }
}

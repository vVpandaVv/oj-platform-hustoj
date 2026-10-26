<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\RegistryDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Domain\SqlIdentifier;
use InvalidArgumentException;

final class SchemaTableDefinition implements RegistryDefinition
{
    /**
     * @var list<ColumnDefinition>
     */
    private readonly array $tableColumns;

    /**
     * @var list<IndexDefinition>
     */
    private readonly array $tableIndexes;

    /**
     * @var list<ForeignKeyDefinition>
     */
    private readonly array $tableForeignKeys;

    private readonly SqlIdentifier $tableName;

    /**
     * @param list<ColumnDefinition> $columns
     * @param list<IndexDefinition> $indexes
     * @param list<ForeignKeyDefinition> $foreignKeys
     */
    public function __construct(
        string $name,
        private readonly ModuleId $moduleOwner,
        private readonly string $schemaVersion,
        array $columns,
        array $indexes,
        array $foreignKeys = []
    ) {
        $this->tableName = new SqlIdentifier($name);

        if (preg_match('/^[1-9][0-9]*$/', $this->schemaVersion) !== 1) {
            throw new InvalidArgumentException('스키마 버전은 1 이상의 정수 문자열이어야 합니다.');
        }

        if ($columns === [] || $indexes === []) {
            throw new InvalidArgumentException('테이블에는 열과 인덱스가 필요합니다.');
        }

        $columnNames = array_map(
            static fn (ColumnDefinition $column): string => $column->name(),
            $columns
        );

        if (count(array_unique($columnNames)) !== count($columnNames)) {
            throw new InvalidArgumentException('테이블 열 이름이 중복되었습니다.');
        }

        $primaryCount = 0;
        $indexNames = [];
        foreach ($indexes as $index) {
            if ($index->isPrimary()) {
                ++$primaryCount;
            } elseif ($index->name() !== null) {
                $indexNames[] = $index->name();
            }

            foreach ($index->columns() as $column) {
                if (! in_array($column, $columnNames, true)) {
                    throw new InvalidArgumentException('인덱스가 존재하지 않는 열을 참조합니다.');
                }
            }
        }

        if ($primaryCount !== 1 || count(array_unique($indexNames)) !== count($indexNames)) {
            throw new InvalidArgumentException('테이블은 기본 키 하나와 중복되지 않은 인덱스 이름을 가져야 합니다.');
        }

        foreach ($foreignKeys as $foreignKey) {
            if (! in_array($foreignKey->column(), $columnNames, true)) {
                throw new InvalidArgumentException('외래 키가 존재하지 않는 열을 참조합니다.');
            }
        }

        $this->tableColumns = array_values($columns);
        $this->tableIndexes = array_values($indexes);
        $this->tableForeignKeys = array_values($foreignKeys);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['name'] ?? ''),
            new ModuleId((string) ($definition['owner_module'] ?? '')),
            (string) ($definition['schema_version'] ?? ''),
            array_map(
                static fn (array $column): ColumnDefinition => ColumnDefinition::fromArray($column),
                array_values((array) ($definition['columns'] ?? []))
            ),
            array_map(
                static fn (array $index): IndexDefinition => IndexDefinition::fromArray($index),
                array_values((array) ($definition['indexes'] ?? []))
            ),
            array_map(
                static fn (array $foreignKey): ForeignKeyDefinition => ForeignKeyDefinition::fromArray($foreignKey),
                array_values((array) ($definition['foreign_keys'] ?? []))
            )
        );
    }

    public function key(): string
    {
        return $this->tableName->value();
    }

    public function owner(): ModuleId
    {
        return $this->moduleOwner;
    }

    public function schemaVersion(): string
    {
        return $this->schemaVersion;
    }

    /**
     * @return list<ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->tableColumns;
    }

    /**
     * @return list<IndexDefinition>
     */
    public function indexes(): array
    {
        return $this->tableIndexes;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeys(): array
    {
        return $this->tableForeignKeys;
    }

    public function fingerprint(): string
    {
        $canonical = [
            'name' => $this->key(),
            'owner' => $this->owner()->value(),
            'version' => $this->schemaVersion(),
            'columns' => array_map(
                static fn (ColumnDefinition $column): array => $column->canonical(),
                $this->columns()
            ),
            'indexes' => array_map(
                static fn (IndexDefinition $index): array => $index->canonical(),
                $this->indexes()
            ),
            'foreign_keys' => array_map(
                static fn (ForeignKeyDefinition $foreignKey): array => $foreignKey->canonical(),
                $this->foreignKeys()
            ),
        ];

        return hash(
            'sha256',
            (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}

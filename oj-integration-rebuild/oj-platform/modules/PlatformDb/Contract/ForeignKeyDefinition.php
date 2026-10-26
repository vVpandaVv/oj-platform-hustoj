<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Contract;

use CodingPanda\OjPlatform\Modules\PlatformDb\Domain\SqlIdentifier;
use InvalidArgumentException;

final class ForeignKeyDefinition
{
    private readonly SqlIdentifier $constraintName;

    private readonly SqlIdentifier $columnName;

    private readonly SqlIdentifier $referencedTableName;

    private readonly SqlIdentifier $referencedColumnName;

    public function __construct(
        string $name,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        private readonly string $onDelete,
        private readonly string $onUpdate
    ) {
        $this->constraintName = new SqlIdentifier($name);
        $this->columnName = new SqlIdentifier($column);
        $this->referencedTableName = new SqlIdentifier($referencedTable);
        $this->referencedColumnName = new SqlIdentifier($referencedColumn);

        foreach ([$this->onDelete, $this->onUpdate] as $action) {
            if (! in_array($action, ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'], true)) {
                throw new InvalidArgumentException('외래 키 동작이 올바르지 않습니다.');
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            (string) ($definition['name'] ?? ''),
            (string) ($definition['column'] ?? ''),
            (string) ($definition['referenced_table'] ?? ''),
            (string) ($definition['referenced_column'] ?? ''),
            strtoupper((string) ($definition['on_delete'] ?? 'RESTRICT')),
            strtoupper((string) ($definition['on_update'] ?? 'RESTRICT'))
        );
    }

    public function name(): string
    {
        return $this->constraintName->value();
    }

    public function column(): string
    {
        return $this->columnName->value();
    }

    public function referencedTable(): string
    {
        return $this->referencedTableName->value();
    }

    public function referencedColumn(): string
    {
        return $this->referencedColumnName->value();
    }

    public function onDelete(): string
    {
        return $this->onDelete;
    }

    public function onUpdate(): string
    {
        return $this->onUpdate;
    }

    /**
     * @return array<string, string>
     */
    public function canonical(): array
    {
        return [
            'name' => $this->name(),
            'column' => $this->column(),
            'referenced_table' => $this->referencedTable(),
            'referenced_column' => $this->referencedColumn(),
            'on_delete' => $this->onDelete(),
            'on_update' => $this->onUpdate(),
        ];
    }
}

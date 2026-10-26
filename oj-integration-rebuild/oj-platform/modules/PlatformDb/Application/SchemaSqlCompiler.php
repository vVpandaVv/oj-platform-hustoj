<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Application;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ColumnDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ForeignKeyDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\IndexDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaCompiler;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Domain\SqlIdentifier;

final class SchemaSqlCompiler implements SchemaCompiler
{
    public function __construct(private readonly SchemaOwnershipMarker $ownershipMarker)
    {
    }

    public function compileCreate(
        SchemaTableDefinition $schema,
        string $installationId
    ): string {
        return $this->compileTable($schema, $installationId, true);
    }

    public function compileSynchronize(
        SchemaTableDefinition $schema,
        string $installationId
    ): string {
        return $this->compileTable($schema, $installationId, false);
    }

    public function compileOwnershipUpdate(
        SchemaTableDefinition $schema,
        string $installationId
    ): string {
        return sprintf(
            'ALTER TABLE %s COMMENT=%s',
            (new SqlIdentifier($schema->key()))->quoted(),
            $this->quoteString(
                $this->ownershipMarker->create($installationId, $schema)
            )
        );
    }

    private function compileTable(
        SchemaTableDefinition $schema,
        string $installationId,
        bool $includeForeignKeys
    ): string {
        $parts = [];

        foreach ($schema->columns() as $column) {
            $parts[] = $this->compileColumn($column);
        }

        foreach ($schema->indexes() as $index) {
            $parts[] = $this->compileIndex($index);
        }

        if ($includeForeignKeys) {
            foreach ($schema->foreignKeys() as $foreignKey) {
                $parts[] = $this->compileForeignKey($foreignKey);
            }
        }

        $comment = $this->quoteString(
            $this->ownershipMarker->create($installationId, $schema)
        );

        return sprintf(
            "CREATE TABLE %s (\n  %s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=%s",
            (new SqlIdentifier($schema->key()))->quoted(),
            implode(",\n  ", $parts),
            $comment
        );
    }

    private function compileColumn(ColumnDefinition $column): string
    {
        $parts = [
            $column->quotedName(),
            $column->type(),
            $column->isNullable() ? 'NULL' : 'NOT NULL',
        ];

        if ($column->defaultExpression() !== null) {
            $parts[] = 'DEFAULT ' . $column->defaultExpression();
        } elseif ($column->hasDefault()) {
            $parts[] = 'DEFAULT ' . $this->compileDefault($column->defaultValue());
        }

        if ($column->isAutoIncrement()) {
            $parts[] = 'AUTO_INCREMENT';
        }

        return implode(' ', $parts);
    }

    private function compileIndex(IndexDefinition $index): string
    {
        $columns = implode(
            ', ',
            array_map(
                static fn (string $column): string => (new SqlIdentifier($column))->quoted(),
                $index->columns()
            )
        );

        if ($index->isPrimary()) {
            return sprintf('PRIMARY KEY (%s)', $columns);
        }

        $prefix = $index->isUnique() ? 'UNIQUE KEY' : 'KEY';

        return sprintf(
            '%s %s (%s)',
            $prefix,
            (new SqlIdentifier((string) $index->name()))->quoted(),
            $columns
        );
    }

    private function compileForeignKey(ForeignKeyDefinition $foreignKey): string
    {
        return sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            (new SqlIdentifier($foreignKey->name()))->quoted(),
            (new SqlIdentifier($foreignKey->column()))->quoted(),
            (new SqlIdentifier($foreignKey->referencedTable()))->quoted(),
            (new SqlIdentifier($foreignKey->referencedColumn()))->quoted(),
            $foreignKey->onDelete(),
            $foreignKey->onUpdate()
        );
    }

    private function compileDefault(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->quoteString((string) $value);
    }

    private function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

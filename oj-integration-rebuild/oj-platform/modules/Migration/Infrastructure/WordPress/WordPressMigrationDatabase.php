<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use DomainException;
use RuntimeException;

final class WordPressMigrationDatabase
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }
    public function isAvailable(): bool
    {
        foreach (['prepare', 'query', 'get_row', 'get_results', 'get_var', 'get_col', 'esc_like'] as $method) {
            if (! method_exists($this->database, $method)) {
                return false;
            }
        }

        return true;
    }


    public function name(): string
    {
        return isset($this->database->dbname) ? (string) $this->database->dbname : '';
    }

    public function prefix(): string
    {
        $prefix = isset($this->database->prefix) ? (string) $this->database->prefix : '';

        return preg_match('/^[a-zA-Z0-9_]*$/', $prefix) === 1 ? $prefix : '';
    }

    /**
     * @return array{exists:bool,rows:int,engine:string,collation:string,comment:string,column_fingerprint:string}
     */
    public function inspect(string $table): array
    {
        $table = $this->identifier($table);
        $this->assertDatabase();
        $status = $this->database->get_row($this->database->prepare(
            'SELECT ENGINE, TABLE_COLLATION, TABLE_COMMENT, TABLE_ROWS '
            . 'FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
            $this->name(),
            $table
        ), 'ARRAY_A');
        if (! is_array($status)) {
            return [
                'exists' => false,
                'rows' => 0,
                'engine' => '',
                'collation' => '',
                'comment' => '',
                'column_fingerprint' => hash('sha256', 'missing:' . $table),
            ];
        }

        return [
            'exists' => true,
            'rows' => $this->count($table),
            'engine' => (string) ($status['ENGINE'] ?? ''),
            'collation' => (string) ($status['TABLE_COLLATION'] ?? ''),
            'comment' => (string) ($status['TABLE_COMMENT'] ?? ''),
            'column_fingerprint' => hash('sha256', implode('|', $this->columns($table))),
        ];
    }

    public function isLegacySource(string $table, string $sourceKey, string $targetTable): bool
    {
        $table = $this->identifier($table);
        $this->assertDatabase();
        $status = $this->database->get_row($this->database->prepare(
            'SELECT TABLE_COMMENT FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
            $this->name(), $table
        ), 'ARRAY_A');
        if (! is_array($status)) { return false; }
        if ($table === $targetTable && str_starts_with((string) ($status['TABLE_COMMENT'] ?? ''), 'oj-platform|')) {
            return false;
        }
        return in_array($sourceKey, $this->columns($table), true);
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        $table = $this->identifier($table);
        $this->assertDatabase();
        $rows = $this->database->get_results(
            'SHOW COLUMNS FROM ' . $this->quote($table),
            'ARRAY_A'
        );
        if (! is_array($rows)) {
            throw new RuntimeException('이전 테이블 열을 확인하지 못했습니다.');
        }

        $columns = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['Field'] ?? null)) {
                $columns[] = $this->identifier($row['Field']);
            }
        }

        return $columns;
    }

    /** @return list<string> */
    public function tablesLike(string $prefix): array
    {
        $this->assertDatabase();
        if (preg_match('/^[a-zA-Z0-9_]+$/', $prefix) !== 1) {
            throw new DomainException('이전 테이블 검색 접두사가 올바르지 않습니다.');
        }
        $rows = $this->database->get_col($this->database->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
            $this->name(),
            $this->database->esc_like($prefix) . '%'
        ));

        return is_array($rows)
            ? array_values(array_filter(array_map('strval', $rows)))
            : [];
    }

    public function count(string $table): int
    {
        $table = $this->identifier($table);
        $value = $this->database->get_var('SELECT COUNT(*) FROM ' . $this->quote($table));

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** @param array<string, mixed> $row */
    public function contains(
        string $table,
        SchemaTableDefinition $schema,
        array $row
    ): bool {
        $table = $this->identifier($table);
        $normalized = $this->normalizeRow($schema, $row);
        $primary = [];
        foreach ($schema->indexes() as $index) {
            if (! $index->isPrimary()) {
                continue;
            }
            foreach ($index->columns() as $column) {
                if (! array_key_exists($column, $normalized)
                    || $normalized[$column] === null) {
                    throw new DomainException('대상 행의 기본 키를 확인할 수 없습니다.');
                }
                $primary[] = $column;
            }
            break;
        }
        if ($primary === []) {
            throw new DomainException('목표 스키마의 기본 키를 확인할 수 없습니다.');
        }
        $where = [];
        foreach ($normalized as $column => $value) {
            $where[] = $this->quote($column) . '<=>' . $this->literal($value);
        }
        $value = $this->database->get_var(sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s',
            $this->quote($table),
            implode(' AND ', $where)
        ));

        return is_numeric($value) && (int) $value === 1;
    }

    /**
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    public function batch(
        string $table,
        array $columns,
        string $orderColumn,
        int $offset,
        int $limit
    ): array {
        $table = $this->identifier($table);
        $orderColumn = $this->identifier($orderColumn);
        if ($columns === [] || $offset < 0 || $limit < 1 || $limit > 1000) {
            throw new DomainException('이전 데이터 묶음 요청이 올바르지 않습니다.');
        }
        $select = implode(', ', array_map(
            fn (string $column): string => $this->quote($this->identifier($column)),
            array_values(array_unique($columns))
        ));
        $sql = sprintf(
            'SELECT %s FROM %s ORDER BY %s ASC LIMIT %d OFFSET %d',
            $select,
            $this->quote($table),
            $this->quote($orderColumn),
            $limit,
            $offset
        );
        $rows = $this->database->get_results($sql, 'ARRAY_A');

        return is_array($rows)
            ? array_values(array_filter($rows, 'is_array'))
            : [];
    }

    public function activeNonMigrationJobs(): int
    {
        $state = $this->inspect('background_jobs');
        if (! $state['exists']) {
            return 0;
        }
        $value = $this->database->get_var(
            "SELECT COUNT(*) FROM `background_jobs` "
            . "WHERE `status` IN ('queued', 'running') "
            . "AND `job_type` <> 'platform_migration'"
        );

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public function execute(string $sql): void
    {
        $this->assertDatabase();
        if ($this->database->query($sql) === false) {
            throw new RuntimeException('데이터 이전 SQL 작업을 완료하지 못했습니다.');
        }
    }

    /** @param array<string, mixed> $row */
    public function upsert(
        string $table,
        SchemaTableDefinition $schema,
        array $row
    ): void {
        $table = $this->identifier($table);
        $normalized = $this->normalizeRow($schema, $row);
        if ($normalized === []) {
            throw new DomainException('이전할 대상 행이 비어 있습니다.');
        }
        $primary = [];
        foreach ($schema->indexes() as $index) {
            if ($index->isPrimary()) {
                $primary = $index->columns();
                break;
            }
        }
        $names = array_keys($normalized);
        $updates = array_values(array_diff($names, $primary));
        if ($updates === []) {
            $updates = $primary;
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->quote($table),
            implode(', ', array_map(fn (string $name): string => $this->quote($name), $names)),
            implode(', ', array_map(fn (mixed $value): string => $this->literal($value), $normalized)),
            implode(', ', array_map(
                fn (string $name): string => $this->quote($name) . '=VALUES(' . $this->quote($name) . ')',
                $updates
            ))
        );
        $this->execute($sql);
    }

    public function rename(string $from, string $to): void
    {
        $this->execute(sprintf(
            'RENAME TABLE %s TO %s',
            $this->quote($this->identifier($from)),
            $this->quote($this->identifier($to))
        ));
    }

    public function renamePair(
        string $source,
        string $sourceAlias,
        string $target,
        string $targetName
    ): void {
        $this->execute(sprintf(
            'RENAME TABLE %s TO %s, %s TO %s',
            $this->quote($this->identifier($source)),
            $this->quote($this->identifier($sourceAlias)),
            $this->quote($this->identifier($target)),
            $this->quote($this->identifier($targetName))
        ));
    }

    public function setComment(string $table, string $comment): void
    {
        $table = $this->identifier($table);

        $this->execute(sprintf(
            'ALTER TABLE %s COMMENT=%s',
            $this->quote($table),
            (string) $this->database->prepare('%s', $comment)
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(SchemaTableDefinition $schema, array $row): array
    {
        $normalized = [];
        foreach ($schema->columns() as $column) {
            $name = $column->name();
            if (array_key_exists($name, $row)) {
                if ($row[$name] === null && ! $column->isNullable()) {
                    throw new DomainException($name . ' 필수값이 비어 있습니다.');
                }
                $normalized[$name] = $row[$name];
                continue;
            }
            if ($column->isAutoIncrement() || $column->hasDefault()
                || $column->defaultExpression() !== null) {
                continue;
            }
            if ($column->isNullable()) {
                $normalized[$name] = null;
                continue;
            }
            throw new DomainException($name . ' 필수값을 변환하지 못했습니다.');
        }

        return $normalized;
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value) || is_int($value)) {
            return (string) (int) $value;
        }
        if (is_float($value)) {
            return sprintf('%.14F', $value);
        }

        return (string) $this->database->prepare('%s', (string) $value);
    }

    private function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    private function identifier(string $value): string
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $value) !== 1) {
            throw new DomainException('허용되지 않은 데이터베이스 식별자입니다.');
        }

        return $value;
    }

    private function assertDatabase(): void
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }
    }
}

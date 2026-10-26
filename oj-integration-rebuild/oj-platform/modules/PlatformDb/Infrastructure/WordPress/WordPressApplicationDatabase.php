<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use RuntimeException;

final class WordPressApplicationDatabase implements ApplicationDatabase
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function row(string $sql, array $values = []): ?array
    {
        $this->assertMethod('get_row');
        $row = $this->database->get_row(
            $this->prepare($sql, $values),
            'ARRAY_A'
        );

        return is_array($row) ? $row : null;
    }

    public function rows(string $sql, array $values = []): array
    {
        $this->assertMethod('get_results');
        $rows = $this->database->get_results(
            $this->prepare($sql, $values),
            'ARRAY_A'
        );

        return is_array($rows)
            ? array_values(array_filter($rows, 'is_array'))
            : [];
    }

    public function value(string $sql, array $values = []): mixed
    {
        $this->assertMethod('get_var');

        return $this->database->get_var($this->prepare($sql, $values));
    }

    public function insert(
        string $table,
        array $data,
        array $formats = []
    ): int {
        $this->assertTable($table);
        $this->assertMethod('insert');
        $result = $this->database->insert(
            $table,
            $data,
            $formats === [] ? null : $formats
        );
        if ($result === false) {
            throw new RuntimeException('플랫폼 데이터를 추가하지 못했습니다.');
        }

        return (int) ($this->database->insert_id ?? 0);
    }

    public function update(
        string $table,
        array $data,
        array $where,
        array $formats = [],
        array $whereFormats = []
    ): int {
        $this->assertTable($table);
        $this->assertMethod('update');
        $result = $this->database->update(
            $table,
            $data,
            $where,
            $formats === [] ? null : $formats,
            $whereFormats === [] ? null : $whereFormats
        );
        if ($result === false) {
            throw new RuntimeException('플랫폼 데이터를 수정하지 못했습니다.');
        }

        return (int) $result;
    }

    public function delete(
        string $table,
        array $where,
        array $whereFormats = []
    ): int {
        $this->assertTable($table);
        $this->assertMethod('delete');
        $result = $this->database->delete(
            $table,
            $where,
            $whereFormats === [] ? null : $whereFormats
        );
        if ($result === false) {
            throw new RuntimeException('플랫폼 데이터를 삭제하지 못했습니다.');
        }

        return (int) $result;
    }

    public function beginTransaction(): void
    {
        $this->execute('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->execute('COMMIT');
    }

    public function rollBack(): void
    {
        $this->execute('ROLLBACK');
    }

    /**
     * @param list<mixed> $values
     */
    private function prepare(string $sql, array $values): string
    {
        if ($values === []) {
            return $sql;
        }
        $this->assertMethod('prepare');

        return (string) $this->database->prepare($sql, ...$values);
    }

    private function execute(string $sql): void
    {
        $this->assertMethod('query');
        if ($this->database->query($sql) === false) {
            throw new RuntimeException('데이터베이스 트랜잭션을 처리하지 못했습니다.');
        }
    }

    private function assertMethod(string $method): void
    {
        if (! method_exists($this->database, $method)) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }
    }

    private function assertTable(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new RuntimeException('허용되지 않은 테이블 이름입니다.');
        }
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress;

use RuntimeException;

final class SubmissionPlatformDatabase
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    /**
     * @param list<mixed> $values
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $values = []): ?array
    {
        $this->assertMethod('get_row');
        $row = $this->database->get_row(
            $this->prepare($sql, $values),
            'ARRAY_A'
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $values
     * @return list<array<string, mixed>>
     */
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

    /**
     * @param array<string, mixed> $data
     * @param list<string> $formats
     */
    public function insert(
        string $table,
        array $data,
        array $formats = []
    ): int {
        $this->assertMethod('insert');
        $result = $this->database->insert(
            $table,
            $data,
            $formats === [] ? null : $formats
        );
        if ($result === false) {
            throw new RuntimeException('플랫폼 제출 데이터를 추가하지 못했습니다.');
        }

        return (int) ($this->database->insert_id ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @param list<string> $formats
     * @param list<string> $whereFormats
     */
    public function update(
        string $table,
        array $data,
        array $where,
        array $formats = [],
        array $whereFormats = []
    ): int {
        $this->assertMethod('update');
        $result = $this->database->update(
            $table,
            $data,
            $where,
            $formats === [] ? null : $formats,
            $whereFormats === [] ? null : $whereFormats
        );
        if ($result === false) {
            throw new RuntimeException('플랫폼 제출 데이터를 수정하지 못했습니다.');
        }

        return (int) $result;
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $this->assertMethod('delete');
        $result = $this->database->delete($table, $where);
        if ($result === false) {
            throw new RuntimeException('플랫폼 제출 데이터를 삭제하지 못했습니다.');
        }

        return (int) $result;
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

    private function assertMethod(string $method): void
    {
        if (! method_exists($this->database, $method)) {
            throw new RuntimeException('WordPress 데이터베이스 연결을 사용할 수 없습니다.');
        }
    }
}

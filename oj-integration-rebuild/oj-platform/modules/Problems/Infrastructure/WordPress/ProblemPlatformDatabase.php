<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use RuntimeException;
use Throwable;

final class ProblemPlatformDatabase
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
    public function insert(string $table, array $data, array $formats = []): int
    {
        $this->assertMethod('insert');
        $result = $this->database->insert(
            $table,
            $data,
            $formats === [] ? null : $formats
        );
        if ($result === false) {
            throw $this->databaseFailure(
                '플랫폼 문제 데이터를 추가하지 못했습니다.'
            );
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
    ): void {
        $this->assertMethod('update');
        $result = $this->database->update(
            $table,
            $data,
            $where,
            $formats === [] ? null : $formats,
            $whereFormats === [] ? null : $whereFormats
        );
        if ($result === false) {
            throw $this->databaseFailure(
                '플랫폼 문제 데이터를 수정하지 못했습니다.'
            );
        }
    }

    /**
     * @param array<string, mixed> $where
     * @param list<string> $formats
     */
    public function delete(string $table, array $where, array $formats = []): void
    {
        $this->assertMethod('delete');
        $result = $this->database->delete(
            $table,
            $where,
            $formats === [] ? null : $formats
        );
        if ($result === false) {
            throw $this->databaseFailure(
                '플랫폼 문제 데이터를 삭제하지 못했습니다.'
            );
        }
    }

    /**
     * @param list<mixed> $values
     */
    public function execute(string $sql, array $values = []): void
    {
        $this->assertMethod('query');
        $result = $this->database->query($this->prepare($sql, $values));
        if ($result === false) {
            throw $this->databaseFailure(
                '플랫폼 문제 데이터 명령을 실행하지 못했습니다.'
            );
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $this->execute('START TRANSACTION');
        try {
            $result = $operation();
            $this->execute('COMMIT');

            return $result;
        } catch (Throwable $error) {
            try {
                $this->execute('ROLLBACK');
            } catch (Throwable) {
            }

            throw $error;
        }
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

    private function databaseFailure(string $message): RuntimeException
    {
        $databaseError = $this->database->last_error ?? null;
        if (! is_string($databaseError) || trim($databaseError) === '') {
            return new RuntimeException($message);
        }
        $normalized = preg_replace(
            '/[\x00-\x20\x7f]+/u',
            ' ',
            trim($databaseError)
        );
        if (! is_string($normalized) || $normalized === '') {
            return new RuntimeException($message);
        }
        if (strlen($normalized) > 1000) {
            $normalized = substr($normalized, 0, 1000);
        }

        return new RuntimeException(
            $message . ' 데이터베이스 오류: ' . $normalized
        );
    }
}

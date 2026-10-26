<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthTransaction;
use RuntimeException;

final class WordPressAuthTransaction implements AuthTransaction
{
    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;
        if (! is_object($database)) {
            $database = new \stdClass();
        }

        return new self($database);
    }

    public function begin(): void
    {
        $this->execute('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->execute('COMMIT');
    }

    public function rollBack(): void
    {
        if (! method_exists($this->database, 'query')) {
            return;
        }

        $this->database->query('ROLLBACK');
    }

    private function execute(string $statement): void
    {
        if (
            ! method_exists($this->database, 'query')
            || $this->database->query($statement) === false
        ) {
            throw new RuntimeException('계정 데이터 트랜잭션을 실행하지 못했습니다.');
        }
    }
}

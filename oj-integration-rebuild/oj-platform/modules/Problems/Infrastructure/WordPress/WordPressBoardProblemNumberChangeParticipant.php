<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberMap;
use RuntimeException;
use Throwable;

final class WordPressBoardProblemNumberChangeParticipant implements ProblemNumberChangeParticipant
{
    private string $postmetaTable;

    public function __construct(private readonly object $database)
    {
        $postmeta = $this->database->postmeta ?? null;
        $this->postmetaTable = is_string($postmeta)
            ? $postmeta
            : (string) (($this->database->prefix ?? 'wp_') . 'postmeta');
        if (preg_match('/^[A-Za-z0-9_]+$/', $this->postmetaTable) !== 1) {
            throw new RuntimeException('WordPress postmeta 테이블 이름이 올바르지 않습니다.');
        }
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function key(): string
    {
        return 'wordpress';
    }

    public function inspect(ProblemNumberMap $mapping): array
    {
        $this->assertDatabase();

        return [
            'board_contexts' => $this->countForIds($mapping->sourceIds()),
        ];
    }

    public function move(ProblemNumberMap $mapping): void
    {
        $this->assertDatabase();
        $this->query('START TRANSACTION');
        try {
            foreach ($mapping->pairs() as $source => $target) {
                $sql = $this->database->prepare(
                    "UPDATE `{$this->postmetaTable}` AS context_id
                    INNER JOIN `{$this->postmetaTable}` AS context_type
                      ON context_type.post_id = context_id.post_id
                     AND context_type.meta_key = '_oj_board_context_type'
                     AND context_type.meta_value = 'problem'
                    SET context_id.meta_value = %s
                    WHERE context_id.meta_key = '_oj_board_context_id'
                      AND context_id.meta_value = %s",
                    (string) $target,
                    (string) $source
                );
                $this->query((string) $sql);
            }
            $this->query('COMMIT');
        } catch (Throwable $error) {
            try {
                $this->query('ROLLBACK');
            } catch (Throwable) {
            }
            throw $error;
        }
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('post_meta');
        }
    }

    public function verifyMoved(ProblemNumberMap $mapping): void
    {
        if ($this->countForIds($mapping->sourceIds()) > 0) {
            throw new RuntimeException(
                'WordPress 문제 게시판 문맥에 이전 문제 번호가 남아 있습니다.'
            );
        }
    }

    /**
     * @param list<int> $ids
     */
    private function countForIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $values = array_map('strval', $ids);
        $sql = $this->database->prepare(
            "SELECT COUNT(*)
            FROM `{$this->postmetaTable}` AS context_id
            INNER JOIN `{$this->postmetaTable}` AS context_type
              ON context_type.post_id = context_id.post_id
             AND context_type.meta_key = '_oj_board_context_type'
             AND context_type.meta_value = 'problem'
            WHERE context_id.meta_key = '_oj_board_context_id'
              AND context_id.meta_value IN ({$placeholders})",
            ...$values
        );
        $value = $this->database->get_var($sql);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function query(string $sql): void
    {
        if ($this->database->query($sql) === false) {
            throw new RuntimeException('WordPress 문제 문맥 번호를 변경하지 못했습니다.');
        }
    }

    private function assertDatabase(): void
    {
        foreach (['prepare', 'get_var', 'query'] as $method) {
            if (! method_exists($this->database, $method)) {
                throw new RuntimeException('WordPress 문제 문맥 DB를 사용할 수 없습니다.');
            }
        }
    }
}

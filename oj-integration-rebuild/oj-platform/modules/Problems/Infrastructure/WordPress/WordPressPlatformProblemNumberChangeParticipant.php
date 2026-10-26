<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberMap;
use DomainException;
use JsonException;
use RuntimeException;
use Throwable;

final class WordPressPlatformProblemNumberChangeParticipant implements ProblemNumberChangeParticipant
{
    private const REFERENCES = [
        'class_problem_links' => 'problem_id',
        'contest_problem_settings' => 'problem_id',
        'problem_ownerships' => 'problem_id',
        'problem_collaborators' => 'problem_id',
        'problem_metadata' => 'problem_id',
        'problem_topic_links' => 'problem_id',
        'quiz_revisions' => 'problem_id',
        'quiz_attempt_summaries' => 'problem_id',
        'problem_book_category_items' => 'problem_id',
        'personal_problem_book_items' => 'problem_id',
    ];

    public function __construct(private readonly object $database)
    {
    }

    public static function fromGlobals(): self
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return new self(is_object($database) ? $database : new \stdClass());
    }

    public function key(): string
    {
        return 'platform';
    }

    public function inspect(ProblemNumberMap $mapping): array
    {
        $this->assertDatabase();
        $tables = $this->availableTables();
        $sourceSet = array_fill_keys($mapping->sourceIds(), true);
        $externalTargets = array_values(array_filter(
            $mapping->targetIds(),
            static fn (int $id): bool => ! isset($sourceSet[$id])
        ));
        $counts = [];
        foreach ($tables as $table => $column) {
            $counts[$table] = $this->countForIds(
                $table,
                $column,
                $mapping->sourceIds()
            );
            if (
                $externalTargets !== []
                && $this->countForIds($table, $column, $externalTargets) > 0
            ) {
                throw new DomainException(
                    '새 문제 번호에 연결된 플랫폼 데이터가 이미 있습니다.'
                );
            }
        }
        if (isset($tables['problem_metadata'])) {
            $counts['recommendation_links'] = $this->countStructuredLinks(
                $mapping
            );
        }

        return $counts;
    }

    public function move(ProblemNumberMap $mapping): void
    {
        $this->assertDatabase();
        $tables = $this->availableTables();
        $this->query('START TRANSACTION');
        try {
            if (isset($tables['problem_metadata'])) {
                $this->moveStructuredLinks($mapping);
            }
            foreach ($tables as $table => $column) {
                foreach ($mapping->pairs() as $source => $target) {
                    $sql = $this->database->prepare(
                        sprintf(
                            'UPDATE `%s` SET `%s` = %%d WHERE `%s` = %%d',
                            $table,
                            $column,
                            $column
                        ),
                        $target,
                        $source
                    );
                    $this->query((string) $sql);
                }
            }
            $this->query('COMMIT');
        } catch (Throwable $error) {
            try {
                $this->query('ROLLBACK');
            } catch (Throwable) {
            }
            throw $error;
        }
    }

    public function verifyMoved(ProblemNumberMap $mapping): void
    {
        $this->assertDatabase();
        $tables = $this->availableTables();
        foreach ($tables as $table => $column) {
            if ($this->countForIds($table, $column, $mapping->sourceIds()) > 0) {
                throw new RuntimeException(
                    '플랫폼 DB에 이전 문제 번호 참조가 남아 있습니다.'
                );
            }
        }
        if (
            isset($tables['problem_metadata'])
            && $this->countStructuredLinks($mapping) > 0
        ) {
            throw new RuntimeException(
                '플랫폼 추천 연결에 이전 문제 번호가 남아 있습니다.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function availableTables(): array
    {
        $available = [];
        foreach (self::REFERENCES as $table => $column) {
            $count = $this->scalar(
                'SELECT COUNT(*) FROM information_schema.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = DATABASE()'
                    . ' AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                [$table, $column]
            );
            if ($count > 0) {
                $available[$table] = $column;
            }
        }

        return $available;
    }

    /**
     * @param list<int> $ids
     */
    private function countForIds(
        string $table,
        string $column,
        array $ids
    ): int {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        return $this->scalar(
            sprintf(
                'SELECT COUNT(*) FROM `%s` WHERE `%s` IN (%s)',
                $table,
                $column,
                $placeholders
            ),
            $ids
        );
    }

    private function countStructuredLinks(ProblemNumberMap $mapping): int
    {
        $count = 0;
        foreach ($this->structuredRows() as $row) {
            $value = $this->decodeLinks((string) ($row['recommendation_links'] ?? ''));
            [, $changes] = $this->mapStructuredValue($value, $mapping, null);
            $count += $changes;
        }

        return $count;
    }

    private function moveStructuredLinks(ProblemNumberMap $mapping): void
    {
        foreach ($this->structuredRows() as $row) {
            $problemId = (int) ($row['problem_id'] ?? 0);
            $value = $this->decodeLinks((string) ($row['recommendation_links'] ?? ''));
            [$mapped, $changes] = $this->mapStructuredValue($value, $mapping, null);
            if ($changes === 0) {
                continue;
            }
            try {
                $json = json_encode(
                    $mapped,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            } catch (JsonException $error) {
                throw new DomainException(
                    '추천 문제 연결을 다시 직렬화하지 못했습니다.',
                    0,
                    $error
                );
            }
            $updated = $this->database->update(
                'problem_metadata',
                ['recommendation_links' => $json],
                ['problem_id' => $problemId],
                ['%s'],
                ['%d']
            );
            if ($updated === false) {
                throw new RuntimeException('추천 문제 번호 연결을 변경하지 못했습니다.');
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structuredRows(): array
    {
        $rows = $this->database->get_results(
            "SELECT problem_id, recommendation_links
            FROM problem_metadata
            WHERE recommendation_links IS NOT NULL
              AND recommendation_links <> ''",
            'ARRAY_A'
        );

        return is_array($rows)
            ? array_values(array_filter($rows, 'is_array'))
            : [];
    }

    private function decodeLinks(string $json): mixed
    {
        try {
            return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DomainException(
                '추천 문제 연결 JSON을 먼저 수정해야 번호를 변경할 수 있습니다.',
                0,
                $error
            );
        }
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    private function mapStructuredValue(
        mixed $value,
        ProblemNumberMap $mapping,
        ?string $parentKey
    ): array {
        if (! is_array($value)) {
            if (
                in_array(
                    $parentKey,
                    ['problem_id', 'recommended_problem_id', 'related_problem_id', 'problem_ids'],
                    true
                )
                && (is_int($value) || (is_string($value) && ctype_digit($value)))
            ) {
                $number = (int) $value;
                $target = $mapping->pairs()[$number] ?? null;
                if ($target !== null) {
                    return [is_string($value) ? (string) $target : $target, 1];
                }
            }

            return [$value, 0];
        }
        $mapped = [];
        $changes = 0;
        $rootList = $parentKey === null && array_is_list($value);
        foreach ($value as $key => $item) {
            $childKey = is_string($key)
                ? $key
                : ($rootList ? 'problem_ids' : $parentKey);
            [$mappedItem, $itemChanges] = $this->mapStructuredValue(
                $item,
                $mapping,
                $childKey
            );
            $mapped[$key] = $mappedItem;
            $changes += $itemChanges;
        }

        return [$mapped, $changes];
    }

    /**
     * @param list<mixed> $values
     */
    private function scalar(string $sql, array $values = []): int
    {
        $prepared = $values === []
            ? $sql
            : $this->database->prepare($sql, ...$values);
        $value = $this->database->get_var($prepared);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function query(string $sql): void
    {
        if ($this->database->query($sql) === false) {
            throw new RuntimeException('플랫폼 문제 번호 변경 명령을 실행하지 못했습니다.');
        }
    }

    private function assertDatabase(): void
    {
        foreach (['prepare', 'get_var', 'get_results', 'update', 'query'] as $method) {
            if (! method_exists($this->database, $method)) {
                throw new RuntimeException('WordPress 플랫폼 DB 연결을 사용할 수 없습니다.');
            }
        }
    }
}

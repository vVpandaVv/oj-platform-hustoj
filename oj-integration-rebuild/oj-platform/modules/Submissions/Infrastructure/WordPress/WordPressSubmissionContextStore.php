<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use InvalidArgumentException;
use RuntimeException;

final class WordPressSubmissionContextStore implements SubmissionContextStore, \CodingPanda\OjPlatform\Modules\Submissions\Contract\CursorSubmissionContextStore
{
    private const TABLE = 'submission_contexts';

    public function __construct(
        private readonly SubmissionPlatformDatabase $database
    ) {
    }

    public function save(
        SubmissionId $submissionId,
        SubmissionContext $context
    ): void {
        if ($this->storedContext($submissionId) !== null) {
            throw new RuntimeException(
                '제출 문맥은 생성 후 변경할 수 없습니다.'
            );
        }
        if ($context->classId() === null && $context->groupId() === null) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'class_id' => $context->classId(),
            'group_id' => $context->groupId(),
            'updated_at' => $now,
        ];
        $this->database->insert(
            self::TABLE,
            ['submission_id' => $submissionId->value()]
                + $data
                + ['created_at' => $now]
        );
    }

    public function remove(SubmissionId $submissionId): void
    {
        $this->database->delete(
            self::TABLE,
            ['submission_id' => $submissionId->value()]
        );
    }

    public function findMany(array $submissionIds): array
    {
        $ids = [];
        foreach ($submissionIds as $submissionId) {
            if ($submissionId instanceof SubmissionId) {
                $ids[$submissionId->value()] = $submissionId->value();
            }
        }
        if ($ids === []) {
            return [];
        }

        $contexts = [];
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $rows = $this->database->rows(
                'SELECT submission_id, class_id, group_id FROM '
                    . self::TABLE . ' WHERE submission_id IN ('
                    . implode(', ', array_fill(0, count($chunk), '%d'))
                    . ')',
                $chunk
            );
            foreach ($rows as $row) {
                $submissionId = (int) ($row['submission_id'] ?? 0);
                if ($submissionId < 1) {
                    continue;
                }
                $contexts[$submissionId] = new SubmissionContext(
                    0,
                    -1,
                    $this->nullablePositiveInt($row['class_id'] ?? null),
                    $this->nullablePositiveInt($row['group_id'] ?? null)
                );
            }
        }

        return $contexts;
    }

    public function submissionIds(
        ?int $classId,
        ?int $groupId,
        int $limit,
        int $offset
    ): array {
        if (($classId === null) === ($groupId === null)) {
            throw new InvalidArgumentException(
                '수업 또는 그룹 제출 문맥 하나만 지정해야 합니다.'
            );
        }
        if ($limit < 1 || $limit > 1000 || $offset < 0) {
            throw new InvalidArgumentException('제출 문맥 조회 범위가 올바르지 않습니다.');
        }

        $column = $classId !== null ? 'class_id' : 'group_id';
        $value = $classId ?? $groupId;
        $rows = $this->database->rows(
            'SELECT submission_id FROM ' . self::TABLE
                . ' WHERE ' . $column . ' = %d'
                . ' ORDER BY submission_id DESC LIMIT %d OFFSET %d',
            [(int) $value, $limit, $offset]
        );

        return array_values(array_filter(array_map(
            static fn (array $row): int =>
                (int) ($row['submission_id'] ?? 0),
            $rows
        ), static fn (int $id): bool => $id > 0));
    }

    public function submissionIdsBefore(?int $classId, ?int $groupId, int $limit, ?int $beforeId): array
    {
        if (($classId === null) === ($groupId === null) || $limit < 1 || $limit > 1000 || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('제출 문맥 커서가 올바르지 않습니다.');
        }
        $column = $classId !== null ? 'class_id' : 'group_id';
        $values = [(int) ($classId ?? $groupId)];
        $where = ' WHERE ' . $column . ' = %d';
        if ($beforeId !== null) { $where .= ' AND submission_id < %d'; $values[] = $beforeId; }
        $values[] = $limit;
        $rows = $this->database->rows('SELECT submission_id FROM ' . self::TABLE . $where
            . ' ORDER BY submission_id DESC LIMIT %d', $values);
        return array_values(array_map(static fn (array $row): int => (int) $row['submission_id'], $rows));
    }

    /** @return array<string, mixed>|null */
    private function storedContext(SubmissionId $submissionId): ?array
    {
        return $this->database->row(
            'SELECT class_id, group_id FROM ' . self::TABLE
                . ' WHERE submission_id = %d LIMIT 1',
            [$submissionId->value()]
        );
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}

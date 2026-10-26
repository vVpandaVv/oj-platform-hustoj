<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemBookRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\PersonalProblemBookItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBook;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBookVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;
use RuntimeException;

final class WordPressProblemBookRepository implements ProblemBookRepository
{
    public function __construct(private readonly ProblemPlatformDatabase $database)
    {
    }

    public function publicBooks(): array
    {
        return $this->books(
            'WHERE c.`deleted_at` IS NULL AND c.`visibility` = %s',
            [ProblemBookVisibility::Members->value]
        );
    }

    public function managedBooks(): array
    {
        return $this->books('WHERE c.`deleted_at` IS NULL');
    }

    public function find(int $bookId): ?ProblemBook
    {
        if ($bookId < 1) {
            return null;
        }
        $books = $this->books(
            'WHERE c.`id` = %d AND c.`deleted_at` IS NULL',
            [$bookId]
        );

        return $books[0] ?? null;
    }

    public function save(
        ?int $bookId,
        ?int $parentId,
        string $name,
        string $slug,
        string $description,
        ProblemBookVisibility $visibility,
        int $sortOrder,
        array $problemIds,
        int $actorUserId
    ): ProblemBook {
        if ($actorUserId < 1) {
            throw new DomainException('문제집 작성 사용자를 확인할 수 없습니다.');
        }
        $candidate = new ProblemBook(
            $bookId ?? 0,
            $parentId,
            $name,
            $slug,
            $description,
            $visibility,
            $sortOrder,
            $actorUserId,
            '',
            '',
            $problemIds
        );
        $savedId = $this->database->transaction(function () use (
            $candidate,
            $bookId,
            $actorUserId
        ): int {
            $this->assertParent($candidate->parentId(), $bookId);
            $now = gmdate('Y-m-d H:i:s');
            if ($bookId === null) {
                $savedId = $this->database->insert('problem_book_categories', [
                    'parent_id' => $candidate->parentId(),
                    'name' => $candidate->name(),
                    'slug' => $candidate->slug(),
                    'description' => $candidate->description(),
                    'visibility' => $candidate->visibility()->value,
                    'sort_order' => $candidate->sortOrder(),
                    'created_by_user_id' => $actorUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            } else {
                $existing = $this->database->row(
                    'SELECT `id` FROM `problem_book_categories`'
                        . ' WHERE `id` = %d AND `deleted_at` IS NULL FOR UPDATE',
                    [$bookId]
                );
                if ($existing === null) {
                    throw new DomainException('수정할 공용 문제집을 찾을 수 없습니다.');
                }
                $savedId = $bookId;
                $this->database->update(
                    'problem_book_categories',
                    [
                        'parent_id' => $candidate->parentId(),
                        'name' => $candidate->name(),
                        'slug' => $candidate->slug(),
                        'description' => $candidate->description(),
                        'visibility' => $candidate->visibility()->value,
                        'sort_order' => $candidate->sortOrder(),
                        'updated_at' => $now,
                    ],
                    ['id' => $savedId]
                );
                $this->database->delete(
                    'problem_book_category_items',
                    ['category_id' => $savedId]
                );
            }
            foreach ($candidate->problemIds() as $index => $problemId) {
                $this->database->insert('problem_book_category_items', [
                    'category_id' => $savedId,
                    'problem_id' => $problemId->value(),
                    'sort_order' => $index + 1,
                    'created_by_user_id' => $actorUserId,
                    'created_at' => $now,
                ]);
            }

            return $savedId;
        });

        return $this->find($savedId)
            ?? throw new RuntimeException('저장한 공용 문제집을 찾을 수 없습니다.');
    }

    public function delete(int $bookId): void
    {
        $this->database->transaction(function () use ($bookId): void {
            $existing = $this->database->row(
                'SELECT `id` FROM `problem_book_categories`'
                    . ' WHERE `id` = %d AND `deleted_at` IS NULL FOR UPDATE',
                [$bookId]
            );
            if ($existing === null) {
                throw new DomainException('삭제할 공용 문제집을 찾을 수 없습니다.');
            }
            $child = $this->database->row(
                'SELECT `id` FROM `problem_book_categories`'
                    . ' WHERE `parent_id` = %d AND `deleted_at` IS NULL LIMIT 1',
                [$bookId]
            );
            if ($child !== null) {
                throw new DomainException('하위 문제집을 먼저 이동하거나 삭제해 주세요.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $this->database->update(
                'problem_book_categories',
                ['deleted_at' => $now, 'updated_at' => $now],
                ['id' => $bookId]
            );
        });
    }

    public function personalItems(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $rows = $this->database->rows(
            'SELECT `id`, `user_id`, `problem_id`, `note`, `sort_order`,'
                . ' `created_at`, `updated_at` FROM `personal_problem_book_items`'
                . ' WHERE `user_id` = %d ORDER BY `sort_order`, `id`',
            [$userId]
        );

        return array_map(
            fn (array $row): PersonalProblemBookItem =>
                $this->hydratePersonal($row),
            $rows
        );
    }

    public function hasPersonalItem(int $userId, ProblemId $problemId): bool
    {
        return $this->database->row(
            'SELECT `id` FROM `personal_problem_book_items`'
                . ' WHERE `user_id` = %d AND `problem_id` = %d',
            [$userId, $problemId->value()]
        ) !== null;
    }

    public function addPersonalItem(
        int $userId,
        ProblemId $problemId,
        string $note
    ): void {
        if (
            $userId < 1
            || strlen($note) > 65535
            || preg_match('//u', $note) !== 1
        ) {
            throw new DomainException('개인 문제집 항목 형식이 올바르지 않습니다.');
        }
        $this->database->transaction(function () use (
            $userId,
            $problemId,
            $note
        ): void {
            $existing = $this->database->row(
                'SELECT `id` FROM `personal_problem_book_items`'
                    . ' WHERE `user_id` = %d AND `problem_id` = %d FOR UPDATE',
                [$userId, $problemId->value()]
            );
            $now = gmdate('Y-m-d H:i:s');
            if ($existing !== null) {
                $this->database->update(
                    'personal_problem_book_items',
                    ['note' => $note, 'updated_at' => $now],
                    ['id' => (int) ($existing['id'] ?? 0)]
                );

                return;
            }
            $order = $this->database->row(
                'SELECT COALESCE(MAX(`sort_order`), 0) + 1 AS `next_order`'
                    . ' FROM `personal_problem_book_items` WHERE `user_id` = %d',
                [$userId]
            );
            $this->database->insert('personal_problem_book_items', [
                'user_id' => $userId,
                'problem_id' => $problemId->value(),
                'note' => $note,
                'sort_order' => (int) ($order['next_order'] ?? 1),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function removePersonalItem(
        int $userId,
        ProblemId $problemId
    ): void {
        if ($userId < 1) {
            throw new DomainException('개인 문제집 사용자를 확인할 수 없습니다.');
        }
        $this->database->delete(
            'personal_problem_book_items',
            ['user_id' => $userId, 'problem_id' => $problemId->value()]
        );
    }

    /**
     * @param list<mixed> $values
     * @return list<ProblemBook>
     */
    private function books(string $where, array $values = []): array
    {
        $rows = $this->database->rows(
            'SELECT c.`id`, c.`parent_id`, c.`name`, c.`slug`, c.`description`,'
                . ' c.`visibility`, c.`sort_order`, c.`created_by_user_id`,'
                . ' c.`created_at`, c.`updated_at`,'
                . ' i.`problem_id`, i.`sort_order` AS `item_order`'
                . ' FROM `problem_book_categories` c'
                . ' LEFT JOIN `problem_book_category_items` i'
                . ' ON i.`category_id` = c.`id` ' . $where
                . ' ORDER BY c.`sort_order`, c.`name`, c.`id`, i.`sort_order`, i.`id`',
            $values
        );
        $grouped = [];
        foreach ($rows as $row) {
            $bookId = (int) ($row['id'] ?? 0);
            if (! isset($grouped[$bookId])) {
                $grouped[$bookId] = ['row' => $row, 'problem_ids' => []];
            }
            $problemId = (int) ($row['problem_id'] ?? 0);
            if ($problemId > 0) {
                $grouped[$bookId]['problem_ids'][] = new ProblemId($problemId);
            }
        }
        $books = [];
        foreach ($grouped as $item) {
            $row = $item['row'];
            $visibility = ProblemBookVisibility::tryFrom(
                (string) ($row['visibility'] ?? '')
            );
            if ($visibility === null) {
                throw new RuntimeException('저장된 문제집 공개 범위가 올바르지 않습니다.');
            }
            $books[] = new ProblemBook(
                (int) ($row['id'] ?? 0),
                $this->nullablePositiveInt($row['parent_id'] ?? null),
                (string) ($row['name'] ?? ''),
                (string) ($row['slug'] ?? ''),
                (string) ($row['description'] ?? ''),
                $visibility,
                (int) ($row['sort_order'] ?? 0),
                (int) ($row['created_by_user_id'] ?? 0),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
                $item['problem_ids']
            );
        }

        return $books;
    }

    private function assertParent(?int $parentId, ?int $bookId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($bookId !== null && $parentId === $bookId) {
            throw new DomainException('문제집을 자기 자신의 하위로 둘 수 없습니다.');
        }
        $parent = $this->database->row(
            'SELECT `id`, `parent_id` FROM `problem_book_categories`'
                . ' WHERE `id` = %d AND `deleted_at` IS NULL',
            [$parentId]
        );
        if ($parent === null || $this->nullablePositiveInt($parent['parent_id'] ?? null) !== null) {
            throw new DomainException('상위 문제집은 최상위 항목이어야 합니다.');
        }
        if ($bookId !== null) {
            $child = $this->database->row(
                'SELECT `id` FROM `problem_book_categories`'
                    . ' WHERE `parent_id` = %d AND `deleted_at` IS NULL LIMIT 1',
                [$bookId]
            );
            if ($child !== null) {
                throw new DomainException(
                    '하위 문제집이 있는 항목은 다른 문제집 아래로 이동할 수 없습니다.'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePersonal(array $row): PersonalProblemBookItem
    {
        return new PersonalProblemBookItem(
            (int) ($row['id'] ?? 0),
            (int) ($row['user_id'] ?? 0),
            new ProblemId((int) ($row['problem_id'] ?? 0)),
            (string) ($row['note'] ?? ''),
            (int) ($row['sort_order'] ?? 0),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? '')
        );
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}

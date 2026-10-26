<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemBook
{
    /**
     * @param list<ProblemId> $problemIds
     */
    public function __construct(
        private readonly int $id,
        private readonly ?int $parentId,
        private readonly string $name,
        private readonly string $slug,
        private readonly string $description,
        private readonly ProblemBookVisibility $visibility,
        private readonly int $sortOrder,
        private readonly int $createdByUserId,
        private readonly string $createdAt,
        private readonly string $updatedAt,
        private readonly array $problemIds
    ) {
        if (
            $this->id < 0
            || ($this->parentId !== null && $this->parentId < 1)
            || trim($this->name) === ''
            || strlen($this->name) > 191
            || preg_match('//u', $this->name) !== 1
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slug) !== 1
            || strlen($this->slug) > 191
            || strlen($this->description) > 65535
            || preg_match('//u', $this->description) !== 1
            || $this->sortOrder < 0
            || $this->createdByUserId < 1
            || count($this->problemIds) > 1000
        ) {
            throw new InvalidArgumentException('문제집 형식이 올바르지 않습니다.');
        }
        $ids = [];
        foreach ($this->problemIds as $problemId) {
            if (! $problemId instanceof ProblemId) {
                throw new InvalidArgumentException('문제집 문제 번호가 올바르지 않습니다.');
            }
            $ids[] = $problemId->value();
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('문제집에 같은 문제가 중복되었습니다.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function parentId(): ?int
    {
        return $this->parentId;
    }

    public function name(): string
    {
        return trim($this->name);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function visibility(): ProblemBookVisibility
    {
        return $this->visibility;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function createdByUserId(): int
    {
        return $this->createdByUserId;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    /**
     * @return list<ProblemId>
     */
    public function problemIds(): array
    {
        return $this->problemIds;
    }
}

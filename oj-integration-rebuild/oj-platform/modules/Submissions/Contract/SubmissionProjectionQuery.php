<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use DateTimeImmutable;
use InvalidArgumentException;

final class SubmissionProjectionQuery
{
    /**
     * @param list<string> $userLogins
     * @param list<int> $problemIds
     */
    public function __construct(
        private readonly array $userLogins = [],
        private readonly array $problemIds = [],
        private readonly ?int $contestId = null,
        private readonly ?int $classId = null,
        private readonly ?int $groupId = null,
        private readonly ?bool $valid = true,
        private readonly int $limit = 500,
        private readonly int $offset = 0,
        private readonly ?int $resultCode = null,
        private readonly ?string $submittedFrom = null,
        private readonly ?string $submittedUntil = null
    ) {
        if (
            ($this->contestId !== null && $this->contestId < 0)
            || ($this->classId !== null && $this->classId < 1)
            || $this->limit < 1
            || $this->limit > 1000
            || $this->offset < 0
        ) {
            throw new InvalidArgumentException('제출 보고서 조회 범위가 올바르지 않습니다.');
        }
        if (
            ! $this->validTimestamp($this->submittedFrom)
            || ! $this->validTimestamp($this->submittedUntil)
            || (
                $this->submittedFrom !== null
                && $this->submittedUntil !== null
                && $this->submittedFrom > $this->submittedUntil
            )
        ) {
            throw new InvalidArgumentException('제출 보고서 조회 기간이 올바르지 않습니다.');
        }
    }

    /**
     * @return list<string>
     */
    public function userLogins(): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', $this->userLogins),
            static fn (string $value): bool => $value !== ''
        )));
    }

    /**
     * @return list<int>
     */
    public function problemIds(): array
    {
        return array_values(array_unique(array_filter(
            $this->problemIds,
            static fn (mixed $value): bool => is_int($value) && $value > 0
        )));
    }

    public function contestId(): ?int
    {
        return $this->contestId;
    }

    public function classId(): ?int
    {
        return $this->classId;
    }

    public function groupId(): ?int
    {
        return $this->groupId;
    }

    public function valid(): ?bool
    {
        return $this->valid;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function resultCode(): ?int
    {
        return $this->resultCode;
    }

    public function submittedFrom(): ?string
    {
        return $this->submittedFrom;
    }

    public function submittedUntil(): ?string
    {
        return $this->submittedUntil;
    }

    private function validTimestamp(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }
}

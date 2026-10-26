<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

final class SubmissionManagedScope
{
    /**
     * @param list<int> $classIds
     * @param list<int> $groupIds
     * @param list<int> $contestIds
     */
    public function __construct(
        private readonly array $classIds = [],
        private readonly array $groupIds = [],
        private readonly array $contestIds = []
    ) {
    }

    /** @return list<int> */
    public function classIds(): array
    {
        return $this->positiveUnique($this->classIds);
    }

    /** @return list<int> */
    public function groupIds(): array
    {
        return $this->positiveUnique($this->groupIds);
    }

    /** @return list<int> */
    public function contestIds(): array
    {
        return $this->positiveUnique($this->contestIds);
    }

    public function isEmpty(): bool
    {
        return $this->classIds() === []
            && $this->groupIds() === []
            && $this->contestIds() === [];
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function positiveUnique(array $values): array
    {
        return array_values(array_unique(array_filter(
            $values,
            static fn (mixed $value): bool => is_int($value) && $value > 0
        )));
    }
}

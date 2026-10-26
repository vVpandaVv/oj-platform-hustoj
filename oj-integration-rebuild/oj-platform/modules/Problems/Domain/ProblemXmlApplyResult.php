<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemXmlApplyResult
{
    /**
     * @param list<ProblemXmlApplyRow> $rows
     */
    public function __construct(
        private readonly ?string $reference,
        private readonly array $rows
    ) {
        foreach ($this->rows as $row) {
            if (! $row instanceof ProblemXmlApplyRow) {
                throw new InvalidArgumentException('문제 XML 적용 결과가 올바르지 않습니다.');
            }
        }
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    /**
     * @return list<ProblemXmlApplyRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function createdCount(): int
    {
        return $this->countStatus(ProblemXmlApplyRow::CREATED);
    }

    public function updatedCount(): int
    {
        return $this->countStatus(ProblemXmlApplyRow::UPDATED);
    }

    public function failedCount(): int
    {
        return $this->countStatus(ProblemXmlApplyRow::FAILED);
    }

    private function countStatus(string $status): int
    {
        return count(array_filter(
            $this->rows,
            static fn (ProblemXmlApplyRow $row): bool => $row->status() === $status
        ));
    }
}

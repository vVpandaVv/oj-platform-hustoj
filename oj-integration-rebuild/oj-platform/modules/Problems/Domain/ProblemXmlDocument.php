<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemXmlDocument
{
    /**
     * @param list<ProblemXmlItem> $items
     * @param list<ProblemXmlIssue> $issues
     */
    public function __construct(
        private readonly array $items,
        private readonly array $issues
    ) {
        foreach ($this->items as $item) {
            if (! $item instanceof ProblemXmlItem) {
                throw new InvalidArgumentException('문제 XML 항목 목록이 올바르지 않습니다.');
            }
        }
        foreach ($this->issues as $issue) {
            if (! $issue instanceof ProblemXmlIssue) {
                throw new InvalidArgumentException('문제 XML 오류 목록이 올바르지 않습니다.');
            }
        }
    }

    /**
     * @return list<ProblemXmlItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return list<ProblemXmlIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }
}

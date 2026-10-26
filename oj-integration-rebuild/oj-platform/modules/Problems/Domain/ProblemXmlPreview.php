<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemXmlPreview
{
    /**
     * @param list<ProblemXmlPreviewRow> $rows
     * @param list<ProblemXmlIssue> $issues
     */
    public function __construct(
        private readonly string $reference,
        private readonly array $rows,
        private readonly array $issues,
        private readonly string $fallbackTopicKey,
        private readonly bool $allowOverwrite,
        private readonly bool $includeMetadata,
        private readonly bool $includeTestData
    ) {
        if (trim($this->reference) === '') {
            throw new InvalidArgumentException('문제 XML 미리보기 정보가 올바르지 않습니다.');
        }
        foreach ($this->rows as $row) {
            if (! $row instanceof ProblemXmlPreviewRow) {
                throw new InvalidArgumentException('문제 XML 미리보기 행 목록이 올바르지 않습니다.');
            }
        }
        foreach ($this->issues as $issue) {
            if (! $issue instanceof ProblemXmlIssue) {
                throw new InvalidArgumentException('문제 XML 미리보기 오류 목록이 올바르지 않습니다.');
            }
        }
    }

    public function reference(): string
    {
        return $this->reference;
    }

    /**
     * @return list<ProblemXmlPreviewRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<ProblemXmlIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function fallbackTopicKey(): string
    {
        return $this->fallbackTopicKey;
    }

    public function allowOverwrite(): bool
    {
        return $this->allowOverwrite;
    }

    public function includeMetadata(): bool
    {
        return $this->includeMetadata;
    }

    public function includeTestData(): bool
    {
        return $this->includeTestData;
    }

    public function selectableCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (ProblemXmlPreviewRow $row): bool => ! $row->blocked()
        ));
    }
}

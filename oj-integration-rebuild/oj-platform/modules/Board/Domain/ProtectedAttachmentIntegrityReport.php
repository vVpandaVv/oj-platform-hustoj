<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Domain;

use InvalidArgumentException;

final class ProtectedAttachmentIntegrityReport
{
    /**
     * @var list<ProtectedAttachmentIntegrityIssue>
     */
    private readonly array $issues;

    /**
     * @param list<ProtectedAttachmentIntegrityIssue> $issues
     */
    public function __construct(
        private readonly int $recordCount,
        private readonly int $fileCount,
        array $issues,
        private readonly bool $truncated
    ) {
        if ($this->recordCount < 0 || $this->fileCount < 0) {
            throw new InvalidArgumentException('보호 첨부 검사 집계가 올바르지 않습니다.');
        }
        foreach ($issues as $issue) {
            if (! $issue instanceof ProtectedAttachmentIntegrityIssue) {
                throw new InvalidArgumentException('보호 첨부 검사 결과 형식이 올바르지 않습니다.');
            }
        }
        $this->issues = array_values($issues);
    }

    public function recordCount(): int
    {
        return $this->recordCount;
    }

    public function fileCount(): int
    {
        return $this->fileCount;
    }

    /**
     * @return list<ProtectedAttachmentIntegrityIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function isHealthy(): bool
    {
        return $this->issues === [] && ! $this->truncated;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }
}

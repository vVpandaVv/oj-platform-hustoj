<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemXmlPreviewRow
{
    public const CREATE = 'create';
    public const UPDATE = 'update';

    public function __construct(
        private readonly int $index,
        private readonly ?ProblemId $targetProblemId,
        private readonly string $title,
        private readonly string $operation,
        private readonly bool $blocked,
        private readonly string $message,
        private readonly int $testPairCount,
        private readonly string $contentHash
    ) {
        if (
            $this->index < 0
            || trim($this->title) === ''
            || ! in_array($this->operation, [self::CREATE, self::UPDATE], true)
            || $this->testPairCount < 0
            || preg_match('/^[a-f0-9]{64}$/', $this->contentHash) !== 1
            || ($this->blocked && trim($this->message) === '')
        ) {
            throw new InvalidArgumentException('문제 XML 미리보기 행이 올바르지 않습니다.');
        }
    }

    public function index(): int
    {
        return $this->index;
    }

    public function targetProblemId(): ?ProblemId
    {
        return $this->targetProblemId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function blocked(): bool
    {
        return $this->blocked;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function testPairCount(): int
    {
        return $this->testPairCount;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }
}

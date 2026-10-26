<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemXmlApplyRow
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const FAILED = 'failed';

    public function __construct(
        private readonly int $index,
        private readonly ?ProblemId $problemId,
        private readonly string $title,
        private readonly string $status,
        private readonly string $message
    ) {
        if (
            $this->index < 0
            || trim($this->title) === ''
            || ! in_array(
                $this->status,
                [self::CREATED, self::UPDATED, self::FAILED],
                true
            )
            || ($this->status === self::FAILED && trim($this->message) === '')
        ) {
            throw new InvalidArgumentException('문제 XML 적용 결과 행이 올바르지 않습니다.');
        }
    }

    public function index(): int
    {
        return $this->index;
    }

    public function problemId(): ?ProblemId
    {
        return $this->problemId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }
}

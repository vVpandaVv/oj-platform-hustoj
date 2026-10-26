<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemXmlIssue
{
    public function __construct(
        private readonly int $index,
        private readonly ?int $problemId,
        private readonly string $title,
        private readonly string $message
    ) {
        if ($this->index < 0 || trim($this->message) === '') {
            throw new InvalidArgumentException('문제 XML 오류 정보가 올바르지 않습니다.');
        }
    }

    public function index(): int
    {
        return $this->index;
    }

    public function problemId(): ?int
    {
        return $this->problemId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function message(): string
    {
        return $this->message;
    }
}

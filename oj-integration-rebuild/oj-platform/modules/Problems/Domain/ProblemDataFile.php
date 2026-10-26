<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class ProblemDataFile
{
    public function __construct(
        private readonly string $name,
        private readonly int $size,
        private readonly string $sha256,
        private readonly string $modifiedAt
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function modifiedAt(): string
    {
        return $this->modifiedAt;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    public function isEditable(): bool
    {
        return in_array(
            $this->extension(),
            ['in', 'out', 'ans', 'txt', 'c', 'cc', 'cpp', 'log'],
            true
        );
    }

    public function kindLabel(): string
    {
        return match ($this->extension()) {
            'in' => '입력',
            'out', 'ans' => '출력',
            'c', 'cc', 'cpp' => 'SPJ·생성기 소스',
            'zip' => 'ZIP',
            'log' => '로그',
            'txt' => '텍스트',
            default => $this->name === 'spj' ? 'SPJ 실행 파일' : '파일',
        };
    }
}

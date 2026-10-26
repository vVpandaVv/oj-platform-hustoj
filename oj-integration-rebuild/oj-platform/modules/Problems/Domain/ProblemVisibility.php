<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

enum ProblemVisibility: string
{
    case Published = 'published';
    case Archived = 'archived';

    public static function fromJudgeDefunct(string $value): self
    {
        return strtoupper(trim($value)) === 'Y'
            ? self::Archived
            : self::Published;
    }

    public function judgeDefunct(): string
    {
        return $this === self::Archived ? 'Y' : 'N';
    }
}

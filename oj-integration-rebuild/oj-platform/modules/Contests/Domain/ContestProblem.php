<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

final class ContestProblem
{
    public function __construct(
        private readonly int $problemId,
        private readonly int $number,
        private readonly float $score
    ) {
    }

    public function problemId(): int { return $this->problemId; }
    public function number(): int { return $this->number; }
    public function score(): float { return $this->score; }
    public function numberLabel(): string
    {
        $number = $this->number;
        $label = '';
        do {
            $label = chr(65 + ($number % 26)) . $label;
            $number = intdiv($number, 26) - 1;
        } while ($number >= 0);

        return $label;
    }
}

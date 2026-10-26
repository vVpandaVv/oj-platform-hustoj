<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class JudgeProblem
{
    public function __construct(
        private readonly ProblemId $id,
        private readonly string $title,
        private readonly string $description,
        private readonly string $input,
        private readonly string $output,
        private readonly string $sampleInput,
        private readonly string $sampleOutput,
        private readonly string $hint,
        private readonly string $source,
        private readonly int $timeLimitSeconds,
        private readonly int $memoryLimitMegabytes,
        private readonly bool $specialJudge,
        private readonly ProblemVisibility $visibility,
        private readonly int $accepted,
        private readonly int $submitted,
        private readonly string $forbiddenCode,
        private readonly string $prefixCode,
        private readonly string $starterCode,
        private readonly string $suffixCode,
        private readonly ?int $languageMask,
        private readonly string $credits = ''
    ) {
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('문제 제목은 비어 있을 수 없습니다.');
        }
        if ($this->timeLimitSeconds < 1 || $this->memoryLimitMegabytes < 1) {
            throw new InvalidArgumentException('시간·메모리 제한은 1 이상이어야 합니다.');
        }
        if ($this->accepted < 0 || $this->submitted < 0) {
            throw new InvalidArgumentException('문제 통계는 음수일 수 없습니다.');
        }
    }

    public function id(): ProblemId
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function input(): string
    {
        return $this->input;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function sampleInput(): string
    {
        return $this->sampleInput;
    }

    public function sampleOutput(): string
    {
        return $this->sampleOutput;
    }

    public function hint(): string
    {
        return $this->hint;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function timeLimitSeconds(): int
    {
        return $this->timeLimitSeconds;
    }

    public function memoryLimitMegabytes(): int
    {
        return $this->memoryLimitMegabytes;
    }

    public function specialJudge(): bool
    {
        return $this->specialJudge;
    }

    public function visibility(): ProblemVisibility
    {
        return $this->visibility;
    }

    public function accepted(): int
    {
        return $this->accepted;
    }

    public function submitted(): int
    {
        return $this->submitted;
    }

    public function passRate(): float
    {
        return $this->submitted === 0
            ? 0.0
            : round(($this->accepted / $this->submitted) * 100, 1);
    }

    public function forbiddenCode(): string
    {
        return $this->forbiddenCode;
    }

    /** @return list<string> */
    public function forbiddenWords(): array
    {
        $raw = preg_replace('/\R/u', "\n", $this->forbiddenCode) ?? $this->forbiddenCode;
        $words = [];
        $word = '';
        for ($index = 0, $length = strlen($raw); $index < $length; ++$index) {
            $character = $raw[$index];
            // Escape only delimiters and backslashes; preserve other literal sequences.
            if ($character === '\\' && in_array($raw[$index + 1] ?? '', ['/', '\\'], true)) {
                $word .= $raw[++$index];
            } elseif ($character === '/' || $character === "\n") {
                $words[] = $word;
                $word = '';
            } else {
                $word .= $character;
            }
        }
        $words[] = $word;

        return array_values(array_unique(array_filter(
            array_map('trim', $words),
            static fn (string $word): bool => $word !== ''
        )));
    }

    public function prefixCode(): string
    {
        return $this->prefixCode;
    }

    public function starterCode(): string
    {
        return $this->starterCode;
    }

    public function suffixCode(): string
    {
        return $this->suffixCode;
    }

    public function languageMask(): ?int
    {
        return $this->languageMask;
    }

    public function credits(): string
    {
        return $this->credits;
    }
}

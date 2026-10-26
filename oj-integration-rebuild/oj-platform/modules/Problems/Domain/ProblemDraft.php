<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemDraft
{
    public const DEFAULT_LANGUAGE_MASK = 4194303;
    public const MAX_LANGUAGE_MASK = 2147483647;
    public const MAX_FORBIDDEN_CODE_CHARACTERS = 100;
    public const MAX_WRAPPER_CODE_BYTES = 65535;
    public function __construct(
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
        private readonly string $forbiddenCode,
        private readonly string $prefixCode,
        private readonly string $starterCode,
        private readonly string $suffixCode,
        private readonly ?int $languageMask,
        private readonly string $credits = ''
    ) {
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('문제 제목을 입력해 주세요.');
        }
        if ($this->timeLimitSeconds < 1 || $this->timeLimitSeconds > 3600) {
            throw new InvalidArgumentException('시간 제한은 1초부터 3600초까지 입력할 수 있습니다.');
        }
        if ($this->memoryLimitMegabytes < 1 || $this->memoryLimitMegabytes > 1048576) {
            throw new InvalidArgumentException('메모리 제한 범위가 올바르지 않습니다.');
        }
        $forbiddenLength = preg_match_all('/./us', $this->forbiddenCode);
        if ($forbiddenLength === false) {
            throw new InvalidArgumentException('제출 코드 정책은 UTF-8 문자여야 합니다.');
        }
        if ($forbiddenLength > self::MAX_FORBIDDEN_CODE_CHARACTERS) {
            throw new InvalidArgumentException('제출 금지어는 100자까지 입력할 수 있습니다.');
        }
        foreach ([$this->prefixCode, $this->starterCode, $this->suffixCode] as $code) {
            if (preg_match('//u', $code) !== 1 || strlen($code) > self::MAX_WRAPPER_CODE_BYTES) {
                throw new InvalidArgumentException('앞·시작·뒤 코드는 각각 65,535바이트까지 입력할 수 있습니다.');
            }
        }
        if (
            $this->languageMask !== null
            && ($this->languageMask < 0 || $this->languageMask > self::MAX_LANGUAGE_MASK)
        ) {
            throw new InvalidArgumentException('허용 언어 값의 범위가 올바르지 않습니다.');
        }
    }

    public function title(): string
    {
        return trim($this->title);
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

    public function forbiddenCode(): string
    {
        return $this->forbiddenCode;
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

    public function withVisibility(ProblemVisibility $visibility): self
    {
        return new self(
            $this->title,
            $this->description,
            $this->input,
            $this->output,
            $this->sampleInput,
            $this->sampleOutput,
            $this->hint,
            $this->source,
            $this->timeLimitSeconds,
            $this->memoryLimitMegabytes,
            $this->specialJudge,
            $visibility,
            $this->forbiddenCode,
            $this->prefixCode,
            $this->starterCode,
            $this->suffixCode,
            $this->languageMask,
            $this->credits
        );
    }

    public static function fromProblem(JudgeProblem $problem): self
    {
        return new self(
            $problem->title(),
            $problem->description(),
            $problem->input(),
            $problem->output(),
            $problem->sampleInput(),
            $problem->sampleOutput(),
            $problem->hint(),
            $problem->source(),
            $problem->timeLimitSeconds(),
            $problem->memoryLimitMegabytes(),
            $problem->specialJudge(),
            $problem->visibility(),
            $problem->forbiddenCode(),
            $problem->prefixCode(),
            $problem->starterCode(),
            $problem->suffixCode(),
            $problem->languageMask(),
            $problem->credits()
        );
    }
}

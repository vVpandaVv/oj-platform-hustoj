<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use InvalidArgumentException;

final class SubmissionCodePolicy
{
    /**
     * @param list<string> $forbiddenFragments
     */
    public function __construct(
        private readonly int $problemId,
        private readonly string $title,
        private readonly string $entryMode,
        private readonly array $forbiddenFragments,
        private readonly string $prefixCode,
        private readonly string $starterCode,
        private readonly string $suffixCode,
        private readonly ?int $disabledLanguageMask,
        private readonly ?string $completionSchema,
        private readonly ?string $completionHash,
        private readonly string $policyVersion,
        private readonly ?string $orderingSchema = null,
        private readonly ?string $orderingHash = null
    ) {
        if ($this->problemId < 1) {
            throw new InvalidArgumentException('문제 번호는 1 이상이어야 합니다.');
        }
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('문제 제목은 비어 있을 수 없습니다.');
        }
        if (! in_array($this->entryMode, ['full_code', 'code_completion', 'code_ordering'], true)) {
            throw new InvalidArgumentException('문제 코드 작성 방식이 올바르지 않습니다.');
        }
        if (
            $this->entryMode === 'code_completion'
            && ($this->completionSchema === null || $this->completionHash === null)
        ) {
            throw new InvalidArgumentException('빈칸 완성 정책에 스키마와 해시가 필요합니다.');
        }
        if ($this->entryMode === 'code_ordering'
            && ($this->orderingSchema === null || preg_match('/^[a-f0-9]{64}$/D', (string) $this->orderingHash) !== 1)) {
            throw new InvalidArgumentException('코드 배열 정책에 스키마와 해시가 필요합니다.');
        }
        foreach ($this->forbiddenFragments as $fragment) {
            if (! is_string($fragment) || trim($fragment) === '') {
                throw new InvalidArgumentException('제출 금지 코드 조각이 올바르지 않습니다.');
            }
        }
        if (preg_match('/^problem-code-policy-v1:[a-f0-9]{64}$/', $this->policyVersion) !== 1) {
            throw new InvalidArgumentException('문제 제출 정책 버전이 올바르지 않습니다.');
        }
    }

    public function problemId(): int
    {
        return $this->problemId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function entryMode(): string
    {
        return $this->entryMode;
    }

    /**
     * @return list<string>
     */
    public function forbiddenFragments(): array
    {
        return $this->forbiddenFragments;
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

    public function disabledLanguageMask(): ?int
    {
        return $this->disabledLanguageMask;
    }

    public function completionSchema(): ?string
    {
        return $this->completionSchema;
    }

    public function completionHash(): ?string
    {
        return $this->completionHash;
    }

    public function policyVersion(): string
    {
        return $this->policyVersion;
    }

    public function orderingSchema(): ?string { return $this->orderingSchema; }

    public function orderingHash(): ?string { return $this->orderingHash; }
}

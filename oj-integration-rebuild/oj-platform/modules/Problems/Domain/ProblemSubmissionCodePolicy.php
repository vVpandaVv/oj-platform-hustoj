<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class ProblemSubmissionCodePolicy
{
    /**
     * @param list<string> $forbiddenFragments
     */
    public function __construct(
        private readonly ProblemId $problemId,
        private readonly CodeEntryMode $entryMode,
        private readonly array $forbiddenFragments,
        private readonly string $prefixCode,
        private readonly string $starterCode,
        private readonly string $suffixCode,
        private readonly ?int $languageMask,
        private readonly ?string $completionSchema,
        private readonly ?string $completionHash,
        private readonly string $policyVersion,
        private readonly ?string $orderingSchema = null,
        private readonly ?string $orderingHash = null
    ) {
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function entryMode(): CodeEntryMode
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

    public function languageMask(): ?int
    {
        return $this->languageMask;
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

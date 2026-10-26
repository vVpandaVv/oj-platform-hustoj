<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;

final class SubmissionSourceRequest
{
    /**
     * @param array<string, string> $completionAnswers
     * @param list<string> $orderingBlockIds
     */
    public function __construct(
        private readonly SubmissionCodePolicy $policy,
        private readonly int $requestedLanguageId,
        private readonly string $source,
        private readonly string $templateHash,
        private readonly array $completionAnswers,
        private readonly string $blocklyWorkspace,
        private readonly string $blocklyProfile,
        private readonly array $orderingBlockIds = []
    ) {
    }

    public function policy(): SubmissionCodePolicy
    {
        return $this->policy;
    }

    public function requestedLanguageId(): int
    {
        return $this->requestedLanguageId;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function templateHash(): string
    {
        return $this->templateHash;
    }

    /**
     * @return array<string, string>
     */
    public function completionAnswers(): array
    {
        return $this->completionAnswers;
    }

    /** @return list<string> */
    public function orderingBlockIds(): array
    {
        return $this->orderingBlockIds;
    }

    public function blocklyWorkspace(): string
    {
        return $this->blocklyWorkspace;
    }

    public function blocklyProfile(): string
    {
        return $this->blocklyProfile;
    }
}

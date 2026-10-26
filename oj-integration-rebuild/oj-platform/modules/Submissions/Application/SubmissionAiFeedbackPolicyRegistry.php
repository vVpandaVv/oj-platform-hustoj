<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionAiFeedbackPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

final class SubmissionAiFeedbackPolicyRegistry
{
    /** @var list<SubmissionAiFeedbackPolicy> */
    private array $policies = [];

    public function register(SubmissionAiFeedbackPolicy $policy): void
    {
        $this->policies[] = $policy;
    }

    public function allows(SubmissionSummary $submission): bool
    {
        foreach ($this->policies as $policy) {
            if ($policy->supports($submission) && ! $policy->allows($submission)) {
                return false;
            }
        }

        return true;
    }
}

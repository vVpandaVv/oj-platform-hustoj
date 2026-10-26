<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionAiFeedbackPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

final class ContestSubmissionAiFeedbackPolicy implements SubmissionAiFeedbackPolicy
{
    public function __construct(
        private readonly ContestSubmissionAccess $contests
    ) {
    }

    public function supports(SubmissionSummary $submission): bool
    {
        return $submission->contestId() > 0;
    }

    public function allows(SubmissionSummary $submission): bool
    {
        return $this->contests->allowsAiFeedback($submission->contestId());
    }
}

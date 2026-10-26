<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

interface SubmissionAiFeedbackPolicy
{
    public function supports(SubmissionSummary $submission): bool;

    public function allows(SubmissionSummary $submission): bool;
}

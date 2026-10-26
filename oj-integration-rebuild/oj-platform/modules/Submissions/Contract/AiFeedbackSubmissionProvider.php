<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface AiFeedbackSubmissionProvider
{
    public function findForActor(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId
    ): ?AiFeedbackSubmission;
}

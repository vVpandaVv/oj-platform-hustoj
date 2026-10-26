<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionDetailExtension
{
    public function key(): string;

    public function render(
        int $actorUserId,
        string $actorUserLogin,
        AiFeedbackSubmission $submission
    ): string;
}

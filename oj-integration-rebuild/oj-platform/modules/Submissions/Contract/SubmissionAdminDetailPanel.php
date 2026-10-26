<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionAdminDetailPanel
{
    public function renderAdmin(int $actorUserId, string $actorUserLogin, int $submissionId): string;
}

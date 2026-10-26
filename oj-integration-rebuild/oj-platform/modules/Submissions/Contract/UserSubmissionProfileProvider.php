<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\UserSubmissionProfile;

interface UserSubmissionProfileProvider
{
    public function find(string $userLogin, int $recentLimit = 5): UserSubmissionProfile;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface UserSubmissionPreferenceProvider
{
    public function forUser(int $userId): UserSubmissionPreferences;
}

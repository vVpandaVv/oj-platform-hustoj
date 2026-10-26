<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;

interface UserPreferenceRepository
{
    public function find(int $userId): ?UserPreferences;

    public function save(UserPreferences $preferences): void;
}

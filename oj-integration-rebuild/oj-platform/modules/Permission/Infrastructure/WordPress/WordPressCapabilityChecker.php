<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker;

final class WordPressCapabilityChecker implements CapabilityChecker
{
    public function userCan(int $userId, string $capability): bool
    {
        return function_exists('user_can') && user_can($userId, $capability);
    }

    public function isSuperAdmin(int $userId): bool
    {
        return function_exists('is_super_admin') && is_super_admin($userId);
    }
}

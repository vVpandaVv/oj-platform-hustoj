<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface FixedWindowAttemptLimiter
{
    public function consumeFixedWindow(string $scope, string $identifier, int $limit, int $windowSeconds): bool;
}

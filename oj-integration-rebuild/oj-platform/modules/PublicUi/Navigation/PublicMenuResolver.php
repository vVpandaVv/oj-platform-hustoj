<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Navigation;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;

final class PublicMenuResolver
{
    public function __construct(
        private readonly PublicMenuSettingsService $settings
    ) {
    }

    /**
     * @return list<PublicMenuItem>
     */
    public function resolve(UserIdentity $user): array
    {
        return $this->settings->resolve($user);
    }
}

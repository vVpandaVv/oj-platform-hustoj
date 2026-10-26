<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\AccountLinkProvider;

final class WordPressAccountLinkProvider implements AccountLinkProvider
{
    private const LOGOUT_ACTION = 'oj_logout';

    public function __construct(
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function logoutUrl(string $redirectPath = '/login'): string
    {
        unset($redirectPath);

        return $this->actionUrls->actionUrl();
    }

    public function logoutFields(): string
    {
        return $this->security->hiddenFields(self::LOGOUT_ACTION);
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Contract;

interface AccountLinkProvider
{
    public function logoutUrl(string $redirectPath = '/login'): string;

    public function logoutFields(): string;
}

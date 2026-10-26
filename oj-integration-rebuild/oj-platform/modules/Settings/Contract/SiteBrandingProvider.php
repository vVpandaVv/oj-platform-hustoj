<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface SiteBrandingProvider
{
    public function serviceName(): string;

    public function shortDescription(): string;

    public function supportLine(): string;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface ServerInstallationLink
{
    public function url(): ?string;
}

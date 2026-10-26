<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\SpjSandboxProfile;

interface SpjSandboxProfileProvider
{
    public function current(): SpjSandboxProfile;
}

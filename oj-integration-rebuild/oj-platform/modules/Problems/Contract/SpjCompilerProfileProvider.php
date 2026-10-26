<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\SpjCompilerProfile;

interface SpjCompilerProfileProvider
{
    public function forSource(string $sourceName): SpjCompilerProfile;
}

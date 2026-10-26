<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemImage;

/**
 * Publishes problem text images and returns an absolute public URL.
 */
interface ProblemImageStore
{
    public function store(ProblemImage $image): string;
}

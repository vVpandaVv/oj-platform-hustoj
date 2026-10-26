<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

interface SetupReadinessProbe
{
    /**
     * @return list<string>
     */
    public function blockers(): array;
}

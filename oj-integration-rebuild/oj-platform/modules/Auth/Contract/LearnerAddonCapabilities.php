<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface LearnerAddonCapabilities
{
    /** @return list<string> WordPress capability keys declared for learners by accepted addons. */
    public function capabilityKeys(): array;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface FeatureDisableGuard
{
    public function featureKey(): string;

    /** @return list<string> */
    public function blockers(): array;
}

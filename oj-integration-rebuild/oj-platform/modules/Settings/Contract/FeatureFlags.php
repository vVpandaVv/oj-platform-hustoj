<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface FeatureFlags
{
    public function isEnabled(string $featureKey): bool;
}

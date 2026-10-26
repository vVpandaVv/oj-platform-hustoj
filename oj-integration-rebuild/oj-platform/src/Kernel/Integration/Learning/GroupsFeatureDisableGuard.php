<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDisableGuard;

final class GroupsFeatureDisableGuard implements FeatureDisableGuard
{
    public function __construct(
        private readonly ClassService $classes,
        private readonly ContestService $contests
    ) {
    }

    public function featureKey(): string
    {
        return 'groups';
    }

    public function blockers(): array
    {
        return array_values(array_merge(
            $this->classes->groupFeatureBlockers(),
            $this->contests->groupFeatureBlockers()
        ));
    }
}

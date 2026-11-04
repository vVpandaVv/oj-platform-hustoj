<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAddonCapabilities;

/** @internal */
final class AcceptedAddonLearnerCapabilities implements LearnerAddonCapabilities
{
    public function __construct(private readonly AcceptedAddonCatalog $catalog)
    {
    }

    public function capabilityKeys(): array
    {
        // No addon declaration is trusted until the registration validation completes.
        if (! $this->catalog->isFrozen()) {
            return [];
        }

        $keys = [];
        foreach ($this->catalog->accepted() as $entry) {
            foreach ($entry->manifest()?->capabilities() ?? [] as $capability) {
                if ($capability->audience() === 'learner') {
                    $keys[] = AddonCapabilityKeys::capabilityKey($capability->key());
                }
            }
        }

        return array_values(array_unique($keys));
    }
}

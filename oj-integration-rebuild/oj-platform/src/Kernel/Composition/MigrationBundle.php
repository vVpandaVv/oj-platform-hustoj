<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationJobAccessRule;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyInstallationPresence;
use CodingPanda\OjPlatform\Modules\Migration\Module;

final class MigrationBundle
{
    /** @param array<string, object> $services */
    public function __construct(
        private readonly Module $module,
        private readonly MigrationJobAccessRule $jobAccessRule,
        private readonly LegacyInstallationPresence $legacyPresence,
        private readonly array $services
    ) {
    }

    public function module(): Module
    {
        return $this->module;
    }

    public function jobAccessRule(): MigrationJobAccessRule
    {
        return $this->jobAccessRule;
    }

    public function legacyPresence(): LegacyInstallationPresence
    {
        return $this->legacyPresence;
    }

    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }
}

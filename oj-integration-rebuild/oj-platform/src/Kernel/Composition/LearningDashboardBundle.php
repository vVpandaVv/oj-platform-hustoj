<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\LearningDashboard\Module;

final class LearningDashboardBundle
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly Module $module,
        private readonly array $services
    ) {
    }

    public function module(): Module
    {
        return $this->module;
    }

    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessPolicy;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobController;

final class JobsUiBundle
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly JobController $controlService,
        private readonly array $services
    ) {
    }

    public function controlService(): JobController
    {
        return $this->controlService;
    }

    public function access(): JobAccessPolicy
    {
        $access = $this->services[JobAccessPolicy::class] ?? null;
        if (! $access instanceof JobAccessPolicy) {
            throw new \LogicException('Jobs 접근 정책 서비스가 없습니다.');
        }

        return $access;
    }

    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }
}

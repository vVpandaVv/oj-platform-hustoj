<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\Messaging\Application\MessageReportService;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingService;
use CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingSettingsService;
use CodingPanda\OjPlatform\Modules\Messaging\Module;

final class MessagingBundle
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

    public function service(): MessagingService
    {
        return $this->typedService(MessagingService::class);
    }

    public function reportService(): MessageReportService
    {
        return $this->typedService(MessageReportService::class);
    }

    public function settingsService(): MessagingSettingsService
    {
        return $this->typedService(MessagingSettingsService::class);
    }

    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $serviceId
     * @return T
     */
    private function typedService(string $serviceId): object
    {
        $service = $this->services[$serviceId] ?? null;
        if (! is_object($service) || ! $service instanceof $serviceId) {
            throw new \LogicException('Messaging 조립 서비스가 없습니다: ' . $serviceId);
        }

        return $service;
    }
}

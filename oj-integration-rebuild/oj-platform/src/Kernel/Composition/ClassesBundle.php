<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Module;

final class ClassesBundle
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

    public function service(): ClassService
    {
        return $this->typedService(ClassService::class);
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
            throw new \LogicException('Classes 조립 서비스가 없습니다: ' . $serviceId);
        }

        return $service;
    }
}
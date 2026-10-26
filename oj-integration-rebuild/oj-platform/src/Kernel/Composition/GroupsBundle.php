<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\Groups\Application\GroupBulkMemberImportJobAccessRule;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Module;

final class GroupsBundle
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

    public function service(): GroupService
    {
        return $this->typedService(GroupService::class);
    }

    public function jobAccessRule(): GroupBulkMemberImportJobAccessRule
    {
        return $this->typedService(GroupBulkMemberImportJobAccessRule::class);
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
            throw new \LogicException('Groups 조립 서비스가 없습니다: ' . $serviceId);
        }

        return $service;
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;

final class BuildContainer
{
    /**
     * @var array<string, object>
     */
    private array $services = [];

    private bool $frozen = false;

    public function bind(string $serviceId, object $service): void
    {
        if ($this->frozen) {
            throw KernelException::frozenContainer();
        }

        $serviceId = trim($serviceId);
        if ($serviceId === '') {
            throw KernelException::invalidServiceId();
        }

        if (isset($this->services[$serviceId])) {
            throw KernelException::duplicateService($serviceId);
        }

        $this->services[$serviceId] = $service;
    }

    public function has(string $serviceId): bool
    {
        return isset($this->services[$serviceId]);
    }

    public function get(string $serviceId): object
    {
        if (! isset($this->services[$serviceId])) {
            throw KernelException::missingService($serviceId);
        }

        return $this->services[$serviceId];
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}

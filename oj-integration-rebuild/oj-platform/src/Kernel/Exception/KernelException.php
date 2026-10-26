<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Exception;

use LogicException;

final class KernelException extends LogicException
{
    public static function invalidModuleId(string $moduleId): self
    {
        return new self(sprintf('Invalid module id: %s', $moduleId));
    }

    public static function invalidModuleDefinition(string $reason): self
    {
        return new self(sprintf('Invalid module definition: %s', $reason));
    }

    public static function duplicateModule(string $moduleId): self
    {
        return new self(sprintf('Duplicate module id: %s', $moduleId));
    }

    public static function missingModule(string $moduleId): self
    {
        return new self(sprintf('Module is not registered: %s', $moduleId));
    }

    public static function missingModuleDependency(string $moduleId, string $dependencyId): self
    {
        return new self(sprintf(
            'Module %s requires missing module %s.',
            $moduleId,
            $dependencyId
        ));
    }

    /**
     * @param list<string> $cycle
     */
    public static function circularModuleDependency(array $cycle): self
    {
        return new self(sprintf('Circular module dependency: %s', implode(' -> ', $cycle)));
    }

    public static function duplicateDefinition(string $registry, string $key): self
    {
        return new self(sprintf('Duplicate definition %s in registry %s.', $key, $registry));
    }

    public static function frozenRegistry(string $registry): self
    {
        return new self(sprintf('Registry is frozen: %s', $registry));
    }

    public static function invalidRegistryType(string $registry, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Registry %s expects %s, received %s.',
            $registry,
            $expected,
            $actual
        ));
    }

    public static function invalidRegistry(string $reason): self
    {
        return new self(sprintf('Invalid registry: %s', $reason));
    }

    public static function duplicateRegistry(string $registry): self
    {
        return new self(sprintf('Duplicate registry name: %s', $registry));
    }

    public static function frozenRegistrySet(): self
    {
        return new self('Registry set is already frozen.');
    }

    public static function duplicateService(string $serviceId): self
    {
        return new self(sprintf('Duplicate build service: %s', $serviceId));
    }

    public static function invalidServiceId(): self
    {
        return new self('Build service id cannot be empty.');
    }

    public static function missingService(string $serviceId): self
    {
        return new self(sprintf('Build service is not registered: %s', $serviceId));
    }

    public static function frozenContainer(): self
    {
        return new self('Build container is frozen.');
    }

    public static function invalidBootPhase(string $expected, string $actual): self
    {
        return new self(sprintf('Invalid boot phase. Expected %s, received %s.', $expected, $actual));
    }

    public static function bootAlreadyAttempted(): self
    {
        return new self('Kernel boot can be attempted only once.');
    }
}

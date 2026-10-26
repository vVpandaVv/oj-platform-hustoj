<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Registry;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;

final class RegistrySet
{
    /**
     * @var array<string, FreezableRegistry>
     */
    private array $registries = [];

    /**
     * @var list<RegistrySetValidator>
     */
    private readonly array $validators;

    private bool $frozen = false;

    /**
     * @param list<FreezableRegistry> $registries
     * @param list<RegistrySetValidator> $validators
     */
    public function __construct(array $registries, array $validators = [])
    {
        foreach ($registries as $registry) {
            if (isset($this->registries[$registry->name()])) {
                throw KernelException::duplicateRegistry($registry->name());
            }

            if ($registry->isFrozen()) {
                throw KernelException::invalidRegistry(sprintf(
                    'Registry %s was frozen before it entered the set.',
                    $registry->name()
                ));
            }

            $this->registries[$registry->name()] = $registry;
        }

        $this->validators = array_values($validators);
    }

    public function validateAndFreeze(): void
    {
        if ($this->frozen) {
            throw KernelException::frozenRegistrySet();
        }

        foreach ($this->registries as $registry) {
            $registry->validate();
        }

        foreach ($this->validators as $validator) {
            $validator->validate();
        }

        foreach ($this->registries as $registry) {
            $registry->freeze();
        }

        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * @return array<string, FreezableRegistry>
     */
    public function registries(): array
    {
        return $this->registries;
    }
}

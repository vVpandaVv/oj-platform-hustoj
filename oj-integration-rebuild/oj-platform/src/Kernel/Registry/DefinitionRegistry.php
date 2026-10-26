<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Registry;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;

/**
 * @template TDefinition of RegistryDefinition
 */
final class DefinitionRegistry implements FreezableRegistry
{
    /**
     * @var array<string, TDefinition>
     */
    private array $definitions = [];

    private bool $frozen = false;

    /**
     * @param class-string<TDefinition> $definitionClass
     */
    public function __construct(
        private readonly string $registryName,
        private readonly string $definitionClass
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $this->registryName) !== 1) {
            throw KernelException::invalidRegistry(sprintf(
                'Invalid registry name: %s',
                $this->registryName
            ));
        }

        if (! is_a($this->definitionClass, RegistryDefinition::class, true)) {
            throw KernelException::invalidRegistry(sprintf(
                '%s must implement %s.',
                $this->definitionClass,
                RegistryDefinition::class
            ));
        }
    }

    public function name(): string
    {
        return $this->registryName;
    }

    public function register(RegistryDefinition $definition): void
    {
        if ($this->frozen) {
            throw KernelException::frozenRegistry($this->registryName);
        }

        if (! $definition instanceof $this->definitionClass) {
            throw KernelException::invalidRegistryType(
                $this->registryName,
                $this->definitionClass,
                $definition::class
            );
        }

        $key = trim($definition->key());
        if ($key === '') {
            throw KernelException::invalidRegistry(sprintf(
                'Registry %s received an empty definition key.',
                $this->registryName
            ));
        }

        if (isset($this->definitions[$key])) {
            throw KernelException::duplicateDefinition($this->registryName, $key);
        }

        $this->definitions[$key] = $definition;
    }

    /**
     * @return TDefinition|null
     */
    public function find(string $key): ?RegistryDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return array<string, TDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    public function validate(): void
    {
        foreach ($this->definitions as $key => $definition) {
            if ($definition->key() !== $key) {
                throw KernelException::invalidRegistry(sprintf(
                    'Registry %s key changed after registration: %s',
                    $this->registryName,
                    $key
                ));
            }
        }
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

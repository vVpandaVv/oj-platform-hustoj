<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Module;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;

final class ModuleCatalog
{
    /**
     * @var array<string, Module>
     */
    private array $modules = [];

    /**
     * @var array<string, ModuleDefinition>
     */
    private array $definitions = [];

    /**
     * @param list<Module> $modules
     */
    public function __construct(array $modules)
    {
        foreach ($modules as $module) {
            $definition = $module->definition();
            $moduleId = $definition->id()->value();

            if (isset($this->modules[$moduleId])) {
                throw KernelException::duplicateModule($moduleId);
            }

            $this->modules[$moduleId] = $module;
            $this->definitions[$moduleId] = $definition;
        }
    }

    public function has(ModuleId|string $moduleId): bool
    {
        $key = $moduleId instanceof ModuleId ? $moduleId->value() : $moduleId;

        return isset($this->modules[$key]);
    }

    public function module(ModuleId|string $moduleId): Module
    {
        $key = $moduleId instanceof ModuleId ? $moduleId->value() : $moduleId;

        if (! isset($this->modules[$key])) {
            throw KernelException::missingModule($key);
        }

        return $this->modules[$key];
    }

    public function definition(ModuleId|string $moduleId): ModuleDefinition
    {
        $key = $moduleId instanceof ModuleId ? $moduleId->value() : $moduleId;

        if (! isset($this->definitions[$key])) {
            throw KernelException::missingModule($key);
        }

        return $this->definitions[$key];
    }

    /**
     * @return array<string, Module>
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * @return array<string, ModuleDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }
}

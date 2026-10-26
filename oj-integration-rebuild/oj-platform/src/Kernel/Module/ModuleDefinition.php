<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Module;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;

final class ModuleDefinition
{
    /**
     * @var list<ModuleId>
     */
    private readonly array $requiredDependencies;

    /**
     * @var list<ModuleId>
     */
    private readonly array $optionalDependencies;

    /**
     * @param list<ModuleId|string> $requiredDependencies
     * @param list<ModuleId|string> $optionalDependencies
     */
    public function __construct(
        private readonly ModuleId $id,
        private readonly ModuleType $type,
        array $requiredDependencies = [],
        array $optionalDependencies = []
    ) {
        $this->requiredDependencies = $this->normalizeDependencies($requiredDependencies);
        $this->optionalDependencies = $this->normalizeDependencies($optionalDependencies);

        $requiredIds = array_map(
            static fn (ModuleId $dependency): string => $dependency->value(),
            $this->requiredDependencies
        );

        foreach ($this->optionalDependencies as $dependency) {
            if (in_array($dependency->value(), $requiredIds, true)) {
                throw KernelException::invalidModuleDefinition(sprintf(
                    'Dependency %s is both required and optional for module %s.',
                    $dependency->value(),
                    $this->id->value()
                ));
            }
        }
    }

    public function id(): ModuleId
    {
        return $this->id;
    }

    public function type(): ModuleType
    {
        return $this->type;
    }

    /**
     * @return list<ModuleId>
     */
    public function requiredDependencies(): array
    {
        return $this->requiredDependencies;
    }

    /**
     * @return list<ModuleId>
     */
    public function optionalDependencies(): array
    {
        return $this->optionalDependencies;
    }

    /**
     * @param list<ModuleId|string> $dependencies
     *
     * @return list<ModuleId>
     */
    private function normalizeDependencies(array $dependencies): array
    {
        $normalized = [];

        foreach ($dependencies as $dependency) {
            $moduleId = $dependency instanceof ModuleId ? $dependency : new ModuleId($dependency);

            if ($moduleId->equals($this->id)) {
                throw KernelException::invalidModuleDefinition(sprintf(
                    'Module %s cannot depend on itself.',
                    $this->id->value()
                ));
            }

            if (isset($normalized[$moduleId->value()])) {
                throw KernelException::invalidModuleDefinition(sprintf(
                    'Module %s declares dependency %s more than once.',
                    $this->id->value(),
                    $moduleId->value()
                ));
            }

            $normalized[$moduleId->value()] = $moduleId;
        }

        return array_values($normalized);
    }
}

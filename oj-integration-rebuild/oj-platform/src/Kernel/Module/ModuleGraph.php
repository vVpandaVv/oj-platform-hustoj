<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Module;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;

final class ModuleGraph
{
    /**
     * @return list<Module>
     */
    public function sort(ModuleCatalog $catalog): array
    {
        $states = [];
        $stack = [];
        $ordered = [];

        foreach (array_keys($catalog->modules()) as $moduleId) {
            $this->visit($moduleId, $catalog, $states, $stack, $ordered);
        }

        return $ordered;
    }

    /**
     * @param array<string, 1|2> $states
     * @param list<string> $stack
     * @param list<Module> $ordered
     */
    private function visit(
        string $moduleId,
        ModuleCatalog $catalog,
        array &$states,
        array &$stack,
        array &$ordered
    ): void {
        if (($states[$moduleId] ?? null) === 2) {
            return;
        }

        if (($states[$moduleId] ?? null) === 1) {
            $cycleStart = array_search($moduleId, $stack, true);
            $cycle = $cycleStart === false ? $stack : array_slice($stack, $cycleStart);
            $cycle[] = $moduleId;

            throw KernelException::circularModuleDependency($cycle);
        }

        $states[$moduleId] = 1;
        $stack[] = $moduleId;
        $definition = $catalog->definition($moduleId);

        foreach ($definition->requiredDependencies() as $dependency) {
            if (! $catalog->has($dependency)) {
                throw KernelException::missingModuleDependency(
                    $moduleId,
                    $dependency->value()
                );
            }

            $this->visit($dependency->value(), $catalog, $states, $stack, $ordered);
        }

        foreach ($definition->optionalDependencies() as $dependency) {
            if ($catalog->has($dependency)) {
                $this->visit($dependency->value(), $catalog, $states, $stack, $ordered);
            }
        }

        array_pop($stack);
        $states[$moduleId] = 2;
        $ordered[] = $catalog->module($moduleId);
    }
}

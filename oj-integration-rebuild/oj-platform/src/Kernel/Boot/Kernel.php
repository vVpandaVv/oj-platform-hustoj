<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Boot;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;
use CodingPanda\OjPlatform\Kernel\Addon\AddonLifecycle;
use CodingPanda\OjPlatform\Kernel\Addon\NullAddonLifecycle;
use CodingPanda\OjPlatform\Kernel\Module\Module;
use CodingPanda\OjPlatform\Kernel\Module\ModuleCatalog;
use CodingPanda\OjPlatform\Kernel\Module\ModuleGraph;
use CodingPanda\OjPlatform\Kernel\Registry\RegistrySet;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

final class Kernel
{
    private bool $bootAttempted = false;

    private readonly AddonLifecycle $addons;

    public function __construct(
        private readonly ModuleCatalog $modules,
        private readonly ModuleGraph $moduleGraph,
        private readonly RegistrySet $registries,
        private readonly Clock $clock,
        ?AddonLifecycle $addons = null
    ) {
        $this->addons = $addons ?? new NullAddonLifecycle();
    }

    public function boot(): BootReport
    {
        if ($this->bootAttempted) {
            throw KernelException::bootAlreadyAttempted();
        }

        $this->bootAttempted = true;
        $trace = new BootTrace();
        $trace->advance(BootPhase::Configuration);

        $orderedModules = $this->moduleGraph->sort($this->modules);
        $trace->advance(BootPhase::ModuleGraph);

        foreach ($orderedModules as $module) {
            $module->register();
        }
        $trace->advance(BootPhase::ContractRegistration);

        $this->addons->collectAndFreeze();

        $this->registries->validateAndFreeze();
        $trace->advance(BootPhase::RegistryFreeze);

        foreach ($orderedModules as $module) {
            $module->connect();
        }
        $trace->advance(BootPhase::AdapterConnection);
        $trace->advance(BootPhase::RuntimeReady);

        $this->addons->publishRuntimeReady();
        return new BootReport(
            array_map(
                static fn (Module $module): string => $module->definition()->id()->value(),
                $orderedModules
            ),
            $trace->completedPhases(),
            $this->clock->now()
        );
    }
}

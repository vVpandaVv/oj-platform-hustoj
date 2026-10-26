<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

/** @internal */
final class AddonAiTaskContributor
{
    public function __construct(
        private readonly AddonAiTaskContributions $tasks
    ) {
    }

    public function contribute(AddonManifest $manifest): void
    {
        foreach ($manifest->aiTasks() as $task) {
            $this->tasks->add($task);
        }
    }
}

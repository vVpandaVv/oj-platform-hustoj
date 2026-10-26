<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

final class NullAddonLifecycle implements AddonLifecycle
{
    public function collectAndFreeze(): void
    {
    }

    public function publishRuntimeReady(): void
    {
    }
}

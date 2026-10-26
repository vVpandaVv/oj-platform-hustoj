<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

interface AddonLifecycle
{
    public function collectAndFreeze(): void;

    public function publishRuntimeReady(): void;
}

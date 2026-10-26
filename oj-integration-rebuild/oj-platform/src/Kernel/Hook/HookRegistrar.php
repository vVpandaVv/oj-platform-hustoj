<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Hook;

interface HookRegistrar
{
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 0
    ): void;
}

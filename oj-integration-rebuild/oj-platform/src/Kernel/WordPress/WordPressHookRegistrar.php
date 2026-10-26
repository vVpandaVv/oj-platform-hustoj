<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\WordPress;

use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use RuntimeException;

final class WordPressHookRegistrar implements HookRegistrar
{
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 0
    ): void {
        if (! function_exists('add_action')) {
            throw new RuntimeException('WordPress action API is not available.');
        }

        add_action($hook, $callback, $priority, $acceptedArguments);
    }
}

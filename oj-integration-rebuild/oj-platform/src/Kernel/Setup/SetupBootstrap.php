<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;

final class SetupBootstrap
{
    public function __construct(
        private readonly HookRegistrar $hooks,
        private readonly SetupCoordinator $coordinator,
        private readonly SetupScreen $screen
    ) {
    }

    public function registerTemporaryHooks(?SetupSnapshot $snapshot = null): void
    {
        if (($snapshot ?? $this->coordinator->inspect())->allowsRuntime()) {
            return;
        }

        $this->hooks->addAction(
            'admin_menu',
            [$this->screen, 'registerPage']
        );
        $this->hooks->addAction(
            'admin_notices',
            [$this->screen, 'renderPendingNotice']
        );
        $this->hooks->addAction(
            'admin_post_oj_platform_setup',
            [$this->screen, 'handleAction']
        );
        $this->hooks->addAction(
            'admin_enqueue_scripts',
            [$this->screen, 'enqueueAssets'],
            10,
            1
        );
    }
}

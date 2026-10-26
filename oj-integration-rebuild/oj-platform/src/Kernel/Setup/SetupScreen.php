<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

interface SetupScreen
{
    public function registerPage(): void;

    public function renderPendingNotice(): void;

    public function handleAction(): void;

    public function enqueueAssets(string $hookSuffix): void;
}

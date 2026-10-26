<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

final class InstallationIdFactory
{
    public function create(): string
    {
        return bin2hex(random_bytes(16));
    }
}

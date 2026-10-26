<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

interface SetupInspectionCache
{
    public function read(string $fingerprint): ?SetupSnapshot;
    public function write(string $fingerprint, SetupSnapshot $snapshot): void;
    public function clear(): void;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

interface SafeRedirect
{
    public function afterLogin(string $candidate): string;
}

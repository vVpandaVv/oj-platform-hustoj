<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Registry;

interface FreezableRegistry
{
    public function name(): string;

    public function validate(): void;

    public function freeze(): void;

    public function isFrozen(): bool;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Module;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;

final class ModuleId
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw KernelException::invalidModuleId($value);
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

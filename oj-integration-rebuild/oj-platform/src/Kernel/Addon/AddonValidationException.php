<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use DomainException;

/** @internal */
final class AddonValidationException extends DomainException
{
    public function __construct(
        private readonly string $runtimeStatus,
        private readonly string $problemCode
    ) {
        parent::__construct($problemCode);
    }

    public function runtimeStatus(): string { return $this->runtimeStatus; }
    public function problemCode(): string { return $this->problemCode; }
}

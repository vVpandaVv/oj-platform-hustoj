<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class BoundedProcessResult
{
    public function __construct(
        private readonly int $exitCode,
        private readonly string $standardOutput,
        private readonly string $standardError,
        private readonly bool $timedOut,
        private readonly bool $outputLimitExceeded
    ) {
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function standardOutput(): string
    {
        return $this->standardOutput;
    }

    public function standardError(): string
    {
        return $this->standardError;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    public function outputLimitExceeded(): bool
    {
        return $this->outputLimitExceeded;
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0
            && ! $this->timedOut
            && ! $this->outputLimitExceeded;
    }
}

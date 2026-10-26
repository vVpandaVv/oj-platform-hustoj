<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use RuntimeException;

final class AiProviderException extends RuntimeException
{
    public function __construct(
        private readonly string $safeCode,
        string $safeMessage,
        private readonly bool $transient
    ) {
        parent::__construct($safeMessage);
    }

    public function safeCode(): string
    {
        return $this->safeCode;
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}

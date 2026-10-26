<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

final class AccessDecision
{
    private function __construct(
        private readonly bool $allowed,
        private readonly ?HttpResponse $response
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function reject(HttpResponse $response): self
    {
        return new self(false, $response);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function response(): ?HttpResponse
    {
        return $this->response;
    }
}

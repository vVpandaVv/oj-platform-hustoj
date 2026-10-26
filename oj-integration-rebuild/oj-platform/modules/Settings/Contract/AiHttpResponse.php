<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use InvalidArgumentException;

final class AiHttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers,
        private readonly string $body,
        private readonly int $latencyMs
    ) {
        if (
            $this->statusCode < 100
            || $this->statusCode > 599
            || $this->latencyMs < 0
            || strlen($this->body) > 2097152
        ) {
            throw new InvalidArgumentException('AI HTTP 응답이 올바르지 않습니다.');
        }
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }
}

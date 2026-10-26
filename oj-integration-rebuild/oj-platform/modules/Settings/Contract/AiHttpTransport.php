<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface AiHttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $expectedResolvedIps
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeoutSeconds,
        int $timeoutSeconds,
        array $expectedResolvedIps
    ): AiHttpResponse;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use InvalidArgumentException;

final class AiEndpointValidation
{
    /**
     * @param list<string> $resolvedIps
     */
    public function __construct(
        private readonly string $normalizedBaseUrl,
        private readonly array $resolvedIps
    ) {
        if (
            filter_var($this->normalizedBaseUrl, FILTER_VALIDATE_URL) === false
            || $this->resolvedIps === []
        ) {
            throw new InvalidArgumentException('AI endpoint 검사 결과가 올바르지 않습니다.');
        }
        foreach ($this->resolvedIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('AI endpoint 확인 주소가 올바르지 않습니다.');
            }
        }
    }

    public function normalizedBaseUrl(): string
    {
        return $this->normalizedBaseUrl;
    }

    /**
     * @return list<string>
     */
    public function resolvedIps(): array
    {
        return $this->resolvedIps;
    }
}

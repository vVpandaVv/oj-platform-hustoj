<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonPageRequestV1
{
    /**
     * @param array<string, string> $parameters
     * @param array<string, string|list<string>> $query
     */
    public function __construct(
        private readonly string $routeKey,
        private readonly int $actorUserId,
        private readonly array $parameters,
        private readonly array $query,
        private readonly string $locale,
        private readonly string $correlationId
    ) {
        ValueValidator::contributionKey($this->routeKey, 'routeKey');
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::routeParameterMap($this->parameters, 'parameters');
        ValueValidator::queryMap($this->query, 'query');
        ValueValidator::safeToken($this->locale, 'locale', 32);
        ValueValidator::correlationId($this->correlationId);
    }

    public function routeKey(): string { return $this->routeKey; }
    public function actorUserId(): int { return $this->actorUserId; }
    /** @return array<string, string> */
    public function parameters(): array { return $this->parameters; }
    /** @return array<string, string|list<string>> */
    public function query(): array { return $this->query; }
    public function locale(): string { return $this->locale; }
    public function correlationId(): string { return $this->correlationId; }
}

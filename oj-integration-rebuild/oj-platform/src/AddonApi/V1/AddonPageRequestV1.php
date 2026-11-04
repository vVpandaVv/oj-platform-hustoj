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
        private readonly string $correlationId,
        private readonly array $navigationUrls = []
    ) {
        ValueValidator::contributionKey($this->routeKey, 'routeKey');
        ValueValidator::positiveUserId($this->actorUserId, 'actorUserId');
        ValueValidator::routeParameterMap($this->parameters, 'parameters');
        ValueValidator::queryMap($this->query, 'query');
        ValueValidator::safeToken($this->locale, 'locale', 32);
        ValueValidator::correlationId($this->correlationId);
        if (count($this->navigationUrls) > 66) { throw new \InvalidArgumentException('Navigation URL map is too large.'); }
        foreach ($this->navigationUrls as $key => $url) { ValueValidator::simpleKey($key, 'navigationUrls'); if (!is_string($url) || strlen($url) > 2048 || !str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\\x00-\\x20\\x7f]/', $url)) { throw new \InvalidArgumentException('Invalid navigation URL.'); } }
    }

    /** @return array<string,string> */
    public function navigationUrls(): array { return $this->navigationUrls; }

    public function routeKey(): string { return $this->routeKey; }
    public function actorUserId(): int { return $this->actorUserId; }
    /** @return array<string, string> */
    public function parameters(): array { return $this->parameters; }
    /** @return array<string, string|list<string>> */
    public function query(): array { return $this->query; }
    public function locale(): string { return $this->locale; }
    public function correlationId(): string { return $this->correlationId; }
}

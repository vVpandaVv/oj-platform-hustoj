<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
final class AddonNativeLearningLinkV1
{
    public function __construct(private readonly string $routeKey, private readonly string $label, private readonly array $parameters = [], private readonly array $query = [])
    { ValueValidator::contributionKey($routeKey, 'routeKey'); ValueValidator::label($label); ValueValidator::routeParameterMap($parameters, 'parameters'); ValueValidator::queryMap($query, 'query'); if (count($parameters) > 8 || count($query) > 12) { throw new \InvalidArgumentException('Native catalog link is too large.'); } }
    public function routeKey(): string { return $this->routeKey; }
    public function label(): string { return $this->label; }
    public function parameters(): array { return $this->parameters; }
    public function query(): array { return $this->query; }
}

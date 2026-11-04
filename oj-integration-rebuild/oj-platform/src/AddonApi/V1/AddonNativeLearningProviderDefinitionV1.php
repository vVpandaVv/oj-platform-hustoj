<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1;
use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;
final class AddonNativeLearningProviderDefinitionV1
{
    public function __construct(private readonly string $key, private readonly string $label, private readonly AddonNativeLearningProviderV1 $provider, private readonly int $order = 50)
    { ValueValidator::contributionKey($key); ValueValidator::label($label); if ($order < 0 || $order > 999) { throw new \InvalidArgumentException('Invalid native catalog order.'); } }
    public function key(): string { return $this->key; }
    public function label(): string { return $this->label; }
    public function provider(): AddonNativeLearningProviderV1 { return $this->provider; }
    public function order(): int { return $this->order; }
}

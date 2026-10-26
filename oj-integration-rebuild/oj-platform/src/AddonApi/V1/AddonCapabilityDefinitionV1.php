<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonCapabilityDefinitionV1
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $audience
    ) {
        ValueValidator::capabilityKey($this->key, 'key');
        ValueValidator::label($this->label);
        ValueValidator::oneOf(
            $this->audience,
            ['operator', 'educator', 'learner'],
            'audience'
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function audience(): string
    {
        return $this->audience;
    }
}

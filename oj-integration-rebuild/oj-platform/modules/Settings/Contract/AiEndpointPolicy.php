<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderDefinition;

interface AiEndpointPolicy
{
    public function validate(
        AiProviderDefinition $definition,
        string $baseUrl
    ): AiEndpointValidation;
}

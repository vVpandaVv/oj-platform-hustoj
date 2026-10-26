<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;

/** Optional capability; existing provider implementations keep their original interface. */
interface StructuredAiProviderClient extends AiProviderClient
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed>|null $schema
     * @param array<string,int|float> $options
     * @return array<string,mixed>
     */
    public function generateStructured(
        AiProviderProfile $profile,
        array $messages,
        int $maxOutputTokens,
        ?array $schema,
        array $options
    ): array;
}

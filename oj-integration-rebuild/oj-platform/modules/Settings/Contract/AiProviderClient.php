<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;

interface AiProviderClient
{
    public function adapterKey(): string;

    /**
     * @return array<string, string>
     */
    public function models(AiProviderProfile $profile): array;

    /**
     * @param list<array{role:string,content:string}> $messages
     * @return array<string, mixed>
     */
    public function generate(
        AiProviderProfile $profile,
        array $messages,
        int $maxOutputTokens
    ): array;
}

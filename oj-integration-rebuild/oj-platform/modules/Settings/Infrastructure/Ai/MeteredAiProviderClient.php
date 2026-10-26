<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Infrastructure\Ai;

use CodingPanda\OjPlatform\Modules\Settings\Application\AiUsageRecorder;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Contract\StructuredAiProviderClient;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;

final class MeteredAiProviderClient implements StructuredAiProviderClient
{
    public function __construct(private readonly AiProviderClient $inner, private readonly AiUsageRecorder $usage)
    {
    }
    public function adapterKey(): string
    {
        return $this->inner->adapterKey();
    }
    public function models(AiProviderProfile $profile): array
    {
        return $this->inner->models($profile);
    }
    public function generate(AiProviderProfile $profile, array $messages, int $maxOutputTokens): array
    {
        return $this->usage->measure(
            $profile,
            fn (): array => $this->inner->generate($profile, $messages, $maxOutputTokens)
        );
    }
    public function generateStructured(
        AiProviderProfile $profile,
        array $messages,
        int $maxOutputTokens,
        ?array $schema,
        array $options
    ): array {
        return $this->usage->measure($profile, fn (): array => $this->inner instanceof StructuredAiProviderClient
            ? $this->inner->generateStructured($profile, $messages, $maxOutputTokens, $schema, $options)
            : $this->inner->generate($profile, $messages, $maxOutputTokens));
    }
}

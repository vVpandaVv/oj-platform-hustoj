<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface AddonAiRequestLedger
{
    /** @return array<string,mixed> Cached or newly committed provider result, never an ambiguous replay. */
    public function execute(string $addonId, int $actor, string $requestKey, string $inputHash, \Closure $operation): array;
    public function cleanup(): array;
    public function eraseActor(int $actor): void;
    public function hasActor(int $actor): bool;
}

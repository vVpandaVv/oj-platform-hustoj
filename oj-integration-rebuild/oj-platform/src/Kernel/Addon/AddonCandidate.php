<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

/** @internal */
final class AddonCandidate
{
    /** @var (callable(\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1): void)|null */
    private $onReady;

    private function __construct(
        private readonly string $addonId,
        private readonly ?AddonManifest $manifest,
        private readonly string $failureCode,
        ?callable $onReady
    ) {
        $this->onReady = $onReady;
    }

    /** @param (callable(\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1): void)|null $onReady */
    public static function manifest(string $addonId, AddonManifest $manifest, ?callable $onReady = null): self
    {
        return new self($addonId, $manifest, '', $onReady);
    }

    public static function failed(string $addonId, string $failureCode): self
    {
        return new self($addonId, null, $failureCode, null);
    }

    public function addonId(): string { return $this->addonId; }
    public function manifestValue(): ?AddonManifest { return $this->manifest; }
    public function failureCode(): string { return $this->failureCode; }
    public function failedRegistration(): bool { return $this->manifest === null; }
    /** @return (callable(\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1): void)|null */
    public function onReadyCallback(): ?callable { return $this->onReady; }
}

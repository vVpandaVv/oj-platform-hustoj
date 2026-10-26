<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use InvalidArgumentException;

/** @internal */
final class AddonCatalogEntry
{
    /** @var (callable(\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1): void)|null */
    private $onReady;

    /**
     * @param (callable(\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1): void)|null $onReady
     */
    public function __construct(
        private readonly string $addonId,
        private readonly ?AddonManifest $manifest,
        private readonly string $status,
        private readonly string $code,
        ?callable $onReady = null
    ) {
        $this->onReady = $onReady;
        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/D', $this->addonId) !== 1) {
            throw new InvalidArgumentException('Invalid addon catalog ID.');
        }
        if (! in_array($this->status, ['accepted', 'incompatible', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid addon catalog status.');
        }
        if (($this->status === 'accepted') !== ($this->manifest instanceof AddonManifest)) {
            throw new InvalidArgumentException('Invalid accepted addon catalog entry.');
        }
        if (($this->status === 'accepted') !== ($this->code === '')) {
            throw new InvalidArgumentException('Invalid addon catalog code.');
        }
        if ($this->status !== 'accepted' && $this->onReady !== null) {
            throw new InvalidArgumentException('Only an accepted addon catalog entry may carry a ready callback.');
        }
    }

    public function addonId(): string { return $this->addonId; }
    public function manifest(): ?AddonManifest { return $this->manifest; }
    public function status(): string { return $this->status; }
    public function code(): string { return $this->code; }
    public function isAccepted(): bool { return $this->status === 'accepted'; }
    /** @return (callable(\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1): void)|null */
    public function onReadyCallback(): ?callable { return $this->onReady; }
}

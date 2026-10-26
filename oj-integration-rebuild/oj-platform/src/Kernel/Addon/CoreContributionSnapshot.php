<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

/** @internal */
final class CoreContributionSnapshot
{
    /**
     * @param list<string> $routeKeys
     * @param list<string> $routePaths
     * @param list<string> $consoleMenuKeys
     * @param list<string> $consoleAnchors
     * @param list<string> $publicRoots
     * @param list<string> $capabilityKeys
     * @param list<string> $assetHandles
     * @param list<string> $aiTaskKeys
     */
    public function __construct(
        private readonly array $routeKeys,
        private readonly array $routePaths,
        private readonly array $consoleMenuKeys,
        private readonly array $consoleAnchors,
        private readonly array $publicRoots,
        private readonly array $capabilityKeys,
        private readonly array $assetHandles,
        private readonly array $aiTaskKeys
    ) {
    }

    /** @return list<string> */
    public function routeKeys(): array { return $this->routeKeys; }
    /** @return list<string> */
    public function routePaths(): array { return $this->routePaths; }
    /** @return list<string> */
    public function consoleMenuKeys(): array { return $this->consoleMenuKeys; }
    /** @return list<string> */
    public function consoleAnchors(): array { return $this->consoleAnchors; }
    /** @return list<string> */
    public function publicRoots(): array { return $this->publicRoots; }
    /** @return list<string> */
    public function capabilityKeys(): array { return $this->capabilityKeys; }
    /** @return list<string> */
    public function assetHandles(): array { return $this->assetHandles; }
    /** @return list<string> */
    public function aiTaskKeys(): array { return $this->aiTaskKeys; }
}

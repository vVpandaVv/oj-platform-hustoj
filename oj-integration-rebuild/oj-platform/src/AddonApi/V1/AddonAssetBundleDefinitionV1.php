<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use CodingPanda\OjPlatform\AddonApi\V1\Internal\ValueValidator;

final class AddonAssetBundleDefinitionV1
{
    /**
     * @param list<string> $styles
     * @param list<string> $scripts
     * @param list<string> $dependencies
     */
    public function __construct(
        private readonly string $key,
        private readonly string $handle,
        private readonly array $styles,
        private readonly array $scripts,
        private readonly array $dependencies,
        private readonly string $version
    ) {
        ValueValidator::contributionKey($this->key);
        ValueValidator::simpleKey($this->handle, 'handle');
        ValueValidator::relativeAssetList($this->styles, 'styles', 'css', 8);
        ValueValidator::relativeAssetList($this->scripts, 'scripts', 'js', 8);
        ValueValidator::handleList($this->dependencies, 'dependencies', 32);
        ValueValidator::semanticVersion($this->version, 'version');
    }

    public function key(): string { return $this->key; }
    public function handle(): string { return $this->handle; }
    /** @return list<string> */
    public function styles(): array { return $this->styles; }
    /** @return list<string> */
    public function scripts(): array { return $this->scripts; }
    /** @return list<string> */
    public function dependencies(): array { return $this->dependencies; }
    public function version(): string { return $this->version; }
}

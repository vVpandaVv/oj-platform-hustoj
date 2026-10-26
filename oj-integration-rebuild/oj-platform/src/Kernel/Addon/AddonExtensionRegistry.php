<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonExtensionRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchExtensionV1;
use LogicException;

/** @internal Mutable only during the early Addon API registration phase. */
final class AddonExtensionRegistry implements AddonExtensionRegistrationGatewayV1
{
    private ?ResearchExtensionV1 $research = null;
    private bool $closed = false;

    public function __construct(private readonly string $version)
    {
    }

    public function apiVersion(): string
    {
        return $this->version;
    }

    public function registerResearch(ResearchExtensionV1 $extension): void
    {
        $this->assertOpen();
        if (version_compare($this->version, '1.2.0', '<') || version_compare($this->version, '2.0.0', '>=')) {
            throw new LogicException('Research extensions require Addon API >=1.2.0 <2.0.0.');
        }
        if ($this->research !== null) {
            throw new LogicException('Only one research extension may be registered.');
        }
        $this->research = $extension;
    }

    public function close(): void
    {
        $this->assertOpen();
        $this->closed = true;
    }

    public function research(): ?ResearchExtensionV1
    {
        if (! $this->closed) {
            throw new LogicException('Extension registration must finish before composition.');
        }
        return $this->research;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('Addon extension registration is closed.');
        }
    }
}

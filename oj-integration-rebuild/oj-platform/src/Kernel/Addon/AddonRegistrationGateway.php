<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** @internal */
final class AddonRegistrationGateway implements AddonRegistrationGatewayV1
{
    /** @var list<AddonCandidate> */
    private array $candidates = [];
    private bool $closed = false;

    public function __construct(
        private readonly string $version,
        private readonly ErrorReporter $errors
    ) {
    }

    public function apiVersion(): string
    {
        return $this->version;
    }

    public function register(string $addonId, callable $build): void
    {
        if ($this->closed) {
            throw new LogicException('The addon registration window is closed.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/D', $addonId) !== 1) {
            $this->errors->report(new InvalidArgumentException('Invalid addon ID.'));
            return;
        }

        $builder = new AddonManifestBuilder();
        try {
            $build($builder);
            $this->candidates[] = AddonCandidate::manifest(
                $addonId,
                $builder->close(),
                $builder->onReadyCallback()
            );
        } catch (Throwable $error) {
            $this->errors->report($error);
            $this->candidates[] = AddonCandidate::failed(
                $addonId,
                'addon_registration_failed'
            );
        }
    }

    /** @return list<AddonCandidate> */
    public function close(): array
    {
        if ($this->closed) {
            throw new LogicException('The addon registration window was already closed.');
        }
        $this->closed = true;

        return $this->candidates;
    }
}

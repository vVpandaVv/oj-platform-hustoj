<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use LogicException;

/**
 * Tracks runtime callback readiness separately from manifest acceptance.
 *
 * @internal
 */
final class AddonRuntimeReadiness
{
    public const PENDING = 'pending';
    public const READY = 'ready';
    public const FAILED = 'failed';
    public const NOT_REQUIRED = 'not_required';

    /** @var array<string, array{status: string, code: string}> */
    private array $states = [];

    public function register(string $addonId, bool $callbackRegistered): void
    {
        if (isset($this->states[$addonId])) {
            throw new LogicException('Addon runtime readiness was already registered.');
        }

        $this->states[$addonId] = [
            'status' => $callbackRegistered ? self::PENDING : self::NOT_REQUIRED,
            'code' => '',
        ];
    }

    public function markReady(string $addonId): void
    {
        $this->transition($addonId, self::READY, '');
    }

    public function markFailed(
        string $addonId,
        string $code = 'addon_runtime_callback_failed'
    ): void {
        $this->transition($addonId, self::FAILED, $code);
    }

    /** @return array{status: string, code: string}|null */
    public function state(string $addonId): ?array
    {
        return $this->states[$addonId] ?? null;
    }

    /** @return array<string, array{status: string, code: string}> */
    public function all(): array
    {
        return $this->states;
    }

    private function transition(string $addonId, string $status, string $code): void
    {
        $current = $this->states[$addonId] ?? null;
        if ($current === null || $current['status'] !== self::PENDING) {
            throw new LogicException('Addon runtime readiness cannot transition from its current state.');
        }

        $this->states[$addonId] = ['status' => $status, 'code' => $code];
    }
}

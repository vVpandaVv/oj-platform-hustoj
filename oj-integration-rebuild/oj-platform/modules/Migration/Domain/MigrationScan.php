<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Domain;

use InvalidArgumentException;

final class MigrationScan
{
    /**
     * @param array<string, array<string, scalar|null>> $tables
     * @param list<string> $blockers
     * @param list<string> $warnings
     * @param list<string> $legacyPlugins
     */
    public function __construct(
        private readonly array $tables,
        private readonly array $blockers,
        private readonly array $warnings,
        private readonly array $legacyPlugins,
        private readonly string $databaseFingerprint
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $this->databaseFingerprint) !== 1) {
            throw new InvalidArgumentException('이전 기준 fingerprint가 올바르지 않습니다.');
        }
    }

    /**
     * @return array<string, array<string, scalar|null>>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    public function legacyPlugins(): array
    {
        return $this->legacyPlugins;
    }

    public function databaseFingerprint(): string
    {
        return $this->databaseFingerprint;
    }

    public function canPlan(): bool
    {
        return $this->blockers === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function safeSummary(): array
    {
        return [
            'tables' => $this->tables,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'legacy_plugins' => $this->legacyPlugins,
            'database_fingerprint' => $this->databaseFingerprint,
        ];
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Contract;

use InvalidArgumentException;

final class LegacyInstallationReport
{
    /**
     * @param list<string> $legacyTables
     * @param list<string> $recognizedTargetTables
     */
    public function __construct(
        private readonly array $legacyTables,
        private readonly array $recognizedTargetTables
    ) {
        if (
            self::tableList($this->legacyTables) !== $this->legacyTables
            || self::tableList($this->recognizedTargetTables) !== $this->recognizedTargetTables
            || array_diff($this->recognizedTargetTables, $this->legacyTables) !== []
        ) {
            throw new InvalidArgumentException('기존 설치 감지 보고서가 올바르지 않습니다.');
        }
    }

    public static function none(): self
    {
        return new self([], []);
    }

    public function isCandidate(): bool
    {
        return $this->legacyTables !== [];
    }

    /** @return list<string> */
    public function legacyTables(): array
    {
        return $this->legacyTables;
    }

    /** @return list<string> */
    public function recognizedTargetTables(): array
    {
        return $this->recognizedTargetTables;
    }

    /** @return list<string> */
    private static function tableList(array $tables): array
    {
        $normalized = [];
        foreach ($tables as $table) {
            if (is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1) {
                $normalized[] = $table;
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;

final class LegacySourceLocator
{
    public function __construct(private readonly WordPressMigrationDatabase $database)
    {
    }

    public function isAvailable(): bool
    {
        return $this->database->isAvailable();
    }

    /** @return list<string> */
    public function existing(MigrationTable $table): array
    {
        $logical = $table->legacyLogicalName();
        if ($logical === null) {
            return [];
        }

        $candidates = $logical === 'problem_metadata'
            ? $this->problemMetadataCandidates()
            : $this->prefixedCandidates($logical);
        $existing = [];
        foreach ($candidates as $candidate) {
            if (! $this->database->isLegacySource($candidate, $table->sourceKey(), $table->targetTable())) {
                continue;
            }
            $existing[] = $candidate;
        }

        return array_values(array_unique($existing));
    }

    public function one(MigrationTable $table): ?string
    {
        $existing = $this->existing($table);

        return count($existing) === 1 ? $existing[0] : null;
    }

    /** @return list<string> */
    private function prefixedCandidates(string $logical): array
    {
        $base = 'oj_' . $logical;
        $prefix = $this->database->prefix();

        return array_values(array_unique(array_filter([
            $prefix . $base,
            $base,
        ])));
    }

    /** @return list<string> */
    private function problemMetadataCandidates(): array
    {
        $prefix = $this->database->prefix();
        $aliases = $this->database->tablesLike('legacy_problem_metadata_');

        return array_values(array_unique(array_merge([
            'problem_metadata',
            $prefix . 'oj_problem_metadata',
            'oj_problem_metadata',
            $prefix . 'problem_metadata',
        ], $aliases)));
    }
}

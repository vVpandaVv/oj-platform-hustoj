<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCatalog;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyInstallationPresence;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyInstallationReport;

final class WordPressLegacyInstallationPresence implements LegacyInstallationPresence
{
    public function __construct(
        private readonly MigrationCatalog $catalog,
        private readonly LegacySourceLocator $sources
    ) {
    }

    public function inspect(): LegacyInstallationReport
    {
        $legacyTables = [];
        if (! $this->sources->isAvailable()) {
            return LegacyInstallationReport::none();
        }

        $recognizedTargets = [];

        foreach ($this->catalog->all() as $table) {
            foreach ($this->sources->existing($table) as $sourceTable) {
                $legacyTables[] = $sourceTable;
                if ($sourceTable === $table->targetTable()) {
                    $recognizedTargets[] = $sourceTable;
                }
            }
        }

        $legacyTables = array_values(array_unique($legacyTables));
        $recognizedTargets = array_values(array_unique($recognizedTargets));
        sort($legacyTables, SORT_STRING);
        sort($recognizedTargets, SORT_STRING);

        return new LegacyInstallationReport(
            $legacyTables,
            $recognizedTargets
        );
    }
}

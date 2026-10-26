<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Contract;

use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCatalog;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationScan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWorkResult;

interface LegacyMigrationGateway
{
    public function scan(MigrationCatalog $catalog): MigrationScan;

    public function execute(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult;

    /**
     * @param list<string> $pluginFiles
     */
    public function deactivateLegacyPlugins(array $pluginFiles): MigrationWorkResult;
}

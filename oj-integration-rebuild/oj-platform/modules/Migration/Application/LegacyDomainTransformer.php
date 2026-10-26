<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;

interface LegacyDomainTransformer
{
    public function supports(string $transformer): bool;

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public function transform(
        MigrationTable $table,
        array $row,
        MigrationPlan $plan
    ): array;
}


<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Contract;

interface ContestSettingsBatchRepository
{
    /** @param list<int> $ids @return array<int,\CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings> */
    public function settingsMany(array $ids): array;
}

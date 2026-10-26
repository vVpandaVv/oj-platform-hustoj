<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeUserRecord;

interface LegacyJudgeUserRecordSource
{
    public function findByLogin(string $userLogin): ?LegacyJudgeUserRecord;
}

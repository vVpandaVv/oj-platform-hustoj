<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeCandidate;

interface LegacyJudgeUserReader
{
    public function verify(string $userLogin, string $password): ?LegacyJudgeCandidate;
}

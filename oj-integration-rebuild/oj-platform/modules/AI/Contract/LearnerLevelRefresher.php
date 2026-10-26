<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Contract;

use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;

interface LearnerLevelRefresher
{
    public function refresh(int $userId): LearnerLevelProfile;
}

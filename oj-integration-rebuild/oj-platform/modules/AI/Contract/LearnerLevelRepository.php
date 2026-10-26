<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Contract;

use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;

interface LearnerLevelRepository
{
    public function find(int $userId): ?LearnerLevelProfile;

    public function save(LearnerLevelProfile $profile): void;

    /**
     * @return list<LearnerLevelProfile>
     */
    public function recent(int $limit = 100): array;
}

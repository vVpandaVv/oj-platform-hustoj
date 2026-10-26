<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Contract;

interface GroupHomeRepository
{
    /** @return list<\CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup> */
    public function homeFor(int $userId, bool $managed, bool $all, int $limit): array;
}

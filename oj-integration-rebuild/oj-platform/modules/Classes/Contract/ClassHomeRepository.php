<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Contract;

interface ClassHomeRepository
{
    /** @return list<\CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass> */
    public function homeFor(int $userId, array $groupIds, bool $managed, bool $all, string $now, int $limit): array;
}

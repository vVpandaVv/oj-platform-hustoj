<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Groups\Contract;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
interface GroupLearningRepository
{
    /** @return list<LearningGroup> */
    public function learningFor(int $userId): array;
}

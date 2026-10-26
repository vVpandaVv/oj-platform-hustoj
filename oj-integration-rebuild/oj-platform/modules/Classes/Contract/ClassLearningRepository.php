<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Classes\Contract;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
interface ClassLearningRepository
{
    /** @param list<int> $groupIds @return list<LearningClass> */
    public function learningFor(int $userId, array $groupIds): array;
}

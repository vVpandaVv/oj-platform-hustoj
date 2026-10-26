<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Classes\Contract;
interface ClassProblemChangePublisher
{
    public function saved(int $classId,int $actorUserId,array $problemIds):void;
}

<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Problems\Contract;
interface ProblemChangePublisher
{
    public function saved(int $problemId,int $actorUserId,string $change):void;
}

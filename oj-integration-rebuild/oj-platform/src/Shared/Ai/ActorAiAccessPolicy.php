<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Shared\Ai;

interface ActorAiAccessPolicy
{
    public function assertAiAccess(int $actorUserId): void;
}

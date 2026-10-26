<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Contract;
interface PilotResources
{
    public function guides(): array;
    public function problems(int $actor, array $ids): array;
}

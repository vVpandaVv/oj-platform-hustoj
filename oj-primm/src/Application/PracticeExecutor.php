<?php
declare(strict_types=1);
namespace OjPrimm\Application;

interface PracticeExecutor
{
    public function execute(int $actor, array $material, string $source, int $caseIndex, string $key, ?string $ticket): array;
}

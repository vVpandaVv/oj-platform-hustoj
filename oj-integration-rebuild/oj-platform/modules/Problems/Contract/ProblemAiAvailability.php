<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

interface ProblemAiAvailability
{
    public function isAvailable(string $taskType): bool;
}

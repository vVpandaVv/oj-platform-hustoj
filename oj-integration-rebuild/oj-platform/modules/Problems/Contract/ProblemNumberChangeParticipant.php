<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberMap;

interface ProblemNumberChangeParticipant
{
    public function key(): string;

    /**
     * @return array<string, int>
     */
    public function inspect(ProblemNumberMap $mapping): array;

    public function move(ProblemNumberMap $mapping): void;

    public function verifyMoved(ProblemNumberMap $mapping): void;
}

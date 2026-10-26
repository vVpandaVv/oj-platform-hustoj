<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

interface ProblemDataRootProvider
{
    public function root(): string;

    public function problemDirectory(ProblemId $problemId): string;
}

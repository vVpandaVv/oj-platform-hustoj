<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;

interface ProblemListGateway
{
    /** @return list<JudgeProblem> List projections omit long body/template fields. */
    public function searchList(ProblemSearch $search): array;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonCodeOrderingGatewayV1
{
    public function puzzle(AddonCodeOrderingQueryV1 $query): AddonCodeOrderingPuzzleResultV1;

    /** Reconstructs the learner's order; success is not a correctness or AC verdict. */
    public function assemble(AddonCodeOrderingAnswerV1 $answer): AddonCodeOrderingAssemblyResultV1;
}

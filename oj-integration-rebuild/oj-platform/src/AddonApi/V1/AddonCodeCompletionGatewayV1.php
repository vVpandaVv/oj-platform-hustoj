<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonCodeCompletionGatewayV1
{
    public function template(AddonCodeCompletionQueryV1 $query): AddonCodeCompletionTemplateResultV1;

    /** Reconstruction success is not an execution, correctness, or AC verdict. */
    public function assemble(AddonCodeCompletionAnswerV1 $answer): AddonCodeCompletionAssemblyResultV1;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\SpjCompileResult;

interface ProblemSpjCompiler
{
    public function compile(
        ProblemId $problemId,
        string $sourceName,
        string $expectedSourceSha256,
        ?string $expectedBinarySha256,
        ?string $expectedLogSha256
    ): SpjCompileResult;
}

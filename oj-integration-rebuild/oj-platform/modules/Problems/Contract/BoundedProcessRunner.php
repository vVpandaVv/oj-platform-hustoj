<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\BoundedProcessResult;

interface BoundedProcessRunner
{
    /**
     * @param list<string> $command
     */
    public function run(
        array $command,
        string $workingDirectory,
        int $timeoutSeconds,
        int $maximumOutputBytes
    ): BoundedProcessResult;
}

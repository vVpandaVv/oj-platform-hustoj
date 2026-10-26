<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

final class VisualGraderInstallResult
{
    public function __construct(
        public readonly bool $sourceWritten,
        public readonly ?int $compileJobId,
        public readonly ?string $compileProblem
    ) {
    }
}

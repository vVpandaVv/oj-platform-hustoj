<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTransaction;

final class WordPressProblemTransaction implements ProblemTransaction
{
    public function __construct(private readonly ProblemPlatformDatabase $database)
    {
    }

    public function run(callable $operation): mixed
    {
        return $this->database->transaction($operation);
    }
}

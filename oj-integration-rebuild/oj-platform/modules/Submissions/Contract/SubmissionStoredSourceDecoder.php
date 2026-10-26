<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionStoredSourceDecoder
{
    public function key(): string;

    public function decode(string $storedSource): ?SubmissionStoredSource;
}

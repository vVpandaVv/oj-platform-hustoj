<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionSourceViewProvider
{
    public function view(string $storedSource): SubmissionStoredSource;
}

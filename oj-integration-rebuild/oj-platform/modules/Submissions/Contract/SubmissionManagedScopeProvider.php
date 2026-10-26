<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionManagedScope;

interface SubmissionManagedScopeProvider
{
    public function scopeFor(int $userId): SubmissionManagedScope;
}

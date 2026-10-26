<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionLanguageAvailability
{
    /** @return array<int, string> */
    public function allowedForSite(): array;

    public function isAllowedForSite(int $languageId): bool;
}

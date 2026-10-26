<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionLanguageSettingsLog
{
    public function append(int $disabledLanguageMask, int $actorUserId): void;
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageAvailability;

final class SettingsSubmissionLanguageAvailability implements
    SubmissionLanguageAvailability
{
    public function __construct(
        private readonly SubmissionSettingsProvider $settings,
        private readonly SubmissionLanguageCatalog $languages
    ) {
    }

    public function allowedForSite(): array
    {
        return $this->languages->allowed(
            $this->settings->current()->disabledLanguageMask(),
            null
        );
    }

    public function isAllowedForSite(int $languageId): bool
    {
        return isset($this->allowedForSite()[$languageId]);
    }
}

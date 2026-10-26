<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

final class UserSettingsInput
{
    public function __construct(
        public readonly string $email,
        public readonly string $displayName,
        public readonly ?string $affiliation,
        public readonly ?string $birthDate,
        public readonly ?string $bio,
        public readonly ?string $guardianCode,
        public readonly string $profileVisibility,
        public readonly string $activityVisibility,
        public readonly ?int $defaultLanguage,
        public readonly string $editorTheme,
        public readonly string $defaultEditor,
        public readonly int $editorFontSize,
        public readonly ?string $codeTemplate,
        public readonly bool $inlineSubmissionPanel = false
    ) {
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use InvalidArgumentException;

final class UserSubmissionPreferenceService implements UserSubmissionPreferenceProvider
{
    public function __construct(
        private readonly UserPreferenceRepository $preferences
    ) {
    }

    public function forUser(int $userId): UserSubmissionPreferences
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('사용자 번호는 1 이상이어야 합니다.');
        }
        $preferences = $this->preferences->find($userId)
            ?? new UserPreferences($userId);

        return new UserSubmissionPreferences(
            $preferences->defaultLanguage(),
            $preferences->editorTheme(),
            $preferences->codeTemplate(),
            $preferences->defaultEditor(),
            $preferences->editorFontSize(),
            $preferences->inlineSubmissionPanel()
        );
    }
}

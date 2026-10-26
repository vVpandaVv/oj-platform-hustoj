<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageSettingsLog;
use InvalidArgumentException;

final class WordPressSubmissionLanguageSettingsLog implements
    SubmissionLanguageSettingsLog
{
    private const TABLE = 'submission_language_settings_log';

    public function __construct(
        private readonly SubmissionPlatformDatabase $database
    ) {
    }

    public function append(int $disabledLanguageMask, int $actorUserId): void
    {
        if ($disabledLanguageMask < 0 || $actorUserId < 1) {
            throw new InvalidArgumentException('제출 언어 설정 이력이 올바르지 않습니다.');
        }
        $this->database->insert(self::TABLE, [
            'language_mask' => $disabledLanguageMask,
            'created_by_user_id' => $actorUserId,
        ]);
    }
}

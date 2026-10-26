<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageSettingsLog;

final class SubmissionSettingsService
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly SubmissionLanguageSettingsLog $languageLog,
        private readonly SubmissionLanguageCatalog $languages
    ) {
    }

    /**
     * @param list<int> $enabledLanguageIds
     */
    public function saveLanguages(
        int $actorUserId,
        array $enabledLanguageIds,
        int $pageSize,
        int $maximumPollSeconds,
        int $expectedRevision
    ): SettingsSnapshot {
        $mask = $this->languages->disabledMaskForEnabled(
            $enabledLanguageIds
        );
        $snapshot = $this->settings->save(
            'submission',
            [
                'disabled_language_mask' => $mask,
                'page_size' => max(10, min(100, $pageSize)),
                'maximum_poll_seconds' => max(
                    30,
                    min(600, $maximumPollSeconds)
                ),
            ],
            $expectedRevision
        );
        $this->languageLog->append($mask, $actorUserId);

        return $snapshot;
    }

    public function saveRules(
        int $maxSourceKilobytes,
        int $cooldownSeconds,
        bool $allowResubmitAfterAccept,
        int $expectedRevision,
        bool $requestLimitEnabled = true,
        int $requestLimitCount = 30,
        int $requestLimitSeconds = 60,
        ?bool $allowCodeCopy = null,
        ?bool $allowCodePaste = null
    ): SettingsSnapshot {
        $existing = $this->settings->read('submission_rules', [])->value();
        $existing = is_array($existing) ? $existing : [];
        return $this->settings->save(
            'submission_rules',
            [
                'max_source_kb' => max(1, min(60, $maxSourceKilobytes)),
                'cooldown_seconds' => max(0, min(600, $cooldownSeconds)),
                'allow_resubmit_after_accept' => $allowResubmitAfterAccept,
                'allow_code_copy' => $allowCodeCopy ?? (bool) ($existing['allow_code_copy'] ?? true),
                'allow_code_paste' => $allowCodePaste ?? (bool) ($existing['allow_code_paste'] ?? true),
                'request_limit_enabled' => $requestLimitEnabled,
                'request_limit_count' => max(1, min(1000, $requestLimitCount)),
                'request_limit_seconds' => max(1, min(3600, $requestLimitSeconds)),
            ],
            $expectedRevision
        );
    }

    public function saveDisplay(
        bool $showTime,
        bool $showMemory,
        bool $showLanguage,
        int $expectedRevision
    ): SettingsSnapshot {
        return $this->settings->save(
            'submission_display',
            [
                'show_time' => $showTime,
                'show_memory' => $showMemory,
                'show_language' => $showLanguage,
            ],
            $expectedRevision
        );
    }
}

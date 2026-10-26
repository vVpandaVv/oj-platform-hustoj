<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSettings;

final class SubmissionSettingsProvider
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function current(): SubmissionSettings
    {
        $submission = $this->arrayValue(
            $this->settings->read('submission', [])->value()
        );
        $rules = $this->arrayValue(
            $this->settings->read('submission_rules', [])->value()
        );
        $maxKilobytes = max(
            1,
            min(60, (int) ($rules['max_source_kb'] ?? 60))
        );

        return new SubmissionSettings(
            max(
                0,
                (int) (
                    $submission['disabled_language_mask']
                    ?? $submission['language_mask']
                    ?? 0
                )
            ),
            $maxKilobytes * 1024,
            max(0, min(600, (int) ($rules['cooldown_seconds'] ?? 3))),
            (bool) ($rules['allow_resubmit_after_accept'] ?? true),
            max(10, min(100, (int) ($submission['page_size'] ?? 50))),
            max(
                30,
                min(600, (int) ($submission['maximum_poll_seconds'] ?? 180))
            ),
            (bool) ($rules['allow_code_copy'] ?? true),
            (bool) ($rules['allow_code_paste'] ?? true)
        );
    }

    /** @return array{enabled:bool, count:int, seconds:int} */
    public function requestLimit(): array
    {
        $rules = $this->arrayValue($this->settings->read('submission_rules', [])->value());
        return [
            'enabled' => ($rules['request_limit_enabled'] ?? true) !== false,
            'count' => max(1, min(1000, (int) ($rules['request_limit_count'] ?? 30))),
            'seconds' => max(1, min(3600, (int) ($rules['request_limit_seconds'] ?? 60))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function display(): array
    {
        $value = $this->settings->read('submission_display', [])->value();
        $display = $this->arrayValue($value);

        return [
            'show_time' => (bool) ($display['show_time'] ?? true),
            'show_memory' => (bool) ($display['show_memory'] ?? true),
            'show_language' => (bool) ($display['show_language'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}

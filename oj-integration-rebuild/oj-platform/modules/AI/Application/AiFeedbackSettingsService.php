<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Domain\AiFeedbackSettings;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;

final class AiFeedbackSettingsService
{
    private const DEFAULTS = [
        'enabled' => true,
        'result_scope' => 'all',
        'active_guides' => [
            'insufficient' => 'foundation-learning',
            'beginner' => 'beginner-steps',
            'intermediate' => 'intermediate-trace',
            'advanced' => 'advanced-review',
        ],
    ];

    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function snapshot(): SettingsSnapshot
    {
        return $this->settings->read('ai_feedback', self::DEFAULTS);
    }

    public function current(): AiFeedbackSettings
    {
        $value = $this->snapshot()->value();
        $value = is_array($value) ? $value : [];
        $guides = is_array($value['active_guides'] ?? null)
            ? $value['active_guides']
            : [];

        return new AiFeedbackSettings(
            (bool) ($value['enabled'] ?? self::DEFAULTS['enabled']),
            is_string($value['result_scope'] ?? null)
                ? $value['result_scope']
                : self::DEFAULTS['result_scope'],
            [
                'insufficient' => $this->guide(
                    $guides,
                    'insufficient'
                ),
                'beginner' => $this->guide($guides, 'beginner'),
                'intermediate' => $this->guide($guides, 'intermediate'),
                'advanced' => $this->guide($guides, 'advanced'),
            ]
        );
    }

    public function usesGuide(string $guideKey): bool
    {
        return in_array(
            trim($guideKey),
            $this->current()->activeGuides(),
            true
        );
    }

    /**
     * @param array<string, string> $activeGuides
     */
    public function save(
        bool $enabled,
        string $resultScope,
        array $activeGuides,
        int $expectedRevision
    ): void {
        $validated = new AiFeedbackSettings(
            $enabled,
            $resultScope,
            $activeGuides
        );
        $this->settings->save('ai_feedback', [
            'enabled' => $validated->isEnabled(),
            'result_scope' => $validated->resultScope(),
            'active_guides' => $validated->activeGuides(),
        ], $expectedRevision);
    }

    /**
     * @param array<string, mixed> $guides
     */
    private function guide(array $guides, string $level): string
    {
        $value = $guides[$level] ?? self::DEFAULTS['active_guides'][$level];

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : self::DEFAULTS['active_guides'][$level];
    }
}

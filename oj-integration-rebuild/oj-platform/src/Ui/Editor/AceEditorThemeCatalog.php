<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Editor;

final class AceEditorThemeCatalog
{
    public const DEFAULT_THEME = 'chrome';

    /** @var array<string, string> */
    private const LIGHT_THEMES = [
        'chrome' => 'Chrome',
        'cloud_editor' => 'Cloud Editor',
        'cloud9_day' => 'Cloud9 Day',
        'clouds' => 'Clouds',
        'crimson_editor' => 'Crimson Editor',
        'dawn' => 'Dawn',
        'dreamweaver' => 'Dreamweaver',
        'eclipse' => 'Eclipse',
        'github' => 'GitHub',
        'github_light_default' => 'GitHub Light Default',
        'gruvbox_light_hard' => 'Gruvbox Light Hard',
        'iplastic' => 'IPlastic',
        'katzenmilch' => 'Katzenmilch',
        'kuroir' => 'Kuroir',
        'solarized_light' => 'Solarized Light',
        'sqlserver' => 'SQL Server',
        'textmate' => 'TextMate',
        'tomorrow' => 'Tomorrow',
        'xcode' => 'Xcode',
    ];

    /** @var array<string, string> */
    private const DARK_THEMES = [
        'ambiance' => 'Ambiance',
        'chaos' => 'Chaos',
        'cloud_editor_dark' => 'Cloud Editor Dark',
        'cloud9_night' => 'Cloud9 Night',
        'cloud9_night_low_color' => 'Cloud9 Night Low Color',
        'clouds_midnight' => 'Clouds Midnight',
        'cobalt' => 'Cobalt',
        'dracula' => 'Dracula',
        'github_dark' => 'GitHub Dark',
        'gob' => 'Gob',
        'gruvbox' => 'Gruvbox',
        'gruvbox_dark_hard' => 'Gruvbox Dark Hard',
        'idle_fingers' => 'Idle Fingers',
        'kr_theme' => 'KR Theme',
        'merbivore' => 'Merbivore',
        'merbivore_soft' => 'Merbivore Soft',
        'mono_industrial' => 'Mono Industrial',
        'monokai' => 'Monokai',
        'nord_dark' => 'Nord Dark',
        'one_dark' => 'One Dark',
        'pastel_on_dark' => 'Pastel on Dark',
        'solarized_dark' => 'Solarized Dark',
        'terminal' => 'Terminal',
        'tomorrow_night' => 'Tomorrow Night',
        'tomorrow_night_blue' => 'Tomorrow Night Blue',
        'tomorrow_night_bright' => 'Tomorrow Night Bright',
        'tomorrow_night_eighties' => 'Tomorrow Night Eighties',
        'twilight' => 'Twilight',
        'vibrant_ink' => 'Vibrant Ink',
    ];

    /** @return array<string, array<string, string>> */
    public static function groups(): array
    {
        return [
            '밝은 테마' => self::LIGHT_THEMES,
            '어두운 테마' => self::DARK_THEMES,
        ];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::themes());
    }

    public static function canonical(string $theme): ?string
    {
        $theme = strtolower(trim($theme));
        if (str_starts_with($theme, 'ace/theme/')) {
            $theme = substr($theme, strlen('ace/theme/'));
        }
        $theme = match ($theme) {
            'light' => self::DEFAULT_THEME,
            'dark' => 'monokai',
            default => $theme,
        };

        return array_key_exists($theme, self::themes()) ? $theme : null;
    }

    public static function normalize(string $theme): string
    {
        return self::canonical($theme) ?? self::DEFAULT_THEME;
    }

    public static function isDark(string $theme): bool
    {
        $canonical = self::normalize($theme);

        return array_key_exists($canonical, self::DARK_THEMES);
    }

    /** @return array<string, string> */
    private static function themes(): array
    {
        return self::LIGHT_THEMES + self::DARK_THEMES;
    }
}

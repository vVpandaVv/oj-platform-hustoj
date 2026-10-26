<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Component;

/**
 * Light / dark / follow-the-OS picker shown in the account menus.
 * oj-ui.js stores the choice per browser and applies it to <html>.
 */
final class ColorSchemeChoice
{
    public static function render(): string
    {
        $options = '';
        foreach (['auto' => '시스템', 'light' => '라이트', 'dark' => '다크'] as $value => $label) {
            $options .= '<button type="button" data-ojp-scheme-choice="' . $value . '"'
                . ' aria-pressed="' . ($value === 'auto' ? 'true' : 'false') . '">' . $label . '</button>';
        }

        return '<div class="ojp-scheme-choice" role="group" aria-label="화면 모드">'
            . '<span>화면 모드</span><div class="ojp-scheme-choice__options">' . $options . '</div></div>';
    }
}

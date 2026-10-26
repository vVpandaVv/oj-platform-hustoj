<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Component;

final class PasswordFieldView
{
    public function result(string $id, string $label, string $value): string
    {
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<div class="ojp-password-field" data-ojp-secret-field>'
            . '<input class="ojp-input ojp-secret-value" id="' . $escape($id) . '" type="password"'
            . ' value="' . $escape($value) . '" readonly autocomplete="off" spellcheck="false"'
            . ' aria-label="' . $escape($label) . '" data-ojp-secret-value>'
            . '<button class="ojp-button ojp-button--small" type="button" data-ojp-secret-toggle'
            . ' aria-controls="' . $escape($id) . '" aria-pressed="false" aria-label="비밀번호 보임" title="비밀번호 보임">보임</button>'
            . '</div>';
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;

final class WordPressActionSecurity implements ActionSecurity
{
    private const NONCE_FIELD = '_oj_nonce';

    public function hiddenFields(string $actionKey): string
    {
        $nonce = wp_create_nonce($actionKey);

        return '<input type="hidden" name="action" value="'
            . esc_attr($actionKey)
            . '"><input type="hidden" name="' . self::NONCE_FIELD . '" value="'
            . esc_attr($nonce)
            . '">';
    }

    public function verify(string $actionKey, HttpRequest $request): bool
    {
        $nonce = $request->bodyString(self::NONCE_FIELD);

        return $nonce !== '' && wp_verify_nonce($nonce, $actionKey) === 1;
    }
}

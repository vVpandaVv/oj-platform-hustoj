<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentSessionTerminator;
use RuntimeException;

final class WordPressCurrentSessionTerminator implements CurrentSessionTerminator
{
    public function terminate(): void
    {
        if (! function_exists('wp_logout')) {
            throw new RuntimeException('WordPress 로그아웃 기능을 사용할 수 없습니다.');
        }

        wp_logout();
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Console;

final class OperationsHtml
{
    public static function header(
        string $eyebrow,
        string $title,
        string $description
    ): string {
        return '<header class="ojp-page-header"><div><p class="ojp-eyebrow">'
            . self::escape($eyebrow) . '</p><h1>' . self::escape($title)
            . '</h1><p>' . self::escape($description)
            . '</p></div></header>';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    public static function selected(string $left, string $right): string
    {
        return $left === $right ? ' selected' : '';
    }

    public static function checked(bool $checked): string
    {
        return $checked ? ' checked' : '';
    }
}

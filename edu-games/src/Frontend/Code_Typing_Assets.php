<?php
declare(strict_types=1);

namespace EduGames\Frontend;

final class Code_Typing_Assets {
    public static function register(): void {
        if (is_admin()) {
            return;
        }

        wp_register_style(
            'edu-games-code-typing',
            plugins_url('blocks/code-typing/style.css', EDU_GAMES_FILE),
            array(),
            self::asset_version('blocks/code-typing/style.css')
        );

        wp_register_script(
            'edu-games-code-typing',
            plugins_url('blocks/code-typing/view.js', EDU_GAMES_FILE),
            array('edu-games-rank-pager','edu-games-progress'),
            self::asset_version('blocks/code-typing/view.js'),
            true
        );
    }

    public static function enqueue(): void {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('edu-games-code-typing');
        wp_enqueue_script('edu-games-code-typing');
    }

    private static function asset_version(string $relative_path): string {
        $path = EDU_GAMES_PATH . ltrim($relative_path, '/');

        return is_readable($path) ? (string) filemtime($path) : EDU_GAMES_VERSION;
    }
}

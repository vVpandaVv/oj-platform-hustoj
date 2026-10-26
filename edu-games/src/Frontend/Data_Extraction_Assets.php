<?php
declare(strict_types=1);

namespace EduGames\Frontend;

final class Data_Extraction_Assets {
    public static function register(): void {
        if (is_admin()) {
            return;
        }

        wp_register_style(
            'edu-games-data-extraction',
            plugins_url('blocks/data-extraction/style.css', EDU_GAMES_FILE),
            array(),
            self::asset_version('blocks/data-extraction/style.css')
        );

        wp_register_script(
            'edu-games-data-extraction',
            plugins_url('blocks/data-extraction/view.js', EDU_GAMES_FILE),
            array('edu-games-rank-pager'),
            self::asset_version('blocks/data-extraction/view.js'),
            true
        );
    }

    public static function enqueue(): void {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('edu-games-data-extraction');
        wp_enqueue_script('edu-games-data-extraction');
    }

    private static function asset_version(string $relative_path): string {
        $path = EDU_GAMES_PATH . ltrim($relative_path, '/');

        return is_readable($path) ? (string) filemtime($path) : EDU_GAMES_VERSION;
    }
}

<?php
declare(strict_types=1);

namespace EduGames\Frontend;

final class Image_Data_Assets {
    public static function register(): void {
        if (is_admin()) {
            return;
        }

        wp_register_style(
            'edu-games-image-data',
            plugins_url('blocks/image-data/style.css', EDU_GAMES_FILE),
            array(),
            self::asset_version('blocks/image-data/style.css')
        );

        wp_register_script(
            'edu-games-image-data',
            plugins_url('blocks/image-data/view.js', EDU_GAMES_FILE),
            array('edu-games-progress'),
            self::asset_version('blocks/image-data/view.js'),
            true
        );
    }

    public static function enqueue(): void {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('edu-games-image-data');
        wp_enqueue_script('edu-games-image-data');
    }

    private static function asset_version(string $relative_path): string {
        $path = EDU_GAMES_PATH . ltrim($relative_path, '/');

        return is_readable($path) ? (string) filemtime($path) : EDU_GAMES_VERSION;
    }
}

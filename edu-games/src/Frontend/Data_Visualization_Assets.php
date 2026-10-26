<?php
declare(strict_types=1);

namespace EduGames\Frontend;

final class Data_Visualization_Assets {
    public static function register(): void {
        if (is_admin()) {
            return;
        }

        wp_register_style(
            'edu-games-data-visualization',
            plugins_url('blocks/data-visualization/style.css', EDU_GAMES_FILE),
            array(),
            self::asset_version('blocks/data-visualization/style.css')
        );

        wp_register_script(
            'edu-games-data-visualization',
            plugins_url('blocks/data-visualization/view.js', EDU_GAMES_FILE),
            array('edu-games-rank-pager'),
            self::asset_version('blocks/data-visualization/view.js'),
            true
        );
    }

    public static function enqueue(): void {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('edu-games-data-visualization');
        wp_enqueue_script('edu-games-data-visualization');
    }

    private static function asset_version(string $relative_path): string {
        $path = EDU_GAMES_PATH . ltrim($relative_path, '/');

        return is_readable($path) ? (string) filemtime($path) : EDU_GAMES_VERSION;
    }
}

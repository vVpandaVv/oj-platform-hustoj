<?php
declare(strict_types=1);

namespace EduGames\Frontend;

final class Outlier_Detection_Assets {
    public static function register(): void {
        if (is_admin()) {
            return;
        }

        wp_register_style(
            'edu-games-outlier-detection',
            plugins_url('blocks/outlier-detection/style.css', EDU_GAMES_FILE),
            array(),
            self::asset_version('blocks/outlier-detection/style.css')
        );

        wp_register_script(
            'edu-games-outlier-detection',
            plugins_url('blocks/outlier-detection/view.js', EDU_GAMES_FILE),
            array('edu-games-rank-pager'),
            self::asset_version('blocks/outlier-detection/view.js'),
            true
        );
    }

    public static function enqueue(): void {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('edu-games-outlier-detection');
        wp_enqueue_script('edu-games-outlier-detection');
    }

    private static function asset_version(string $relative_path): string {
        $path = EDU_GAMES_PATH . ltrim($relative_path, '/');

        return is_readable($path) ? (string) filemtime($path) : EDU_GAMES_VERSION;
    }
}

<?php
declare(strict_types=1);

namespace EduGames\Frontend;

final class AI_Classifier_Lab_Assets {
    public static function register(): void {
        if (is_admin()) {
            return;
        }

        wp_register_style(
            'edu-games-ai-classifier-lab',
            plugins_url('blocks/ai-classifier-lab/style.css', EDU_GAMES_FILE),
            array(),
            self::asset_version('blocks/ai-classifier-lab/style.css')
        );

        wp_register_script(
            'edu-games-ai-classifier-lab',
            plugins_url('blocks/ai-classifier-lab/view.js', EDU_GAMES_FILE),
            array('edu-games-rank-pager'),
            self::asset_version('blocks/ai-classifier-lab/view.js'),
            true
        );
    }

    public static function enqueue(): void {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('edu-games-ai-classifier-lab');
        wp_enqueue_script('edu-games-ai-classifier-lab');
    }

    private static function asset_version(string $relative_path): string {
        $path = EDU_GAMES_PATH . ltrim($relative_path, '/');

        return is_readable($path) ? (string) filemtime($path) : EDU_GAMES_VERSION;
    }
}

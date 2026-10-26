<?php
declare(strict_types=1);

namespace EduGames\Frontend;

final class AI_Model_Lab_Assets {
    public static function register(): void {
        if (is_admin()) {
            return;
        }

        wp_register_style(
            'edu-games-ai-model-lab',
            plugins_url('blocks/ai-model-lab/style.css', EDU_GAMES_FILE),
            array(),
            self::asset_version('blocks/ai-model-lab/style.css')
        );

        wp_register_script(
            'edu-games-ai-model-lab',
            plugins_url('blocks/ai-model-lab/view.js', EDU_GAMES_FILE),
            array(),
            self::asset_version('blocks/ai-model-lab/view.js'),
            true
        );
    }

    public static function enqueue(): void {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style('edu-games-ai-model-lab');
        wp_enqueue_script('edu-games-ai-model-lab');
    }

    private static function asset_version(string $relative_path): string {
        $path = EDU_GAMES_PATH . ltrim($relative_path, '/');

        return is_readable($path) ? (string) filemtime($path) : EDU_GAMES_VERSION;
    }
}

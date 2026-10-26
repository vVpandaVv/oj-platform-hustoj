<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\AI_Model_Lab_Renderer;
use EduGames\Frontend\AI_Model_Lab_Assets;
use EduGames\Games\Modules\AI_Model_Lab\AI_Model_Lab_Module;

final class AI_Model_Lab_Shortcode {
    public static function register(): void {
        add_shortcode(AI_Model_Lab_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . AI_Model_Lab_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        AI_Model_Lab_Assets::enqueue();

        return AI_Model_Lab_Renderer::render($atts, false);
    }
}

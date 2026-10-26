<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Binary_Arcade_Renderer;
use EduGames\Frontend\Binary_Arcade_Assets;
use EduGames\Games\Modules\Binary_Conversion\Binary_Conversion_Module;

final class Binary_Arcade_Shortcode {
    public static function register(): void {
        add_shortcode(Binary_Conversion_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Binary_Conversion_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Binary_Arcade_Assets::enqueue();

        return Binary_Arcade_Renderer::render($atts, false);
    }
}

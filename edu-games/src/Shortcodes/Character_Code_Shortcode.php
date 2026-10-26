<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Character_Code_Renderer;
use EduGames\Frontend\Character_Code_Assets;
use EduGames\Games\Modules\Character_Code\Character_Code_Module;

final class Character_Code_Shortcode {
    public static function register(): void {
        add_shortcode(Character_Code_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Character_Code_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Character_Code_Assets::enqueue();

        return Character_Code_Renderer::render($atts, false);
    }
}

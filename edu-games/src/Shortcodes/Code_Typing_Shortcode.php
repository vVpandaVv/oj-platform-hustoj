<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Code_Typing_Renderer;
use EduGames\Frontend\Code_Typing_Assets;
use EduGames\Games\Modules\Code_Typing\Code_Typing_Module;

final class Code_Typing_Shortcode {
    public static function register(): void {
        add_shortcode(Code_Typing_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Code_Typing_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Code_Typing_Assets::enqueue();

        return Code_Typing_Renderer::render($atts, false);
    }
}

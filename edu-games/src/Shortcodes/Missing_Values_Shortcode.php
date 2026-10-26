<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Missing_Values_Renderer;
use EduGames\Frontend\Missing_Values_Assets;
use EduGames\Games\Modules\Missing_Values\Missing_Values_Module;

final class Missing_Values_Shortcode {
    public static function register(): void {
        add_shortcode(Missing_Values_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Missing_Values_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Missing_Values_Assets::enqueue();

        return Missing_Values_Renderer::render($atts, false);
    }
}

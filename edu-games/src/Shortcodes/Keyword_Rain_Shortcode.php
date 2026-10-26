<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Keyword_Rain_Renderer;
use EduGames\Frontend\Keyword_Rain_Assets;
use EduGames\Games\Modules\Keyword_Rain\Keyword_Rain_Module;

final class Keyword_Rain_Shortcode {
    public static function register(): void {
        add_shortcode(Keyword_Rain_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Keyword_Rain_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Keyword_Rain_Assets::enqueue();

        return Keyword_Rain_Renderer::render($atts, false);
    }
}

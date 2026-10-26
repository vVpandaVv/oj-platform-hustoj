<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Outlier_Detection_Renderer;
use EduGames\Frontend\Outlier_Detection_Assets;
use EduGames\Games\Modules\Outlier_Detection\Outlier_Detection_Module;

final class Outlier_Detection_Shortcode {
    public static function register(): void {
        add_shortcode(Outlier_Detection_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Outlier_Detection_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Outlier_Detection_Assets::enqueue();

        return Outlier_Detection_Renderer::render($atts, false);
    }
}

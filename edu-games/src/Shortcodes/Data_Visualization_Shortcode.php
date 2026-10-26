<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Data_Visualization_Renderer;
use EduGames\Frontend\Data_Visualization_Assets;
use EduGames\Games\Modules\Data_Visualization\Data_Visualization_Module;

final class Data_Visualization_Shortcode {
    public static function register(): void {
        add_shortcode(Data_Visualization_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Data_Visualization_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Data_Visualization_Assets::enqueue();

        return Data_Visualization_Renderer::render($atts, false);
    }
}

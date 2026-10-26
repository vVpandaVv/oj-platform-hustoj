<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Device_Matching_Renderer;
use EduGames\Frontend\Device_Matching_Assets;
use EduGames\Games\Modules\Device_Matching\Device_Matching_Module;

final class Device_Matching_Shortcode {
    public static function register(): void {
        add_shortcode(Device_Matching_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Device_Matching_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Device_Matching_Assets::enqueue();

        return Device_Matching_Renderer::render($atts, false);
    }
}

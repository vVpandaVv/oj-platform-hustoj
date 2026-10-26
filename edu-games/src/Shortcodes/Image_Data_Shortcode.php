<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Image_Data_Renderer;
use EduGames\Frontend\Image_Data_Assets;
use EduGames\Games\Modules\Image_Data\Image_Data_Module;

final class Image_Data_Shortcode {
    public static function register(): void {
        add_shortcode(Image_Data_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Image_Data_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Image_Data_Assets::enqueue();

        return Image_Data_Renderer::render($atts, false);
    }
}

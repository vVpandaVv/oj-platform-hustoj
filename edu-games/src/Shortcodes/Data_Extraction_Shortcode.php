<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Data_Extraction_Renderer;
use EduGames\Frontend\Data_Extraction_Assets;
use EduGames\Games\Modules\Data_Extraction\Data_Extraction_Module;

final class Data_Extraction_Shortcode {
    public static function register(): void {
        add_shortcode(Data_Extraction_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Data_Extraction_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Data_Extraction_Assets::enqueue();

        return Data_Extraction_Renderer::render($atts, false);
    }
}

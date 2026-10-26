<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

use EduGames\Blocks\Data_Structure_Lab_Renderer;
use EduGames\Frontend\Data_Structure_Lab_Assets;
use EduGames\Games\Modules\Data_Structure_Lab\Data_Structure_Lab_Module;

final class Data_Structure_Lab_Shortcode {
    public static function register(): void {
        add_shortcode(Data_Structure_Lab_Module::SHORTCODE, array(__CLASS__, 'render'));
        add_shortcode('oj_' . Data_Structure_Lab_Module::SHORTCODE, array(__CLASS__, 'render'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts = array()): string {
        Data_Structure_Lab_Assets::enqueue();

        return Data_Structure_Lab_Renderer::render($atts, false);
    }
}

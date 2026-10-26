<?php
declare(strict_types=1);

namespace EduGames\Core;

use EduGames\Addon\Oj_Platform_Registration;
use EduGames\Blocks\Block_Registrar;
use EduGames\Frontend\AI_Classifier_Lab_Assets;
use EduGames\Frontend\AI_Model_Lab_Assets;
use EduGames\Frontend\Binary_Arcade_Assets;
use EduGames\Frontend\Character_Code_Assets;
use EduGames\Frontend\Code_Typing_Assets;
use EduGames\Frontend\Data_Extraction_Assets;
use EduGames\Frontend\Data_Visualization_Assets;
use EduGames\Frontend\Data_Structure_Lab_Assets;
use EduGames\Frontend\Device_Matching_Assets;
use EduGames\Frontend\Game_Access_Guard;
use EduGames\Frontend\Image_Data_Assets;
use EduGames\Frontend\Keyword_Rain_Assets;
use EduGames\Frontend\Missing_Values_Assets;
use EduGames\Frontend\Outlier_Detection_Assets;
use EduGames\Shortcodes\AI_Classifier_Lab_Shortcode;
use EduGames\Shortcodes\AI_Model_Lab_Shortcode;
use EduGames\Shortcodes\Binary_Arcade_Shortcode;
use EduGames\Shortcodes\Character_Code_Shortcode;
use EduGames\Shortcodes\Code_Typing_Shortcode;
use EduGames\Shortcodes\Data_Extraction_Shortcode;
use EduGames\Shortcodes\Data_Visualization_Shortcode;
use EduGames\Shortcodes\Data_Structure_Lab_Shortcode;
use EduGames\Shortcodes\Device_Matching_Shortcode;
use EduGames\Shortcodes\Game_Pages_Shortcodes;
use EduGames\Shortcodes\Image_Data_Shortcode;
use EduGames\Shortcodes\Keyword_Rain_Shortcode;
use EduGames\Shortcodes\Missing_Values_Shortcode;
use EduGames\Shortcodes\Outlier_Detection_Shortcode;
use EduGames\Database\Installer;
use EduGames\Games\Game_Module_Registry;
use EduGames\Rest\Api;

final class Plugin {
    private static ?Game_Module_Registry $registry = null;

    public static function boot(): void {
        add_action('init', array(__CLASS__, 'load_textdomain'));
        add_action('plugins_loaded', array(Installer::class, 'maybe_install'), 12);
        add_action('plugins_loaded', array(\EduGames\Learning\Installer::class, 'maybe_install'), 13);
        add_action('plugins_loaded', array(\EduGames\Progress\Store::class, 'install'), 14);
        add_action('plugins_loaded', array(__CLASS__, 'register_contracts'), 20);
        // Runs on init, not plugins_loaded — post functions like this one
        // rely on parts of WordPress (rewrite rules among them) that aren't
        // set up yet at plugins_loaded; calling them that early previously
        // fataled the whole site on every request (see git history).
        add_action('init', array(Legacy_Ranking_Page_Cleanup::class, 'maybe_remove_legacy_page'), 20);
        add_action('init', array(Block_Registrar::class, 'register'));
        add_action('init', array(Game_Access_Guard::class, 'register'));
        add_action('init', array(AI_Classifier_Lab_Shortcode::class, 'register'));
        add_action('init', array(AI_Model_Lab_Shortcode::class, 'register'));
        add_action('init', array(Binary_Arcade_Shortcode::class, 'register'));
        add_action('init', array(Character_Code_Shortcode::class, 'register'));
        add_action('init', array(Code_Typing_Shortcode::class, 'register'));
        add_action('init', array(Data_Extraction_Shortcode::class, 'register'));
        add_action('init', array(Data_Visualization_Shortcode::class, 'register'));
        add_action('init', array(Data_Structure_Lab_Shortcode::class, 'register'));
        add_action('init', array(Device_Matching_Shortcode::class, 'register'));
        add_action('init', array(Image_Data_Shortcode::class, 'register'));
        add_action('init', array(Keyword_Rain_Shortcode::class, 'register'));
        add_action('init', array(Missing_Values_Shortcode::class, 'register'));
        add_action('init', array(Outlier_Detection_Shortcode::class, 'register'));
        add_action('init', array(Game_Pages_Shortcodes::class, 'register'));
        add_action('init', array(AI_Classifier_Lab_Assets::class, 'register'));
        add_action('init', array(AI_Model_Lab_Assets::class, 'register'));
        add_action('init', array(Binary_Arcade_Assets::class, 'register'));
        add_action('init', array(Character_Code_Assets::class, 'register'));
        add_action('init', array(Code_Typing_Assets::class, 'register'));
        add_action('init', array(Data_Extraction_Assets::class, 'register'));
        add_action('init', array(Data_Visualization_Assets::class, 'register'));
        add_action('init', array(Data_Structure_Lab_Assets::class, 'register'));
        add_action('init', array(Device_Matching_Assets::class, 'register'));
        add_action('init', array(Image_Data_Assets::class, 'register'));
        add_action('init', array(Keyword_Rain_Assets::class, 'register'));
        add_action('init', array(Missing_Values_Assets::class, 'register'));
        add_action('init', array(Outlier_Detection_Assets::class, 'register'));
        add_action('rest_api_init', array(Api::class, 'register_routes'));
        Oj_Platform_Registration::register();
    }

    public static function activate(): void {
        Installer::install();
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain('edu-games', false, dirname(plugin_basename(EDU_GAMES_FILE)) . '/languages');
    }

    public static function register_contracts(): void {
        do_action('edu_games_registry_ready', self::registry());
    }

    public static function registry(): Game_Module_Registry {
        if (! self::$registry instanceof Game_Module_Registry) {
            self::$registry = new Game_Module_Registry();
        }

        return self::$registry;
    }
}

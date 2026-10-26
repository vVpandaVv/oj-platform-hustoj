<?php
declare(strict_types=1);

namespace LearningMaterials\Core;

use LearningMaterials\Addon\Oj_Platform_Registration;
use LearningMaterials\Database\Installer;
use LearningMaterials\Http\Materials_Action_Controller;
use LearningMaterials\Http\Preview_Diagnostics_Controller;

final class Plugin {
    public static function boot(): void {
        add_action('init', array(__CLASS__, 'load_textdomain'));
        add_action('plugins_loaded', array(Installer::class, 'maybe_install'), 12);
        Materials_Action_Controller::register();
        Preview_Diagnostics_Controller::register();
        Oj_Platform_Registration::register();
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain('learning-materials', false, dirname(plugin_basename(LEARNING_MATERIALS_FILE)) . '/languages');
    }
}

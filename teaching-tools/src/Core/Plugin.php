<?php
declare(strict_types=1);

namespace TeachingTools\Core;

use TeachingTools\Admin\Realtime_Settings_Page;
use TeachingTools\Admin\Privacy_Tools;
use TeachingTools\Admin\Session_Report;
use TeachingTools\Addon\Oj_Platform_Registration;
use TeachingTools\Database\Installer;
use TeachingTools\Jobs\Session_Cleanup;
use TeachingTools\Rest\Api;
use TeachingTools\Rest\Controllers\Whiteboard_Controller;

final class Plugin {
    public static function boot(): void {
        add_action('init', array(__CLASS__, 'load_textdomain'));
        add_action('plugins_loaded', array(Installer::class, 'maybe_install'), 12);
        add_action('rest_api_init', array(Api::class, 'register_routes'));
        Whiteboard_Controller::register_download();
        Realtime_Settings_Page::register();
        Privacy_Tools::register();
        Session_Report::register();
        Session_Cleanup::register();
        Oj_Platform_Registration::register();
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain('teaching-tools', false, dirname(plugin_basename(TEACHING_TOOLS_FILE)) . '/languages');
    }
}

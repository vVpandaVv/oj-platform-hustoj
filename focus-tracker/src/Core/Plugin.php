<?php
declare(strict_types=1);

namespace FocusTracker\Core;

use FocusTracker\Addon\Oj_Platform_Registration;
use FocusTracker\Admin\Settings_Page;
use FocusTracker\Database\Installer;
use FocusTracker\Jobs\Retention_Cleanup;
use FocusTracker\Rest\Api;
use FocusTracker\Tracking\Tracked_Page_Enqueuer;
use FocusTracker\Privacy\Personal_Data;
use FocusTracker\Support\Request_Limiter;
use FocusTracker\Support\Release_Readiness;
use FocusTracker\Support\Student_Participation_Defaults;

final class Plugin {
    public static function boot(): void {
        add_action('init', array(__CLASS__, 'load_textdomain'));
        add_action('plugins_loaded', array(Installer::class, 'maybe_install'), 12);
        add_action('rest_api_init', array(Api::class, 'register_routes'));
        Settings_Page::register();
        Personal_Data::register();
        Request_Limiter::register();
        Release_Readiness::register();
        Student_Participation_Defaults::register();

        // Theme-rendered fallback. Standalone platform pages receive the
        // tracker through the manifest's siteAsset definition instead.
        add_action('wp_enqueue_scripts', array(Tracked_Page_Enqueuer::class, 'maybe_enqueue'));

        Retention_Cleanup::register();
        Oj_Platform_Registration::register();
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain('focus-tracker', false, dirname(plugin_basename(FOCUS_TRACKER_FILE)) . '/languages');
    }
}

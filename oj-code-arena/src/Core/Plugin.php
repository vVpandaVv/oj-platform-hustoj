<?php
declare(strict_types=1);

namespace OjCodeArena\Core;

use OjCodeArena\Addon\Oj_Platform_Registration;
use OjCodeArena\Admin\Settings_Page;
use OjCodeArena\Database\Installer;
use OjCodeArena\Integration\Edu_Games;
use OjCodeArena\Jobs\Cleanup;
use OjCodeArena\Rest\Api;
use OjCodeArena\Support\Privacy;

final class Plugin {
    public static function boot(): void {
        add_action('init', array(__CLASS__, 'load_textdomain'));
        add_action('plugins_loaded', array(Installer::class, 'maybe_install'), 12);
        add_action('rest_api_init', array(Api::class, 'register_routes'));
        Settings_Page::register();
        Cleanup::register();
        Privacy::register();
        Edu_Games::register();
        Oj_Platform_Registration::register();
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain('oj-code-arena', false, dirname(plugin_basename(OJ_CODE_ARENA_FILE)) . '/languages');
    }
}

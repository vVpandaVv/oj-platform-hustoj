<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Core;

use OjCodeLab\Addon\Oj_Platform_Registration;
use OjCodeLab\Database\Installer;
use OjCodeLab\Jobs\Dataset_Cache_Cleanup;
use OjCodeLab\Rest\Api;
use OjCodeLab\Support\Cross_Origin_Isolation;
use OjCodeLab\Support\Worker_Script_Route;

final class Plugin {
    public static function boot(): void {
        add_action('init', array(__CLASS__, 'load_textdomain'));
        add_action('plugins_loaded', array(Installer::class, 'maybe_install'), 12);
        add_action('rest_api_init', array(Api::class, 'register_routes'));

        Dataset_Cache_Cleanup::register();
        add_action('oj_code_lab_summary_backfill', array(\OjCodeLab\Database\Notebook_Repository::class, 'backfill_summaries'));
        \OjCodeLab\Support\Privacy::register();
        \OjCodeLab\Support\Operations::register();
        Cross_Origin_Isolation::register();
        Worker_Script_Route::register();
        Oj_Platform_Registration::register();
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain('oj-code-lab', false, dirname(plugin_basename(OJ_CODE_LAB_FILE)) . '/languages');
    }
}

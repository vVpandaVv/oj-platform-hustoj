<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Remove only this example's user-meta key when WordPress deletes the plugin.
delete_metadata('user', 0, '_oj_addon_starter_note', '', true);

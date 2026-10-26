<?php
declare(strict_types=1);

/**
 * Runs only when the plugin is removed via "삭제" in the Plugins screen —
 * never on deactivate/reactivate, so a teacher toggling the plugin off for a
 * day never loses saved seating layouts. WordPress includes this file
 * standalone (the main teaching-tools.php bootstrap, its autoloader, and
 * every TEACHING_TOOLS_* constant are NOT loaded), so table/option/upload
 * names are hardcoded here rather than reused from Installer or
 * Whiteboard_Image_Repository.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$participants_table = $wpdb->prefix . 'teaching_tools_session_participants';
$sessions_table = $wpdb->prefix . 'teaching_tools_sessions';
$seating_layouts_table = $wpdb->prefix . 'teaching_tools_seating_layouts';
$session_hosts_table = $wpdb->prefix . 'teaching_tools_session_hosts';
$lesson_templates_table = $wpdb->prefix . 'teaching_tools_lesson_templates';

// Participants first — it has no FK enforcement but logically depends on
// sessions, so drop in dependency order for anyone reading this later.
$wpdb->query("DROP TABLE IF EXISTS {$participants_table}");
$wpdb->query("DROP TABLE IF EXISTS {$session_hosts_table}");
$wpdb->query("DROP TABLE IF EXISTS {$sessions_table}");
$wpdb->query("DROP TABLE IF EXISTS {$seating_layouts_table}");
$wpdb->query("DROP TABLE IF EXISTS {$lesson_templates_table}");

delete_option('teaching_tools_db_version');
delete_option('teaching_tools_realtime');
delete_option('teaching_tools_retention_days');
delete_option('teaching_tools_active_session_hours');

// Session_Code_Guard's rate-limit transients expire on their own within
// minutes, but clean them up anyway rather than leaving stray rows behind.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_httt_code_guard_') . '%'
    )
);
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_timeout_httt_code_guard_') . '%'
    )
);

// Whiteboard images are already deleted per-session on close and by the
// cleanup cron (see Whiteboard_Image_Repository::delete_for_session()), but
// remove the whole upload directory as a final backstop in case any session
// folder was left behind (e.g. a crash between the DB row and the files).
$upload_dir = wp_upload_dir();
$whiteboard_dir = trailingslashit((string) $upload_dir['basedir']) . 'teaching-tools-whiteboard';

if (is_dir($whiteboard_dir)) {
    teaching_tools_uninstall_delete_directory($whiteboard_dir);
}

function teaching_tools_uninstall_delete_directory(string $dir): void {
    $items = scandir($dir);
    if (false === $items) {
        return;
    }

    foreach ($items as $item) {
        if ('.' === $item || '..' === $item) {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            teaching_tools_uninstall_delete_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

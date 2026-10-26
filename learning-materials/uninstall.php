<?php
declare(strict_types=1);

/**
 * Runs only when the plugin is removed via "삭제" in the Plugins screen —
 * never on deactivate/reactivate. WordPress includes this file standalone
 * (the main learning-materials.php bootstrap, its autoloader, and every
 * LEARNING_MATERIALS_* constant are NOT loaded), so table/option/storage
 * names are hardcoded here rather than reused from Installer/Material_Storage.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$material_groups_table = $wpdb->prefix . 'learning_material_groups';
$materials_table = $wpdb->prefix . 'learning_materials';

// Groups link table first — it has no FK enforcement but logically depends
// on materials, so drop in dependency order for anyone reading this later.
$wpdb->query("DROP TABLE IF EXISTS {$material_groups_table}");
$wpdb->query("DROP TABLE IF EXISTS {$materials_table}");

delete_option('learning_materials_db_version');

$private_dir = trailingslashit((string) WP_CONTENT_DIR) . 'learning-materials-private';
if (is_dir($private_dir)) {
    learning_materials_uninstall_delete_directory($private_dir);
}

function learning_materials_uninstall_delete_directory(string $dir): void {
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
            learning_materials_uninstall_delete_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

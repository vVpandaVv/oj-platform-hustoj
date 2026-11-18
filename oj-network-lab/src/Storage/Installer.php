<?php
declare(strict_types=1);
namespace OjNetworkLab\Storage;
final class Installer
{
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'oj_network_lab_projects'; }
    public static function maybeInstall(): void
    {
        if (get_option('oj_network_lab_schema', '') === '1' || (int) get_option('oj_network_lab_install_retry', 0) > time()) { return; }
        update_option('oj_network_lab_install_retry', time() + 60, false);
        if (self::install()) { delete_option('oj_network_lab_install_retry'); }
    }
    public static function install(): bool
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            client_id varchar(36) NOT NULL,
            title varchar(80) NOT NULL,
            project_json longtext NOT NULL,
            project_hash char(64) NOT NULL,
            revision bigint unsigned NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY owner_client (user_id,client_id),
            KEY owner_updated (user_id,updated_at),
            KEY retention (updated_at)
        ) ENGINE=InnoDB {$charset};");
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) { return false; }
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . $table);
        foreach (['id', 'user_id', 'client_id', 'title', 'project_json', 'project_hash', 'revision', 'updated_at'] as $name) { if (!in_array($name, $columns, true)) { return false; } }
        update_option('oj_network_lab_schema', '1', false); return get_option('oj_network_lab_schema') === '1';
    }
}

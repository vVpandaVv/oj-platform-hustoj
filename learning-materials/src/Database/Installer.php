<?php
declare(strict_types=1);

namespace LearningMaterials\Database;

final class Installer {
    public static function maybe_install(): void {
        if (get_option('learning_materials_db_version') !== LEARNING_MATERIALS_VERSION || ! self::materials_table_exists()) {
            self::install();
        }
    }

    public static function install(): void {
        self::create_materials_table();
        self::create_material_groups_table();
        update_option('learning_materials_db_version', LEARNING_MATERIALS_VERSION);
    }

    public static function materials_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'learning_materials';
    }

    public static function material_groups_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'learning_material_groups';
    }

    private static function create_materials_table(): void {
        global $wpdb;

        $table = self::materials_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL,
            description longtext NULL,
            source_url text NULL,
            category varchar(120) NOT NULL DEFAULT '',
            tags_json text NULL,
            is_pinned tinyint(1) NOT NULL DEFAULT 0,
            publication_status varchar(16) NOT NULL DEFAULT 'published',
            publish_from datetime NULL,
            publish_until datetime NULL,
            storage_filename varchar(255) NOT NULL,
            original_filename varchar(255) NOT NULL,
            extension varchar(10) NOT NULL,
            mime_type varchar(150) NOT NULL,
            file_size bigint(20) unsigned NOT NULL,
            uploaded_by_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY uploaded_by (uploaded_by_user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_material_groups_table(): void {
        global $wpdb;

        $table = self::material_groups_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            material_id bigint(20) unsigned NOT NULL,
            group_id varchar(160) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY material_group (material_id, group_id),
            KEY material_id (material_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function materials_table_exists(): bool {
        global $wpdb;

        $table = self::materials_table();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}

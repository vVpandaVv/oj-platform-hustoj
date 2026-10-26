<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Database;

use OjCodeLab\Datasets\Sample_Dataset_Seeder;

final class Installer {
    public const SCHEMA_VERSION = '2';
    public static function maybe_install(): void {
        if (get_option('oj_code_lab_db_version') !== self::SCHEMA_VERSION || ! self::tables_exist()) {
            self::install();
        }
    }

    public static function install(): void {
        $first_install = ! get_option('oj_code_lab_db_version');
        self::create_notebooks_table();
        self::create_datasets_table();
        self::create_dataset_cache_table();
        self::create_uploads_table();
        global $wpdb;
        if (! self::tables_exist()) {
            update_option('oj_code_lab_install_error', '필수 테이블 생성에 실패했습니다.', false);
            return;
        }
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . self::notebooks_table());
        if (array_diff(array('revision', 'cell_count', 'has_error'), $columns)) {
            update_option('oj_code_lab_install_error', '노트북 스키마 변경에 실패했습니다.', false);
            return;
        }
        if ($first_install) {
            $seeded = Sample_Dataset_Seeder::restore_missing();
            if (is_wp_error($seeded)) {
                update_option('oj_code_lab_install_error', $seeded->get_error_message(), false);
                return;
            }
        }
        // Legacy cache timestamps used site time. Cache content is disposable.
        if (get_option('oj_code_lab_db_version') !== self::SCHEMA_VERSION) {
            if (false === $wpdb->query('DELETE FROM ' . self::dataset_cache_table())) {
                update_option('oj_code_lab_install_error', '캐시 시간대 마이그레이션에 실패했습니다.', false);
                return;
            }
        }
        Notebook_Repository::backfill_summaries();
        if ('' !== $wpdb->last_error) {
            update_option('oj_code_lab_install_error', '노트북 요약 마이그레이션에 실패했습니다.', false);
            return;
        }
        delete_option('oj_code_lab_install_error');
        update_option('oj_code_lab_db_version', self::SCHEMA_VERSION, false);
    }

    public static function notebooks_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'oj_code_lab_notebooks';
    }

    public static function datasets_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'oj_code_lab_datasets';
    }

    public static function dataset_cache_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'oj_code_lab_dataset_cache';
    }

    public static function uploads_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'oj_code_lab_uploads';
    }

    private static function create_notebooks_table(): void {
        global $wpdb;

        $table = self::notebooks_table();
        $charset_collate = $wpdb->get_charset_collate();

        // One row per learner: this addon gives each student a single
        // scratch notebook, not a multi-file project — isolation between
        // students comes from keying every row on their own user_id.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            cells longtext NOT NULL,
            updated_at datetime NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            cell_count int(10) unsigned NULL,
            has_error tinyint(1) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_datasets_table(): void {
        global $wpdb;

        $table = self::datasets_table();
        $charset_collate = $wpdb->get_charset_collate();

        // Teacher-curated shortcuts (docs/README §데이터 불러오기): each row
        // is one dataset that shows up in every student's "추천 데이터"
        // list. `source` picks which of the other columns is meaningful —
        // 'kaggle' uses kaggle_owner/kaggle_slug (+ optional file_name),
        // 'url' uses source_url (공공데이터포털 direct links, GitHub raw
        // CSVs, or anything on the school's own server), and 'upload' uses
        // upload_key to find a file the teacher sent straight to this
        // plugin (see Datasets\Upload_Store). file_name is always the
        // display/download name shown to students. sample_key is NULL for
        // anything a teacher added themselves; for a bundled sample
        // (Datasets\Sample_Dataset_Seeder) it's that sample's stable id,
        // used to tell "never seeded" apart from "a teacher deleted it" so
        // restoring defaults never creates duplicates.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            label varchar(191) NOT NULL,
            icon varchar(8) NOT NULL DEFAULT '',
            source varchar(20) NOT NULL DEFAULT 'kaggle',
            kaggle_owner varchar(191) NULL,
            kaggle_slug varchar(191) NULL,
            source_url varchar(500) NULL,
            upload_key varchar(64) NULL,
            file_name varchar(255) NULL,
            sample_key varchar(64) NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            sort_order int(10) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY sort_order (sort_order),
            KEY sample_key (sample_key)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_dataset_cache_table(): void {
        global $wpdb;

        $table = self::dataset_cache_table();
        $charset_collate = $wpdb->get_charset_collate();

        // Fetched dataset bytes, keyed by an opaque sha1 of whatever the
        // caller used to identify the source (a Kaggle "owner/slug/file"
        // triplet or a raw URL — see Datasets\Dataset_Cache). Kept in the
        // database rather than a private filesystem directory: there is no
        // extra folder/permission for an operator to provision, and a
        // handful of classroom-sized CSVs (see Support\Dataset_Limits) is a
        // trivial amount of row storage. Rows past the 24h TTL are reaped
        // by Jobs\Dataset_Cache_Cleanup, not left to grow unbounded.
        $sql = "CREATE TABLE {$table} (
            cache_key char(40) NOT NULL,
            content longblob NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (cache_key),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_uploads_table(): void {
        global $wpdb;

        $table = self::uploads_table();
        $charset_collate = $wpdb->get_charset_collate();

        // Teacher-uploaded dataset files ("자체 서버 데이터" — see
        // Datasets\Upload_Store). Addressed only by an opaque UUID key;
        // Database\Dataset_Repository.file_name carries the display name
        // separately, so nothing here is ever used to build a path.
        $sql = "CREATE TABLE {$table} (
            upload_key char(36) NOT NULL,
            content longblob NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (upload_key)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function tables_exist(): bool {
        global $wpdb;
        foreach (array(self::notebooks_table(), self::datasets_table(), self::dataset_cache_table(), self::uploads_table()) as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { return false; }
        }
        return true;
    }
}

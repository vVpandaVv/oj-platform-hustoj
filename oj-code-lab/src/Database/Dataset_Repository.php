<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Database;

use WP_Error;

final class Dataset_Repository {
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array|WP_Error {
        global $wpdb;

        $table = Installer::datasets_table();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A);

        if ('' !== $wpdb->last_error) { return self::read_failure(); }
        return array_map(static fn (array $row): array => self::to_array($row), (array) $rows);
    }

    public static function find(int $id): array|WP_Error|null {
        global $wpdb;

        $table = Installer::datasets_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        if ('' !== $wpdb->last_error) { return self::read_failure(); }
        return null === $row ? null : self::to_array($row);
    }

    /**
     * @param array{label: string, icon: string, source: string, kaggleOwner?: ?string, kaggleSlug?: ?string, sourceUrl?: ?string, uploadKey?: ?string, fileName?: ?string, sampleKey?: ?string, createdBy: int} $fields
     */
    public static function create(array $fields): int|WP_Error {
        global $wpdb;

        $table = Installer::datasets_table();
        $result = $wpdb->insert(
            $table,
            array(
                'label' => $fields['label'],
                'icon' => $fields['icon'],
                'source' => $fields['source'],
                'kaggle_owner' => $fields['kaggleOwner'] ?? null,
                'kaggle_slug' => $fields['kaggleSlug'] ?? null,
                'source_url' => $fields['sourceUrl'] ?? null,
                'upload_key' => $fields['uploadKey'] ?? null,
                'file_name' => $fields['fileName'] ?? null,
                'sample_key' => $fields['sampleKey'] ?? null,
                'created_by' => $fields['createdBy'],
                'created_at' => current_time('mysql'),
                'sort_order' => self::next_sort_order(),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d')
        );

        if (false === $result) {
            return new WP_Error('oj_code_lab_dataset_save_failed', __('추천 데이터를 저장하지 못했습니다. 다시 시도해 주세요.', 'oj-code-lab'), array('status' => 500));
        }

        return (int) $wpdb->insert_id;
    }

    public static function delete(int $id): bool|WP_Error {
        global $wpdb;
        $table = Installer::datasets_table();
        $uploads = Installer::uploads_table();
        $result = $wpdb->query($wpdb->prepare("DELETE d, u FROM {$table} d LEFT JOIN {$uploads} u ON u.upload_key = d.upload_key WHERE d.id = %d", $id));
        if (false === $result) { return new WP_Error('oj_code_lab_dataset_delete_failed', '추천 데이터를 삭제하지 못했습니다. 다시 시도해 주세요.', array('status' => 500)); }
        return true;
    }

    /**
     * @return list<string> every sample_key currently present (used by
     *   Datasets\Sample_Dataset_Seeder to find which bundled samples are
     *   missing — either never seeded, or deleted by a teacher).
     */
    public static function existing_sample_keys(): array|WP_Error {
        global $wpdb;

        $table = Installer::datasets_table();
        $keys = $wpdb->get_col("SELECT sample_key FROM {$table} WHERE sample_key IS NOT NULL");

        if ('' !== $wpdb->last_error) { return self::read_failure(); }
        return array_map('strval', $keys);
    }

    private static function next_sort_order(): int {
        global $wpdb;

        $table = Installer::datasets_table();
        $max = $wpdb->get_var("SELECT MAX(sort_order) FROM {$table}");

        return null === $max ? 0 : ((int) $max + 10);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function to_array(array $row): array {
        return array(
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'icon' => (string) $row['icon'],
            'source' => (string) $row['source'],
            'kaggleOwner' => null !== $row['kaggle_owner'] ? (string) $row['kaggle_owner'] : null,
            'kaggleSlug' => null !== $row['kaggle_slug'] ? (string) $row['kaggle_slug'] : null,
            'sourceUrl' => null !== $row['source_url'] ? (string) $row['source_url'] : null,
            'fileName' => null !== $row['file_name'] ? (string) $row['file_name'] : null,
            'sampleKey' => null !== $row['sample_key'] ? (string) $row['sample_key'] : null,
            'createdBy' => (int) $row['created_by'],
            'createdAt' => (string) $row['created_at'],
        );
    }

    /**
     * Internal-only accessor (includes upload_key, never sent to the
     * browser) used by Dataset_Controller when resolving an import.
     */
    public static function upload_key(int $id): string|WP_Error|null {
        global $wpdb;

        $table = Installer::datasets_table();
        $key = $wpdb->get_var($wpdb->prepare("SELECT upload_key FROM {$table} WHERE id = %d", $id));

        if ('' !== $wpdb->last_error) { return self::read_failure(); }
        return null === $key ? null : (string) $key;
    }
    private static function read_failure(): WP_Error {
        return new WP_Error('oj_code_lab_dataset_read_failed', __('추천 데이터를 불러오지 못했습니다. 자료를 유지한 채 잠시 후 다시 시도해 주세요.', 'oj-code-lab'), array('status' => 500));
    }

}

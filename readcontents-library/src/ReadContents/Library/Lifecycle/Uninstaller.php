<?php
/**
 * Permanent cleanup for the OJ reading-library storage, not standalone editors.
 *
 * @package ReadContentsLibrary
 */

declare(strict_types=1);

namespace ReadContents\Library\Lifecycle;

use RuntimeException;

final class Uninstaller
{
    private const POST_TYPE = 'rcis_content_node';
    private const BATCH_SIZE = 100;
    private const OPTIONS = [
        'rcis_lesson_overrides',
        'rcis_editor_migration_version',
        'rcis_server_import_job',
        'contents_editor_version',
        'contents_editor_activated_at',
    ];

    public static function run(): void
    {
        global $wpdb;

        if (!is_multisite()) {
            self::deleteSiteContent();
            return;
        }

        $offset = 0;
        do {
            $siteIds = get_sites([
                'fields' => 'ids',
                'number' => self::BATCH_SIZE,
                'offset' => $offset,
                'orderby' => 'id',
                'order' => 'ASC',
            ]);
            if ('' !== $wpdb->last_error) {
                throw new RuntimeException('Could not read the site list for reading-library cleanup.');
            }
            foreach ($siteIds as $siteId) {
                switch_to_blog((int) $siteId);
                try {
                    self::deleteSiteContent();
                } finally {
                    restore_current_blog();
                }
            }
            $offset += count($siteIds);
        } while (count($siteIds) === self::BATCH_SIZE);
    }

    private static function deleteSiteContent(): void
    {
        global $wpdb;

        $lastId = 0;
        $deleted = 0;
        do {
            // Avoid status/language filters and offset skips as earlier rows disappear.
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                self::POST_TYPE,
                $lastId,
                self::BATCH_SIZE
            ));
            if ('' !== $wpdb->last_error) {
                throw new RuntimeException('Could not read the reading-library deletion batch.');
            }

            foreach ($ids as $id) {
                $lastId = (int) $id;
                $post = get_post($lastId);
                if (!$post) {
                    continue;
                }
                if (self::POST_TYPE !== $post->post_type) {
                    throw new RuntimeException('Reading content changed during uninstall.');
                }
                // WordPress removes this post's metadata, revisions and cached records.
                if (!wp_delete_post($lastId, true) || get_post($lastId)) {
                    throw new RuntimeException('A reading-library post could not be deleted.');
                }
                ++$deleted;
            }
        } while (count($ids) === self::BATCH_SIZE);

        foreach (self::OPTIONS as $option) {
            delete_option($option);
            $missing = new \stdClass();
            if (get_option($option, $missing) !== $missing) {
                throw new RuntimeException('A reading-library option could not be deleted.');
            }
        }
        delete_transient('contents_editor_runtime_health');

        if (function_exists('oj_log_audit_event')) {
            oj_log_audit_event(
                'readcontents_library_uninstalled',
                '읽기자료 플러그인 삭제로 등록된 콘텐츠를 정리했습니다.',
                ['deleted_nodes' => $deleted, 'post_type' => self::POST_TYPE],
                'info'
            );
        }
    }
}

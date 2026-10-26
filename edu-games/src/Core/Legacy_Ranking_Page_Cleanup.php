<?php
declare(strict_types=1);

namespace EduGames\Core;

/**
 * One-time migration: earlier versions of this plugin auto-created a plain
 * WordPress page titled "게임 순위" embedding the ranking block. That page
 * used the theme's own page template, so it looked inconsistent with the
 * rest of the platform — theme header/footer, WP admin bar and all.
 * Rankings now live at a proper Addon API public page instead (see
 * Oj_Platform_Registration::register_public_ranking()), which reuses the
 * platform's own page shell like every other addon page.
 *
 * This trashes a leftover page only when it contains the uncustomized
 * default block alone. Edited/empty pages and sites with trash disabled
 * are left intact. Runs once, tracked by
 * MIGRATION_DONE_OPTION_KEY.
 */
final class Legacy_Ranking_Page_Cleanup {
    private const PAGE_ID_OPTION_KEY = 'edu_games_ranking_page_id';
    private const OLD_CLEANUP_DONE_OPTION_KEY = 'edu_games_ranking_page_cleanup_done';
    private const MIGRATION_DONE_OPTION_KEY = 'edu_games_ranking_page_removed';
    private const BLOCK_NAME = 'edu-games/group-leaderboard';

    public static function maybe_remove_legacy_page(): void {
        if (get_option(self::MIGRATION_DONE_OPTION_KEY)) {
            return;
        }

        $tracked_id = (int) get_option(self::PAGE_ID_OPTION_KEY, 0);
        if ($tracked_id > 0) {
            $post = get_post($tracked_id);
            if ($post instanceof \WP_Post && self::looks_auto_generated($post)
                && defined('EMPTY_TRASH_DAYS') && EMPTY_TRASH_DAYS > 0
                && 'trash' !== $post->post_status) {
                if (! wp_trash_post($tracked_id)) {
                    return;
                }
            }
        }

        delete_option(self::PAGE_ID_OPTION_KEY);
        delete_option(self::OLD_CLEANUP_DONE_OPTION_KEY);
        update_option(self::MIGRATION_DONE_OPTION_KEY, 1);
    }

    private static function looks_auto_generated(\WP_Post $post): bool {
        if ('page' !== $post->post_type) {
            return false;
        }

        $blocks = array_values(array_filter(
            parse_blocks($post->post_content),
            static fn(array $block): bool => null !== $block['blockName'] || '' !== trim($block['innerHTML'])
        ));

        // Preserve empty/edited pages and customized blocks; only the original
        // standalone default block is safe to retire automatically.
        return 1 === count($blocks)
            && self::BLOCK_NAME === $blocks[0]['blockName']
            && empty($blocks[0]['attrs'])
            && empty($blocks[0]['innerBlocks'])
            && '' === trim($blocks[0]['innerHTML']);
    }
}

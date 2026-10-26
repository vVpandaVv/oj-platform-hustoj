<?php
declare(strict_types=1);

namespace EduGames\Frontend;

use EduGames\Games\Game_Visibility;
use EduGames\Support\Capabilities;

final class Game_Access_Guard {
    public static function register(): void {
        add_filter('pre_render_block', array(__CLASS__, 'pre_render_block'), 10, 2);
        add_filter('pre_do_shortcode_tag', array(__CLASS__, 'pre_do_shortcode_tag'), 10, 4);
    }

    /**
     * @param mixed $pre_render
     * @param array<string, mixed> $parsed_block
     * @return mixed
     */
    public static function pre_render_block($pre_render, array $parsed_block) {
        $block_name = (string) ($parsed_block['blockName'] ?? '');
        $prefix = 'edu-games/';

        if (! str_starts_with($block_name, $prefix)) {
            return $pre_render;
        }

        foreach (edu_games_registry()->all(false) as $game_id => $definition) {
            if (($definition['block'] ?? '') === $block_name) {
                return self::can_access($game_id) ? $pre_render : self::closed_notice();
            }
        }

        return $pre_render;
    }

    /**
     * @param mixed $return
     * @param string $tag
     * @param array<string, mixed>|string $attr
     * @param array<int, string> $m
     * @return mixed
     */
    public static function pre_do_shortcode_tag($return, string $tag, $attr, array $m) {
        $map = self::shortcode_game_map();

        if (! isset($map[$tag])) {
            return $return;
        }

        if (self::can_access($map[$tag])) {
            return $return;
        }

        return self::closed_notice();
    }

    private static function can_access(string $game_id): bool {
        if (Capabilities::can_preview_closed()) {
            return true;
        }

        return Game_Visibility::is_open($game_id);
    }

    private static function closed_notice(): string {
        return '<div class="edu-games-closed-notice" style="max-width:1120px;margin:12px auto;padding:18px;border:1px solid #d7dee9;border-radius:8px;background:#fff;color:#69758a;font-weight:800;">'
            . esc_html__('이 게임은 현재 공개되지 않았습니다.', 'edu-games')
            . '</div>';
    }

    /**
     * @return array<string, string>
     */
    private static function shortcode_game_map(): array {
        $map = array();

        foreach (edu_games_registry()->all(false) as $game_id => $definition) {
            $tag = (string) ($definition['shortcode'] ?? '');
            if ($tag === '') {
                continue;
            }

            $map[$tag] = $game_id;
            $map['oj_' . $tag] = $game_id;
        }

        return $map;
    }
}

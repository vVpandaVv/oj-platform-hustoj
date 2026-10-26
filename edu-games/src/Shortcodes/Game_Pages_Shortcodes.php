<?php
declare(strict_types=1);

namespace EduGames\Shortcodes;

final class Game_Pages_Shortcodes {
    public static function register(): void {
        add_shortcode('game_hub', array(__CLASS__, 'render_hub'));
        add_shortcode('oj_game_hub', array(__CLASS__, 'render_hub'));
        add_shortcode('game_leaderboard', array(__CLASS__, 'render_leaderboard'));
        add_shortcode('oj_game_leaderboard', array(__CLASS__, 'render_leaderboard'));
        add_shortcode('player_stats', array(__CLASS__, 'render_player_stats'));
        add_shortcode('oj_player_stats', array(__CLASS__, 'render_player_stats'));
        add_shortcode('group_leaderboard', array(__CLASS__, 'render_group_leaderboard'));
        add_shortcode('oj_group_leaderboard', array(__CLASS__, 'render_group_leaderboard'));
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render_hub(array $atts = array()): string {
        return do_blocks('<!-- wp:edu-games/game-hub /-->');
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render_leaderboard(array $atts = array()): string {
        return do_blocks('<!-- wp:edu-games/game-leaderboard /-->');
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render_player_stats(array $atts = array()): string {
        return do_blocks('<!-- wp:edu-games/player-stats /-->');
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function render_group_leaderboard(array $atts = array()): string {
        return do_blocks('<!-- wp:edu-games/group-leaderboard /-->');
    }
}

<?php
declare(strict_types=1);

namespace OjCodeArena\Integration;

use OjCodeArena\Addon\Oj_Platform_Registration;

/**
 * Loose link with the Edu Games plugin (when it is active): Code Arena shows
 * up as a card in the Edu Games hub. Nothing in Edu Games is changed —
 * its own extension hook is used, and the hub's fixed card address
 * (/oj-addons/edu-games/games/code-arena) is sent on to the arena page.
 *
 * Edu Games' score API refuses this card (it has no scoring rules), so its
 * leaderboards are untouched; arena records stay in Code Arena.
 */
final class Edu_Games {
    public const GAME_ID = 'code-arena';

    public static function register(): void {
        add_action('edu_games_registry_ready', array(__CLASS__, 'add_card'));
        add_action('init', array(__CLASS__, 'forward_hub_link'), 1);
    }

    /** @param object $registry EduGames\Games\Game_Module_Registry */
    public static function add_card($registry): void {
        if (! is_object($registry) || ! method_exists($registry, 'register') || ! interface_exists('EduGames\\Games\\Contracts\\Game_Module')) {
            return;
        }
        $registry->register(new Edu_Games_Card());
    }

    public static function card_path(): string {
        return '/oj-addons/edu-games/games/' . self::GAME_ID;
    }

    public static function forward_hub_link(): void {
        if (! function_exists('edu_games_registry') || 'GET' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
            return;
        }
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if (untrailingslashit($path) === $base . self::card_path()) {
            wp_safe_redirect(home_url(Oj_Platform_Registration::PLAY_PATH), 302);
            exit;
        }
    }
}

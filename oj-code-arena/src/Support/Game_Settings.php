<?php
declare(strict_types=1);

namespace OjCodeArena\Support;

/**
 * Class-game settings. Same ranges as game-server/src/settings.js — the
 * game server re-validates whatever it receives.
 */
final class Game_Settings {
    private const RANGES = array(
        'teams' => array(2, 8, 4),
        'limitMin' => array(1, 30, 6),
        'blocks' => array(0, 144, 0),
        'occSec' => array(15, 60, 30),
        'bossEverySec' => array(10, 120, 20),
        'bossWaitSec' => array(5, 20, 8),
        'bossLimitSec' => array(15, 90, 30),
    );

    /** @return array<string,mixed> */
    public static function defaults(): array {
        return self::clean(array());
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function clean(array $in): array {
        $out = array();
        foreach (self::RANGES as $key => list($min, $max, $default)) {
            $v = isset($in[$key]) && is_numeric($in[$key]) ? (int) $in[$key] : $default;
            $out[$key] = max($min, min($max, $v));
        }
        if ($out['blocks'] > 0) {
            $out['blocks'] = max(24, (int) round($out['blocks'] / 12) * 12);
        }
        $out['penalty'] = array_key_exists('penalty', $in) ? (bool) $in['penalty'] : true;
        $out['attacks'] = ! empty($in['attacks']);
        $out['selfPick'] = array_key_exists('selfPick', $in) ? (bool) $in['selfPick'] : true;
        $out['botPace'] = in_array($in['botPace'] ?? '', array('slow', 'normal', 'fast'), true) ? $in['botPace'] : 'normal';
        $tags = array();
        foreach ((array) ($in['tags'] ?? array()) as $t) {
            $t = mb_substr(trim(wp_strip_all_tags((string) $t)), 0, 20);
            if ('' !== $t) {
                $tags[$t] = true;
            }
        }
        $out['tags'] = array_slice(array_keys($tags), 0, 20);
        return $out;
    }
}

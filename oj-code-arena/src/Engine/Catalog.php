<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/**
 * Items, board building and settings ranges — PHP twin of
 * game-server/src/{items,board,settings}.js.
 */
final class Catalog {
    public const ITEMS = array(
        'bomb' => array('help', '폭탄', '주변 8칸을 한 번에 풀어요'),
        'sweep' => array('help', '가로 쓸기', '내 오른쪽 칸을 줄 끝까지 풀어요'),
        'drop' => array('help', '세로 쓸기', '내 아래 칸을 맨 아래까지 풀어요'),
        'hint' => array('help', '자동 완성', '12초 동안 Tab 으로 코드를 끝까지 채워요'),
        'shrink' => array('help', '짧게 하기', '주변 칸의 코드를 짧은 코드로 바꿔요'),
        'stall' => array('penalty', '정전', '우리 팀 전원이 5초 동안 멈춰요'),
        'shield' => array('shield', '방패', '들어오는 공격 하나를 막아요 (Ctrl+Shift+9)'),
        'ice' => array('attack', '얼음', '상대 팀이 7초 동안 아무것도 못 해요'),
        'fog' => array('attack', '안개', '상대 팀 화면의 코드가 8초 동안 가려져요'),
        'undo' => array('attack', '되돌리기', '상대 팀이 푼 칸 5개를 다시 잠가요'),
        'scatter' => array('attack', '흩어지기', '상대 팀원을 아무 칸으로 순간 이동시켜요'),
        'mirror' => array('attack', '거울', '상대 팀의 방향키가 8초 동안 반대로 바뀌어요'),
    );
    public const HELP = array('bomb', 'sweep', 'drop', 'hint', 'shrink');
    public const ATTACK = array('ice', 'fog', 'undo', 'scatter', 'mirror');
    public const DURATION = array('stall' => 5000, 'hint' => 12000, 'ice' => 7000, 'fog' => 8000, 'mirror' => 8000);
    public const UNDO_COUNT = 5;
    public const DECK_SIZE = 5;
    public const MAX_SHIELDS = 2;
    public const DEFEND_MS = 2500;
    private const PENALTY_RATE = 0.12;

    public const COLS = 12;

    /** @return array{id:string, kind:string, name:string, desc:string}|null */
    public static function item(string $id): ?array {
        if (! isset(self::ITEMS[$id])) {
            return null;
        }
        list($kind, $name, $desc) = self::ITEMS[$id];
        return array('id' => $id, 'kind' => $kind, 'name' => $name, 'desc' => $desc);
    }

    public static function kind(string $id): string {
        return self::ITEMS[$id][0] ?? '';
    }

    /** @return array<string,array{kind:string,name:string,desc:string}> same shape as the Node "items" catalog */
    public static function items(): array {
        $out = array();
        foreach (self::ITEMS as $id => $row) {
            $out[$id] = array('kind' => $row[0], 'name' => $row[1], 'desc' => $row[2]);
        }
        return $out;
    }

    public static function roll_reward(bool $penalty, bool $attacks): string {
        $r = Rng::random();
        if ($penalty && $r < self::PENALTY_RATE) {
            return 'stall';
        }
        if ($attacks) {
            if ($r < self::PENALTY_RATE + 0.23) {
                return 'shield';
            }
            if ($r < self::PENALTY_RATE + 0.48) {
                return self::ATTACK[random_int(0, count(self::ATTACK) - 1)];
            }
        }
        return self::HELP[random_int(0, count(self::HELP) - 1)];
    }

    public static function roll_gather(bool $attacks): string {
        if ($attacks && Rng::random() < 0.3) {
            return 'shield';
        }
        return self::HELP[random_int(0, count(self::HELP) - 1)];
    }

    // ── board ─────────────────────────────────────────────────

    public static function normalize_blocks(int $n): int {
        $v = (int) round($n / self::COLS) * self::COLS;
        return min(144, max(24, $v));
    }

    public static function blocks_for(int $setting, int $people): int {
        if ($setting > 0) {
            return self::normalize_blocks($setting);
        }
        return self::normalize_blocks(min(96, max(1, $people) * 24));
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    public static function layout(int $seed, array $codes, int $blocks): array {
        $n = self::normalize_blocks($blocks);
        $rng = new Rng($seed);
        $pool = $codes ? array_values($codes) : array('print(1)');
        $order = array();
        while (count($order) < $n) {
            $order = array_merge($order, $rng->shuffle($pool));
        }
        return array_slice($order, 0, $n);
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    public static function short_codes(array $codes): array {
        $short = array_values(array_filter($codes, static fn (string $c): bool => strlen((string) preg_replace('/\s+/', '', $c)) <= 9));
        return $short ?: array('print(1)', 'x = 1', 'a = 0', "print('')");
    }

    // ── settings (same ranges as game-server/src/settings.js) ────

    private const RANGES = array(
        'teams' => array(2, 8, 4),
        'maxPlayers' => array(2, 12, 6),
        'limitMin' => array(1, 30, 6),
        'blocks' => array(0, 144, 0),
        'occSec' => array(15, 60, 30),
        'bossEverySec' => array(10, 120, 20),
        'bossWaitSec' => array(5, 20, 8),
        'bossLimitSec' => array(15, 90, 30),
    );

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function settings(array $in, string $mode): array {
        $out = array();
        foreach (self::RANGES as $key => list($min, $max, $default)) {
            $v = isset($in[$key]) && is_numeric($in[$key]) ? (int) $in[$key] : $default;
            $out[$key] = max($min, min($max, $v));
        }
        if ($out['blocks'] > 0) {
            $out['blocks'] = max(24, (int) round($out['blocks'] / 12) * 12);
        }
        $out['penalty'] = array_key_exists('penalty', $in) ? (bool) $in['penalty'] : true;
        $out['attacks'] = 'class' === $mode && ! empty($in['attacks']);
        $out['selfPick'] = array_key_exists('selfPick', $in) ? (bool) $in['selfPick'] : true;
        $out['botPace'] = in_array($in['botPace'] ?? '', array('slow', 'normal', 'fast'), true) ? $in['botPace'] : 'normal';
        $tags = array();
        foreach ((array) ($in['tags'] ?? array()) as $t) {
            $t = mb_substr(trim(strip_tags((string) $t)), 0, 20);
            if ('' !== $t) {
                $tags[$t] = true;
            }
        }
        $out['tags'] = array_slice(array_keys($tags), 0, 20);
        if ('class' !== $mode) {
            $out['teams'] = 1;
        }
        return $out;
    }
}

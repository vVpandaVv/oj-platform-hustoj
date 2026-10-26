<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/**
 * One team's board for one round — PHP twin of game-server/src/match.js.
 * State is a plain array ($s) so the whole arena can be stored as JSON
 * between requests. Messages go out through the owning Arena.
 */
final class Team_Match {
    private const MAX_INPUT = 300;
    private const GATHER_MS = 6000;
    private const PACE_MS = array('slow' => 18000, 'normal' => 11000, 'fast' => 6500);
    private const BOT_WRONG = 0.1;

    /** @var array<string,mixed> */
    public array $s;
    private Arena $a;

    /** @param array<string,mixed> $s */
    public function __construct(Arena $arena, array $s) {
        $this->a = $arena;
        $this->s = $s;
    }

    /**
     * @param array<string,mixed> $o team, codes(list), limitMs, occMs, bossWaitMs, bossLimitMs, penalty, botPace, startAt, idle
     */
    public static function create(Arena $arena, array $o, int $seed, int $blocks): self {
        $cells = array();
        foreach (Catalog::layout($seed, $o['codes'], $blocks) as $code) {
            $cells[] = array('code' => $code, 'solved' => false, 'by' => null, 'crown' => null);
        }
        return new self($arena, array(
            'team' => (int) $o['team'],
            'cells' => $cells,
            'cols' => Catalog::COLS,
            'rows' => intdiv(count($cells), Catalog::COLS),
            'players' => array(),
            'occ' => array(),
            'boss' => null,
            'fx' => array('stall' => 0, 'ice' => 0, 'fog' => 0, 'mirror' => 0),
            'incoming' => array(),
            'startAt' => (int) $o['startAt'],
            'endAt' => (int) $o['startAt'] + (int) $o['limitMs'],
            'pausedAt' => 0,
            'pausedTotal' => 0,
            'ended' => null,
            'doneAt' => 0,
            'lastInput' => (int) $o['startAt'],
            'idleWarned' => false,
            'stats' => array('bosses' => 0, 'sent' => 0, 'got' => 0, 'blocked' => 0),
            'opts' => array(
                'limitMs' => (int) $o['limitMs'], 'occMs' => (int) $o['occMs'], 'bossWaitMs' => (int) $o['bossWaitMs'],
                'bossLimitMs' => (int) $o['bossLimitMs'], 'penalty' => (bool) $o['penalty'], 'botPace' => (string) $o['botPace'],
                'idle' => (bool) $o['idle'],
            ),
        ));
    }

    private function now(): int {
        return $this->a->now();
    }

    private function team(array $msg): void {
        $this->a->to_team((int) $this->s['team'], $msg);
    }

    private function player(string $id, array $msg): void {
        if (ctype_digit($id)) {
            $this->a->to_uid((int) $id, $msg);
        }
    }

    // ── players ──────────────────────────────────────────────

    /** @param array<string,mixed> $info id, uid, name, avatar, color, bot */
    public function join(array $info): void {
        $id = (string) $info['id'];
        if (! isset($this->s['players'][$id])) {
            list($r, $c) = $this->random_open_cell();
            $this->s['players'][$id] = array(
                'id' => $id, 'uid' => (int) ($info['uid'] ?? 0), 'name' => (string) $info['name'],
                'avatar' => (int) $info['avatar'], 'color' => (int) $info['color'], 'bot' => ! empty($info['bot']),
                'online' => true, 'r' => $r, 'c' => $c, 'solved' => 0, 'bosses' => 0, 'occ' => -1,
                'deck' => array(), 'shields' => 0, 'hintUntil' => 0, 'bot_state' => null,
            );
            $this->team(array('t' => 'player', 'p' => $this->pub($this->s['players'][$id])));
        } elseif (! $this->s['players'][$id]['online']) {
            $this->set_online($id, true);
        }
        if (empty($this->s['players'][$id]['bot'])) {
            $this->player($id, $this->snapshot($id));
        }
    }

    public function leave(string $id): void {
        if (! isset($this->s['players'][$id])) {
            return;
        }
        $this->drop_busy($id, 'left');
        unset($this->s['players'][$id]);
        $this->team(array('t' => 'left', 'id' => $id));
    }

    public function set_online(string $id, bool $online): void {
        if (! isset($this->s['players'][$id]) || $this->s['players'][$id]['online'] === $online) {
            return;
        }
        $this->s['players'][$id]['online'] = $online;
        if (! $online) {
            $this->drop_busy($id, 'left');
        }
        $this->team(array('t' => 'online', 'id' => $id, 'on' => $online));
    }

    private function drop_busy(string $id, string $why): void {
        if ($this->s['players'][$id]['occ'] >= 0) {
            $this->release($id, $why);
        }
        if ($this->s['boss'] && $this->s['boss']['by'] === $id) {
            $this->end_boss('left');
        }
    }

    /** @return list<string> */
    private function humans(): array {
        $out = array();
        foreach ($this->s['players'] as $id => $p) {
            if (! $p['bot'] && $p['online']) {
                $out[] = (string) $id;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $p */
    public function pub(array $p): array {
        return array(
            'id' => $p['id'], 'name' => $p['name'], 'avatar' => $p['avatar'], 'color' => $p['color'], 'bot' => $p['bot'],
            'online' => $p['online'], 'r' => $p['r'], 'c' => $p['c'], 'solved' => $p['solved'], 'bosses' => $p['bosses'],
        );
    }

    // ── helpers ──────────────────────────────────────────────

    private function remaining(int $t): int {
        $now = $this->s['pausedAt'] ?: $this->now();
        return max(0, $t - $now);
    }

    public function elapsed(): int {
        $now = $this->s['ended'] ? $this->s['doneAt'] : ($this->s['pausedAt'] ?: $this->now());
        return max(0, $now - $this->s['startAt'] - $this->s['pausedTotal']);
    }

    private function blocker(?string $id): string {
        if ($this->s['ended']) {
            return 'ended';
        }
        if ($this->s['pausedAt']) {
            return 'paused';
        }
        if ($this->now() < $this->s['startAt']) {
            return 'countdown';
        }
        if (null === $id || ! isset($this->s['players'][$id])) {
            return 'unknown';
        }
        if ($this->is_frozen($this->now())) {
            return 'frozen';
        }
        return '';
    }

    public function is_frozen(int $now): bool {
        return $this->s['fx']['stall'] > $now || $this->s['fx']['ice'] > $now;
    }

    public function is_free(int $i): bool {
        $cell = $this->s['cells'][$i] ?? null;
        return null !== $cell && ! $cell['solved'] && ! isset($this->s['occ'][$i]) && ! ($this->s['boss'] && $this->s['boss']['i'] === $i);
    }

    private function standing_on(int $i): bool {
        foreach ($this->s['players'] as $p) {
            if ($p['r'] * $this->s['cols'] + $p['c'] === $i) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:int,1:int} */
    private function random_open_cell(): array {
        $open = array();
        foreach (array_keys($this->s['cells']) as $i) {
            if (! ($this->s['boss'] && $this->s['boss']['i'] === $i)) {
                $open[] = $i;
            }
        }
        $i = $open ? $open[random_int(0, count($open) - 1)] : 0;
        return array(intdiv($i, $this->s['cols']), $i % $this->s['cols']);
    }

    public function solved_count(): int {
        $n = 0;
        foreach ($this->s['cells'] as $c) {
            $n += $c['solved'] ? 1 : 0;
        }
        return $n;
    }

    /** @return array{solved:int,total:int,pct:int} */
    public function progress(): array {
        $solved = $this->solved_count();
        $total = count($this->s['cells']);
        return array('solved' => $solved, 'total' => $total, 'pct' => $total ? intdiv($solved * 100, $total) : 0);
    }

    public function snapshot(string $me): array {
        $p = $this->s['players'][$me] ?? null;
        $now = $this->now();
        $occ = array();
        foreach ($this->s['occ'] as $i => $o) {
            $occ[] = array('i' => (int) $i, 'by' => $o['id'], 'ms' => $this->remaining($o['until']));
        }
        $fighting = $p && $this->s['boss'] && $this->s['boss']['by'] === $me && 'fight' === $this->s['boss']['phase'];
        return array(
            't' => 'state',
            'team' => $this->s['team'],
            'me' => $me,
            'cols' => $this->s['cols'],
            'rows' => $this->s['rows'],
            'cells' => $this->s['cells'],
            'players' => array_values(array_map(array($this, 'pub'), $this->s['players'])),
            'occ' => $occ,
            'boss' => $this->boss_pub(),
            'fx' => $this->fx_pub(),
            'hintMs' => $p ? $this->remaining($p['hintUntil']) : 0,
            'deck' => $p ? $this->deck_pub($p) : null,
            'incoming' => array_map(array($this, 'incoming_pub'), $this->s['incoming']),
            'limits' => array('occMs' => $this->s['opts']['occMs'], 'bossWaitMs' => $this->s['opts']['bossWaitMs'], 'bossLimitMs' => $this->s['opts']['bossLimitMs']),
            'startMs' => max(0, $this->s['startAt'] - ($this->s['pausedAt'] ?: $now)),
            'endMs' => $this->remaining($this->s['endAt']),
            'paused' => (bool) $this->s['pausedAt'],
            'attacks' => $this->a->attacks(),
            'ended' => $this->s['ended'] ? $this->result() : null,
            'question' => $fighting ? $this->question_pub() : null,
        );
    }

    public function fx_pub(): array {
        $out = array();
        foreach ($this->s['fx'] as $k => $t) {
            $out[$k] = $this->remaining((int) $t);
        }
        return $out;
    }

    /** @param array<string,mixed> $p */
    private function deck_pub(array $p): array {
        $attacks = $this->a->attacks();
        $slots = array();
        for ($k = 0; $k < Catalog::DECK_SIZE; $k++) {
            $id = $p['deck'][$k] ?? null;
            if (! $id) {
                $slots[] = null;
                continue;
            }
            $it = Catalog::item($id);
            if ('attack' === $it['kind'] && ! $attacks) {
                $it['off'] = true;
            }
            $slots[] = $it;
        }
        return array('slots' => $slots, 'shields' => $p['shields']);
    }

    public function boss_pub(): ?array {
        $b = $this->s['boss'];
        if (! $b) {
            return null;
        }
        $out = array('i' => $b['i'], 'phase' => $b['phase'], 'ms' => $this->remaining($b['until']), 'by' => $b['by'], 'title' => $b['q']['title'] ?? '');
        if ('gather' === $b['phase']) {
            $out += $this->gather_count();
        }
        return $out;
    }

    private function question_pub(): array {
        $q = $this->s['boss']['q'];
        return array('title' => $q['title'], 'code' => $q['code'], 'input' => $q['input'] ?? '', 'blank' => ! empty($q['blank']), 'ms' => $this->remaining($this->s['boss']['until']));
    }

    /** @param array<string,mixed> $inc */
    private function incoming_pub(array $inc): array {
        return array('id' => $inc['id'], 'item' => Catalog::item($inc['item']), 'from' => $inc['fromTeam'], 'fromName' => $inc['fromName'], 'ms' => $this->remaining($inc['at']));
    }

    public function send_deck(string $id): void {
        $p = $this->s['players'][$id] ?? null;
        if ($p && ! $p['bot']) {
            $this->player($id, array('t' => 'deck') + $this->deck_pub($p));
        }
    }

    public function touch(string $id): void {
        $p = $this->s['players'][$id] ?? null;
        if ($p && ! $p['bot']) {
            $this->s['lastInput'] = $this->now();
            if ($this->s['idleWarned']) {
                $this->s['idleWarned'] = false;
                $this->team(array('t' => 'idle', 'warn' => false));
            }
        }
    }

    private function grid_fns(): array {
        $solved = fn (int $i): bool => ! empty($this->s['cells'][$i]['solved']);
        $blocked = fn (int $i): bool => (bool) ($this->s['boss'] && $this->s['boss']['i'] === $i);
        return array($solved, $blocked);
    }

    // ── movement ─────────────────────────────────────────────

    /** @param array<string,mixed> $cmd */
    public function move(string $id, array $cmd): void {
        if (! isset($this->s['players'][$id])) {
            return;
        }
        $this->touch($id);
        $p = &$this->s['players'][$id];
        $seq = isset($cmd['seq']) && is_int($cmd['seq']) ? $cmd['seq'] : -1;
        $fighting = $this->s['boss'] && $this->s['boss']['by'] === $id;
        if ($this->blocker($id) || $p['occ'] >= 0 || $fighting) {
            if (! $p['bot']) {
                $this->player($id, array('t' => 'pos', 'id' => $id, 'r' => $p['r'], 'c' => $p['c'], 'seq' => $seq));
            }
            return;
        }
        $safe = array(
            'dir' => in_array($cmd['dir'] ?? '', array('L', 'R', 'U', 'D'), true) ? $cmd['dir'] : null,
            'jump' => ! empty($cmd['jump']),
            'to' => in_array($cmd['to'] ?? '', array('home', 'end', 'first', 'last'), true) ? $cmd['to'] : null,
        );
        list($solved, $blocked) = $this->grid_fns();
        list($r, $c) = Rules::move($this->s['cols'], $this->s['rows'], $solved, $blocked, $p['r'], $p['c'], $safe, $this->s['fx']['mirror'] > $this->now());
        $p['r'] = $r;
        $p['c'] = $c;
        unset($p);
        $this->team(array('t' => 'pos', 'id' => $id, 'r' => $r, 'c' => $c, 'seq' => $seq));
        if ($this->s['boss'] && 'gather' === $this->s['boss']['phase']) {
            $this->check_gather();
        }
    }

    // ── occupy / submit ──────────────────────────────────────

    public function occupy(string $id): array {
        $why = $this->blocker($id);
        if ($why) {
            return array('ok' => false, 'why' => $why);
        }
        $this->touch($id);
        $p = $this->s['players'][$id];
        if ($p['occ'] >= 0 || ($this->s['boss'] && $this->s['boss']['by'] === $id)) {
            return array('ok' => false, 'why' => 'busy');
        }
        $i = $p['r'] * $this->s['cols'] + $p['c'];
        if ($this->s['cells'][$i]['solved']) {
            return array('ok' => false, 'why' => 'solved');
        }
        if ($this->s['boss'] && $this->s['boss']['i'] === $i) {
            return array('ok' => false, 'why' => 'boss');
        }
        if (isset($this->s['occ'][$i])) {
            $holder = $this->s['players'][$this->s['occ'][$i]['id']]['name'] ?? '';
            return array('ok' => false, 'why' => 'taken', 'by' => $holder);
        }
        $ms = $this->s['opts']['occMs'];
        $this->s['occ'][$i] = array('id' => $id, 'until' => $this->now() + $ms);
        $this->s['players'][$id]['occ'] = $i;
        $this->team(array('t' => 'occ', 'i' => $i, 'by' => $id, 'ms' => $ms));
        return array('ok' => true, 'i' => $i, 'ms' => $ms);
    }

    public function release(string $id, string $why = 'release'): void {
        $p = $this->s['players'][$id] ?? null;
        if (! $p || $p['occ'] < 0) {
            return;
        }
        $i = $p['occ'];
        unset($this->s['occ'][$i]);
        $this->s['players'][$id]['occ'] = -1;
        $this->team(array('t' => 'occ', 'i' => $i, 'by' => null, 'why' => $why));
    }

    public function submit(string $id, string $text): array {
        $why = $this->blocker($id);
        if ($why) {
            return array('ok' => false, 'why' => $why);
        }
        $this->touch($id);
        $i = $this->s['players'][$id]['occ'];
        if ($i < 0) {
            return array('ok' => false, 'why' => 'not-holding');
        }
        if (! Rules::check(array('code' => $this->s['cells'][$i]['code']), mb_substr($text, 0, self::MAX_INPUT))) {
            $this->release($id, 'wrong');
            return array('ok' => true, 'correct' => false, 'i' => $i);
        }
        unset($this->s['occ'][$i]);
        $this->s['players'][$id]['occ'] = -1;
        $this->team(array('t' => 'occ', 'i' => $i, 'by' => null, 'why' => 'solved'));
        $this->s['players'][$id]['solved']++;
        $p = $this->s['players'][$id];
        $this->solve($i, $p['name'], 'type', null);
        $this->team(array('t' => 'stat', 'id' => $id, 'solved' => $p['solved'], 'bosses' => $p['bosses']));
        $this->check_clear();
        return array('ok' => true, 'correct' => true, 'i' => $i);
    }

    private function solve(int $i, string $by, string $via, ?string $crown): void {
        $this->s['cells'][$i]['solved'] = true;
        $this->s['cells'][$i]['by'] = $by;
        $this->s['cells'][$i]['crown'] = $crown;
        $this->team(array('t' => 'cell', 'i' => $i, 'solved' => true, 'by' => $by, 'crown' => $crown, 'via' => $via));
    }

    private function check_clear(): void {
        if ($this->s['ended']) {
            return;
        }
        foreach ($this->s['cells'] as $c) {
            if (! $c['solved']) {
                return;
            }
        }
        $this->end('clear');
    }

    // ── bosses ───────────────────────────────────────────────

    /**
     * @param array<string,mixed> $q
     * @param array<string,mixed> $opts gather, row, side, gatherOnly
     */
    public function spawn_boss(array $q, array $opts = array()): bool {
        if ($this->s['ended'] || $this->s['boss']) {
            return false;
        }
        $now = $this->now();
        $usable = fn (int $i): bool => $this->is_free($i) && ! $this->standing_on($i);
        $i = -1;
        $phase = 'wait';
        $until = $now + $this->s['opts']['bossWaitMs'];
        if (! empty($opts['gather']) && count($this->humans()) >= 3) {
            $col = ! empty($opts['side']) ? $this->s['cols'] - 2 : 1;
            for ($k = 0; $k < $this->s['rows'] && $i < 0; $k++) {
                $cand = ((((int) $opts['row']) + $k) % $this->s['rows']) * $this->s['cols'] + $col;
                if ($usable($cand)) {
                    $i = $cand;
                }
            }
            if ($i >= 0) {
                $phase = 'gather';
                $until = $now + self::GATHER_MS;
            }
        }
        if ($i < 0 && ! empty($opts['gatherOnly'])) {
            return false;
        }
        if ($i < 0) {
            $pool = array();
            foreach (array_keys($this->s['cells']) as $k) {
                if ($usable($k)) {
                    $pool[] = $k;
                }
            }
            if (! $pool) {
                return false;
            }
            $i = $pool[random_int(0, count($pool) - 1)];
        }
        $this->s['boss'] = array('i' => $i, 'q' => $q, 'phase' => $phase, 'until' => $until, 'by' => null);
        $this->team(array('t' => 'boss') + $this->boss_pub());
        return true;
    }

    public function grab(string $id, string $key): array {
        $why = $this->blocker($id);
        if ($why) {
            return array('ok' => false, 'why' => $why);
        }
        $this->touch($id);
        $b = $this->s['boss'];
        if (! $b || 'wait' !== $b['phase']) {
            return array('ok' => false, 'why' => 'no-boss');
        }
        $p = $this->s['players'][$id];
        if ($p['occ'] >= 0) {
            return array('ok' => false, 'why' => 'busy');
        }
        $br = intdiv($b['i'], $this->s['cols']);
        $bc = $b['i'] % $this->s['cols'];
        $ok = $br === $p['r'] && (('Delete' === $key && $bc === $p['c'] + 1) || ('Backspace' === $key && $bc === $p['c'] - 1));
        if (! $ok) {
            return array('ok' => false, 'why' => 'not-adjacent');
        }
        $this->s['boss']['phase'] = 'fight';
        $this->s['boss']['by'] = $id;
        $this->s['boss']['until'] = $this->now() + $this->s['opts']['bossLimitMs'];
        $this->team(array('t' => 'boss') + $this->boss_pub());
        return array('ok' => true, 'q' => $this->question_pub());
    }

    public function boss_submit(string $id, string $text): array {
        $why = $this->blocker($id);
        if ($why && 'frozen' !== $why) {
            return array('ok' => false, 'why' => $why);
        }
        $this->touch($id);
        $b = $this->s['boss'];
        if (! $b || 'fight' !== $b['phase'] || $b['by'] !== $id) {
            return array('ok' => false, 'why' => 'no-fight');
        }
        if (! Rules::check($b['q'], mb_substr($text, 0, 1200))) {
            $this->end_boss('wrong');
            return array('ok' => true, 'correct' => false);
        }
        $this->s['players'][$id]['solved']++;
        $this->s['players'][$id]['bosses']++;
        $this->s['stats']['bosses']++;
        $p = $this->s['players'][$id];
        $this->s['boss'] = null;
        $this->solve($b['i'], $p['name'], 'boss', $p['name']);
        $this->team(array('t' => 'boss', 'i' => -1, 'end' => 'win', 'by' => $id));
        $this->team(array('t' => 'stat', 'id' => $id, 'solved' => $p['solved'], 'bosses' => $p['bosses']));
        $item = Catalog::roll_reward((bool) $this->s['opts']['penalty'], $this->a->attacks());
        $this->give($id, $item);
        $this->check_clear();
        return array('ok' => true, 'correct' => true, 'item' => Catalog::item($item));
    }

    public function give_up(string $id): void {
        if ($this->s['boss'] && $this->s['boss']['by'] === $id) {
            $this->end_boss('giveup');
        }
    }

    private function end_boss(string $reason): void {
        if (! $this->s['boss']) {
            return;
        }
        $by = $this->s['boss']['by'];
        $this->s['boss'] = null;
        $this->team(array('t' => 'boss', 'i' => -1, 'end' => $reason, 'by' => $by));
    }

    /** @return array{got:int,need:int} */
    private function gather_count(): array {
        $b = $this->s['boss'];
        $br = intdiv($b['i'], $this->s['cols']);
        $bc = $b['i'] % $this->s['cols'];
        $humans = $this->humans();
        $got = 0;
        foreach ($humans as $id) {
            $p = $this->s['players'][$id];
            if ($p['r'] === $br && abs($p['c'] - $bc) === 1) {
                $got++;
            }
        }
        return array('got' => $got, 'need' => count($humans));
    }

    private function check_gather(): void {
        $b = $this->s['boss'];
        if (! $b || 'gather' !== $b['phase']) {
            return;
        }
        $g = $this->gather_count();
        $this->team(array('t' => 'gather') + $g);
        if ($g['need'] > 0 && $g['got'] >= $g['need']) {
            $this->s['boss'] = null;
            $this->s['stats']['bosses']++;
            $this->solve($b['i'], '모두', 'gather', '모두');
            $this->team(array('t' => 'boss', 'i' => -1, 'end' => 'gather', 'by' => null));
            foreach ($this->humans() as $id) {
                $this->give($id, Catalog::roll_gather($this->a->attacks()));
            }
            $this->check_clear();
        }
    }

    // ── items ────────────────────────────────────────────────

    public function give(string $id, string $item): void {
        $kind = Catalog::kind($item);
        $name = $this->s['players'][$id]['name'];
        if ('penalty' === $kind) {
            $this->s['fx']['stall'] = $this->now() + Catalog::DURATION['stall'];
            $this->team(array('t' => 'fx', 'type' => 'stall', 'ms' => Catalog::DURATION['stall'], 'by' => $name));
            $this->team(array('t' => 'item', 'kind' => 'penalty', 'by' => $name, 'item' => Catalog::item($item)));
            foreach ($this->s['players'] as $pid => $q) {
                if ($q['occ'] >= 0) {
                    $this->release((string) $pid, 'stall');
                }
            }
            return;
        }
        $lost = false;
        if ('shield' === $kind) {
            if ($this->s['players'][$id]['shields'] < Catalog::MAX_SHIELDS) {
                $this->s['players'][$id]['shields']++;
            } else {
                $lost = true;
            }
        } elseif (count($this->s['players'][$id]['deck']) < Catalog::DECK_SIZE) {
            $this->s['players'][$id]['deck'][] = $item;
        } else {
            $lost = true;
        }
        $this->send_deck($id);
        $this->team(array('t' => 'item', 'kind' => 'get', 'by' => $name, 'item' => Catalog::item($item), 'lost' => $lost));
    }

    /** @return list<int> */
    private function neighbours(int $i): array {
        $r = intdiv($i, $this->s['cols']);
        $c = $i % $this->s['cols'];
        $out = array();
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if (! $dr && ! $dc) {
                    continue;
                }
                $nr = $r + $dr;
                $nc = $c + $dc;
                if ($nr >= 0 && $nc >= 0 && $nr < $this->s['rows'] && $nc < $this->s['cols']) {
                    $out[] = $nr * $this->s['cols'] + $nc;
                }
            }
        }
        return $out;
    }

    /** @param list<int> $list */
    private function sweep_cells(array $list, string $by): void {
        foreach ($list as $i) {
            if ($this->s['cells'][$i]['solved'] || ($this->s['boss'] && $this->s['boss']['i'] === $i)) {
                continue;
            }
            if (isset($this->s['occ'][$i])) {
                $this->release($this->s['occ'][$i]['id'], 'item');
            }
            $this->solve($i, $by, 'item', null);
        }
    }

    public function use_item(string $id, int $slot): array {
        $why = $this->blocker($id);
        if ($why) {
            return array('ok' => false, 'why' => $why);
        }
        $this->touch($id);
        $k = $slot - 1;
        $p = $this->s['players'][$id];
        $item = $p['deck'][$k] ?? null;
        if (! $item) {
            return array('ok' => false, 'why' => 'empty');
        }
        $kind = Catalog::kind($item);
        $here = $p['r'] * $this->s['cols'] + $p['c'];
        if ('attack' === $kind) {
            if (! $this->a->attacks()) {
                return array('ok' => false, 'why' => 'attacks-off');
            }
            if (! $this->a->route_attack($this, $p, $item)) {
                return array('ok' => false, 'why' => 'no-target');
            }
            $this->s['stats']['sent']++;
        } elseif ('bomb' === $item) {
            $this->sweep_cells($this->neighbours($here), $p['name']);
        } elseif ('sweep' === $item) {
            $list = array();
            for ($c = $p['c'] + 1; $c < $this->s['cols']; $c++) {
                $list[] = $p['r'] * $this->s['cols'] + $c;
            }
            $this->sweep_cells($list, $p['name']);
        } elseif ('drop' === $item) {
            $list = array();
            for ($r = $p['r'] + 1; $r < $this->s['rows']; $r++) {
                $list[] = $r * $this->s['cols'] + $p['c'];
            }
            $this->sweep_cells($list, $p['name']);
        } elseif ('hint' === $item) {
            $this->s['players'][$id]['hintUntil'] = $this->now() + Catalog::DURATION['hint'];
            $this->player($id, array('t' => 'hint', 'ms' => Catalog::DURATION['hint']));
        } elseif ('shrink' === $item) {
            $short = $this->a->short_codes();
            foreach ($this->neighbours($here) as $i) {
                if (! $this->is_free($i)) {
                    continue;
                }
                $code = $short[random_int(0, count($short) - 1)];
                $this->s['cells'][$i]['code'] = $code;
                $this->team(array('t' => 'code', 'i' => $i, 'code' => $code));
            }
        }
        array_splice($this->s['players'][$id]['deck'], $k, 1);
        $this->send_deck($id);
        $this->team(array('t' => 'item', 'kind' => 'attack' === $kind ? 'attack' : 'use', 'by' => $p['name'], 'item' => Catalog::item($item)));
        $this->check_clear();
        return array('ok' => true, 'item' => Catalog::item($item));
    }

    /** @param array<string,mixed> $inc id, item, fromTeam, fromName */
    public function receive_attack(array $inc): bool {
        if ($this->s['ended']) {
            return false;
        }
        $inc['at'] = $this->now() + Catalog::DEFEND_MS;
        $this->s['incoming'][] = $inc;
        $this->team(array('t' => 'incoming') + $this->incoming_pub($inc));
        return true;
    }

    public function defend(string $id): array {
        if (! isset($this->s['players'][$id]) || $this->s['ended']) {
            return array('ok' => false, 'why' => 'ended');
        }
        $this->touch($id);
        if (! $this->s['incoming']) {
            return array('ok' => false, 'why' => 'nothing');
        }
        if ($this->s['players'][$id]['shields'] <= 0) {
            return array('ok' => false, 'why' => 'no-shield');
        }
        $inc = array_shift($this->s['incoming']);
        $this->s['players'][$id]['shields']--;
        $this->s['stats']['blocked']++;
        $this->send_deck($id);
        $this->team(array('t' => 'incoming_end', 'id' => $inc['id'], 'blocked' => true, 'by' => $this->s['players'][$id]['name']));
        $this->a->attack_done($this, $inc, true);
        return array('ok' => true, 'blocked' => Catalog::item($inc['item']));
    }

    /** @param array<string,mixed> $inc */
    private function apply_attack(array $inc): void {
        $now = $this->now();
        $this->s['stats']['got']++;
        $id = $inc['item'];
        if (in_array($id, array('ice', 'fog', 'mirror'), true)) {
            $this->s['fx'][$id] = $now + Catalog::DURATION[$id];
            $this->team(array('t' => 'fx', 'type' => $id, 'ms' => Catalog::DURATION[$id], 'by' => $inc['fromName']));
            if ('ice' === $id) {
                foreach ($this->s['players'] as $pid => $p) {
                    if ($p['occ'] >= 0) {
                        $this->release((string) $pid, 'ice');
                    }
                }
            }
        } elseif ('undo' === $id) {
            $solved = array();
            foreach ($this->s['cells'] as $i => $c) {
                if ($c['solved']) {
                    $solved[] = $i;
                }
            }
            for ($n = 0; $n < Catalog::UNDO_COUNT && $solved; $n++) {
                $pick = random_int(0, count($solved) - 1);
                $i = $solved[$pick];
                array_splice($solved, $pick, 1);
                $this->s['cells'][$i] = array('code' => $this->s['cells'][$i]['code'], 'solved' => false, 'by' => null, 'crown' => null);
                $this->team(array('t' => 'cell', 'i' => $i, 'solved' => false, 'by' => null, 'crown' => null, 'via' => 'undo'));
            }
        } elseif ('scatter' === $id) {
            list($r, $c) = $this->random_open_cell();
            foreach ($this->s['players'] as $pid => $p) {
                $pid = (string) $pid;
                if ($this->s['boss'] && $this->s['boss']['by'] === $pid) {
                    continue;
                }
                if ($p['occ'] >= 0) {
                    $this->release($pid, 'scatter');
                }
                $this->s['players'][$pid]['r'] = $r;
                $this->s['players'][$pid]['c'] = $c;
                $this->team(array('t' => 'pos', 'id' => $pid, 'r' => $r, 'c' => $c, 'seq' => -1));
            }
        }
        $this->team(array('t' => 'incoming_end', 'id' => $inc['id'], 'blocked' => false));
        $this->a->attack_done($this, $inc, false);
    }

    // ── clock ────────────────────────────────────────────────

    public function pause(bool $on): void {
        $now = $this->now();
        if ($on && ! $this->s['pausedAt'] && ! $this->s['ended']) {
            $this->s['pausedAt'] = $now;
        } elseif (! $on && $this->s['pausedAt']) {
            $shift = $now - $this->s['pausedAt'];
            if ($this->s['pausedAt'] < $this->s['startAt']) {
                $this->s['startAt'] += $shift;
            } else {
                $this->s['pausedTotal'] += $shift;
            }
            $this->s['pausedAt'] = 0;
            $this->s['endAt'] += $shift;
            foreach ($this->s['occ'] as $i => $o) {
                $this->s['occ'][$i]['until'] += $shift;
            }
            if ($this->s['boss']) {
                $this->s['boss']['until'] += $shift;
            }
            foreach ($this->s['fx'] as $k => $t) {
                if ($t) {
                    $this->s['fx'][$k] += $shift;
                }
            }
            foreach ($this->s['incoming'] as $k => $inc) {
                $this->s['incoming'][$k]['at'] += $shift;
            }
            foreach ($this->s['players'] as $pid => $p) {
                if ($p['hintUntil']) {
                    $this->s['players'][$pid]['hintUntil'] += $shift;
                }
                if ($p['bot_state']) {
                    $this->s['players'][$pid]['bot_state']['nextAt'] += $shift;
                }
            }
            $this->s['lastInput'] += $shift;
        } else {
            return;
        }
        $this->team(array(
            't' => 'pause',
            'on' => (bool) $this->s['pausedAt'],
            'endMs' => $this->remaining($this->s['endAt']),
            'startMs' => max(0, $this->s['startAt'] - ($this->s['pausedAt'] ?: $now)),
        ));
    }

    public function tick(): void {
        if ($this->s['ended'] || $this->s['pausedAt']) {
            return;
        }
        $now = $this->now();
        if ($now < $this->s['startAt']) {
            return;
        }
        foreach ($this->s['occ'] as $i => $o) {
            if ($o['until'] <= $now) {
                if (isset($this->s['players'][$o['id']])) {
                    $this->release($o['id'], 'timeout');
                } else {
                    unset($this->s['occ'][$i]);
                }
            }
        }
        $b = $this->s['boss'];
        if ($b && $b['until'] <= $now) {
            $this->end_boss('fight' === $b['phase'] ? 'timeout' : ('gather' === $b['phase'] ? 'scattered' : 'escaped'));
        } elseif ($b && 'gather' === $b['phase']) {
            $this->check_gather();
        }
        while ($this->s['incoming'] && $this->s['incoming'][0]['at'] <= $now) {
            $this->apply_attack(array_shift($this->s['incoming']));
        }
        foreach (array_keys($this->s['players']) as $pid) {
            if (isset($this->s['players'][$pid]) && $this->s['players'][$pid]['bot']) {
                $this->bot_tick((string) $pid, $now);
            }
        }
        if ($this->s['opts']['idle'] && $this->humans()) {
            $quiet = $now - $this->s['lastInput'];
            $idle = Arena::IDLE_MS;
            if (! $this->s['idleWarned'] && $quiet >= $idle) {
                $this->s['idleWarned'] = true;
                $this->team(array('t' => 'idle', 'warn' => true, 'ms' => Arena::IDLE_WARN_MS));
            } elseif ($quiet >= $idle + Arena::IDLE_WARN_MS) {
                $this->end('idle');
                return;
            }
        }
        if ($now >= $this->s['endAt']) {
            $this->end('time');
        }
    }

    public function end(string $reason): void {
        if ($this->s['ended']) {
            return;
        }
        $this->s['ended'] = $reason;
        $this->s['doneAt'] = $this->now();
        $this->s['boss'] = null;
        $this->s['occ'] = array();
        foreach (array_keys($this->s['players']) as $pid) {
            $this->s['players'][$pid]['occ'] = -1;
        }
        $this->s['incoming'] = array();
        $this->team(array('t' => 'end', 'result' => $this->result()));
        $this->a->on_match_end($this);
    }

    public function result(): array {
        $players = array();
        foreach ($this->s['players'] as $p) {
            $players[] = array(
                'id' => $p['id'], 'uid' => $p['uid'], 'name' => $p['name'], 'avatar' => $p['avatar'], 'color' => $p['color'],
                'bot' => $p['bot'], 'solved' => $p['solved'], 'bosses' => $p['bosses'], 'score' => $p['solved'] + $p['bosses'] * 3,
            );
        }
        usort($players, static fn (array $x, array $y): int => $y['score'] <=> $x['score']);
        return array(
            'team' => $this->s['team'],
            'reason' => $this->s['ended'],
            'cleared' => 'clear' === $this->s['ended'],
            'ms' => $this->elapsed(),
            'limitMs' => $this->s['opts']['limitMs'],
            'bosses' => $this->s['stats']['bosses'],
            'attacks' => array('sent' => $this->s['stats']['sent'], 'got' => $this->s['stats']['got'], 'blocked' => $this->s['stats']['blocked']),
            'players' => $players,
        ) + $this->progress();
    }

    // ── bots (game-server/src/bot.js) ────────────────────────

    private function bot_tick(string $id, int $now): void {
        $p = $this->s['players'][$id];
        $b = $p['bot_state'] ?? null;
        if (! $b) {
            $b = array('nextAt' => $now + 800 + random_int(0, 800), 'target' => -1, 'typingUntil' => 0);
        }
        if ($now < $b['nextAt']) {
            $this->s['players'][$id]['bot_state'] = $b;
            return;
        }
        if ($this->is_frozen($now)) {
            $b['nextAt'] = $now + 400;
            $this->s['players'][$id]['bot_state'] = $b;
            return;
        }
        if ($p['occ'] >= 0) {
            if ($now >= $b['typingUntil']) {
                $code = $this->s['cells'][$p['occ']]['code'];
                $this->s['players'][$id]['bot_state'] = $b;
                $this->submit($id, Rng::random() < self::BOT_WRONG ? $code . '#' : $code);
                $b['target'] = -1;
                $b['nextAt'] = $now + 500 + random_int(0, 500);
            } else {
                $b['nextAt'] = $now + 300;
            }
            $this->s['players'][$id]['bot_state'] = $b;
            return;
        }
        if ($b['target'] < 0 || ! $this->is_free($b['target'])) {
            $b['target'] = $this->nearest_free($p);
        }
        if ($b['target'] < 0) {
            $b['nextAt'] = $now + 1000;
            $this->s['players'][$id]['bot_state'] = $b;
            return;
        }
        $tr = intdiv($b['target'], $this->s['cols']);
        $tc = $b['target'] % $this->s['cols'];
        if ($tr === $p['r'] && $tc === $p['c']) {
            $res = $this->occupy($id);
            if ($res['ok']) {
                $pace = self::PACE_MS[$this->s['opts']['botPace']] ?? self::PACE_MS['normal'];
                $b['typingUntil'] = $now + (int) ($pace * (0.55 + Rng::random() * 0.35));
            } else {
                $b['target'] = -1;
            }
            $b['nextAt'] = $now + 300;
            $this->s['players'][$id]['bot_state'] = $b;
            return;
        }
        $tries = array();
        if ($tr !== $p['r']) {
            $tries[] = $tr > $p['r'] ? 'D' : 'U';
        }
        if ($tc !== $p['c']) {
            $tries[] = $tc > $p['c'] ? 'R' : 'L';
        }
        $start = $p['r'] * $this->s['cols'] + $p['c'];
        $this->s['players'][$id]['bot_state'] = $b;
        foreach ($tries as $dir) {
            $this->move($id, array('dir' => $dir, 'seq' => -1));
            $q = $this->s['players'][$id];
            if ($q['r'] * $this->s['cols'] + $q['c'] !== $start) {
                break;
            }
        }
        $q = $this->s['players'][$id];
        if ($q['r'] * $this->s['cols'] + $q['c'] === $start) {
            $b['target'] = -1;
        }
        $b['nextAt'] = $now + 260 + random_int(0, 140);
        $this->s['players'][$id]['bot_state'] = $b;
    }

    /** @param array<string,mixed> $p */
    private function nearest_free(array $p): int {
        $found = array();
        foreach (array_keys($this->s['cells']) as $i) {
            if (! $this->is_free($i)) {
                continue;
            }
            $r = intdiv($i, $this->s['cols']);
            $c = $i % $this->s['cols'];
            $found[] = array($i, abs($r - $p['r']) + abs($c - $p['c']));
        }
        if (! $found) {
            return -1;
        }
        usort($found, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        $top = array_slice($found, 0, 3);
        return $top[random_int(0, count($top) - 1)][0];
    }
}

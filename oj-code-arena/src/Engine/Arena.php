<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/**
 * A class game or a free room — PHP twin of game-server/src/arena.js for the
 * built-in (no game server) mode. Messages are collected in $out as
 * [scope, payload] pairs and written to the events table by Store.
 *
 * Scopes: all · learners · teachers · boards · team:N · uid:N
 */
final class Arena {
    public const IDLE_MS = 90000;
    public const IDLE_WARN_MS = 15000;
    public const COUNTDOWN_MS = 5000;
    public const ONLINE_MS = 9000;
    private const GATHER_EVERY = 4;
    private const MAX_BOTS = 40;
    private const BOT_NAMES = array('연습봇 가', '연습봇 나', '연습봇 다', '연습봇 라', '연습봇 마', '연습봇 바', '연습봇 사', '연습봇 아');

    /** @var array<string,mixed> */
    public array $s;
    /** @var array{normal: list<array<string,mixed>>, boss: list<array<string,mixed>>} */
    private array $problems;
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $out = array();
    /** @var array<int|string,Team_Match> */
    private array $matches = array();
    private int $now;
    /** @var array<string,mixed>|null result to save after this request */
    public ?array $finished = null;

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $problems
     */
    public function __construct(array $state, array $problems, int $now) {
        $this->s = $state;
        $this->problems = array(
            'normal' => array_values((array) ($problems['normal'] ?? array())),
            'boss' => array_values((array) ($problems['boss'] ?? array())),
        );
        $this->now = $now;
        if (! empty($this->s['round']['matches'])) {
            foreach ($this->s['round']['matches'] as $team => $ms) {
                $this->matches[(int) $team] = new Team_Match($this, $ms);
            }
        }
    }

    /**
     * @param array<string,mixed> $o id, mode, wpId, title, context, settings, roster(list|null), hostUid
     * @return array<string,mixed>
     */
    public static function fresh(array $o, int $now): array {
        $roster = null;
        if (is_array($o['roster'] ?? null)) {
            $roster = array();
            foreach ($o['roster'] as $r) {
                $roster[(string) (int) $r['uid']] = (string) $r['name'];
            }
        }
        $mode = 'free' === $o['mode'] ? 'free' : 'class';
        $settings = Catalog::settings((array) ($o['settings'] ?? array()), $mode);
        return array(
            'id' => (string) $o['id'], 'mode' => $mode, 'wpId' => (int) ($o['wpId'] ?? 0),
            'title' => (string) ($o['title'] ?? ''), 'context' => (string) ($o['context'] ?? ''),
            'settings' => $settings, 'attacks' => $settings['attacks'], 'roster' => $roster,
            'hostUid' => (int) ($o['hostUid'] ?? 0), 'members' => array(), 'bots' => array(), 'banned' => array(),
            'phase' => 'lobby', 'round' => null, 'roundNo' => 0, 'lastResult' => null, 'feed' => array(),
            'createdAt' => $now, 'touchedAt' => $now, 'closed' => false, 'closeReason' => '', 'dirty' => true,
            'lastStandings' => '', 'lastBoardAt' => 0, 'botSeq' => 0,
        );
    }

    /** Writes the matches back into the state array. */
    public function export(): array {
        if ($this->s['round']) {
            $this->s['round']['matches'] = array();
            foreach ($this->matches as $team => $m) {
                $this->s['round']['matches'][(string) $team] = $m->s;
            }
        }
        return $this->s;
    }

    public function now(): int {
        return $this->now;
    }

    /** Moves this request's clock (tests). */
    public function set_now(int $now): void {
        $this->now = $now;
    }

    public function attacks(): bool {
        return (bool) $this->s['attacks'];
    }

    /** @return list<string> */
    public function short_codes(): array {
        return Catalog::short_codes((array) ($this->s['round']['codes'] ?? array()));
    }

    // ── outputs ──────────────────────────────────────────────

    private function emit(string $scope, array $msg): void {
        $this->out[] = array($scope, $msg);
    }

    public function to_uid(int $uid, array $msg): void {
        $this->emit('uid:' . $uid, $msg);
    }

    public function to_team(int $team, array $msg): void {
        $this->emit('team:' . $team, $msg);
        if (in_array($msg['t'], array('end', 'fx', 'item', 'boss'), true)) {
            $this->emit('teachers', $msg + array('team' => $team));
        }
    }

    private function broadcast(array $msg): void {
        $this->emit('all', $msg);
    }

    private function feed(string $text, string $kind): void {
        $item = array('text' => $text, 'kind' => $kind, 'at' => (int) floor(microtime(true) * 1000));
        $this->s['feed'][] = $item;
        if (count($this->s['feed']) > 40) {
            array_shift($this->s['feed']);
        }
        $this->broadcast(array('t' => 'feed') + $item);
    }

    private function changed(): void {
        $this->s['dirty'] = true;
        $this->s['touchedAt'] = $this->now;
    }

    // ── membership ───────────────────────────────────────────

    private function team_count(): int {
        return 'class' === $this->s['mode'] ? (int) $this->s['settings']['teams'] : 1;
    }

    private function capacity(): int {
        return 'free' === $this->s['mode'] ? (int) $this->s['settings']['maxPlayers'] : 400;
    }

    private function headcount(): int {
        return count($this->s['members']) + count($this->s['bots']);
    }

    public function can_enter(int $uid): string {
        if ($this->s['closed']) {
            return 'closed';
        }
        if (in_array($uid, $this->s['banned'], true)) {
            return 'kicked';
        }
        if (is_array($this->s['roster']) && ! isset($this->s['roster'][(string) $uid])) {
            return 'not-in-roster';
        }
        $known = isset($this->s['members'][(string) $uid]);
        if (! $known && $this->headcount() >= $this->capacity()) {
            return 'full';
        }
        if ('free' === $this->s['mode'] && 'lobby' !== $this->s['phase'] && ! $known) {
            return 'started';
        }
        return '';
    }

    /**
     * A learner arrives (first poll). Teachers and boards only get a
     * snapshot.
     *
     * @param array{avatar:int,color:int} $look
     */
    public function attach(int $uid, string $name, string $role, array $look): void {
        $this->s['touchedAt'] = $this->now;
        if ('learner' !== $role) {
            $this->emit('uid:' . $uid, array('t' => 'arena') + $this->detail());
            if ('board' === $role) {
                $this->emit('uid:' . $uid, $this->board_view());
            }
            return;
        }
        $key = (string) $uid;
        if (! isset($this->s['members'][$key])) {
            $this->s['members'][$key] = array(
                'uid' => $uid, 'name' => $name, 'avatar' => $look['avatar'], 'color' => $look['color'],
                'team' => 'free' === $this->s['mode'] ? 1 : 0, 'online' => true, 'seen' => $this->now,
            );
            if (! $this->s['hostUid'] && 'free' === $this->s['mode']) {
                $this->s['hostUid'] = $uid;
            }
        } else {
            $this->s['members'][$key]['online'] = true;
            $this->s['members'][$key]['seen'] = $this->now;
        }
        $this->changed();
        $this->emit('uid:' . $uid, array('t' => 'arena') + $this->detail());
        $m = $this->s['members'][$key];
        if ($this->s['round'] && $m['team']) {
            $this->ensure_match((int) $m['team'])->join($this->player_info($m));
        }
    }

    /** @param array<string,int> $seen uid → last poll time (ms) */
    public function presence(array $seen): void {
        foreach ($this->s['members'] as $key => $m) {
            $last = max((int) $m['seen'], (int) ($seen[(string) $key] ?? 0));
            $this->s['members'][$key]['seen'] = $last;
            $online = $this->now - $last < self::ONLINE_MS;
            if ($online !== (bool) $m['online']) {
                $this->s['members'][$key]['online'] = $online;
                $this->match_of((int) $m['team'])?->set_online((string) $key, $online);
                $this->changed();
            }
            // Free rooms forget people who left (reload grace: 20 s, 60 s in play).
            if ('free' === $this->s['mode'] && ! $online) {
                $grace = 'playing' === $this->s['phase'] ? 60000 : 20000;
                if ($this->now - $last > $grace) {
                    $this->remove_member((int) $key);
                }
            }
        }
    }

    public function remove_member(int $uid): void {
        $key = (string) $uid;
        if (! isset($this->s['members'][$key])) {
            return;
        }
        $this->match_of((int) $this->s['members'][$key]['team'])?->leave($key);
        unset($this->s['members'][$key]);
        if ('free' === $this->s['mode']) {
            if ($this->s['hostUid'] === $uid) {
                $keys = array_keys($this->s['members']);
                $this->s['hostUid'] = $keys ? (int) $keys[0] : 0;
            }
            if (! $this->s['members']) {
                $this->close('empty');
            }
        }
        $this->changed();
    }

    /** @param array<string,mixed> $m */
    private function player_info(array $m): array {
        return array('id' => (string) $m['uid'], 'uid' => (int) $m['uid'], 'name' => $m['name'], 'avatar' => $m['avatar'], 'color' => $m['color'], 'bot' => false);
    }

    /** @param array<string,mixed> $b */
    private function bot_info(array $b): array {
        return array('id' => $b['id'], 'uid' => 0, 'name' => $b['name'], 'avatar' => $b['avatar'], 'color' => $b['color'], 'bot' => true);
    }

    /** @param array{avatar:int,color:int} $look */
    public function set_look(int $uid, array $look): void {
        if (isset($this->s['members'][(string) $uid])) {
            $this->s['members'][(string) $uid]['avatar'] = $look['avatar'];
            $this->s['members'][(string) $uid]['color'] = $look['color'];
            $this->changed();
        }
    }

    public function set_team(int $uid, int $no): array {
        $key = (string) $uid;
        if (! isset($this->s['members'][$key])) {
            return array('ok' => false, 'why' => 'unknown');
        }
        if ($no < 0 || $no > $this->team_count()) {
            return array('ok' => false, 'why' => 'bad-team');
        }
        $m = $this->s['members'][$key];
        if ((int) $m['team'] === $no) {
            return array('ok' => true);
        }
        $this->match_of((int) $m['team'])?->leave($key);
        $this->s['members'][$key]['team'] = $no;
        if ($this->s['round'] && $no) {
            $this->ensure_match($no)->join($this->player_info($this->s['members'][$key]));
        }
        $this->changed();
        return array('ok' => true);
    }

    public function auto_assign(): void {
        $counts = $this->team_sizes();
        foreach ($this->s['members'] as $key => $m) {
            if ($m['team']) {
                continue;
            }
            $best = 1;
            for ($n = 2; $n <= $this->team_count(); $n++) {
                if ($counts[$n] < $counts[$best]) {
                    $best = $n;
                }
            }
            $counts[$best]++;
            $this->set_team((int) $key, $best);
        }
    }

    /** @return array<int,int> */
    private function team_sizes(): array {
        $sizes = array_fill(0, $this->team_count() + 1, 0);
        foreach ($this->s['members'] as $m) {
            if ($m['team'] && isset($sizes[$m['team']])) {
                $sizes[$m['team']]++;
            }
        }
        foreach ($this->s['bots'] as $b) {
            if (isset($sizes[$b['team']])) {
                $sizes[$b['team']]++;
            }
        }
        return $sizes;
    }

    // ── bots ─────────────────────────────────────────────────

    public function add_bot(int $team): array {
        $team = $team ?: 1;
        if ($team < 1 || $team > $this->team_count()) {
            return array('ok' => false, 'why' => 'bad-team');
        }
        if (count($this->s['bots']) >= self::MAX_BOTS || $this->headcount() >= $this->capacity()) {
            return array('ok' => false, 'why' => 'full');
        }
        $k = $this->s['botSeq']++;
        $n = count(self::BOT_NAMES);
        $b = array('id' => 'b' . $k, 'name' => self::BOT_NAMES[$k % $n] . ($k >= $n ? ' ' . (intdiv($k, $n) + 1) : ''), 'avatar' => $k % 8, 'color' => ($k + 3) % 8, 'team' => $team);
        $this->s['bots'][$b['id']] = $b;
        if ($this->s['round']) {
            $this->ensure_match($team)->join($this->bot_info($b));
        }
        $this->changed();
        return array('ok' => true, 'id' => $b['id']);
    }

    public function remove_bot(string $id): array {
        if (! isset($this->s['bots'][$id])) {
            return array('ok' => false, 'why' => 'unknown');
        }
        $this->match_of((int) $this->s['bots'][$id]['team'])?->leave($id);
        unset($this->s['bots'][$id]);
        $this->changed();
        return array('ok' => true);
    }

    public function fill_bots(): void {
        $sizes = $this->team_sizes();
        $target = max(1, max(array_slice($sizes, 1)));
        for ($n = 1; $n <= $this->team_count(); $n++) {
            for ($k = $sizes[$n]; $k < $target; $k++) {
                if (! $this->add_bot($n)['ok']) {
                    return;
                }
            }
        }
    }

    public function clear_bots(): void {
        foreach (array_keys($this->s['bots']) as $id) {
            $this->remove_bot((string) $id);
        }
    }

    // ── control ──────────────────────────────────────────────

    /** @param array<string,mixed> $input */
    public function update_settings(array $input): array {
        if ('playing' === $this->s['phase']) {
            return array('ok' => false, 'why' => 'playing');
        }
        $this->s['settings'] = Catalog::settings(array_merge($this->s['settings'], $input), $this->s['mode']);
        $this->s['attacks'] = $this->s['settings']['attacks'];
        foreach ($this->s['members'] as $key => $m) {
            if ($m['team'] > $this->team_count()) {
                $this->s['members'][$key]['team'] = 0;
            }
        }
        foreach ($this->s['bots'] as $id => $b) {
            if ($b['team'] > $this->team_count()) {
                $this->remove_bot((string) $id);
            }
        }
        $this->changed();
        return array('ok' => true);
    }

    /**
     * Refreshes title/roster/problems/settings pushed from WordPress.
     *
     * @param array<string,mixed> $o
     * @param array<string,mixed> $problems
     */
    public function refresh(array $o, array $problems): void {
        $this->s['title'] = (string) ($o['title'] ?? $this->s['title']);
        $this->s['context'] = (string) ($o['context'] ?? $this->s['context']);
        if (is_array($o['roster'] ?? null)) {
            $roster = array();
            foreach ($o['roster'] as $r) {
                $roster[(string) (int) $r['uid']] = (string) $r['name'];
            }
            $this->s['roster'] = $roster;
        }
        if ('playing' !== $this->s['phase']) {
            $this->problems = array('normal' => array_values($problems['normal'] ?? array()), 'boss' => array_values($problems['boss'] ?? array()));
            if (isset($o['settings']) && is_array($o['settings'])) {
                $this->update_settings($o['settings']);
            }
        }
        $this->changed();
    }

    public function set_attacks(bool $on): void {
        $this->s['attacks'] = $on;
        foreach ($this->matches as $m) {
            foreach (array_keys($m->s['players']) as $pid) {
                $m->send_deck((string) $pid);
            }
        }
        $this->feed($on ? '선생님이 공격 아이템을 켰어요' : '선생님이 공격 아이템을 껐어요', 'info');
        $this->changed();
    }

    /** @return array{normal: list<array<string,mixed>>, boss: list<array<string,mixed>>} */
    private function pick_problems(): array {
        $want = array_flip($this->s['settings']['tags']);
        $filter = static function (array $list) use ($want): array {
            if (! $want) {
                return $list;
            }
            $hit = array_values(array_filter($list, static function (array $p) use ($want): bool {
                foreach ((array) ($p['tags'] ?? array()) as $t) {
                    if (isset($want[$t])) {
                        return true;
                    }
                }
                return false;
            }));
            return $hit ?: $list;
        };
        return array('normal' => $filter($this->problems['normal']), 'boss' => $filter($this->problems['boss']));
    }

    public function start(): array {
        if ('playing' === $this->s['phase']) {
            return array('ok' => false, 'why' => 'playing');
        }
        $sizes = $this->team_sizes();
        if (max(array_slice($sizes, 1) ?: array(0)) <= 0) {
            return array('ok' => false, 'why' => 'no-players');
        }
        $pool = $this->pick_problems();
        if (! $pool['normal']) {
            return array('ok' => false, 'why' => 'no-problems');
        }
        $seed = Rng::seed();
        $rng = new Rng($seed ^ 0x5bd1e995);
        $limit = $this->s['settings']['limitMin'] * 60000;
        $slots = array();
        $total = (int) ceil($limit / ($this->s['settings']['bossEverySec'] * 1000));
        for ($k = 1; $k <= $total; $k++) {
            $slots[] = array('gather' => 0 === $k % self::GATHER_EVERY, 'row' => $rng->int(64), 'side' => 1 === $rng->int(2));
        }
        $boss = $rng->shuffle(array_map(static function (array $p): array {
            return array('title' => (string) $p['title'], 'code' => (string) $p['code'], 'input' => (string) ($p['input'] ?? ''), 'answers' => array_values((array) $p['answers']), 'blank' => str_contains((string) $p['code'], '___'));
        }, $pool['boss']));
        $this->s['roundNo']++;
        $this->s['round'] = array(
            'no' => $this->s['roundNo'], 'seed' => $seed,
            'codes' => array_values(array_map(static fn (array $p): string => (string) $p['code'], $pool['normal'])),
            'bossQueue' => $boss, 'bossSlots' => $slots,
            'blocks' => Catalog::blocks_for((int) $this->s['settings']['blocks'], max(array_slice($sizes, 1))),
            'limitMs' => $limit, 'startAt' => $this->now + self::COUNTDOWN_MS, 'wallStart' => time(),
            'pausedAt' => 0, 'pausedTotal' => 0, 'slot' => 0, 'matches' => array(), 'doneOrder' => array(),
        );
        $this->matches = array();
        $this->s['phase'] = 'playing';
        for ($n = 1; $n <= $this->team_count(); $n++) {
            if ($sizes[$n] > 0) {
                $this->ensure_match($n);
            }
        }
        $this->feed($this->s['roundNo'] . '번째 경기를 시작해요!', 'info');
        $this->changed();
        return array('ok' => true);
    }

    public function match_of(int $team): ?Team_Match {
        return ($this->s['round'] && $team) ? ($this->matches[$team] ?? null) : null;
    }

    private function round_elapsed(): int {
        $r = $this->s['round'];
        $now = $r['pausedAt'] ?: $this->now;
        return max(0, $now - $r['startAt'] - $r['pausedTotal']);
    }

    private function round_remaining(): int {
        return $this->s['round'] ? max(0, $this->s['round']['limitMs'] - $this->round_elapsed()) : 0;
    }

    private function ensure_match(int $team): Team_Match {
        if (isset($this->matches[$team])) {
            return $this->matches[$team];
        }
        $r = $this->s['round'];
        $late = $this->now > $r['startAt'];
        $m = Team_Match::create($this, array(
            'team' => $team, 'codes' => $r['codes'],
            'limitMs' => $late ? $this->round_remaining() : $r['limitMs'],
            'occMs' => $this->s['settings']['occSec'] * 1000,
            'bossWaitMs' => $this->s['settings']['bossWaitSec'] * 1000,
            'bossLimitMs' => $this->s['settings']['bossLimitSec'] * 1000,
            'penalty' => $this->s['settings']['penalty'], 'botPace' => $this->s['settings']['botPace'],
            'startAt' => max($this->now, $r['startAt']), 'idle' => 'free' === $this->s['mode'],
        ), (int) $r['seed'], (int) $r['blocks']);
        $this->matches[$team] = $m;
        if ($r['pausedAt']) {
            $m->pause(true);
        }
        foreach ($this->s['members'] as $mem) {
            if ((int) $mem['team'] === $team) {
                $m->join($this->player_info($mem));
                if (! $mem['online']) {
                    $m->set_online((string) $mem['uid'], false);
                }
            }
        }
        foreach ($this->s['bots'] as $b) {
            if ((int) $b['team'] === $team) {
                $m->join($this->bot_info($b));
            }
        }
        return $m;
    }

    public function pause(bool $on): array {
        if (! $this->s['round'] || 'playing' !== $this->s['phase']) {
            return array('ok' => false, 'why' => 'not-playing');
        }
        $r = &$this->s['round'];
        if ($on && ! $r['pausedAt']) {
            $r['pausedAt'] = $this->now;
        } elseif (! $on && $r['pausedAt']) {
            $shift = $this->now - $r['pausedAt'];
            if ($r['pausedAt'] < $r['startAt']) {
                $r['startAt'] += $shift;
            } else {
                $r['pausedTotal'] += $shift;
            }
            $r['pausedAt'] = 0;
        } else {
            return array('ok' => true);
        }
        unset($r);
        foreach ($this->matches as $m) {
            $m->pause($on);
        }
        $this->feed($on ? '선생님이 경기를 잠시 멈췄어요' : '경기를 다시 시작해요', 'info');
        $this->changed();
        return array('ok' => true);
    }

    public function stop(): array {
        if ('playing' !== $this->s['phase']) {
            return array('ok' => false, 'why' => 'not-playing');
        }
        if ($this->s['round']['pausedAt']) {
            $this->pause(false);
        }
        foreach ($this->matches as $m) {
            if (! $m->s['ended']) {
                $m->end('stop');
            }
        }
        $this->finish_round('stop');
        return array('ok' => true);
    }

    public function kick(int $uid): array {
        if (! isset($this->s['members'][(string) $uid])) {
            return array('ok' => false, 'why' => 'unknown');
        }
        $this->s['banned'][] = $uid;
        $this->to_uid($uid, array('t' => 'closed', 'reason' => 'kicked'));
        $this->remove_member($uid);
        return array('ok' => true);
    }

    public function close(string $reason): void {
        if ($this->s['closed']) {
            return;
        }
        if ('playing' === $this->s['phase']) {
            $this->stop();
        }
        $this->s['closed'] = true;
        $this->s['closeReason'] = $reason;
        $this->broadcast(array('t' => 'closed', 'reason' => $reason));
    }

    // ── attacks ──────────────────────────────────────────────

    /** @return list<Team_Match> live matches, best first */
    private function ranking(): array {
        $list = array_values($this->matches);
        usort($list, static function (Team_Match $a, Team_Match $b): int {
            $pa = $a->progress();
            $pb = $b->progress();
            return array($pb['pct'], $pb['solved'], $a->s['team']) <=> array($pa['pct'], $pa['solved'], $b->s['team']);
        });
        return $list;
    }

    /** @param array<string,mixed> $p */
    public function route_attack(Team_Match $from, array $p, string $item): bool {
        $live = array_values(array_filter($this->ranking(), static fn (Team_Match $m): bool => ! $m->s['ended']));
        $k = array_search($from, $live, true);
        if (false === $k || count($live) < 2) {
            return false;
        }
        $target = 0 === $k ? $live[1] : $live[$k - 1];
        $it = Catalog::item($item);
        $ok = $target->receive_attack(array('id' => $from->s['team'] . '-' . $this->now . '-' . random_int(0, 999999), 'item' => $item, 'fromTeam' => $from->s['team'], 'fromName' => $p['name']));
        if ($ok) {
            $this->feed($from->s['team'] . '팀 ' . $p['name'] . ' → ' . $target->s['team'] . '팀에 ' . $it['name'] . '!', 'attack');
            $this->emit('boards', array('t' => 'attack', 'from' => $from->s['team'], 'to' => $target->s['team'], 'item' => $it, 'by' => $p['name']));
        }
        return $ok;
    }

    /** @param array<string,mixed> $inc */
    public function attack_done(Team_Match $target, array $inc, bool $blocked): void {
        $it = Catalog::item($inc['item']);
        if ($blocked) {
            $this->feed($target->s['team'] . '팀이 ' . $it['name'] . ' 공격을 방패로 막았어요', 'shield');
        }
        $this->to_team((int) $inc['fromTeam'], array('t' => 'attack_result', 'to' => $target->s['team'], 'item' => $it, 'blocked' => $blocked));
    }

    // ── round end ────────────────────────────────────────────

    public function on_match_end(Team_Match $m): void {
        if (! $this->s['round']) {
            return;
        }
        if ('clear' === $m->s['ended']) {
            $this->s['round']['doneOrder'][] = $m->s['team'];
            $rank = count($this->s['round']['doneOrder']);
            $this->feed($m->s['team'] . '팀이 ' . $rank . '등으로 판을 모두 풀었어요!', 'clear');
            $this->to_team((int) $m->s['team'], array('t' => 'team_done', 'rank' => $rank, 'ms' => $m->elapsed()));
            $this->emit('boards', array('t' => 'clear', 'team' => $m->s['team'], 'rank' => $rank));
        }
        $this->changed();
        if ('free' === $this->s['mode'] && 'idle' === $m->s['ended']) {
            $this->finish_round('idle');
            $this->close('idle');
            return;
        }
        foreach ($this->matches as $x) {
            if (! $x->s['ended']) {
                return;
            }
        }
        $this->finish_round('stop' === $m->s['ended'] ? 'stop' : 'done');
    }

    private function finish_round(string $reason): void {
        $r = $this->s['round'];
        if (! $r || 'playing' !== $this->s['phase']) {
            return;
        }
        $rows = array();
        foreach ($this->matches as $m) {
            $rows[] = $m->result();
        }
        $done = array_values(array_filter($rows, static fn (array $x): bool => $x['cleared']));
        usort($done, static fn (array $a, array $b): int => array_search($a['team'], $r['doneOrder'], true) <=> array_search($b['team'], $r['doneOrder'], true));
        $rest = array_values(array_filter($rows, static fn (array $x): bool => ! $x['cleared']));
        usort($rest, static fn (array $a, array $b): int => array($b['pct'], $b['solved']) <=> array($a['pct'], $a['solved']));
        $teams = array();
        foreach ($done as $k => $x) {
            $teams[] = $x + array('rank' => $k + 1);
        }
        $prev = null;
        foreach ($rest as $k => $x) {
            $rank = $prev && $prev['pct'] === $x['pct'] && $prev['solved'] === $x['solved'] ? $teams[count($teams) - 1]['rank'] : count($done) + $k + 1;
            $teams[] = $x + array('rank' => $rank);
            $prev = $x;
        }
        $result = array(
            'round' => $r['no'], 'mode' => $this->s['mode'], 'sessionId' => $this->s['wpId'], 'title' => $this->s['title'],
            'reason' => $reason, 'startedAt' => $r['wallStart'], 'endedAt' => time(), 'limitMs' => $r['limitMs'],
            'blocks' => $r['blocks'], 'settings' => $this->s['settings'], 'attacks' => $this->s['attacks'], 'teams' => $teams,
        );
        $this->s['lastResult'] = $result;
        $this->s['phase'] = 'lobby';
        $this->s['round'] = null;
        $this->matches = array();
        $this->broadcast(array('t' => 'round_result', 'result' => $result));
        $this->changed();
        foreach ($teams as $t) {
            foreach ($t['players'] as $p) {
                if (! $p['bot']) {
                    $this->finished = $result;
                    break 2;
                }
            }
        }
    }

    // ── ticking ──────────────────────────────────────────────

    public function tick(): void {
        $r = $this->s['round'];
        if ($r && 'playing' === $this->s['phase']) {
            if (! $r['pausedAt'] && $this->now >= $r['startAt']) {
                $every = $this->s['settings']['bossEverySec'] * 1000;
                $due = intdiv($this->round_elapsed(), $every);
                while ($this->s['round']['slot'] < $due && $this->s['round']['slot'] < count($r['bossSlots'])) {
                    $k = $this->s['round']['slot'];
                    $slot = $r['bossSlots'][$k];
                    $q = $r['bossQueue'] ? $r['bossQueue'][$k % count($r['bossQueue'])] : null;
                    $this->s['round']['slot']++;
                    foreach ($this->matches as $m) {
                        if ($m->s['ended']) {
                            continue;
                        }
                        if ($q) {
                            $m->spawn_boss($q, $slot);
                        } elseif ($slot['gather']) {
                            $m->spawn_boss(array('title' => '모여라', 'code' => '', 'answers' => array()), $slot + array('gatherOnly' => true));
                        }
                    }
                }
            }
            foreach ($this->matches as $m) {
                if ($this->s['round']) {
                    $m->tick();
                }
            }
        }
        $this->push_standings();
        $boardDue = $this->now - (int) $this->s['lastBoardAt'] >= 1000;
        if ($this->s['dirty'] || $boardDue) {
            $this->s['lastBoardAt'] = $this->now;
            $this->emit('boards', $this->board_view());
        }
        if ($this->s['dirty']) {
            $this->s['dirty'] = false;
            $this->broadcast(array('t' => 'arena') + $this->detail());
        }
        $quiet = $this->now - (int) $this->s['touchedAt'];
        if ('free' === $this->s['mode'] && 'lobby' === $this->s['phase'] && $quiet > 600000) {
            $this->close('timeout');
        }
        if ('class' === $this->s['mode'] && 'playing' !== $this->s['phase'] && $quiet > 4 * 3600000) {
            $this->close('timeout');
        }
    }

    /** @return list<array<string,mixed>> */
    public function standings(): array {
        $r = $this->s['round'];
        if (! $r) {
            return array();
        }
        $list = array();
        foreach ($this->ranking() as $m) {
            $p = $m->progress();
            $list[] = array(
                'team' => $m->s['team'], 'solved' => $p['solved'], 'total' => $p['total'], 'pct' => $p['pct'],
                'done' => (bool) $m->s['ended'], 'cleared' => 'clear' === $m->s['ended'], 'ms' => $m->elapsed(),
                'boss' => (bool) $m->s['boss'], 'fx' => $m->fx_pub(),
            );
        }
        $k = 0;
        $prev = null;
        foreach ($list as $i => $row) {
            $done = array_search($row['team'], $r['doneOrder'], true);
            if (false !== $done) {
                $list[$i]['rank'] = $done + 1;
                continue;
            }
            $k++;
            $list[$i]['rank'] = $prev && $prev['pct'] === $row['pct'] && $prev['solved'] === $row['solved'] ? $prev['rank'] : count($r['doneOrder']) + $k;
            $prev = $list[$i];
        }
        usort($list, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        return $list;
    }

    private function push_standings(): void {
        if (! $this->s['round']) {
            return;
        }
        $list = $this->standings();
        $key = (string) json_encode(array_map(static fn (array $s): array => array($s['team'], $s['solved'], $s['rank'], $s['done'], $s['boss']), $list));
        if ($key === $this->s['lastStandings']) {
            return;
        }
        $this->s['lastStandings'] = $key;
        $this->broadcast(array('t' => 'standings', 'list' => $list, 'remainMs' => $this->round_remaining()));
    }

    public function board_view(): array {
        $teams = array();
        for ($n = 1; $n <= $this->team_count(); $n++) {
            $members = array();
            foreach ($this->s['members'] as $x) {
                if ((int) $x['team'] === $n) {
                    $members[] = array('name' => $x['name'], 'avatar' => $x['avatar'], 'color' => $x['color'], 'online' => (bool) $x['online']);
                }
            }
            foreach ($this->s['bots'] as $b) {
                if ((int) $b['team'] === $n) {
                    $members[] = array('name' => $b['name'], 'avatar' => $b['avatar'], 'color' => $b['color'], 'bot' => true, 'online' => true);
                }
            }
            $m = $this->match_of($n);
            if (! $m) {
                $teams[] = array('team' => $n, 'members' => $members, 'cells' => '', 'cols' => Catalog::COLS);
                continue;
            }
            $cells = '';
            foreach ($m->s['cells'] as $i => $c) {
                if ($m->s['boss'] && $m->s['boss']['i'] === $i) {
                    $cells .= 'B';
                } elseif ($c['solved']) {
                    $cells .= $c['crown'] ? 'K' : '1';
                } elseif (isset($m->s['occ'][$i])) {
                    $cells .= 'O';
                } else {
                    $cells .= '0';
                }
            }
            $shields = 0;
            foreach ($m->s['players'] as $p) {
                $shields += $p['shields'];
            }
            $teams[] = array('team' => $n, 'members' => $members, 'cells' => $cells, 'cols' => $m->s['cols'], 'done' => $m->s['ended'], 'fx' => $m->fx_pub(), 'shields' => $shields, 'boss' => $m->s['boss'] ? $m->s['boss']['phase'] : null) + $m->progress();
        }
        $r = $this->s['round'];
        return array(
            't' => 'board', 'phase' => $this->s['phase'], 'paused' => (bool) ($r['pausedAt'] ?? 0),
            'startMs' => $r ? max(0, $r['startAt'] - ($r['pausedAt'] ?: $this->now)) : 0,
            'remainMs' => $this->round_remaining(), 'attacks' => $this->attacks(), 'teams' => $teams, 'standings' => $this->standings(),
        );
    }

    /** Arena detail without the per-viewer "you"/"role" (the browser adds those). */
    public function detail(): array {
        $team_of = function (int $n): array {
            $members = array();
            foreach ($this->s['members'] as $m) {
                if ((int) $m['team'] === $n) {
                    $members[] = $this->member_pub($m);
                }
            }
            foreach ($this->s['bots'] as $b) {
                if ((int) $b['team'] === $n) {
                    $members[] = array('uid' => 0, 'id' => $b['id'], 'name' => $b['name'], 'avatar' => $b['avatar'], 'color' => $b['color'], 'bot' => true, 'online' => true);
                }
            }
            return array('no' => $n, 'members' => $members);
        };
        $teams = array();
        for ($n = 1; $n <= $this->team_count(); $n++) {
            $teams[] = $team_of($n);
        }
        $r = $this->s['round'];
        return array(
            'id' => $this->s['id'], 'mode' => $this->s['mode'], 'title' => $this->s['title'], 'context' => $this->s['context'],
            'phase' => $this->s['phase'], 'settings' => $this->s['settings'], 'attacks' => $this->attacks(), 'host' => $this->s['hostUid'],
            'teams' => $teams, 'waiting' => $team_of(0)['members'],
            'round' => $r ? array('no' => $r['no'], 'remainMs' => $this->round_remaining(), 'paused' => (bool) $r['pausedAt'], 'startMs' => max(0, $r['startAt'] - ($r['pausedAt'] ?: $this->now))) : null,
            'roundNo' => $this->s['roundNo'], 'lastResult' => $this->s['lastResult'], 'feed' => array_slice($this->s['feed'], -12),
            'rosterSize' => is_array($this->s['roster']) ? count($this->s['roster']) : 0,
        );
    }

    /** @param array<string,mixed> $m */
    private function member_pub(array $m): array {
        $match = $this->match_of((int) $m['team']);
        $p = $match ? ($match->s['players'][(string) $m['uid']] ?? null) : null;
        $state = $m['online'] ? 'online' : 'offline';
        if ($p && $m['online']) {
            if ($match->s['boss'] && $match->s['boss']['by'] === (string) $m['uid']) {
                $state = 'boss';
            } elseif ($p['occ'] >= 0) {
                $state = 'typing';
            } else {
                $state = 'playing';
            }
        }
        return array('uid' => $m['uid'], 'id' => (string) $m['uid'], 'name' => $m['name'], 'avatar' => $m['avatar'], 'color' => $m['color'], 'bot' => false, 'online' => (bool) $m['online'], 'state' => $state, 'solved' => $p ? $p['solved'] : 0);
    }

    /** Free-room lobby line. */
    public function summary(): array {
        $members = array();
        foreach (array_slice(array_values($this->s['members']), 0, 8) as $m) {
            $members[] = array('avatar' => $m['avatar'], 'color' => $m['color']);
        }
        $m = $this->match_of(1);
        return array(
            'id' => $this->s['id'], 'title' => $this->s['title'], 'phase' => $this->s['phase'], 'count' => $this->headcount(), 'max' => $this->capacity(),
            'tags' => $this->s['settings']['tags'], 'limitMin' => $this->s['settings']['limitMin'], 'members' => $members,
            'pct' => $m ? $m->progress()['pct'] : 0,
        );
    }

    /** Team of a learner (0 = none), used to pick their events. */
    public function team_of(int $uid): int {
        return (int) ($this->s['members'][(string) $uid]['team'] ?? 0);
    }
}

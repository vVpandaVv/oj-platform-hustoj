<?php
declare(strict_types=1);

namespace OjCodeArena\Engine;

/**
 * Built-in mode transport: every browser polls this (via relay.php, or the
 * REST fallback) with its ticket, a cursor and queued commands. Works with
 * nothing but $wpdb so relay.php can load WordPress with SHORTINIT.
 *
 * Request:  { ticket, arena?, cursor, cmds: [{t, rid?, ...}] }
 * Response: { ok, now, cursor, arena, events: [msg], acks: [{rid,...}], rooms?, ticket? }
 */
final class Relay {
    private const TICK_MS = 200;
    private const MAX_EVENTS = 300;
    private const RATE = 40;

    private Store $store;
    private string $secret;
    private int $now;
    /** @var callable(): array */
    private $bank;
    /** @var callable(array, string): void */
    private $on_result;

    /**
     * @param callable(): array $bank problem bank for new free rooms
     * @param callable(array, string): void $on_result stores a finished round
     */
    public function __construct(Store $store, string $secret, callable $bank, callable $on_result, ?int $now = null) {
        $this->store = $store;
        $this->secret = $secret;
        $this->bank = $bank;
        $this->on_result = $on_result;
        $this->now = $now ?? (int) floor(microtime(true) * 1000);
    }

    // ── WordPress side: create / refresh / close class games ────

    /**
     * Same payload as the Node server's POST /api/sessions.
     *
     * @param array<string,mixed> $o id, title, context, settings, roster, problems
     */
    public function upsert_session(array $o): void {
        $key = 'c' . (int) $o['id'];
        $this->store->locked($key, function () use ($key, $o): void {
            $row = $this->row($key);
            if ($row && ! (int) $row['closed']) {
                $arena = new Arena(json_decode($row['state'], true), (array) $o['problems'], $this->now);
                $arena->refresh($o, (array) $o['problems']);
                $this->save($key, $arena, (array) $o['problems']);
                return;
            }
            $state = Arena::fresh(array('id' => $key, 'mode' => 'class', 'wpId' => (int) $o['id']) + $o, $this->now);
            $this->save($key, new Arena($state, (array) $o['problems'], $this->now), (array) $o['problems'], true);
        });
    }

    public function close_session(int $id): void {
        $key = 'c' . $id;
        $this->store->locked($key, function () use ($key): void {
            $row = $this->row($key);
            if (! $row || (int) $row['closed']) {
                return;
            }
            $arena = new Arena(json_decode($row['state'], true), json_decode($row['problems'], true) ?: array(), $this->now);
            $arena->close('ended');
            $this->save($key, $arena, null);
        });
    }

    /** @return array{sessions:int, rooms:int, players:int} */
    public function status(): array {
        return array(
            'sessions' => $this->store->count_open('class'),
            'rooms' => $this->store->count_open('free'),
            'players' => $this->store->count_players($this->now - 15000),
        );
    }

    // ── polling ──────────────────────────────────────────────

    /**
     * @param array<string,mixed> $req
     * @return array<string,mixed>
     */
    public function handle(array $req): array {
        $claims = self::read_ticket($this->secret, (string) ($req['ticket'] ?? ''), (int) floor($this->now / 1000));
        if (! $claims) {
            return array('ok' => false, 'why' => 'bad-ticket');
        }
        $uid = (int) $claims['uid'];
        $role = (string) $claims['role'];
        $sid = (int) ($claims['sid'] ?? 0);
        $cursor = max(0, (int) ($req['cursor'] ?? 0));
        $cmds = array_slice(array_values(array_filter((array) ($req['cmds'] ?? array()), 'is_array')), 0, 40);
        $acks = array();

        // Which arena: a class game from the ticket, or a free room / the lobby.
        if ($sid > 0) {
            $key = 'c' . $sid;
        } else {
            if ('learner' !== $role || empty($claims['free'])) {
                return array('ok' => false, 'why' => 'free-off');
            }
            $key = preg_match('/^r[0-9a-f]{6}$/', (string) ($req['arena'] ?? '')) ? (string) $req['arena'] : 'lobby';
            if ('lobby' === $key) {
                $back = $this->my_room($uid);
                if ($back) {
                    $key = $back;
                    $cursor = 0;
                }
            }
        }

        if ('lobby' === $key) {
            $this->seen('lobby', $uid, $role);
            foreach ($cmds as $cmd) {
                $res = $this->lobby_command($uid, (string) ($claims['name'] ?? ''), $cmd);
                if (isset($cmd['rid'])) {
                    $acks[] = array('rid' => $cmd['rid']) + $res;
                }
                if (! empty($res['ok']) && ! empty($res['id'])) {
                    $key = (string) $res['id'];
                    $cursor = 0;
                    break;
                }
            }
            if ('lobby' === $key) {
                return $this->reply($claims, 'lobby', $cursor, array(), $acks) + array('rooms' => $this->rooms());
            }
        }

        $this->seen($key, $uid, $role);
        $row = $this->store->head($key);
        if (! $row) {
            return $sid > 0 ? array('ok' => false, 'why' => 'no-session') : $this->reply($claims, 'lobby', 0, array(), $acks) + array('rooms' => $this->rooms());
        }
        if ((int) $row['closed']) {
            $state = json_decode($row['state'], true);
            return array('ok' => false, 'why' => (string) ($state['closeReason'] ?? 'closed') ?: 'closed');
        }

        $first = 0 === $cursor;
        if ('learner' === $role && ! $first) {
            // Only current members keep receiving this arena's events: a
            // kicked learner is refused, anyone else re-enters (and is
            // checked by can_enter) as on a first poll.
            $head = json_decode((string) $row['state'], true);
            if (! isset($head['members'][(string) $uid])) {
                if (in_array($uid, (array) ($head['banned'] ?? array()), true)) {
                    return array('ok' => false, 'why' => 'kicked');
                }
                $first = true;
            }
        }
        if ($first) {
            $cursor = $this->store->max_event_id($key);
        }
        $needs = $first || $cmds || (int) $row['next_tick'] <= $this->now;
        $error = null;
        $team = null;
        if ($needs) {
            $this->store->locked($key, function () use ($key, $claims, $uid, $role, $first, $cmds, &$acks, &$error, &$team): void {
                $row = $this->row($key);
                if (! $row || (int) $row['closed']) {
                    $error = 'closed';
                    return;
                }
                $problems = json_decode($row['problems'], true) ?: array();
                $arena = new Arena(json_decode($row['state'], true), $problems, $this->now);
                $arena->presence($this->store->presence($key));
                if ($first) {
                    if ('learner' === $role) {
                        $why = $arena->can_enter($uid);
                        if ($why) {
                            $error = $why;
                            return;
                        }
                    }
                    $arena->attach($uid, mb_substr((string) ($claims['name'] ?? '학생'), 0, 40), $role, array('avatar' => $uid % 8, 'color' => ($uid * 3) % 8));
                }
                foreach ($cmds as $cmd) {
                    if ('learner' === $role && ! $this->allow($arena, $uid)) {
                        // Over the limit: moves are dropped, the rest are refused.
                        if (isset($cmd['rid'])) {
                            $acks[] = array('rid' => $cmd['rid'], 'ok' => false, 'why' => 'too-fast');
                        }
                        continue;
                    }
                    $res = $this->command($arena, $uid, $role, $cmd);
                    if (isset($cmd['rid']) && null !== $res) {
                        $acks[] = array('rid' => $cmd['rid']) + $res;
                    }
                }
                if ((int) $row['next_tick'] <= $this->now || $cmds) {
                    $arena->tick();
                }
                $team = $arena->team_of($uid);
                $this->save($key, $arena, null);
            });
        }
        if ($error) {
            return array('ok' => false, 'why' => $error);
        }
        if (null === $team) {
            $state = json_decode((string) $row['state'], true);
            $team = (int) ($state['members'][(string) $uid]['team'] ?? 0);
        }
        return $this->reply($claims, $key, $cursor, self::scopes($uid, $role, $team), $acks);
    }

    /**
     * Token bucket per learner, same rate as the Node server (40 commands
     * per second, bursts up to 40). Kept in the arena state.
     */
    private function allow(Arena $arena, int $uid): bool {
        $key = (string) $uid;
        $bucket = $arena->s['rate'][$key] ?? array(self::RATE, $this->now);
        $tokens = min(self::RATE, $bucket[0] + ($this->now - $bucket[1]) * self::RATE / 1000);
        $ok = $tokens >= 1;
        $arena->s['rate'][$key] = array($ok ? $tokens - 1 : $tokens, $this->now);
        return $ok;
    }

    /**
     * @param array<string,mixed> $claims
     * @param list<string> $scopes
     * @param list<array<string,mixed>> $acks
     */
    private function reply(array $claims, string $key, int $cursor, array $scopes, array $acks): array {
        $events = array();
        if ($scopes) {
            $want = array_flip($scopes);
            foreach ($this->store->events_after($key, $cursor, self::MAX_EVENTS) as $r) {
                $cursor = $r['id'];
                if (isset($want[$r['scope']])) {
                    $events[] = json_decode($r['payload'], true);
                }
            }
        }
        // No renewal here: the browser fetches a fresh ticket from WordPress
        // before this one expires, so permissions are checked again.
        return array('ok' => true, 'now' => $this->now, 'cursor' => $cursor, 'arena' => $key, 'events' => $events, 'acks' => $acks);
    }

    /** @return list<string> */
    private static function scopes(int $uid, string $role, int $team): array {
        $base = array('all', 'uid:' . $uid);
        if ('teacher' === $role) {
            return array_merge($base, array('teachers'));
        }
        if ('board' === $role) {
            return array_merge($base, array('boards'));
        }
        return array_merge($base, array('learners', 'team:' . $team));
    }

    /**
     * Routes one command like game-server/src/hub.js. Returns the ack body,
     * or null for fire-and-forget commands.
     *
     * @param array<string,mixed> $cmd
     * @return array<string,mixed>|null
     */
    private function command(Arena $a, int $uid, string $role, array $cmd): ?array {
        $t = (string) ($cmd['t'] ?? '');
        if ('look' === $t) {
            $look = self::look($cmd);
            $a->set_look($uid, $look);
            return array('ok' => true, 'look' => $look);
        }
        if ('teacher' === $role) {
            switch ($t) {
                case 'settings': return $a->update_settings((array) ($cmd['settings'] ?? array()));
                case 'start': return $a->start();
                case 'pause': return $a->pause(! empty($cmd['on']));
                case 'stop': return $a->stop();
                case 'close': $a->close('ended'); return array('ok' => true);
                case 'kick': return $a->kick((int) ($cmd['uid'] ?? 0));
                case 'move': return $a->set_team((int) ($cmd['uid'] ?? 0), (int) ($cmd['team'] ?? 0));
                case 'auto': $a->auto_assign(); return array('ok' => true);
                case 'attacks': $a->set_attacks(! empty($cmd['on'])); return array('ok' => true);
                case 'bot_add': return $a->add_bot((int) ($cmd['team'] ?? 1));
                case 'bot_remove': return $a->remove_bot(mb_substr((string) ($cmd['id'] ?? ''), 0, 10));
                case 'bot_fill': $a->fill_bots(); return array('ok' => true);
                case 'bot_clear': $a->clear_bots(); return array('ok' => true);
            }
            return array('ok' => false, 'why' => 'unknown');
        }
        if ('board' === $role) {
            return array('ok' => false, 'why' => 'read-only');
        }
        $m = $a->s['round'] ? $a->match_of($a->team_of($uid)) : null;
        $pid = (string) $uid;
        $host = 'free' === $a->s['mode'] && (int) $a->s['hostUid'] === $uid;
        $text = static fn (int $max): string => mb_substr((string) ($cmd['text'] ?? ''), 0, $max);
        switch ($t) {
            case 'move':
                if ($m) {
                    $m->move($pid, array('dir' => $cmd['dir'] ?? null, 'jump' => ! empty($cmd['jump']), 'to' => $cmd['to'] ?? null, 'seq' => isset($cmd['seq']) ? (int) $cmd['seq'] : -1));
                }
                return null;
            case 'occupy': return $m ? $m->occupy($pid) : array('ok' => false, 'why' => 'not-playing');
            case 'submit': return $m ? $m->submit($pid, $text(400)) : array('ok' => false, 'why' => 'not-playing');
            case 'release': $m?->release($pid, 'esc'); return null;
            case 'grab': return $m ? $m->grab($pid, (string) ($cmd['key'] ?? '')) : array('ok' => false, 'why' => 'not-playing');
            case 'boss_submit': return $m ? $m->boss_submit($pid, $text(1200)) : array('ok' => false, 'why' => 'not-playing');
            case 'giveup': $m?->give_up($pid); return null;
            case 'item': return $m ? $m->use_item($pid, (int) ($cmd['slot'] ?? 0)) : array('ok' => false, 'why' => 'not-playing');
            case 'defend': return $m ? $m->defend($pid) : array('ok' => false, 'why' => 'not-playing');
            case 'poke': $m?->touch($pid); return null;
            case 'team':
                if ('class' !== $a->s['mode'] || empty($a->s['settings']['selfPick'])) {
                    return array('ok' => false, 'why' => 'teacher-picks');
                }
                return $a->set_team($uid, (int) ($cmd['no'] ?? 0));
            case 'leave':
                if ('free' !== $a->s['mode']) {
                    return array('ok' => false, 'why' => 'class');
                }
                $a->remove_member($uid);
                return array('ok' => true, 'lobby' => true);
            case 'start': return $host ? $a->start() : array('ok' => false, 'why' => 'not-host');
            case 'settings': return $host ? $a->update_settings((array) ($cmd['settings'] ?? array())) : array('ok' => false, 'why' => 'not-host');
            case 'bot':
                if (! $host) {
                    return array('ok' => false, 'why' => 'not-host');
                }
                return 'remove' === ($cmd['op'] ?? '') ? $a->remove_bot(mb_substr((string) ($cmd['id'] ?? ''), 0, 10)) : $a->add_bot(1);
        }
        return array('ok' => false, 'why' => 'unknown');
    }

    /** @param array<string,mixed> $cmd */
    private function lobby_command(int $uid, string $name, array $cmd): array {
        $t = (string) ($cmd['t'] ?? '');
        if ('rooms' === $t || 'look' === $t) {
            return array('ok' => true);
        }
        if ('room_join' === $t) {
            $id = (string) ($cmd['id'] ?? '');
            $row = preg_match('/^r[0-9a-f]{6}$/', $id) ? $this->row($id) : null;
            if (! $row || (int) $row['closed']) {
                return array('ok' => false, 'why' => 'no-room');
            }
            $arena = new Arena(json_decode($row['state'], true), array(), $this->now);
            $why = $arena->can_enter($uid);
            return $why ? array('ok' => false, 'why' => $why) : array('ok' => true, 'id' => $id);
        }
        if ('room_create' !== $t) {
            return array('ok' => false, 'why' => 'not-in-room');
        }
        if ($this->now - $this->store->last_room_created_by($uid) < 30000) {
            return array('ok' => false, 'why' => 'too-soon');
        }
        if ($this->store->count_open('free') >= 60) {
            return array('ok' => false, 'why' => 'too-many-rooms');
        }
        do {
            $id = 'r' . bin2hex(random_bytes(3));
        } while ($this->row($id));
        $title = trim(mb_substr(strip_tags((string) ($cmd['title'] ?? '')), 0, 30));
        $state = Arena::fresh(array(
            'id' => $id, 'mode' => 'free', 'title' => '' !== $title ? $title : $name . '의 방',
            'settings' => (array) ($cmd['settings'] ?? array()), 'hostUid' => $uid,
        ), $this->now);
        $problems = ($this->bank)();
        $this->save($id, new Arena($state, $problems, $this->now), $problems, true, $uid);
        return array('ok' => true, 'id' => $id);
    }

    // ── storage ──────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function row(string $key): ?array {
        return $this->store->row($key);
    }

    /** @param array<string,mixed>|null $problems null = keep stored */
    private function save(string $key, Arena $arena, ?array $problems, bool $insert = false, int $host = 0): void {
        $state = $arena->export();
        $fields = array(
            'state' => (string) json_encode($state, JSON_UNESCAPED_UNICODE),
            'summary' => (string) json_encode($arena->summary(), JSON_UNESCAPED_UNICODE),
            'next_tick' => $this->now + self::TICK_MS,
            'closed' => $state['closed'] ? 1 : 0,
            'updated' => $this->now,
        );
        if (null !== $problems) {
            $fields['problems'] = (string) json_encode($problems, JSON_UNESCAPED_UNICODE);
        }
        if ($insert) {
            $fields += array('mode' => $state['mode'], 'host_uid' => $host, 'created' => $this->now, 'problems' => '{}');
        }
        $events = array();
        foreach ($arena->out as list($scope, $msg)) {
            $events[] = array($scope, (string) json_encode($msg, JSON_UNESCAPED_UNICODE));
        }
        $arena->out = array();
        $this->store->write($key, $fields, $insert, $events, $this->now);
        if ($arena->finished) {
            ($this->on_result)($arena->finished, (string) $state['title']);
            $arena->finished = null;
        }
        if (0 === random_int(0, 49)) {
            $this->store->prune($this->now);
        }
    }

    private function seen(string $key, int $uid, string $role): void {
        $this->store->seen($key, $uid, $role, $this->now);
    }

    private function my_room(int $uid): ?string {
        $key = $this->store->recent_free_arena($uid, $this->now - 60000);
        if (! $key) {
            return null;
        }
        $row = $this->row($key);
        $state = $row ? json_decode($row['state'], true) : null;
        return $state && isset($state['members'][(string) $uid]) ? $key : null;
    }

    /** @return list<array<string,mixed>> */
    private function rooms(): array {
        return array_values(array_filter(array_map(static fn (string $s) => json_decode($s, true), $this->store->free_rooms())));
    }

    // ── tickets (same format as Support\Ticket / sign.js) ─────

    /** @return array<string,mixed>|null */
    public static function read_ticket(string $secret, string $ticket, int $now_sec): ?array {
        if (strlen($secret) < 32 || strlen($ticket) > 4096) {
            return null;
        }
        $dot = strpos($ticket, '.');
        if (false === $dot || $dot < 1) {
            return null;
        }
        $body = substr($ticket, 0, $dot);
        $sig = self::unb64(substr($ticket, $dot + 1));
        if (! hash_equals(hash_hmac('sha256', $body, $secret, true), $sig)) {
            return null;
        }
        $claims = json_decode(self::unb64($body), true);
        if (! is_array($claims) || 1 !== ($claims['v'] ?? 0) || ! is_int($claims['exp'] ?? null) || $claims['exp'] < $now_sec) {
            return null;
        }
        if (! is_int($claims['uid'] ?? null) || $claims['uid'] <= 0 || ! in_array($claims['role'] ?? '', array('learner', 'teacher', 'board'), true)) {
            return null;
        }
        return $claims;
    }

    /** @param array<string,mixed> $claims */
    public static function issue_ticket(string $secret, array $claims, int $now_sec): string {
        $claims['exp'] = $now_sec + 300;
        $body = rtrim(strtr(base64_encode((string) json_encode($claims, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        return $body . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /**
     * @param array<string,mixed> $src
     * @return array{avatar:int,color:int}
     */
    private static function look(array $src): array {
        return array('avatar' => abs((int) ($src['avatar'] ?? 0)) % 8, 'color' => abs((int) ($src['color'] ?? 0)) % 8);
    }
}

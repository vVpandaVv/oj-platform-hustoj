<?php
/**
 * Built-in engine checks (php tests/php/engine.php) — the same scenarios as
 * game-server/test/*.test.js, plus the Relay polling flow on File_Store.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('OJ_CODE_ARENA_PATH', dirname(__DIR__, 2) . '/');
require OJ_CODE_ARENA_PATH . 'src/Core/Autoloader.php';
\OjCodeArena\Core\Autoloader::register();

use OjCodeArena\Engine\Arena;
use OjCodeArena\Engine\File_Store;
use OjCodeArena\Engine\Relay;
use OjCodeArena\Engine\Rules;
use OjCodeArena\Engine\Team_Match;

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (! $ok) {
        $fails++;
    }
}

// ── rules ──────────────────────────────────────────────────
$none = static fn (int $i): bool => false;
check('step stops at edge', array(0, 0) === Rules::step(4, 2, $none, $none, 0, 0, 'L', false));
$solved = static fn (int $i): bool => in_array($i, array(2, 3), true);
check('jump slides unsolved run', array(0, 1) === Rules::step(6, 1, $solved, $none, 0, 0, 'R', true));
check('jump skips solved run', array(0, 4) === Rules::step(6, 1, $solved, $none, 0, 1, 'R', true));
$blk = static fn (int $i): bool => in_array($i, array(0, 7), true);
check('home avoids blocked', array(0, 1) === Rules::edge(4, 2, $blk, 0, 2, 'home'));
check('last avoids blocked', array(1, 2) === Rules::edge(4, 2, $blk, 0, 1, 'last'));
check('mirror flips', array(0, 2) === Rules::move(3, 1, $none, $none, 0, 1, array('dir' => 'L'), true));
check('grading spaces/quotes', Rules::same_code("print( 'a' ,'b' )", 'print("a","b")'));
check('grading string content', ! Rules::same_code("print('a b')", "print('ab')"));
check('grading open string', ! Rules::same_code("print('x)", "print('x')"));
check('grading escaped quote', Rules::same_code('print("It\'s")', "print('It\\'s')"));
check('boss output ignores spaces', Rules::check(array('code' => 'x', 'answers' => array('3 2 1')), '321'));
check('boss blank uses code rules', Rules::check(array('code' => '___', 'answers' => array('range(1, 6)'), 'blank' => true), 'range(1,6)'));

// every default problem self-grades in PHP too
$bank = json_decode((string) file_get_contents(OJ_CODE_ARENA_PATH . 'data/default-bank.json'), true);
$bad = 0;
foreach ($bank['normal'] as $p) { $bad += Rules::check(array('code' => $p['code']), $p['code']) ? 0 : 1; }
foreach ($bank['boss'] as $p) { $bad += Rules::check($p + array('blank' => str_contains($p['code'], '___')), $p['answers'][0]) ? 0 : 1; }
check('default bank self-grades in PHP', 0 === $bad);

// ── arena + matches (in memory) ────────────────────────────
function arena(int $now, array $over = array()): Arena {
    $state = Arena::fresh($over + array('id' => 'c1', 'mode' => 'class', 'wpId' => 1, 'title' => 'T', 'settings' => array('teams' => 3, 'limitMin' => 1, 'attacks' => true, 'bossEverySec' => 10, 'penalty' => false)), $now);
    return new Arena($state, array('normal' => array(array('code' => 'a=1', 'tags' => array()), array('code' => 'b=2', 'tags' => array())), 'boss' => array(array('title' => 'q', 'code' => 'print(1)', 'answers' => array('1'), 'tags' => array()))), $now);
}
function at(Arena $a, int $now): Arena {
    $x = new Arena($a->export(), array('normal' => array(array('code' => 'a=1'), array('code' => 'b=2')), 'boss' => array(array('title' => 'q', 'code' => 'print(1)', 'answers' => array('1')))), $now);
    return $x;
}

$t = 1000000;
$a = arena($t);
foreach (array(1, 2) as $uid) {
    $a->attach($uid, 'u' . $uid, 'learner', array('avatar' => 0, 'color' => 0));
    $a->set_team($uid, $uid);
}
check('start ok', true === $a->start()['ok']);
$m1 = $a->match_of(1);
$m2 = $a->match_of(2);
check('same board for every team', array_column($m1->s['cells'], 'code') === array_column($m2->s['cells'], 'code'));
check('no match for empty team', null === $a->match_of(3));
check('countdown blocks input', 'countdown' === $m1->occupy('1')['why']);

$a = at($a, $t + 5000);
$m1 = $a->match_of(1);
$p = $m1->s['players']['1'];
$i = $p['r'] * 12 + $p['c'];
check('occupy', true === $m1->occupy('1')['ok']);
check('wrong answer releases', false === $m1->submit('1', 'nope')['correct'] && -1 === $m1->s['players']['1']['occ']);
$m1->occupy('1');
check('right answer solves', true === $m1->submit('1', $m1->s['cells'][$i]['code'])['correct'] && $m1->s['cells'][$i]['solved']);

$a = at($a, $t + 15100);
$a->tick();
check('scheduled boss reaches both teams', null !== $a->match_of(1)->s['boss'] && null !== $a->match_of(2)->s['boss']);
$m1 = $a->match_of(1);
$bi = $m1->s['boss']['i'];
$m1->s['players']['1']['r'] = intdiv($bi, 12);
$m1->s['players']['1']['c'] = $bi % 12 - 1 >= 0 ? $bi % 12 - 1 : $bi % 12 + 1;
$key = $bi % 12 - 1 >= 0 ? 'Delete' : 'Backspace';
$g = $m1->grab('1', $key);
check('grab boss, no answers sent', true === $g['ok'] && ! isset($g['q']['answers']));
$win = $m1->boss_submit('1', ' 1 ');
check('boss win gives item and crown', true === $win['correct'] && null !== $win['item'] && 'u1' === $m1->s['cells'][$bi]['crown']);

// attacks: the leader hits the second team; shields block
$m1->s['players']['1']['deck'] = array('fog');
$m1->s['players']['1']['shields'] = 0;
$r = $m1->use_item('1', 1);
check('attack launched', true === $r['ok'] && 1 === count($a->match_of(2)->s['incoming']));
$a->match_of(2)->s['players']['2']['shields'] = 1;
check('shield blocks', true === $a->match_of(2)->defend('2')['ok'] && 0 === count($a->match_of(2)->s['incoming']));

// pause shifts deadlines
$a = at($a, $t + 20000);
$a->pause(true);
$a = at($a, $t + 80000);
$a->tick();
check('paused round does not end', 'playing' === $a->s['phase']);
$a->pause(false);
$a = at($a, $t + 80000 + 45000);
$a->tick();
check('round ends after remaining time, result stored', 'lobby' === $a->s['phase'] && null !== $a->finished && 1 === $a->finished['teams'][0]['rank']);

// bots fill and play
$b = arena($t);
$b->attach(1, 'u1', 'learner', array('avatar' => 0, 'color' => 0));
$b->set_team(1, 1);
$b->fill_bots();
$b->add_bot(2);
$b->fill_bots();
$b->start();
for ($k = 1; $k <= 300; $k++) {
    $b = at($b, $t + 5000 + $k * 200);
    $b->tick();
}
$botSolved = 0;
foreach (array(2, 3) as $n) {
    $bm = $b->match_of($n);
    $botSolved += $bm ? $bm->solved_count() : 0;
}
check('bots solve blocks', $botSolved > 0 || null !== $b->s['lastResult']);

// roster + kick
$c = arena($t, array('roster' => array(array('uid' => 1, 'name' => 'a'))));
check('roster gate', 'not-in-roster' === $c->can_enter(2) && '' === $c->can_enter(1));
$c->attach(1, 'a', 'learner', array('avatar' => 0, 'color' => 0));
$c->kick(1);
check('kicked stays out', 'kicked' === $c->can_enter(1));

// ── relay over File_Store ──────────────────────────────────
$file = sys_get_temp_dir() . '/oca-test-' . getmypid() . '.json';
@unlink($file);
$secret = str_repeat('s', 48);
$results = array();
$store = new File_Store($file);
$relay = static function (int $now) use ($store, $secret, &$results, $bank): Relay {
    return new Relay($store, $secret, static fn (): array => $bank, static function (array $r, string $title) use (&$results): void { $results[] = array($r, $title); }, $now);
};
$ticket = static fn (array $claims, int $now): string => Relay::issue_ticket($secret, $claims + array('v' => 1), intdiv($now, 1000));

$now = 1_800_000_000_000;
$relay($now)->upsert_session(array('id' => 9, 'title' => '3반', 'context' => '1-3', 'settings' => array('teams' => 2, 'limitMin' => 2),
    'roster' => array(array('uid' => 10, 'name' => '가'), array('uid' => 11, 'name' => '나')), 'problems' => array('normal' => array(array('code' => 'x = 1', 'tags' => array())), 'boss' => array())));

$out = $relay($now)->handle(array('ticket' => $ticket(array('uid' => 99, 'name' => '?', 'role' => 'learner', 'sid' => 9), $now), 'cursor' => 0));
check('relay: outsider refused', false === $out['ok'] && 'not-in-roster' === $out['why']);
check('relay: bad ticket refused', 'bad-ticket' === $relay($now)->handle(array('ticket' => 'x.y'))['why']);

$tt = $ticket(array('uid' => 1, 'name' => 'T', 'role' => 'teacher', 'sid' => 9), $now);
$out = $relay($now)->handle(array('ticket' => $tt, 'cursor' => 0));
check('relay: teacher gets arena detail', true === $out['ok'] && 'arena' === ($out['events'][0]['t'] ?? '') && '3반' === $out['events'][0]['title']);
$tcur = $out['cursor'];

$st = $ticket(array('uid' => 10, 'name' => '가', 'role' => 'learner', 'sid' => 9), $now);
$out = $relay($now)->handle(array('ticket' => $st, 'cursor' => 0, 'cmds' => array(array('t' => 'team', 'no' => 1, 'rid' => 1))));
check('relay: learner joins and picks a team', true === $out['ok'] && true === ($out['acks'][0]['ok'] ?? null));
$scur = $out['cursor'];

$out = $relay($now + 100)->handle(array('ticket' => $tt, 'cursor' => $tcur, 'cmds' => array(array('t' => 'start', 'rid' => 2))));
check('relay: teacher starts', true === ($out['acks'][0]['ok'] ?? null));

$out = $relay($now + 300)->handle(array('ticket' => $st, 'cursor' => $scur));
$types = array_column($out['events'], 't');
check('relay: learner receives the board state', in_array('state', $types, true));
$state = $out['events'][array_search('state', $types, true)];
check('relay: 24-block board with countdown', 24 === count($state['cells']) && $state['startMs'] > 0);
$scur = $out['cursor'];

$out = $relay($now + 6000)->handle(array('ticket' => $st, 'cursor' => $scur, 'cmds' => array(array('t' => 'occupy', 'rid' => 3))));
check('relay: occupy after countdown', true === ($out['acks'][0]['ok'] ?? null));
$scur = $out['cursor'];
$out = $relay($now + 6200)->handle(array('ticket' => $st, 'cursor' => $scur, 'cmds' => array(array('t' => 'submit', 'text' => 'x=1', 'rid' => 4))));
check('relay: correct submit', true === ($out['acks'][0]['correct'] ?? null));

$out = $relay($now + 7000)->handle(array('ticket' => $tt, 'cursor' => $tcur, 'cmds' => array(array('t' => 'stop', 'rid' => 5))));
check('relay: teacher stops, result saved once', true === ($out['acks'][0]['ok'] ?? null) && 1 === count($results) && 1 === $results[0][0]['teams'][0]['players'][0]['solved']);
check('relay: result title from session', '3반' === $results[0][1] && 9 === $results[0][0]['sessionId']);

$out = $relay($now + 310000)->handle(array('ticket' => $st, 'cursor' => $scur));
check('relay: expired ticket refused', 'bad-ticket' === $out['why']);
$soon = $ticket(array('uid' => 10, 'name' => '가', 'role' => 'learner', 'sid' => 9), $now + 200000);
$out = $relay($now + 400000)->handle(array('ticket' => $soon, 'cursor' => $scur));
check('relay: relay never renews tickets (WordPress re-checks rights)', true === $out['ok'] && ! isset($out['ticket']));

// presence: a learner who stops polling goes offline
$out = $relay($now + 420000)->handle(array('ticket' => $ticket(array('uid' => 1, 'name' => 'T', 'role' => 'teacher', 'sid' => 9), $now + 420000), 'cursor' => 0));
$detail = $out['events'][0];
$m = $detail['teams'][0]['members'][0] ?? array();
check('relay: silent learner shown offline', isset($m['online']) && false === $m['online']);

// rate limit: 40 commands per second per learner
$burst = $ticket(array('uid' => 11, 'name' => '나', 'role' => 'learner', 'sid' => 9), $now + 421000);
$out = $relay($now + 421000)->handle(array('ticket' => $burst, 'cursor' => 0));
$cmds = array();
for ($k = 0; $k < 40; $k++) {
    $cmds[] = array('t' => 'team', 'no' => 1 + $k % 2, 'rid' => 100 + $k);
}
$a1 = $relay($now + 421001)->handle(array('ticket' => $burst, 'cursor' => $out['cursor'], 'cmds' => $cmds))['acks'];
$a2 = $relay($now + 421002)->handle(array('ticket' => $burst, 'cursor' => $out['cursor'], 'cmds' => $cmds))['acks'];
$tooFast = count(array_filter(array_merge($a1, $a2), static fn (array $a): bool => 'too-fast' === ($a['why'] ?? '')));
check('relay: commands over 40/s are refused', $tooFast >= 38 && $tooFast <= 40);

// a kicked learner who keeps polling gets nothing more
$tk = $ticket(array('uid' => 1, 'name' => 'T', 'role' => 'teacher', 'sid' => 9), $now + 422000);
$relay($now + 422000)->handle(array('ticket' => $tk, 'cursor' => 0, 'cmds' => array(array('t' => 'kick', 'uid' => 11, 'rid' => 1))));
$out = $relay($now + 422100)->handle(array('ticket' => $burst, 'cursor' => 3));
check('relay: kicked learner cannot keep polling', false === $out['ok'] && 'kicked' === $out['why']);

$relay($now + 430000)->close_session(9);
$out = $relay($now + 430100)->handle(array('ticket' => $ticket(array('uid' => 10, 'name' => '가', 'role' => 'learner', 'sid' => 9), $now + 430100), 'cursor' => 5));
check('relay: closed session', false === $out['ok'] && 'ended' === $out['why']);

// free rooms
$fa = $ticket(array('uid' => 20, 'name' => 'A', 'role' => 'learner', 'sid' => 0, 'free' => true), $now);
$fb = $ticket(array('uid' => 21, 'name' => 'B', 'role' => 'learner', 'sid' => 0, 'free' => true), $now);
$out = $relay($now)->handle(array('ticket' => $fa, 'cursor' => 0, 'cmds' => array(array('t' => 'room_create', 'title' => '연습', 'settings' => array('limitMin' => 1), 'rid' => 1))));
check('free: room created and entered', true === $out['ok'] && 1 === preg_match('/^r[0-9a-f]{6}$/', $out['arena']) && true === ($out['acks'][0]['ok'] ?? null));
$room = $out['arena'];
$out = $relay($now + 100)->handle(array('ticket' => $fb, 'cursor' => 0));
check('free: lobby lists the room', 'lobby' === $out['arena'] && '연습' === ($out['rooms'][0]['title'] ?? ''));
$out = $relay($now + 200)->handle(array('ticket' => $fb, 'cursor' => 0, 'cmds' => array(array('t' => 'room_join', 'id' => $room, 'rid' => 2))));
check('free: second learner joins', $room === $out['arena']);
$bcur = $out['cursor'];
$out = $relay($now + 300)->handle(array('ticket' => $fb, 'arena' => $room, 'cursor' => $bcur, 'cmds' => array(array('t' => 'start', 'rid' => 3))));
check('free: only the host starts', 'not-host' === ($out['acks'][0]['why'] ?? ''));
$out = $relay($now + 400)->handle(array('ticket' => $fa, 'arena' => $room, 'cursor' => 1, 'cmds' => array(array('t' => 'start', 'rid' => 4))));
check('free: host starts', true === ($out['acks'][0]['ok'] ?? null));
$out = $relay($now + 30000)->handle(array('ticket' => $fa, 'cursor' => 0, 'cmds' => array(array('t' => 'room_create', 'rid' => 5))));
check('free: reload returns to own room', $room === $out['arena']);
$fc = $ticket(array('uid' => 22, 'name' => 'C', 'role' => 'learner', 'sid' => 0, 'free' => false), $now);
check('free: needs free claim', 'free-off' === $relay($now)->handle(array('ticket' => $fc, 'cursor' => 0))['why']);

@unlink($file);
@unlink($file . '.lock');
echo $fails ? "\n{$fails} failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);

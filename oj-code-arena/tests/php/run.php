<?php
/**
 * Plain-PHP checks without WordPress (php tests/php/run.php):
 * validators, settings, and ticket/signature interop with the Node server
 * (game-server/src/sign.js through tests/php/verify.js).
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('OJ_CODE_ARENA_PATH', dirname(__DIR__, 2) . '/');

function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }

require OJ_CODE_ARENA_PATH . 'src/Core/Autoloader.php';
\OjCodeArena\Core\Autoloader::register();

use OjCodeArena\Support\Game_Settings;
use OjCodeArena\Support\Problem_Validator;
use OjCodeArena\Support\Ticket;

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (! $ok) {
        $fails++;
    }
}
function throws(callable $fn): bool {
    try { $fn(); } catch (\InvalidArgumentException $e) { return true; }
    return false;
}

// validators
$p = Problem_Validator::clean(array('kind' => 'normal', 'code' => "  print('hi')  ", 'tags' => array('출력', '출력', ' 변수 ')));
check('normal trimmed', "print('hi')" === $p['code']);
check('tags deduped', array('출력', '변수') === $p['tags']);
check('hangul rejected', throws(fn () => Problem_Validator::clean(array('kind' => 'normal', 'code' => "print('안녕')"))));
check('multi-line normal rejected', throws(fn () => Problem_Validator::clean(array('kind' => 'normal', 'code' => "a\nb"))));
check('boss needs answer', throws(fn () => Problem_Validator::clean(array('kind' => 'boss', 'title' => 't', 'code' => 'print(1)'))));
check('two blanks rejected', throws(fn () => Problem_Validator::clean(array('kind' => 'boss', 'title' => 't', 'code' => '___ ___', 'answers' => array('1')))));
$b = Problem_Validator::clean(array('kind' => 'boss', 'code' => "x = 1\nprint(x)\n", 'answers' => array(' 1 ', '')));
check('boss defaults title, cleans answers', '보스 문제' === $b['title'] && array('1') === $b['answers'] && "x = 1\nprint(x)" === $b['code']);

// every bundled default problem passes the PHP validator too
$bank = json_decode((string) file_get_contents(OJ_CODE_ARENA_PATH . 'data/default-bank.json'), true);
$bad = 0;
foreach ($bank['normal'] as $n) { if (throws(fn () => Problem_Validator::clean(array('kind' => 'normal') + $n))) { $bad++; echo "  bad normal: {$n['code']}\n"; } }
foreach ($bank['boss'] as $n) { if (throws(fn () => Problem_Validator::clean(array('kind' => 'boss') + $n))) { $bad++; echo "  bad boss: {$n['title']}\n"; } }
check('default bank valid in PHP (' . count($bank['normal']) . '+' . count($bank['boss']) . ')', 0 === $bad);

// settings
$s = Game_Settings::clean(array('teams' => 99, 'limitMin' => '0', 'blocks' => 50, 'attacks' => 1, 'tags' => array('반복문', '<b>x</b>')));
check('settings clamp', 8 === $s['teams'] && 1 === $s['limitMin'] && 48 === $s['blocks'] && true === $s['attacks'] && array('반복문', 'x') === $s['tags']);
check('settings defaults', 4 === Game_Settings::defaults()['teams'] && true === Game_Settings::defaults()['penalty']);

// ticket + signature interop with Node
$secret = str_repeat('k', 48);
$ticket = Ticket::issue(array('uid' => 42, 'name' => '한빛', 'role' => 'learner', 'sid' => 7, 'free' => true), $secret);
$body = '{"a":"가"}';
$sig = Ticket::sign('POST', '/server/results', $body, $secret);
check('php verifies php signature', Ticket::verify('POST', '/server/results', $sig['time'], $sig['signature'], $body, $secret));
check('php rejects changed body', ! Ticket::verify('POST', '/server/results', $sig['time'], $sig['signature'], $body . ' ', $secret));
check('php rejects other route', ! Ticket::verify('GET', '/server/bank', $sig['time'], $sig['signature'], $body, $secret));

$cmd = 'node ' . escapeshellarg(__DIR__ . '/verify.js') . ' ' . escapeshellarg($secret) . ' ' . escapeshellarg($ticket) . ' ' . escapeshellarg($sig['time']) . ' ' . escapeshellarg($sig['signature']) . ' ' . escapeshellarg(base64_encode($body));
$out = json_decode((string) shell_exec($cmd), true);
check('node reads php ticket', is_array($out) && 42 === ($out['ticket']['uid'] ?? 0) && '한빛' === ($out['ticket']['name'] ?? '') && 7 === ($out['ticket']['sid'] ?? 0));
check('node verifies php signature', is_array($out) && true === ($out['request'] ?? false));
check('php verifies node signature', is_array($out) && Ticket::verify('POST', '/server/results', (string) $out['nodeSig']['time'], (string) $out['nodeSig']['signature'], $body, $secret));

// .env generation
use OjCodeArena\Support\Server_Env;
$rest = 'https://oj.test/wp-json/oj-code-arena/v1';
$e = Server_Env::build($secret, 'wss://oj.test/arena', '', $rest, 'https://oj.test/');
check('env: behind proxy', '3210' === $e['PORT'] && '127.0.0.1' === $e['HOST'] && '/arena' === $e['WS_PATH'] && 'https://oj.test' === $e['ALLOWED_ORIGINS']);
$e = Server_Env::build($secret, 'ws://10.0.0.5:3300/game', '', $rest, 'http://oj.test:8080');
check('env: direct port', '3300' === $e['PORT'] && '0.0.0.0' === $e['HOST'] && '/game' === $e['WS_PATH'] && 'http://oj.test:8080' === $e['ALLOWED_ORIGINS']);
$e = Server_Env::build($secret, 'wss://oj.test/arena', 'http://127.0.0.1:3210/arena', $rest, 'https://oj.test');
check('env: internal loopback', '3210' === $e['PORT'] && '127.0.0.1' === $e['HOST']);
$e = Server_Env::build($secret, '', '', $rest, 'https://oj.test');
check('env: no address yet', '3210' === $e['PORT'] && '/arena' === $e['WS_PATH']);
$code = Server_Env::setup_code(Server_Env::build($secret, 'wss://oj.test/arena', '', $rest, 'https://oj.test'));
$decoded = json_decode((string) shell_exec('node -e ' . escapeshellarg('process.stdout.write(JSON.stringify(require(process.argv[1]).decode(process.argv[2])))') . ' ' . escapeshellarg(OJ_CODE_ARENA_PATH . 'game-server/src/setup.js') . ' ' . escapeshellarg($code)), true);
check('node setup.js decodes php setup code', is_array($decoded) && $secret === ($decoded['ARENA_SECRET'] ?? '') && $rest === ($decoded['WP_API'] ?? ''));
check('env text has every key', 6 === substr_count(Server_Env::text($e), '='));

$e = Server_Env::build($secret, 'wss://oj.test/arena', '', $rest, 'https://oj.test');
$install = Server_Env::install_command('wss://oj.test/arena', '', 'https://oj.test', $e, '/srv/WordPress folder/oj-code-arena/');
check('installer quotes actual plugin directory without exposing setup secrets', "sudo bash '/srv/WordPress folder/oj-code-arena/game-server/install.sh'" === $install && ! str_contains($install, $secret));
preg_match('/ojhub1\.([A-Za-z0-9_-]+)/', Server_Env::install_code($e), $matches);
$shared_code = json_decode((string) base64_decode(strtr($matches[1] ?? '', '-_', '+/')), true);
check('installer code names only its own addon', 'oj-code-arena' === ($shared_code['app'] ?? '') && $secret === ($shared_code['env']['ARENA_SECRET'] ?? ''));
check('ticket has arena namespace', 'oj-code-arena' === ($out['ticket']['app'] ?? '') && 'arena' === ($out['ticket']['channel'] ?? ''));
check('installer waits for saved public URL', null === Server_Env::install_command('', '', 'https://oj.test', $e, '/plugin'));
check('installer refuses a different public host', null === Server_Env::install_command('wss://other.test/arena', '', 'https://oj.test', $e, '/plugin'));
check('installer refuses insecure WS on HTTPS site', null === Server_Env::install_command('ws://oj.test/arena', '', 'https://oj.test', $e, '/plugin'));
check('installer refuses remote internal address', null === Server_Env::install_command('wss://oj.test/arena', 'http://10.0.0.5:3200/arena', 'https://oj.test', $e, '/plugin'));
check('installer accepts loopback internal address', null !== Server_Env::install_command('wss://oj.test/arena', 'http://127.0.0.1:3210/arena', 'https://oj.test', $e, '/plugin'));
check('installer requires migrating old port', null === Server_Env::install_command('wss://oj.test/arena', 'http://127.0.0.1:3200/arena', 'https://oj.test', $e, '/plugin'));
check('installer rejects mismatched internal route', null === Server_Env::install_command('wss://oj.test/arena', 'http://127.0.0.1:3200/other', 'https://oj.test', $e, '/plugin'));

echo $fails ? "\n{$fails} failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);

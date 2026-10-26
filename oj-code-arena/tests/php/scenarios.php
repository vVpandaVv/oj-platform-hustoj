<?php
/**
 * Shared rule scenarios (tests/scenarios/rules.json) on the built-in PHP
 * engine — the same file game-server/test/scenarios.test.js runs on Node.
 *   php tests/php/scenarios.php
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('OJ_CODE_ARENA_PATH', dirname(__DIR__, 2) . '/');
require OJ_CODE_ARENA_PATH . 'src/Core/Autoloader.php';
\OjCodeArena\Core\Autoloader::register();

use OjCodeArena\Engine\Arena;
use OjCodeArena\Engine\Team_Match;

$spec = json_decode((string) file_get_contents(OJ_CODE_ARENA_PATH . 'tests/scenarios/rules.json'), true);
$d = $spec['defaults'];
$fails = 0;

foreach ($spec['scenarios'] as $sc) {
    $now = 1000000;
    $arena = new Arena(Arena::fresh(array('id' => 'c1', 'mode' => 'class', 'settings' => array()), $now), array(), $now);
    $m = Team_Match::create($arena, array(
        'team' => 1, 'codes' => array('x'), 'limitMs' => $d['limitMs'], 'occMs' => $d['occMs'], 'bossWaitMs' => $d['bossWaitMs'],
        'bossLimitMs' => $d['bossLimitMs'], 'penalty' => ! empty($d['penalty']), 'botPace' => 'normal', 'startAt' => $now, 'idle' => false,
    ), 1, 24);
    for ($i = 0; $i < 24; $i++) {
        $m->s['cells'][$i] = array('code' => $sc['cells'][(string) $i] ?? "v{$i} = {$i}", 'solved' => false, 'by' => null, 'crown' => null);
    }
    foreach ($sc['solved'] ?? array() as $i) {
        $m->s['cells'][$i]['solved'] = true;
    }
    if (isset($sc['solvedAllBut'])) {
        foreach (array_keys($m->s['cells']) as $i) {
            if (! in_array($i, $sc['solvedAllBut'], true)) {
                $m->s['cells'][$i]['solved'] = true;
            }
        }
    }
    foreach ($sc['players'] ?? array() as $p) {
        $m->join(array('id' => $p['id'], 'uid' => (int) $p['id'], 'name' => 'p' . $p['id'], 'avatar' => 0, 'color' => 0));
        $m->s['players'][$p['id']]['r'] = $p['r'];
        $m->s['players'][$p['id']]['c'] = $p['c'];
    }
    if (isset($sc['boss'])) {
        $m->s['boss'] = array('i' => $sc['boss']['i'], 'q' => array('title' => 'b', 'code' => 'print(1)', 'answers' => array('1')), 'phase' => $sc['boss']['phase'], 'until' => $now + $d['bossWaitMs'], 'by' => null);
    }

    $errors = array();
    foreach ($sc['steps'] as $k => $step) {
        $id = (string) ($step['id'] ?? '');
        $res = array();
        switch ($step['do']) {
            case 'move': $m->move($id, $step['cmd'] + array('seq' => 1)); break;
            case 'occupy': $res = $m->occupy($id); break;
            case 'submit': $res = $m->submit($id, $step['text']); break;
            case 'release': $m->release($id, 'esc'); break;
            case 'advance': $now += $step['ms']; $arena->set_now($now); $m->tick(); break;
            case 'pause': $m->pause((bool) $step['on']); break;
            case 'spawnBoss':
                $m->s['boss'] = array('i' => $step['i'], 'q' => $step['q'] + array('blank' => ! empty($step['q']['blank'])), 'phase' => 'wait', 'until' => $now + $d['bossWaitMs'], 'by' => null);
                break;
            case 'grab': $res = $m->grab($id, $step['key']); break;
            case 'bossSubmit': $res = $m->boss_submit($id, $step['text']); break;
            case 'giveUp': $m->give_up($id); break;
            case 'give': $m->give($id, $step['item']); break;
            case 'item': $res = $m->use_item($id, (int) $step['slot']); break;
            default: $errors[] = 'unknown step ' . $step['do'];
        }
        $where = '#' . ($k + 1) . ' ' . $step['do'];
        foreach ($step['expect'] ?? array() as $key => $want) {
            $ok = true;
            switch ($key) {
                case 'ok':
                case 'why':
                case 'correct':
                    $ok = ($res[$key] ?? null) === $want;
                    break;
                case 'pos':
                    foreach ($want as $pid => $rc) {
                        $p = $m->s['players'][$pid];
                        $ok = $ok && array($p['r'], $p['c']) === $rc;
                    }
                    break;
                case 'solvedCount': $ok = $m->solved_count() === $want; break;
                case 'solved': foreach ($want as $i) { $ok = $ok && $m->s['cells'][$i]['solved']; } break;
                case 'unsolved': foreach ($want as $i) { $ok = $ok && ! $m->s['cells'][$i]['solved']; } break;
                case 'occ':
                    foreach ($want as $i => $pid) {
                        $ok = $ok && (($m->s['occ'][(int) $i]['id'] ?? null) === $pid);
                    }
                    break;
                case 'boss': $ok = ($m->s['boss']['phase'] ?? null) === $want; break;
                case 'deckSize': foreach ($want as $pid => $n) { $ok = $ok && count($m->s['players'][$pid]['deck']) === $n; } break;
                case 'ended': $ok = $m->s['ended'] === $want; break;
                default: $ok = false;
            }
            if (! $ok) {
                $errors[] = "{$where}: {$key} expected " . json_encode($want) . ' got ' . json_encode('ok' === $key || 'why' === $key || 'correct' === $key ? ($res[$key] ?? null) : null);
            }
        }
    }
    echo ($errors ? 'FAIL ' : 'ok   ') . 'scenario: ' . $sc['name'] . "\n";
    foreach ($errors as $e) {
        echo '       ' . $e . "\n";
    }
    $fails += $errors ? 1 : 0;
}

echo $fails ? "\n{$fails} failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);

<?php
/**
 * Edu Games link (php tests/php/integration.php). Uses Edu Games' real
 * interface and registry from the sibling plugin when present, so a change
 * on their side shows up here.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('OJ_CODE_ARENA_PATH', dirname(__DIR__, 2) . '/');
function __($s, $d = '') { return $s; }
function home_url($p = '') { return 'https://oj.test' . $p; }
function add_action(...$a) { return true; }

require OJ_CODE_ARENA_PATH . 'src/Core/Autoloader.php';
\OjCodeArena\Core\Autoloader::register();

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fails += $ok ? 0 : 1;
}

use OjCodeArena\Integration\Edu_Games;

// Without Edu Games nothing happens and nothing breaks.
Edu_Games::add_card(new stdClass());
check('no Edu Games: add_card is a no-op', true);

$edu = dirname(OJ_CODE_ARENA_PATH) . '/edu-games/src/Games/Contracts/Game_Module.php';
if (is_file($edu)) {
    require $edu;
} else {
    eval('namespace EduGames\Games\Contracts; interface Game_Module { public function id(): string; public function definition(): array; }');
}
$registry = new class {
    public array $modules = array();
    public function register(\EduGames\Games\Contracts\Game_Module $m): void { $this->modules[$m->id()] = $m; }
};
Edu_Games::add_card($registry);
$card = $registry->modules['code-arena'] ?? null;
check('card registered in Edu Games registry', null !== $card);
$def = $card ? $card->definition() : array();
check('card is playable, coding-skills, links to the arena', 'playable' === ($def['status'] ?? '') && 'coding-skills' === ($def['category'] ?? '') && 'https://oj.test/oj-addons/oj-code-arena/play' === ($def['url'] ?? ''));
check('card has no block/shortcode (Edu Games access guard ignores it)', '' === $def['block'] && '' === $def['shortcode']);
check('card has empty scoring rules (Edu Games score API rejects it)', array() === ($def['rules']['scoring'] ?? null));
check('hub address is the one Edu Games builds', '/oj-addons/edu-games/games/code-arena' === Edu_Games::card_path());

echo $fails ? "\n{$fails} failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);

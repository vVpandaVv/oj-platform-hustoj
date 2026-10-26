<?php
/**
 * Dev-harness bridge to the built-in engine (not shipped):
 *   echo '{"op":"handle","req":{...}}' | php tools/relay-cli.php
 * Uses File_Store at $OCA_STORE and appends finished rounds to $OCA_RESULTS.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('OJ_CODE_ARENA_PATH', dirname(__DIR__) . '/');
require OJ_CODE_ARENA_PATH . 'src/Core/Autoloader.php';
\OjCodeArena\Core\Autoloader::register();

use OjCodeArena\Engine\File_Store;
use OjCodeArena\Engine\Relay;

$in = json_decode((string) stream_get_contents(STDIN), true) ?: array();
$bank = json_decode((string) file_get_contents(OJ_CODE_ARENA_PATH . 'data/default-bank.json'), true);
$results = (string) getenv('OCA_RESULTS');
$relay = new Relay(
    new File_Store((string) getenv('OCA_STORE')),
    (string) getenv('OCA_SECRET'),
    static fn (): array => $bank,
    static function (array $r, string $title) use ($results): void {
        file_put_contents($results, json_encode(array('title' => $title) + $r, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
);

switch ($in['op'] ?? '') {
    case 'upsert':
        $relay->upsert_session($in['session']);
        echo '{"ok":true}';
        break;
    case 'close':
        $relay->close_session((int) $in['id']);
        echo '{"ok":true}';
        break;
    default:
        echo json_encode($relay->handle((array) ($in['req'] ?? array())), JSON_UNESCAPED_UNICODE);
}

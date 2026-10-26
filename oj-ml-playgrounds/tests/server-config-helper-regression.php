<?php
declare(strict_types=1);
require dirname(__DIR__) . '/resources/server/wp-config-helper.php';
function serverAssert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$source = "<?php\nrequire_once ABSPATH . 'wp-settings.php';\n";
$result = ojPrepareConfig($source, 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR', '/home/judge/oj-ai-private');
serverAssert(str_contains($result, "define('OJ_ML_PLAYGROUNDS_PRIVATE_DIR', '/home/judge/oj-ai-private');"), 'Storage definition missing');
serverAssert(ojPrepareConfig($result, 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR', '/home/judge/oj-ai-private') === $result, 'Rerun must preserve config');
foreach ([
    "<?php define('OJ_ML_PLAYGROUNDS_PRIVATE_DIR', '/different');",
    "<?php define('OJ_ML_PLAYGROUNDS_PRIVATE_DIR', getenv('STORAGE'));",
    "<?php // OJ_ML_PLAYGROUNDS_PRIVATE_DIR is maintained manually\n",
    "<?php declare(strict_types=1);",
] as $conflict) {
    $rejected = false;
    try {
        ojPrepareConfig($conflict, 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR', '/home/judge/oj-ai-private');
    } catch (RuntimeException) {
        $rejected = true;
    }
    serverAssert($rejected, 'Unsafe config must be refused');
}
// Quote/backslash input remains a PHP string and never becomes code.
$quoted = ojPrepareConfig($source, 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR', "/private/a'b\\c");
token_get_all($quoted, TOKEN_PARSE);
serverAssert(ojPrepareConfig($quoted, 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR', "/private/a'b\\c") === $quoted, 'Escaped path rerun must preserve config');

$fixture = dirname(__DIR__, 2) . '/_notes/tmp/server-config-' . bin2hex(random_bytes(4));
mkdir($fixture, 0700, true);
try {
    file_put_contents($fixture . '/wp-settings.php', '<?php');
    file_put_contents($fixture . '/wp-config.php', $source);
    ob_start();
    ojPrepareMain(['helper', '--check', $fixture, 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR', '/private/ai']);
    $checkOutput = (string) ob_get_clean();
    serverAssert(str_contains($checkOutput, 'Config check passed'), 'Helper preflight failed');
    serverAssert(file_get_contents($fixture . '/wp-config.php') === $source, 'Preflight must not write config');
    file_put_contents($fixture . '/wp-config.php', $result);
    $rejected = false;
    try {
        ojPrepareMain(['helper', '--check', $fixture, 'OJ_ML_PLAYGROUNDS_PRIVATE_DIR', '/private/different']);
    } catch (RuntimeException) {
        $rejected = true;
    }
    serverAssert($rejected && file_get_contents($fixture . '/wp-config.php') === $result, 'Conflicting preflight must leave config intact');
} finally {
    unlink($fixture . '/wp-settings.php');
    unlink($fixture . '/wp-config.php');
    rmdir($fixture);
}

fwrite(STDOUT, "Server config helper safety passed.\n");

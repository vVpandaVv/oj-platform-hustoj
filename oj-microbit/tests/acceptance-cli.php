<?php
declare(strict_types=1);
$checks = 0;
function acceptanceCliCheck(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
$plugin = dirname(__DIR__); $base = dirname($plugin) . '/_notes/tmp'; if (!is_dir($base)) { mkdir($base, 0777, true); }
$temporary = $base . '/microbit-acceptance-cli-' . bin2hex(random_bytes(8)); mkdir($temporary); mkdir($temporary . '/wp-content');
$dbEntry = $plugin . '/scripts/acceptance/wp-db-check.php'; $nonceEntry = $plugin . '/scripts/acceptance/wp-nonce.php';
$driver = static function (string $entry, int $uid = 1001): string {
    return '<?php namespace OjMicrobit\\Acceptance {'
        . 'function posix_geteuid(): int { return ' . $uid . '; }'
        . 'function lstat($p) { $s=\\lstat($p); if($s!==false){$s["uid"]=1001;$s["mode"]=(($s["mode"]&0170000)===0040000?0040700:(str_ends_with($p,"unsafe.json")?0100644:0100600));}return $s;}'
        . 'function fstat($f) {$s=\\fstat($f);if($s!==false){$s["uid"]=1001;$s["mode"]=0100600;}return $s;}'
        . '} namespace {if(!function_exists("posix_geteuid")){function posix_geteuid(): int{return 1001;}} require ' . var_export($entry, true) . ';}';
};
file_put_contents($temporary . '/db-driver.php', $driver($dbEntry)); file_put_contents($temporary . '/nonce-driver.php', $driver($nonceEntry)); file_put_contents($temporary . '/root-driver.php', $driver($dbEntry, 0));
$moduleProcess = proc_open([PHP_BINARY, '-m'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $modulePipes);
fclose($modulePipes[0]); $modules = stream_get_contents($modulePipes[1]); fclose($modulePipes[1]); stream_get_contents($modulePipes[2]); fclose($modulePipes[2]); proc_close($moduleProcess);
$phpOptions = preg_match('/^pdo_sqlite\s*$/m', $modules) ? [] : ['-d', 'extension=pdo_sqlite'];
$run = static function (string $file, array $args = [], string $input = '') use ($temporary, $phpOptions): array {
    $process = proc_open([PHP_BINARY, ...$phpOptions, $temporary . '/' . $file, ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Acceptance fixture process failed to start'); }
    fwrite($pipes[0], $input); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), json_decode($out, true, 64, JSON_THROW_ON_ERROR), $out . $err];
};
try {
    $loader = '<?php require ' . var_export(__DIR__ . '/bootstrap.php', true) . '; require ' . var_export(__DIR__ . '/lib/AcceptanceWpdb.php', true)
        . '; $wpdb=new AcceptanceWpdb();$testOptions["oj_microbit_schema_version"]="5";$testOptions["active_plugins"]=["oj-microbit/oj-microbit.php"];$testOptions["oj_microbit_settings"]=["runnerToken"=>"PRIVATE_TOKEN","retentionDays"=>365];$testOptions["cron"]=[time()+60=>["oj_microbit_cleanup"=>[]]];'
        . 'define("WP_PLUGIN_DIR",' . var_export(dirname($plugin), true) . ');$wp_version="6.6.2";function is_multisite(){return false;}echo "PRIVATE_BOOTSTRAP_OUTPUT";';
    file_put_contents($temporary . '/wp-load.php', $loader);
    [$code, $report, $text] = $run('db-driver.php', ['--wp-root', $temporary, '--exercise-id', '6', '--assignment-id', '101', '--group-id', '7', '--group-id', '8', '--class-id', '7', '--iterations', '1']);
    acceptanceCliCheck($code === 0 && $report['ok'] && $report['readOnly'] && $report['verdict'] === 'manual_approval_pending', 'Actual DB CLI boots fake SHORTINIT WordPress and executes real SQLite metadata/list SQL: ' . json_encode($report));
    acceptanceCliCheck(!preg_match('/PRIVATE_|PRIVATE_PERSON|PRIVATE_STUDENT/', $text), 'DB CLI suppresses incidental WordPress output and every private fixture field');
    acceptanceCliCheck($report['probeScope']['assignmentRepresentativeAvailable'] && in_array('lesson-results', array_column($report['queryCases'], 'id'), true), 'Explicit assignment CLI ID executes bounded lesson history and latest summary SQL');
    acceptanceCliCheck($report['probeScope']['groupIdCount'] === 2 && $report['probeScope']['classIdCount'] === 1 && in_array('mixed-context-exercises', array_column($report['queryCases'], 'id'), true), 'Repeated group CLI IDs stay separate from legacy lesson IDs and execute mixed-context SQL');
    [$code, $report] = $run('root-driver.php', ['--wp-root', $temporary]); acceptanceCliCheck($code === 1 && $report['error'] === 'root_execution_refused', 'Root is refused before WordPress bootstrap');
    [$code, $report, $text] = $run('db-driver.php', ['--token', 'PRIVATE_ARG_SECRET']); acceptanceCliCheck($code === 1 && $report['error'] === 'invalid_arguments' && !str_contains($text, 'PRIVATE_ARG_SECRET'), 'Secrets are never accepted or echoed as CLI arguments');
    [$code, $report] = $run('db-driver.php', ['--wp-root', $temporary, '--stdin'], '{"iterations":11}'); acceptanceCliCheck($code === 1 && $report['error'] === 'invalid_probe_options', 'The actual CLI enforces bounded timing iterations');
    [$code, $report] = $run('db-driver.php', ['--wp-root', $temporary, '--stdin'], '{"groupIds":[7,7]}'); acceptanceCliCheck($code === 1 && $report['error'] === 'invalid_probe_options', 'The actual CLI rejects duplicate group IDs before database probing');
    [$code, $report, $text] = $run('db-driver.php', ['--wp-root', $temporary, '--stdin'], '{"password":"PRIVATE_STDIN_SECRET"}'); acceptanceCliCheck($code === 1 && $report['error'] === 'invalid_config' && !str_contains($text, 'PRIVATE_STDIN_SECRET'), 'DB credentials and unknown stdin keys are rejected safely');
    file_put_contents($temporary . '/wp-load.php', '<?php echo "PRIVATE_BOOTSTRAP_OUTPUT";exit(0);');
    [$code, $report, $text] = $run('db-driver.php', ['--wp-root', $temporary]); acceptanceCliCheck($code === 1 && $report['error'] === 'wordpress_bootstrap_aborted' && !str_contains($text, 'PRIVATE_BOOTSTRAP_OUTPUT'), 'An early WordPress exit zero cannot be mistaken for completed acceptance');

    $config = ['testServer' => true, 'baseUrl' => 'https://trial.test/site', 'accounts' => [], 'testFixtureSentinel' => 'PRIVATE_PRESERVED_SETTING'];
    foreach (['teacherA' => 'teach-a', 'teacherB' => 'teach-b', 'studentA' => 'student-a', 'studentB' => 'student-b'] as $label => $username) { $config['accounts'][$label] = ['username' => $username, 'applicationPassword' => 'PRIVATE_APPLICATION_PASSWORD']; }
    file_put_contents($temporary . '/config.json', json_encode($config)); file_put_contents($temporary . '/unsafe.json', json_encode($config));
    $nonceRoot = $temporary . '/site'; mkdir($nonceRoot); mkdir($nonceRoot . '/wp-content');
    file_put_contents($nonceRoot . '/wp-content/oj-microbit-acceptance.json', json_encode(['testServer' => true, 'marker' => 'oj-microbit-acceptance-v1', 'baseUrl' => $config['baseUrl']]));
    $nonceLoader = '<?php require ' . var_export(__DIR__ . '/acceptance-nonce.php', true) . ';function is_multisite(){return false;}file_put_contents(__DIR__."/booted","yes");echo "PRIVATE_BOOTSTRAP_OUTPUT";';
    file_put_contents($nonceRoot . '/wp-load.php', $nonceLoader);
    $args = ['--wp-root', $nonceRoot, '--config', $temporary . '/config.json', '--output', $temporary . '/prepared.json'];
    [$code, $report] = $run('nonce-driver.php', $args); acceptanceCliCheck($code === 1 && $report['error'] === 'full_wordpress_bootstrap_not_confirmed' && !is_file($nonceRoot . '/booted'), 'Full WordPress boot requires a separate explicit flag');
    [$code, $report, $text] = $run('nonce-driver.php', [...$args, '--allow-wordpress-bootstrap']);
    acceptanceCliCheck($code === 0 && $report['accountsPrepared'] === 4 && $report['bootstrapHooksMayWrite'] && !$report['serverAcceptanceApproved'], 'Actual nonce CLI writes only a newly prepared private config and keeps server approval pending');
    acceptanceCliCheck(!preg_match('/PRIVATE_|teach-a|student-a|[a-f0-9]{10}/', $text), 'Nonce CLI stdout/stderr contains no account names, passwords, nonces or WordPress output');
    $prepared = json_decode(file_get_contents($temporary . '/prepared.json'), true, 16, JSON_THROW_ON_ERROR);
    acceptanceCliCheck($prepared['accounts']['teacherA']['applicationPassword'] === $config['accounts']['teacherA']['applicationPassword'] && isset($prepared['accounts']['studentB']['restNonce']) && $prepared['testFixtureSentinel'] === 'PRIVATE_PRESERVED_SETTING', 'The private file preserves account credentials and all original settings despite generic WordPress global variables');
    $before = file_get_contents($temporary . '/prepared.json');
    [$code, $report] = $run('nonce-driver.php', [...$args, '--allow-wordpress-bootstrap']); acceptanceCliCheck($code === 1 && $report['error'] === 'output_already_exists' && file_get_contents($temporary . '/prepared.json') === $before, 'An existing nonce config is never overwritten');
    $unsafeArgs = ['--wp-root', $nonceRoot, '--config', $temporary . '/unsafe.json', '--output', $temporary . '/unsafe-output.json', '--allow-wordpress-bootstrap'];
    [$code, $report] = $run('nonce-driver.php', $unsafeArgs); acceptanceCliCheck($code === 1 && $report['error'] === 'unsafe_private_config', 'Group/world-readable account config is rejected at the actual CLI boundary');
    unlink($nonceRoot . '/booted');
    [$code, $report] = $run('nonce-driver.php', ['--wp-root', $nonceRoot, '--config', $temporary . '/config.json', '--output', $nonceRoot . '/public-output.json', '--allow-wordpress-bootstrap']);
    acceptanceCliCheck($code === 1 && $report['error'] === 'unsafe_output_directory' && !is_file($nonceRoot . '/booted'), 'Credential output inside the web root is refused even if its POSIX mode would be 0600');
    file_put_contents($nonceRoot . '/public-config.json', json_encode($config));
    [$code, $report] = $run('nonce-driver.php', ['--wp-root', $nonceRoot, '--config', $nonceRoot . '/public-config.json', '--output', $temporary . '/other-output.json', '--allow-wordpress-bootstrap']);
    acceptanceCliCheck($code === 1 && $report['error'] === 'unsafe_private_config' && !is_file($nonceRoot . '/booted'), 'Credential input inside the web root is refused before bootstrap');
    file_put_contents($nonceRoot . '/wp-content/oj-microbit-acceptance.json', '{"testServer":true,"marker":"oj-microbit-acceptance-v1","baseUrl":"https://different.test"}');
    [$code, $report] = $run('nonce-driver.php', ['--wp-root', $nonceRoot, '--config', $temporary . '/config.json', '--output', $temporary . '/other-output.json', '--allow-wordpress-bootstrap']);
    acceptanceCliCheck($code === 1 && $report['error'] === 'test_server_marker_mismatch' && !is_file($nonceRoot . '/booted'), 'A marker mismatch blocks bootstrap before any hooks run');
} finally {
    $actual = realpath($temporary); $allowed = realpath($base);
    if ($actual === false || dirname($actual) !== $allowed || is_link($temporary) || !str_starts_with(basename($actual), 'microbit-acceptance-cli-')) { throw new RuntimeException('Unsafe fixture cleanup path'); }
    foreach (['db-driver.php', 'nonce-driver.php', 'root-driver.php', 'wp-load.php', 'config.json', 'unsafe.json', 'prepared.json', 'unsafe-output.json', 'other-output.json', 'booted', 'wp-content/oj-microbit-acceptance.json', 'site/wp-load.php', 'site/booted', 'site/public-config.json', 'site/public-output.json', 'site/wp-content/oj-microbit-acceptance.json'] as $name) { $file = $actual . '/' . $name; if (is_file($file) || is_link($file)) { unlink($file); } }
    rmdir($actual . '/site/wp-content'); rmdir($actual . '/site'); rmdir($actual . '/wp-content'); rmdir($actual);
}
echo '[acceptance-cli] ' . $checks . " checks passed (real CLI + SQLite, fake WordPress/POSIX file metadata; native Linux bootstrap remains unverified)\n";

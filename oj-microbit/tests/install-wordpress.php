<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/runner/install-tools/WordPressConfigurator.php';

use OjMicrobit\Installer\WordPressConfigurator;

$checks = 0; $writes = 0; $testWriteMode = ''; $options = []; $files = [];
function verifyInstall(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function installError(callable $operation, string $expected): void {
    try { $operation(); } catch (RuntimeException $error) { verifyInstall($error->getMessage() === $expected, 'Expected bounded error: ' . $expected); return; }
    throw new RuntimeException('Expected error was not raised: ' . $expected);
}
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['options'][$key] ?? $default; }
function update_option(string $key, mixed $value, mixed $autoload = null): bool {
    $GLOBALS['writes']++; verifyInstall($autoload === false, 'Installer option remains non-autoloaded');
    $mode = $GLOBALS['testWriteMode']; $GLOBALS['testWriteMode'] = '';
    if ($mode === 'fail') { return false; }
    if ($mode === 'interfere') { $GLOBALS['options'][$key] = ['retentionDays' => 999, 'foreign' => true]; return true; }
    $GLOBALS['options'][$key] = $value; return $mode !== 'partial';
}
function delete_option(string $key): bool { $GLOBALS['writes']++; unset($GLOBALS['options'][$key]); return true; }
function resetInstallOptions(?array $settings = null): void {
    $GLOBALS['options'] = ['active_plugins' => ['oj-microbit/oj-microbit.php']];
    if ($settings !== null) { $GLOBALS['options']['oj_microbit_settings'] = $settings; }
    $GLOBALS['files'] = []; $GLOBALS['writes'] = 0; $GLOBALS['testWriteMode'] = '';
}
function installTool(array $overrides = []): WordPressConfigurator {
    return new WordPressConfigurator($overrides, static function (string $path, string $content): void { $GLOBALS['files'][$path] = $content; }, static function (?array $expected, ?array $replacement): bool {
        if (get_option('oj_microbit_settings', null) !== $expected) { return false; }
        if ($replacement === null) { return delete_option('oj_microbit_settings'); }
        $mode = $GLOBALS['testWriteMode'];
        $changed = update_option('oj_microbit_settings', $replacement, false);
        if (!$changed && $mode !== '') { throw new RuntimeException('settings_write_failed'); }
        return $changed;
    });
}
function preparation(?string $token = null): array {
    $request = ['mode' => 'prepare', 'url' => 'http://127.0.0.1:3037', 'secretFile' => '/private/key', 'backupFile' => '/private/backup'];
    if ($token !== null) { $request['token'] = $token; } return $request;
}
function configuration(array $prepared, string $token): array {
    return ['mode' => 'configure', 'url' => 'http://127.0.0.1:3037', 'token' => $token, 'expectedHash' => $prepared['expectedHash']];
}

resetInstallOptions(); $tool = installTool();
$inspection = $tool->execute(['mode' => 'inspect']);
verifyInstall($inspection['active'] && !$inspection['configured'] && $writes === 0 && $files === [], 'Inspection does not write WordPress or handoff files');
$prepared = $tool->execute(preparation()); $generated = trim($files['/private/key']);
verifyInstall(strlen($generated) === 64 && ctype_xdigit($generated), 'A missing key is generated using 32 random bytes');
verifyInstall(json_decode($files['/private/backup'], true)['exists'] === false && $writes === 0, 'Preparation backs up absence without changing settings');
verifyInstall(!str_contains(json_encode($prepared), $generated), 'Preparation response never includes its secret');
$configured = $tool->execute(configuration($prepared, $generated));
verifyInstall($configured['changed'] && $configured['configured'] && $options['oj_microbit_settings']['runnerToken'] === $generated, 'Configure creates the intended loopback connection');
verifyInstall(!str_contains(json_encode($configured), $generated), 'Configure response never contains a key');
$again = $tool->execute(preparation()); $before = $writes;
verifyInstall(!$tool->execute(configuration($again, $generated))['changed'] && $writes === $before, 'Re-running an identical connection performs no option write');
verifyInstall(trim($files['/private/key']) === $generated, 'Preparation reuses the existing WordPress key');
$beforeFiles = $files; $before = $writes;
verifyInstall($tool->execute(['mode' => 'check', 'url' => 'http://127.0.0.1:3037', 'token' => $generated])['configured'] && $files === $beforeFiles && $writes === $before, 'Check mode is read-only');
installError(fn () => $tool->execute(['mode' => 'check', 'url' => 'http://127.0.0.1:3037', 'token' => str_repeat('x', 40)]), 'connection_settings_mismatch');

$stored = ['runnerUrl' => 'https://old.invalid/runner', 'runnerToken' => str_repeat('w', 40), 'retentionDays' => 1095, 'deleteOnUninstall' => true, 'custom' => ['keep' => '한글']];
resetInstallOptions($stored); $tool = installTool(); $envKey = str_repeat('e', 48);
$prepared = $tool->execute(preparation($envKey));
verifyInstall(trim($files['/private/key']) === $envKey && json_decode($files['/private/backup'], true)['settings'] === $stored, 'Existing service key takes precedence and old settings are backed up exactly');
$tool->execute(configuration($prepared, $envKey)); $changed = $options['oj_microbit_settings'];
verifyInstall($changed['retentionDays'] === 1095 && $changed['deleteOnUninstall'] && $changed['custom'] === $stored['custom'], 'Connecting preserves retention, deletion preference and unknown fields');

resetInstallOptions($stored); $prepared = installTool()->execute(preparation());
$options['oj_microbit_settings']['retentionDays'] = 3; $before = $writes;
installError(fn () => installTool()->execute(configuration($prepared, str_repeat('w', 40))), 'settings_changed');
verifyInstall($options['oj_microbit_settings']['retentionDays'] === 3 && $writes === $before, 'An administrator change during installation is not overwritten');

resetInstallOptions($stored); $prepared = installTool()->execute(preparation()); $testWriteMode = 'fail';
installError(fn () => installTool()->execute(configuration($prepared, str_repeat('w', 40))), 'settings_write_failed');
verifyInstall($options['oj_microbit_settings'] === $stored, 'A failed write preserves the original settings');
resetInstallOptions($stored); $prepared = installTool()->execute(preparation()); $testWriteMode = 'partial';
installError(fn () => installTool()->execute(configuration($prepared, str_repeat('w', 40))), 'settings_write_failed');
verifyInstall($options['oj_microbit_settings'] === $stored, 'A partially applied write is restored before failure is reported');
resetInstallOptions(); $prepared = installTool()->execute(preparation()); $key = trim($files['/private/key']); $testWriteMode = 'partial';
installError(fn () => installTool()->execute(configuration($prepared, $key)), 'settings_write_failed');
verifyInstall(!array_key_exists('oj_microbit_settings', $options), 'Rollback restores a previously absent option');
resetInstallOptions($stored); $prepared = installTool()->execute(preparation()); $testWriteMode = 'interfere';
installError(fn () => installTool()->execute(configuration($prepared, str_repeat('w', 40))), 'settings_changed_after_write');
verifyInstall(($options['oj_microbit_settings']['foreign'] ?? false) === true, 'A concurrent foreign update is never rolled back to stale settings');

resetInstallOptions($stored);
$constantTool = installTool(['url' => 'http://127.0.0.1:3037', 'token' => str_repeat('c', 40)]);
$constantPrepared = $constantTool->execute(preparation());
verifyInstall(trim($files['/private/key']) === str_repeat('c', 40) && $constantPrepared['constantsOverride'], 'Matching wp-config constants are reused without editing wp-config');
installError(fn () => $constantTool->execute(preparation(str_repeat('e', 40))), 'token_constant_conflict');
installError(fn () => installTool(['url' => 'http://127.0.0.1:9999'])->execute(preparation()), 'url_constant_conflict');
verifyInstall($writes === 0 && $options['oj_microbit_settings'] === $stored, 'Constant conflicts never change stored settings');
foreach (['https://remote.invalid', 'http://127.0.0.1:0', 'http://127.0.0.1:65536', 'http://127.0.0.1:3037/path', "http://127.0.0.1:3037\n"] as $bad) {
    $request = preparation(); $request['url'] = $bad;
    installError(fn () => installTool()->execute($request), 'invalid_local_url');
}
foreach (['tiny', str_repeat('a', 257), str_repeat('a', 31) . "\n", str_repeat('a', 31) . ' '] as $bad) { installError(fn () => installTool()->execute(preparation($bad)), 'invalid_token'); }
$options['active_plugins'] = [];
installError(fn () => installTool()->execute(['mode' => 'inspect']), 'plugin_inactive');
resetInstallOptions(); $options['oj_microbit_settings'] = 'corrupt';
installError(fn () => installTool()->execute(['mode' => 'inspect']), 'invalid_stored_settings');
resetInstallOptions(); $failingWriter = new WordPressConfigurator([], static function (): void { throw new RuntimeException('private_file_write_failed'); });
installError(fn () => $failingWriter->execute(preparation()), 'private_file_write_failed');
verifyInstall($writes === 0, 'A handoff file failure happens before any database mutation');
echo '[install-wordpress] ' . $checks . " checks passed (option fixtures and private-writer seam; live Linux WordPress bootstrap is separate)\n";

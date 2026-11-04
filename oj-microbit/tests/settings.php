<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OjMicrobit\Domain\RunnerResultSchema;
use OjMicrobit\Storage\Installer;
use OjMicrobit\Support\Settings;

define('ABSPATH', "/srv/WordPress O'Reilly & <site>/");
function esc_attr(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_html(mixed $value): string { return esc_attr($value); }
function esc_url(string $value): string { return esc_attr($value); }
function home_url(string $path): string { return 'https://school.test' . $path; }
function settings_errors(string $option): void {}
function settings_fields(string $option): void { echo '<input type="hidden" name="option_page" value="' . esc_attr($option) . '">'; }
function submit_button(string $label): void { echo '<button type="submit">' . esc_html($label) . '</button>'; }
function checked(mixed $left, mixed $right, bool $echo = true): string { return $left === $right ? ' checked="checked"' : ''; }
function wp_die(string $message): never { throw new DomainException($message); }

$commandBuilder = new ReflectionMethod(Settings::class, 'installationCommands');
$commands = $commandBuilder->invoke(null, "/srv/plugins/O'Reilly/", "/srv/WordPress owner's site/");
$expected = "sudo bash '/srv/plugins/O'\"'\"'Reilly/runner/install.sh' --wp-root '/srv/WordPress owner'\"'\"'s site'";
verify($commands['install'] === $expected, 'POSIX command preserves spaces and shell-quotes apostrophes in both absolute paths');
verify($commands['check'] === $expected . ' --check', 'Diagnostic command uses the exact installer path and check option');
$windows = $commandBuilder->invoke(null, 'C:\\sites\\plugin folder\\', 'C:\\sites\\WordPress\\');
verify($windows['install'] === "sudo bash 'C:/sites/plugin folder/runner/install.sh' --wp-root 'C:/sites/WordPress'", 'Host filesystem separators normalize without using Windows shell quoting');
$rootPaths = $commandBuilder->invoke(null, '/', '/');
$filesystemRoot = str_replace('\\', '/', realpath('/') ?: '/');
verify($rootPaths['install'] === "sudo bash '" . rtrim($filesystemRoot, '/') . "/runner/install.sh' --wp-root '" . $filesystemRoot . "'", 'Filesystem root remains a valid absolute path');
foreach ([['relative-plugin', '/srv/wordpress'], ['/srv/plugins', "bad\npath"], ['', '/srv/wordpress']] as [$pluginPath, $wordpressPath]) {
    throws(fn () => $commandBuilder->invoke(null, $pluginPath, $wordpressPath), InvalidArgumentException::class, 'Ambiguous relative or control-character installation path rejected');
}
$testCapabilities = ['manage_options' => true];
$secret = str_repeat('private_runner_key_', 3);
$testOptions[Settings::OPTION] = ['runnerUrl' => 'http://127.0.0.1:3037', 'runnerToken' => $secret];
$testOptions['oj_microbit_schema_version'] = Installer::SCHEMA_VERSION;
$testHttp = static fn () => ['response' => ['code' => 200], 'body' => json_encode(['ready' => true, 'runtimeVersion' => RunnerResultSchema::RUNTIME_VERSION, 'sandbox' => 'docker-no-network'])];
ob_start(); Settings::render(); $html = (string) ob_get_clean();
verify(str_contains($html, 'WordPress와 별도로 전용 실행 서비스가 필요'), 'Admin page explains why the dedicated runtime must be installed');
verify(str_contains($html, '관리자 SSH') && str_contains($html, '웹 요청으로 실행하지 않습니다'), 'Installation action is explicitly SSH-only and never a web root execution');
verify(!str_contains($html, $secret), 'Stored runtime credential never appears in installation commands or rendered HTML');
verify(preg_match('/<input[^>]+id="oj-microbit-install-command"[^>]+readonly[^>]+value="([^"]*)"/', $html, $match) === 1, 'Installation command is selectable readonly input without a submission name');
$rendered = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
$actual = $commandBuilder->invoke(null, dirname(OJ_MICROBIT_FILE), ABSPATH);
verify($rendered === $actual['install'], 'Rendered install command uses the actual plugin file and WordPress ABSPATH');
verify(str_contains($match[1], '&lt;site&gt;') && !str_contains($html, '<site>'), 'HTML attribute escaping protects special characters in the filesystem path');
verify(preg_match('/<input[^>]+id="oj-microbit-check-command"[^>]+readonly[^>]+value="([^"]*)"/', $html, $check) === 1 && html_entity_decode($check[1], ENT_QUOTES, 'UTF-8') === $actual['check'], 'Admin page exposes the separately selectable diagnostic command');
verify(str_contains($html, '--wp-user') && str_contains($html, '인증키는 설치 과정에서 자동으로 관리'), 'SSH account option and automatic credential handling are explained');
verify(str_contains($html, '컴파일러와 시뮬레이터가 준비되었습니다'), 'Existing health state remains available after installation instructions');
$testOptions['oj_microbit_uninstall_error'] = true;
$storageHealth = Settings::healthTests([])['direct']['oj_microbit_storage']['test'];
verify($storageHealth()['status'] === 'recommended', 'A failed uninstall remains visible in storage health after reactivation');
unset($testOptions['oj_microbit_uninstall_error']);
$testCapabilities = [];
throws(static fn () => Settings::render(), DomainException::class, 'Installation commands remain restricted to administrators');
echo '[settings] ' . $testChecks . " checks passed (admin HTML and POSIX command quoting)\n";

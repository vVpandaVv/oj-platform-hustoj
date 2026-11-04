<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php'; \OjMicrobit\Core\Autoloader::register();
$platformRoot = dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform';
require_once $platformRoot . '/src/Kernel/Autoloader.php'; \CodingPanda\OjPlatform\Kernel\Autoloader::register($platformRoot);
require_once dirname(__DIR__) . '/scripts/acceptance/AcceptanceSupport.php';
require_once dirname(__DIR__) . '/scripts/acceptance/WordPressNoncePreparer.php';

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use OjMicrobit\Acceptance\AcceptanceSupport;
use OjMicrobit\Acceptance\WordPressNoncePreparer;

$checks = 0; $current = 99; $session = ''; $writes = 0;
function nonceCheck(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function nonceReject(callable $operation, string $code): void { try { $operation(); } catch (RuntimeException $error) { nonceCheck($error->getMessage() === $code, 'Expected safe nonce preparation error: ' . $code); return; } throw new RuntimeException('Expected ' . $code); }
function get_option(string $key, mixed $default = false): mixed { return match ($key) { 'siteurl' => 'https://trial.test/site', 'active_plugins' => ['oj-microbit/oj-microbit.php'], default => $default }; }
function get_current_user_id(): int { return $GLOBALS['current']; }
function wp_set_current_user(int $id): void { $GLOBALS['current'] = $id; }
function wp_get_session_token(): string { return $GLOBALS['session']; }
function wp_create_nonce(string $action): string { return substr(hash('sha256', $action . ':' . get_current_user_id()), 0, 10); }
function wp_verify_nonce(string $nonce, string $action): int|false { return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false; }
function get_user_by(string $field, string $name): object|false {
    $map = ['teach-a' => [10, 'teacher'], 'teach-b' => [11, 'teacher'], 'student-a' => [20, 'student'], 'student-b' => [21, 'student'], 'admin' => [1, 'admin']];
    return isset($map[$name]) ? (object) ['ID' => $map[$name][0], 'role' => $map[$name][1]] : false;
}
function user_can(object $user, string $cap): bool {
    return match ($cap) {
        'manage_options' => $user->role === 'admin',
        AddonCapabilityKeyV1::wordpress('oj_microbit_teach') => $user->role === 'teacher',
        AddonCapabilityKeyV1::wordpress('oj_microbit_learn') => in_array($user->role, ['teacher', 'student'], true),
        default => false,
    };
}
function update_option(mixed ...$args): never { $GLOBALS['writes']++; throw new RuntimeException('Unexpected DB write'); }
$config = ['testServer' => true, 'baseUrl' => 'https://trial.test/site/', 'accounts' => []];
foreach (['teacherA' => 'teach-a', 'teacherB' => 'teach-b', 'studentA' => 'student-a', 'studentB' => 'student-b'] as $role => $name) { $config['accounts'][$role] = ['username' => $name, 'applicationPassword' => 'PRIVATE_APPLICATION_PASSWORD']; }
$prepared = WordPressNoncePreparer::prepare($config);
nonceCheck($config['accounts']['teacherA'] === ['username' => 'teach-a', 'applicationPassword' => 'PRIVATE_APPLICATION_PASSWORD'], 'Input config remains unchanged');
nonceCheck($current === 99 && $writes === 0, 'Nonce generation restores current user and never writes options');
$nonces = array_column($prepared['accounts'], 'restNonce'); nonceCheck(count(array_unique($nonces)) === 4, 'Every distinct account receives its own sessionless nonce');
nonceCheck($prepared['accounts']['studentB']['applicationPassword'] === 'PRIVATE_APPLICATION_PASSWORD' && $prepared['baseUrl'] === $config['baseUrl'], 'Passwords and existing config fields are preserved only in the private output payload');
$bad = $config; $bad['accounts']['studentB'] = $bad['accounts']['studentA']; nonceReject(fn () => WordPressNoncePreparer::prepare($bad), 'accounts_must_be_distinct');
$bad = $config; $bad['accounts']['teacherA']['username'] = 'admin'; nonceReject(fn () => WordPressNoncePreparer::prepare($bad), 'account_role_mismatch');
$bad = $config; $bad['accounts']['teacherA']['username'] = 'student-a'; nonceReject(fn () => WordPressNoncePreparer::prepare($bad), 'account_role_mismatch');
$bad = $config; $bad['baseUrl'] = 'https://different.test'; nonceReject(fn () => WordPressNoncePreparer::prepare($bad), 'site_url_mismatch');
$session = 'cookie-session'; nonceReject(fn () => WordPressNoncePreparer::prepare($config), 'nonce_generation_failed'); $session = '';
nonceCheck($current === 99 && $writes === 0, 'Failure paths restore user state and perform no direct writes');
nonceCheck(AcceptanceSupport::baseUrl('https://TRIAL.test:443/site/') === 'https://trial.test/site', 'Base URL normalization matches default ports, host case and trailing slash');
nonceReject(fn () => AcceptanceSupport::baseUrl('http://production.test'), 'https_or_loopback_required');
nonceReject(fn () => AcceptanceSupport::baseUrl('https://name:secret@trial.test'), 'invalid_base_url');
nonceReject(fn () => AcceptanceSupport::arguments(['--token', 'PRIVATE'], [], []), 'invalid_arguments');
echo '[acceptance-nonce] ' . $checks . " checks passed (WordPress nonce/capability boundary fixtures; full native bootstrap and POSIX file checks are separate)\n";

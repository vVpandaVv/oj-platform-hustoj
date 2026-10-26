<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use OJML\Playgrounds\Platform\Privacy_Url;

define('OJ_ML_PLAYGROUNDS_URL', 'https://school.example/wordpress/wp-content/plugins/oj-ml-playgrounds/');
$privacyHome = 'https://school.example/wordpress';
$privacyFilter = null;
$privacyChecks = 0;
function home_url(string $path = ''): string { return $GLOBALS['privacyHome'] . $path; }
function esc_url_raw(string $url, array $protocols): string { return $url; }
function apply_filters(string $hook, mixed $value): mixed {
    if ($hook !== 'oj_ml_playgrounds_privacy_url') { throw new RuntimeException('Unexpected hook'); }
    $filter = $GLOBALS['privacyFilter'];
    return $filter === null ? $value : $filter($value);
}
function privacyCheck(bool $condition, string $message): void {
    $GLOBALS['privacyChecks']++;
    if (!$condition) { throw new RuntimeException($message); }
}

$bundled = OJ_ML_PLAYGROUNDS_URL . 'resources/playground/legal/privacy.ko.html';
privacyCheck(Privacy_Url::resolve() === ['url' => $bundled, 'source' => 'bundled'], 'Unavailable platform keeps bundled notice');
$platform = ['url' => 'https://school.example/wordpress/privacy', 'source' => 'platform'];
privacyCheck(Privacy_Url::resolve('', true) === $platform, 'Empty ML setting inherits the public platform route including subdirectory');
$custom = ['url' => 'https://institution.example/policy', 'source' => 'custom'];
privacyCheck(Privacy_Url::resolve($custom['url'], true) === $custom, 'Existing custom settings remain explicit overrides');
privacyCheck(Privacy_Url::resolve($custom['url'], false) === $custom, 'Custom notice does not depend on platform readiness');
$privacyHome = 'https://second.example';
privacyCheck(Privacy_Url::resolve('', true)['url'] === 'https://second.example/privacy', 'Inherited URL is recomputed, not persisted as an ML copy');
$privacyHome = 'https://school.example/wordpress';

foreach (['javascript:alert(1)', 'data:text/html,unsafe', '//other.example/privacy', 'https:///bad', 'https://',
    "https://school.example/\nsecret", 'https://user:password@school.example/privacy', str_repeat('x', 2049), [], null, 12] as $invalid) {
    privacyCheck(Privacy_Url::resolve($invalid, true) === $platform, 'Invalid stored override falls back to platform');
    $privacyFilter = static fn ($url) => $invalid;
    privacyCheck(Privacy_Url::resolve('', true) === $platform, 'Invalid filter cannot replace a safe platform link');
    privacyCheck(Privacy_Url::resolve($custom['url'], true) === $custom, 'Invalid filter preserves a valid configured link');
    $privacyFilter = null;
}
$privacyFilter = static function ($url) use ($custom) {
    privacyCheck($url === $custom['url'], 'Filter receives the resolved base URL');
    return 'https://override.example/privacy';
};
privacyCheck(Privacy_Url::resolve($custom['url'], true) === ['url' => 'https://override.example/privacy', 'source' => 'filter'], 'Valid filter has highest precedence');
$privacyFilter = static fn ($url) => 'http://legacy.example/privacy';
privacyCheck(Privacy_Url::resolve('', true)['url'] === 'http://legacy.example/privacy', 'Existing HTTP filter compatibility is retained');
$privacyFilter = null;
$privacyHome = 'javascript:invalid';
privacyCheck(Privacy_Url::resolve('', true) === ['url' => $bundled, 'source' => 'bundled'], 'Malformed platform URL does not escape fallback');

$handler = file_get_contents(dirname(__DIR__) . '/src/Platform/Addon_Page_Handler.php');
$ui = file_get_contents(dirname(__DIR__) . '/resources/js/addon.js');
privacyCheck(str_contains($handler, "'data-privacy-url'          => \$privacy_notice['url']"), 'Host exposes the effective URL for both notice and iframe');
privacyCheck(str_contains($ui, '비워 두면 사이트 개인정보 처리방침을 씁니다') && str_contains($ui, 'root.dataset.privacySource'), 'Settings UI explains inheritance and effective source');
echo "Privacy URL regression passed ($privacyChecks checks).\n";

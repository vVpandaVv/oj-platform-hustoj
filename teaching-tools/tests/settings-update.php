<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1 {
    interface AddonPageHandlerV1 { public function handle(AddonPageRequestV1 $request): AddonPageResponseV1; }
    final class AddonPageRequestV1 { public function query(): array { return array(); } }
    final class AddonPageResponseV1 {
        public function __construct(public string $body) {}
        public static function page(string $title, string $body, string $layout, bool $theme): self { return new self($body); }
    }
}
namespace TeachingTools\Support { final class Capabilities { public static function can_manage_settings(): bool { return true; } } }
namespace TeachingTools\Addon\Seating { final class Runtime_Bridge { public static function integrations(): ?object { return null; } } }
namespace {
    define('TEACHING_TOOLS_PATH', str_replace('\\', '/', dirname(__DIR__)) . '/');
    preg_match('/ \* Version: ([^\r\n]+)/', file_get_contents(dirname(__DIR__) . '/teaching-tools.php'), $version);
    define('TEACHING_TOOLS_VERSION', $version[1]);
    function __(string $value, string $domain = ''): string { return $value; }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function esc_attr(string $value): string { return esc_html($value); }
    function esc_url(string $value): string { return esc_html($value); }
    function esc_textarea(string $value): string { return esc_html($value); }
    function esc_html__(string $value, string $domain = ''): string { return esc_html($value); }
    function esc_attr__(string $value, string $domain = ''): string { return esc_html($value); }
    function esc_html_e(string $value, string $domain = ''): void { echo esc_html($value); }
    function wp_json_encode(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE); }
    function wp_create_nonce(string $value): string { return 'fixture'; }
    function wp_nonce_field(string $value): void {}
    function checked(mixed $value): void { if ($value) { echo 'checked'; } }
    function untrailingslashit(string $value): string { return rtrim($value, '/'); }
    function rest_url(string $value): string { return '/api'; }
    function home_url(string $value = ''): string { return 'https://school.test' . $value; }
    function admin_url(string $value): string { return home_url('/wp-admin/' . $value); }
    function wp_parse_url(string $value, int $component = -1): mixed { return parse_url($value, $component); }
    function get_option(string $key, mixed $default): mixed {
        return $key === 'teaching_tools_realtime' ? array('enabled' => true, 'secret' => str_repeat('s', 48), 'public_url' => 'wss://school.test/custom-board') : $default;
    }
    function update_option(mixed ...$values): never { throw new RuntimeException('Viewing the update command must not reset settings'); }
    foreach (array('Support/Realtime_Settings', 'Support/Realtime_Env', 'Support/Realtime_Versions', 'Addon/Ui', 'Addon/Settings_Page_Handler') as $class) { require dirname(__DIR__) . '/src/' . $class . '.php'; }
    $handler = new TeachingTools\Addon\Settings_Page_Handler();
    $page = $handler->handle(new CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1());
    if (($argv[1] ?? '') === 'render') {
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="/oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css">'
            . '<link rel="stylesheet" href="/teaching-tools/resources/css/console.css"></head><body class="ojp-app"><main class="ojp-shell">' . $page->body . '</main>'
            . '<script src="/teaching-tools/resources/js/realtime-settings.js"></script></body></html>';
        exit;
    }
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $page->body, LIBXML_NOERROR | LIBXML_NOWARNING);
    $command = $dom->getElementById('htt-update-command')?->textContent;
    if ($command !== "sudo bash '" . TEACHING_TOOLS_PATH . "realtime-server/install.sh' --update"
        || str_contains($command, 'ojhub1.') || str_contains($command, str_repeat('s', 48))) { throw new RuntimeException('Invalid or sensitive update command'); }
    echo "Settings update command passed: one command, no secret arguments and no setting writes.\n";
    $bundled = TeachingTools\Support\Realtime_Versions::bundled();
    $module = json_decode(file_get_contents(dirname(__DIR__) . '/realtime-addon.json'), true);
    if ($bundled['plugin'] !== TEACHING_TOOLS_VERSION || $bundled['module'] !== $module['version'] || $bundled['core'] !== '1.0.1') { throw new RuntimeException('Bundled versions unavailable'); }
    $checks = array(
        array('1.0.0', '1.0.0', true, true), array($bundled['module'], $bundled['core'], false, true),
        array('99.0.0', '99.0.0', false, true), array('', $bundled['core'], false, false),
    );
    foreach ($checks as [$module, $core, $outdated, $verified]) {
        $report = TeachingTools\Support\Realtime_Versions::report($module, $core);
        if ($report['updateRequired'] !== $outdated || $report['verified'] !== $verified) { throw new RuntimeException('Incorrect update comparison'); }
    }
    echo "Versions passed: outdated, equal, newer and unavailable server versions.\n";
}

<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1 {
    interface AddonPageHandlerV1 { public function handle(AddonPageRequestV1 $request): AddonPageResponseV1; }
    final class AddonPageRequestV1 { public function query(): array { return array(); } }
    final class AddonActivityQueryV1 { public function __construct(mixed ...$values) {} }
    final class AddonPageResponseV1 {
        public function __construct(public string $body) {}
        public static function page(string $title, string $body, string $layout, bool $dark): self { return new self($body); }
    }
}
namespace TeachingTools\Addon\Seating {
    final class Runtime_Bridge {
        public static function integrations(): object {
            return new class {
                public function searchActivities(mixed $query): object {
                    return new class {
                        public function isSuccessful(): bool { return true; }
                        public function items(): array {
                            return array(new class {
                                public function key(): string { return 'material:1'; }
                                public function title(): string { return '수업 자료 A'; }
                                public function url(): string { return '/wp-admin/admin-post.php?action=learning_materials_view&material_id=1'; }
                                public function contentType(): string { return 'application/pdf'; }
                            });
                        }
                    };
                }
            };
        }
    }
}
namespace {
    function __(string $value, string $domain = ''): string { return $value; }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
    function esc_attr(string $value): string { return esc_html($value); }
    function esc_html__(string $value, string $domain = ''): string { return esc_html($value); }
    function esc_attr__(string $value, string $domain = ''): string { return esc_attr($value); }
    function esc_html_e(string $value, string $domain = ''): void { echo esc_html($value); }
    function esc_url_raw(string $value): string { return $value; }
    function wp_json_encode(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE); }
    function wp_create_nonce(string $value): string { return 'fixture'; }
    function rest_url(string $value): string { return '/api'; }
    function home_url(string $value): string { return $value; }
    function get_current_user_id(): int { return 2; }
    function sanitize_text_field(string $value): string { return $value; }

    $base = dirname(__DIR__);
    require $base . '/src/Addon/Ui.php';
    $is_host = ($argv[1] ?? 'host') === 'host';
    if ($is_host) {
        require $base . '/src/Sessions/Session_State_Policy.php';
        require $base . '/src/Addon/Whiteboard/Whiteboard_Host_Page_Handler.php';
        $handler = new TeachingTools\Addon\Whiteboard\Whiteboard_Host_Page_Handler();
        $scripts = array('host-audience', 'pdf-pages', 'whiteboard-render', 'realtime-client', 'host-whiteboard', 'qr-code');
    } else {
        require $base . '/src/Addon/Public_Join_Page_Handler.php';
        $handler = new TeachingTools\Addon\Public_Join_Page_Handler();
        $scripts = array('pdf-pages', 'whiteboard-render', 'whiteboard-viewer', 'realtime-client', 'activity-navigation', 'join');
    }
    $response = $handler->handle(new CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1());
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<link rel="stylesheet" href="/oj-integration-rebuild/oj-platform/resources/ui/css/oj-ui-system.css">';
    foreach (array('console', 'public', 'whiteboard', 'classroom') as $style) { echo '<link rel="stylesheet" href="/teaching-tools/resources/css/' . $style . '.css">'; }
    if ($is_host) { echo '<script defer src="/teaching-tools/resources/vendor/qrcode-generator/qrcode.js"></script>'; }
    foreach ($scripts as $script) { echo '<script defer src="/teaching-tools/resources/js/' . $script . '.js"></script>'; }
    echo '</head><body class="ojp-app"><main class="ojp-shell">' . $response->body . '</main></body></html>';
}

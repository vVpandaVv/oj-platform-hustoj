<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1 {
    interface AddonPageHandlerV1 { public function handle(AddonPageRequestV1 $request): AddonPageResponseV1; }
    final class AddonCapabilityKeyV1
    {
        public static function wordpress(string $key): string { return 'mapped:' . $key; }
    }
    final class AddonPageRequestV1
    {
        public function routeKey(): string { return 'fixture.page'; }
        public function actorUserId(): int { return 7; }
        public function parameters(): array { return []; }
        public function query(): array { return []; }
        public function correlationId(): string { return 'fixture-correlation'; }
    }
    final class AddonPageResponseV1
    {
        private function __construct(public readonly string $body, public readonly int $status) {}
        public static function html(string $title, string $body, int $status = 200): self { return new self($body, $status); }
        public static function unavailable(string $message, string $code): self { return new self($message, 503); }
    }
}

namespace OJML\Playgrounds\Platform {
    final class Addon_Runtime_Bridge
    {
        public function __construct(public bool $ready = true) {}
        public function is_ready(): bool { return $this->ready; }
    }
}

namespace {
    use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
    use OJML\Playgrounds\Ops\Bootstrap;
    use OJML\Playgrounds\Platform\Addon_Page_Handler;
    use OJML\Playgrounds\Rest\Permissions;
    use OJML\Playgrounds\Platform\Addon_Runtime_Bridge;
    use OJML\Playgrounds\Security\Addon_Capabilities;

    define('ABSPATH', dirname(__DIR__) . '/');
    define('OJ_ML_PLAYGROUNDS_URL', 'https://example.test/plugins/oj-ml-playgrounds/');
    define('OJ_ML_PLAYGROUNDS_VERSION', 'fixture');
    $navigationCaps = [];
    $navigationNonceCalls = 0;
    $navigationUrlSuffix = '';
    $navigationSettings = [];
    $navigationSuperAdmin = false;
    final class WP_Error
    {
        public function __construct(public readonly string $code = '', public readonly string $message = '', public readonly array $data = []) {}
    }
    final class WP_REST_Request
    {
        public function get_header(string $name): string { return ''; }
        public function get_param(string $name): mixed { return null; }
    }
    function get_current_user_id(): int { return 7; }
    function is_super_admin(int $userId = 7): bool { return $userId === 7 && $GLOBALS['navigationSuperAdmin']; }
    function user_can(int $userId, string $capability): bool { return $userId === 7 && in_array($capability, $GLOBALS['navigationCaps'], true); }
    function current_user_can(string $capability): bool { return user_can(7, $capability); }
    function rest_url(string $path): string { return 'https://example.test/wp-json/' . $path; }
    function home_url(string $path): string { return 'https://example.test/school' . $path . $GLOBALS['navigationUrlSuffix']; }
    function admin_url(string $path): string { return 'https://example.test/school/wp-admin/' . $path; }
    function wp_create_nonce(string $action): string { ++$GLOBALS['navigationNonceCalls']; return 'fixture-rest-nonce'; }
    function apply_filters(string $hook, mixed $value): mixed { return $value; }
    function get_option(string $key, mixed $default = false): mixed { return $key === \OJML\Playgrounds\Ops\Options::KEY ? $GLOBALS['navigationSettings'] : $default; }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function esc_url(string $value): string { return esc_html($value); }

    require __DIR__ . '/bootstrap.php';

    function navigationAssert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    function navigationAttr(string $html, string $name): string
    {
        preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $html, $match);
        return html_entity_decode($match[1] ?? '', ENT_QUOTES, 'UTF-8');
    }
    function navigationGrant(string ...$keys): void
    {
        $GLOBALS['navigationCaps'] = array_map(static fn (string $key): string => $key === 'manage_options' ? $key : 'mapped:' . $key, $keys);
    }
    function navigationPage(string $capability): \CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1
    {
        return (new Addon_Page_Handler(new Addon_Runtime_Bridge(), 'fixture.page', 'console-results', 'ML 실습 결과', $capability))->handle(new AddonPageRequestV1());
    }
    function navigationSettingsPage(): \CodingPanda\OjPlatform\AddonApi\V1\AddonPageResponseV1
    {
        return (new Addon_Page_Handler(new Addon_Runtime_Bridge(), 'fixture.page', 'console-settings', 'ML 실습 설정', Addon_Capabilities::SETTINGS))->handle(new AddonPageRequestV1());
    }

    navigationGrant(Addon_Capabilities::USE);
    $learner = navigationPage(Addon_Capabilities::USE);
    navigationAssert($learner->status === 200, 'Learner fixture must render.');
    foreach (['data-console-assignments-url', 'data-console-settings-url', 'data-operations-url'] as $field) {
        navigationAssert(navigationAttr($learner->body, $field) === '', 'Learners must not receive privileged navigation targets.');
    }
    navigationAssert(navigationAttr($learner->body, 'data-can-settings') === '0', 'Learners cannot change settings.');
    navigationAssert(navigationAttr($learner->body, 'data-can-report') === '0', 'Learners cannot access teacher reports.');
    navigationAssert(navigationAttr($learner->body, 'data-lab-focus-on-start') === '0'
        && navigationAttr($learner->body, 'data-lab-guidance-collapsed') === '1', 'Default display settings must remain explicit and backwards compatible.');
    $navigationSettings = ['labFocusOnStart' => true, 'labGuidanceCollapsed' => false];
    $focusLab = (new Addon_Page_Handler(new Addon_Runtime_Bridge(), 'fixture.page', 'lab', 'ML 실습', Addon_Capabilities::USE, '', true))->handle(new AddonPageRequestV1());
    navigationAssert(navigationAttr($focusLab->body, 'data-lab-focus-on-start') === '1'
        && navigationAttr($focusLab->body, 'data-lab-guidance-collapsed') === '0', 'Operator display choices reach the actual lab wrapper.');
    navigationAssert(navigationAttr($focusLab->body, 'data-public-pc-mode') === '1'
        && str_contains($focusLab->body, 'data-oj-ml-wipe-local')
        && str_contains($focusLab->body, '공용 기기 보호가 적용됩니다.'), 'Display choices must not disable the public-PC notice or local-data deletion control.');
    $navigationSettings = [];

    navigationGrant(Addon_Capabilities::MANAGE);
    $managerOnly = navigationPage(Addon_Capabilities::MANAGE);
    navigationAssert(navigationAttr($managerOnly->body, 'data-can-report') === '0', 'Assignment management alone does not grant teacher report navigation.');

    navigationGrant(Addon_Capabilities::REPORT, Addon_Capabilities::MANAGE);
    $teacher = navigationPage(Addon_Capabilities::REPORT);
    navigationAssert(navigationAttr($teacher->body, 'data-can-report') === '1', 'Report capability enables results navigation.');
    navigationAssert(str_ends_with(navigationAttr($teacher->body, 'data-console-assignments-url'), '/assignments'), 'Assignment managers receive the assignment URL.');
    navigationAssert(navigationAttr($teacher->body, 'data-console-settings-url') === '' && navigationAttr($teacher->body, 'data-can-settings') === '0', 'Assignment management does not imply operator settings.');

    navigationGrant(Addon_Capabilities::REPORT, Addon_Capabilities::SETTINGS);
    $operator = navigationPage(Addon_Capabilities::REPORT);
    navigationAssert(str_ends_with(navigationAttr($operator->body, 'data-console-settings-url'), '/settings'), 'Teachers granted the settings capability receive the settings URL.');
    navigationAssert(navigationAttr($operator->body, 'data-can-settings') === '1' && !str_contains($operator->body, 'data-operations-url'), 'The removed wp-admin Tools page must not be linked.');
    navigationAssert(navigationAttr($operator->body, 'data-migrate-nonce') === '', 'Only the settings page carries the DB update nonce.');

    navigationGrant(Addon_Capabilities::REPORT, 'manage_options');
    $wpAdminOnly = navigationPage(Addon_Capabilities::REPORT);
    navigationAssert(navigationAttr($wpAdminOnly->body, 'data-can-settings') === '0' && navigationAttr($wpAdminOnly->body, 'data-console-settings-url') === '', 'manage_options alone no longer grants settings; the gate is is_super_admin() || SETTINGS.');

    $navigationSuperAdmin = true;
    $siteAdmin = navigationPage(Addon_Capabilities::REPORT);
    navigationAssert(navigationAttr($siteAdmin->body, 'data-can-settings') === '1' && str_ends_with(navigationAttr($siteAdmin->body, 'data-console-settings-url'), '/settings'), 'Site administrators reach the console settings page without a separate grant.');
    navigationGrant();
    navigationAssert(navigationSettingsPage()->status === 200, 'Site administrators pass the settings page gate without the SETTINGS capability.');
    navigationAssert(navigationPage(Addon_Capabilities::REPORT)->status === 403, 'Super-admin passage is limited to the settings page; other pages keep their capability gate.');
    navigationAssert(Permissions::settingsRead(new WP_REST_Request()) === true, 'Settings REST permission accepts site administrators.');
    $navigationSuperAdmin = false;
    navigationAssert(Permissions::settingsRead(new WP_REST_Request()) instanceof WP_Error, 'Settings REST permission rejects users without SETTINGS.');
    navigationAssert(navigationSettingsPage()->status === 403, 'The settings page rejects users without SETTINGS.');

    navigationGrant(Addon_Capabilities::SETTINGS);
    navigationAssert(Permissions::settingsRead(new WP_REST_Request()) === true, 'Settings REST permission accepts teachers granted SETTINGS.');
    $settingsPage = navigationSettingsPage();
    navigationAssert($settingsPage->status === 200, 'Teachers granted SETTINGS open the console settings page.');
    navigationAssert(navigationAttr($settingsPage->body, 'data-migrate-nonce') !== ''
        && str_ends_with(navigationAttr($settingsPage->body, 'data-admin-post-url'), 'wp-admin/admin-post.php'), 'The console settings page carries the DB update action formerly on the Tools page.');
    navigationAssert(str_contains($settingsPage->body, '<p class="ojp-eyebrow">학습 활동</p><h1>ML 실습 설정</h1>'), 'Settings header names the teacher menu group.');

    $bootstrapSource = (string) file_get_contents(dirname(__DIR__) . '/src/Ops/Bootstrap.php');
    navigationAssert(!str_contains($bootstrapSource, 'add_management_page') && !str_contains($bootstrapSource, "'admin_menu'")
        && !str_contains($bootstrapSource, 'oj-ml-operations'), 'The wp-admin Tools page must not be registered or linked.');
    navigationAssert(!method_exists(Bootstrap::class, 'page') && !method_exists(Bootstrap::class, 'consoleNavigation'), 'The wp-admin Tools screen renderer is removed.');
    navigationAssert(str_contains($bootstrapSource, "'site_status_tests'") && str_contains($bootstrapSource, "add_action('admin_post_oj_ml_migrate'"), 'Site Health test and the DB update handler remain.');
    navigationAssert(str_contains($bootstrapSource, 'Addon_Capabilities::current_user_can_manage_settings()') && !str_contains($bootstrapSource, "'manage_options'"), 'DB update and settings handlers use the console settings gate.');
    navigationAssert(Bootstrap::settingsUrl() === 'https://example.test/school/oj-console/addons/oj-ml-playgrounds/settings', 'Operations links point to the console settings page.');

    navigationGrant();
    $nonceCallsBefore = $navigationNonceCalls;
    $denied = navigationPage(Addon_Capabilities::REPORT);
    navigationAssert($denied->status === 403 && !str_contains($denied->body, 'data-rest-nonce'), 'Denied pages must not expose nonces.');
    navigationAssert($nonceCallsBefore === $navigationNonceCalls, 'Denied pages must not create a nonce.');
    $unavailable = (new Addon_Page_Handler(new Addon_Runtime_Bridge(false), 'fixture.page', 'console-results', 'ML 실습 결과', Addon_Capabilities::REPORT))->handle(new AddonPageRequestV1());
    navigationAssert($unavailable->status === 503 && $nonceCallsBefore === $navigationNonceCalls, 'Unavailable runtime must still fail before nonce creation.');

    navigationGrant(Addon_Capabilities::MANAGE, Addon_Capabilities::REPORT, Addon_Capabilities::SETTINGS);
    $navigationUrlSuffix = '?fixture="quoted"&next=1';
    $escaped = navigationPage(Addon_Capabilities::REPORT);
    navigationAssert(str_contains($escaped->body, '&quot;quoted&quot;') && !str_contains($escaped->body, '?fixture="quoted"'), 'Page dataset URLs must remain attribute-escaped.');
    fwrite(STDOUT, "OJ ML operations navigation regression passed.\n");
}

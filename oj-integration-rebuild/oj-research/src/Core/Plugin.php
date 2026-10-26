<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Core;

final class Plugin
{
    private ?\CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDisableGuard $disableGuard = null;
    private ?\Closure $installed = null;
    public function __construct(private readonly string $file) {}

    public function boot(): void
    {
        add_action('oj_platform_register_extensions', [$this, 'register']);
        add_action('admin_notices', [$this, 'notice']);
        register_activation_hook($this->file, [$this, 'activate']);
        register_deactivation_hook($this->file, [$this, 'deactivate']);
        add_filter('plugin_action_links_' . plugin_basename($this->file), [$this, 'links']);
    }

    public function compatible(): bool
    {
        return defined('OJ_PLATFORM_ADDON_API_VERSION')
            && version_compare((string) constant('OJ_PLATFORM_ADDON_API_VERSION'), '1.2.0', '>=')
            && version_compare((string) constant('OJ_PLATFORM_ADDON_API_VERSION'), '2.0.0', '<')
            && interface_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonExtensionRegistrationGatewayV1::class);
    }

    public function register(\CodingPanda\OjPlatform\AddonApi\V1\AddonExtensionRegistrationGatewayV1 $registration): void
    {
        if (! $this->compatible() || $registration->apiVersion() !== (string) constant('OJ_PLATFORM_ADDON_API_VERSION')) { return; }
        $registration->registerResearch(new ResearchExtension($this->file, function ($guard, \Closure $installed): void {
            $this->disableGuard = $guard;
            $this->installed = $installed;
        }));
    }

    public function activate(): void
    {
        if (! $this->compatible()) {
            wp_die('OJ Research에는 공통 애드온 API 1.2.0 이상(2.0 미만)을 제공하는 OJ Platform 2.0.5 이상이 필요합니다. OJ Platform을 먼저 업데이트해 주세요.');
        }
    }

    public function deactivate(): void
    {
        // An unfinished first installation must still be removable. A failed read is never permission.
        if ($this->installed !== null && ! ($this->installed)()) { return; }
        if ($this->disableGuard !== null) {
            $blockers = $this->disableGuard->blockers();
            if ($blockers !== []) { wp_die(esc_html(implode(' ', $blockers))); }
        }
        // No table, setting, assignment or report data is deleted.
    }

    public function notice(): void
    {
        if (! $this->compatible()) {
            echo '<div class="notice notice-error"><p>OJ Research 연결에 필요한 OJ Platform 공통 애드온 API 1.2 이상(2.0 미만)이 없습니다. 호환 플랫폼을 활성화해 주세요.</p></div>';
        }
    }

    public function links(array $links): array
    {
        if ($this->compatible()) { array_unshift($links, '<a href="' . esc_url(home_url('/oj-console/research')) . '">연구 관리</a>'); }
        return $links;
    }
}

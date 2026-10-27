<?php

declare(strict_types=1);

namespace OjAddonStarter\Core;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use OjAddonStarter\Page\NotePage;
use OjAddonStarter\Rest\NoteApi;

final class Plugin
{
    public const CAPABILITY = 'addon_starter_use';
    private static ?AddonRuntimeContextV1 $runtime = null;

    public static function boot(): void
    {
        // Register during plugin loading, before the platform collects manifests.
        add_action('oj_platform_register_addons', [self::class, 'register'], 10, 1);
        add_action('admin_notices', [self::class, 'dependency_notice']);
    }

    public static function compatible(): bool
    {
        return defined('OJ_PLATFORM_VERSION')
            && version_compare((string) OJ_PLATFORM_VERSION, '2.5.1', '>=')
            && version_compare((string) OJ_PLATFORM_VERSION, '3.0.0', '<')
            && defined('OJ_PLATFORM_ADDON_API_VERSION')
            && version_compare((string) OJ_PLATFORM_ADDON_API_VERSION, '1.8.0', '>=')
            && version_compare((string) OJ_PLATFORM_ADDON_API_VERSION, '2.0.0', '<')
            && interface_exists(AddonRegistrationGatewayV1::class)
            && class_exists(AddonCapabilityKeyV1::class);
    }

    public static function register(AddonRegistrationGatewayV1 $gateway): void
    {
        if (self::compatible()) {
            $gateway->register('oj-addon-starter', [self::class, 'manifest']);
        }
    }

    public static function manifest(AddonManifestBuilderV1 $addon): void
    {
        $addon->manifest(
            namespace: 'addon_starter',
            version: OJ_ADDON_STARTER_VERSION,
            pluginFile: OJ_ADDON_STARTER_FILE,
            requiresPlatform: '>=2.5.1 <3.0.0',
            requiresAddonApi: '>=1.8.0 <2.0.0',
            diagnosticLabel: '개인 메모 시작 예제',
            supportReference: 'oj-addon-starter/1.0.0'
        );
        $addon->capability(new AddonCapabilityDefinitionV1(
            self::CAPABILITY, '시작 예제 사용', 'educator'
        ));
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.addon_starter.assets',
            'oj-addon-starter',
            ['resources/css/note.css'],
            ['resources/js/note.js'],
            [],
            OJ_ADDON_STARTER_VERSION
        ));
        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.addon_starter.note',
            '/oj-console/addons/oj-addon-starter',
            new NotePage(),
            self::CAPABILITY,
            ['addon.addon_starter.assets']
        ));
        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            'addon.addon_starter.menu',
            '개인 메모 예제',
            'classes',
            'addon.addon_starter.note',
            self::CAPABILITY,
            100
        ));
        $addon->onReady([self::class, 'ready']);
    }

    public static function ready(AddonRuntimeContextV1 $runtime): void
    {
        self::$runtime = $runtime;
        // A rejected manifest never receives onReady or registers these routes.
        add_action('rest_api_init', [NoteApi::class, 'register_routes']);
    }

    public static function runtime(): ?AddonRuntimeContextV1
    {
        return self::$runtime;
    }

    public static function can_use(): bool
    {
        return self::$runtime !== null
            && is_user_logged_in()
            && current_user_can(AddonCapabilityKeyV1::wordpress(self::CAPABILITY));
    }

    public static function dependency_notice(): void
    {
        if (self::compatible() || ! current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('OJ Addon Starter를 사용하려면 OJ Platform 2.5.1 이상과 Addon API 1.8이 필요합니다.', 'oj-addon-starter')
            . '</p></div>';
    }
}

<?php
declare(strict_types=1);

namespace FocusTracker\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use FocusTracker\Support\Capabilities;
use FocusTracker\Tracking\Site_Asset_Config_Provider;

/**
 * Registers this plugin as an oj-platform Addon API v1 addon: a console
 * dashboard page and a settings page for teachers (one sidebar menu with
 * local navigation 활동 / 설정) and a public self-view page for students.
 * Tracking on the platform's core pages uses the site-asset extension;
 * its standalone documents do not run WordPress theme asset hooks.
 */
final class Oj_Platform_Registration {
    public static function register(): void {
        add_action('oj_platform_register_addons', array(__CLASS__, 'build'), 10, 1);
    }

    public static function build(AddonRegistrationGatewayV1 $gateway): void {
        $gateway->register('focus-tracker', array(__CLASS__, 'manifest'));
    }

    public static function manifest(AddonManifestBuilderV1 $addon): void {
        $addon->manifest(
            namespace: 'focus_tracker',
            version: FOCUS_TRACKER_VERSION,
            pluginFile: FOCUS_TRACKER_FILE,
            requiresPlatform: '>=2.3.22 <3.0.0',
            requiresAddonApi: '^1.4',
            diagnosticLabel: '집중도 평가 애드온',
            supportReference: 'focus-tracker/addon-api-v1'
        );

        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::VIEW, '집중도 평가 결과 열람', 'educator'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::MANAGE, '집중도 평가 활동 관리', 'educator'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::PARTICIPATE, '집중도 추적 대상', 'learner'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::VIEW_SELF, '본인 집중도 요약 열람', 'learner'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::SETTINGS, '집중도 평가 설정', 'educator'));

        self::register_console($addon);
        self::register_self_view($addon);
        self::register_tracking($addon);

        $addon->onReady(static function (AddonRuntimeContextV1 $runtime): void {
            Runtime_Bridge::connect($runtime);
        });
    }

    private static function register_tracking(AddonManifestBuilderV1 $addon): void {
        if (! $addon instanceof AddonIntegrationManifestBuilderV1) {
            return;
        }

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.focus_tracker.tracker_assets',
            'focus-tracker-tracker',
            array('resources/css/checks.css', 'resources/css/activity-status.css'),
            array('resources/js/activity-gate.js', 'resources/js/tracker.js', 'resources/js/checks.js'),
            array(),
            FOCUS_TRACKER_VERSION
        ));
        $addon->siteAsset(new AddonSiteAssetDefinitionV1(
            'addon.focus_tracker.tracker',
            'addon.focus_tracker.tracker_assets',
            'focus-tracker-config',
            new Site_Asset_Config_Provider()
        ));
        $addon->privacyData(new AddonPrivacyDataDefinitionV1(
            'addon.focus_tracker.learning_records',
            '집중도 및 자동 학습 확인',
            array('사용자·문제 식별자', '화면·입력 활동 신호', '문항 코드 일부·응답', '참여 지표', '일별 누적 집계', '설정·활동 변경 담당자 이력'),
            '원시 활동·문항과 응답은 각각 기본 60일. 일별·활동 집계는 별도 설정하며 기본 기간 제한 없음.',
            '초안은 일시 분석하며 전체를 저장하지 않음. 문항 응답은 집중 점수와 분리. 설정에서 세 보관 기간을 각각 변경. WordPress 개인정보 도구에서 개인별 내보내기·삭제 가능.'
        ));
    }

    private static function register_console(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.focus_tracker.console_assets',
            'focus-tracker-console',
            array('resources/css/console.css', 'resources/css/statistics.css'),
            array('resources/js/dashboard.js', 'resources/js/statistics.js'),
            array(),
            FOCUS_TRACKER_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.focus_tracker.dashboard',
            '/oj-console/addons/focus-tracker',
            new Console_Dashboard_Page_Handler(),
            Capabilities::VIEW,
            array('addon.focus_tracker.console_assets')
        ));

        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            'addon.focus_tracker.dashboard_menu',
            // The platform collects immutable manifest metadata before init.
            // Page handlers translate their content after WordPress initializes.
            '집중도 평가',
            'classes',
            'addon.focus_tracker.dashboard',
            Capabilities::VIEW,
            96
        ));

        // Site settings. The page checks the settings capability itself as
        // well (Capabilities::can_manage_settings, site administrators pass).
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.focus_tracker.settings_assets',
            'focus-tracker-settings',
            array('resources/css/console.css'),
            array(),
            array(),
            FOCUS_TRACKER_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.focus_tracker.settings',
            '/oj-console/addons/focus-tracker/settings',
            new Console_Settings_Page_Handler(),
            Capabilities::SETTINGS,
            array('addon.focus_tracker.settings_assets')
        ));

        if ($addon instanceof AddonConsoleLocalNavBuilderV1) {
            // OJ Platform 2.1+ (Addon API 1.3): 설정 is the last segment of
            // the one "집중도 평가" menu.
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
                'addon.focus_tracker.nav-activities',
                '활동',
                'addon.focus_tracker.dashboard_menu',
                'addon.focus_tracker.dashboard',
                10
            ));
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
                'addon.focus_tracker.nav-settings',
                '설정',
                'addon.focus_tracker.dashboard_menu',
                'addon.focus_tracker.settings',
                90
            ));
        } else {
            // OJ Platform 2.0.x has no local navigation: keep a way in with a
            // separate menu under the same anchor.
            $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
                'addon.focus_tracker.settings_menu',
                '집중도 평가 설정',
                'classes',
                'addon.focus_tracker.settings',
                Capabilities::SETTINGS,
                97
            ));
        }
    }

    private static function register_self_view(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.focus_tracker.self_view_assets',
            'focus-tracker-self-view',
            array('resources/css/public.css', 'resources/css/statistics.css'),
            array('resources/js/self-view.js', 'resources/js/statistics.js'),
            array(),
            FOCUS_TRACKER_VERSION
        ));

        $addon->publicPage(new AddonPublicPageDefinitionV1(
            'addon.focus_tracker.me',
            '/oj-addons/focus-tracker/me',
            new Self_View_Page_Handler(),
            Capabilities::VIEW_SELF,
            array('addon.focus_tracker.self_view_assets')
        ));

        $addon->publicMenu(new AddonPublicMenuDefinitionV1(
            'addon.focus_tracker.me_menu',
            '내 집중도 요약',
            'classes',
            'addon.focus_tracker.me',
            Capabilities::VIEW_SELF,
            60,
            'learner'
        ));
    }
}

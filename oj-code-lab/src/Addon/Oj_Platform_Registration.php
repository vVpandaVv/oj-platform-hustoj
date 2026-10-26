<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use OjCodeLab\Support\Capabilities;

/**
 * Registers this plugin as an oj-platform Addon API v1 addon:
 *
 * - a public "코드 실습실" notebook page for learners (code runs entirely in
 *   their own browser via Pyodide/WASM — no server-side execution);
 * - a console dashboard for teachers to browse their students' saved
 *   notebooks read-only, which needs the Context Gateway (onReady());
 * - a console page for teachers to curate the "추천 데이터" Kaggle shortcuts
 *   students see;
 * - a settings page to store the site's Kaggle credentials, open to site
 *   administrators and to teachers granted "코드 실습실 설정".
 *
 * D-010: one sidebar menu "코드 실습실" (학습 활동) whose local navigation
 * holds the dashboard, the "추천 데이터" page and, last, "설정". Platforms
 * without local navigation (2.0.x) get separate "코드 실습실 추천 데이터"
 * and "코드 실습실 설정" menus under the same anchor instead.
 */
final class Oj_Platform_Registration {
    public static function register(): void {
        add_action('oj_platform_register_addons', array(__CLASS__, 'build'), 10, 1);
    }

    public static function build(AddonRegistrationGatewayV1 $gateway): void {
        $gateway->register('oj-code-lab', array(__CLASS__, 'manifest'));
    }

    public static function manifest(AddonManifestBuilderV1 $addon): void {
        $addon->manifest(
            namespace: 'oj_code_lab',
            version: OJ_CODE_LAB_VERSION,
            pluginFile: OJ_CODE_LAB_FILE,
            requiresPlatform: '>=2.0.0-dev.30 <3.0.0',
            requiresAddonApi: '^1.0',
            diagnosticLabel: '코드 실습실 애드온',
            supportReference: 'oj-code-lab/addon-api-v1'
        );

        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::USE, '코드 실습실 이용', 'learner'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::REVIEW, '학생 노트북 열람', 'educator'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::MANAGE_DATASETS, '추천 데이터 관리', 'educator'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::SETTINGS, '코드 실습실 설정', 'educator'));

        self::register_lab($addon);
        self::register_console_dashboard($addon);
        self::register_console_datasets($addon);
        self::register_console_settings($addon);
        self::register_console_local_nav($addon);

        // Needed only by the teacher dashboard's roster lookup — safe to
        // register even if this manifest is ultimately rejected, since
        // onReady() simply never fires in that case.
        $addon->onReady(static function (AddonRuntimeContextV1 $runtime): void {
            Runtime_Bridge::connect($runtime);
        });
    }

    private static function register_lab(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.oj_code_lab.lab_assets',
            'oj-code-lab-lab',
            array('resources/css/lab.css', 'resources/css/markdown.css'),
            array('resources/vendor/markdown-it/markdown-it.js', 'resources/js/markdown.js', 'resources/js/text-cell.js', 'resources/js/worker-runtime.js', 'resources/js/cell-editor.js', 'resources/js/lab.js'),
            array(),
            OJ_CODE_LAB_VERSION
        ));

        $addon->publicPage(new AddonPublicPageDefinitionV1(
            'addon.oj_code_lab.lab',
            '/oj-addons/oj-code-lab/lab',
            new Lab_Page_Handler(),
            Capabilities::USE,
            array('addon.oj_code_lab.lab_assets')
        ));

        $addon->publicMenu(new AddonPublicMenuDefinitionV1(
            'addon.oj_code_lab.lab_menu',
            __('코드 실습실', 'oj-code-lab'),
            'learning_support',
            'addon.oj_code_lab.lab',
            Capabilities::USE,
            70,
            'learner'
        ));
    }

    private static function register_console_dashboard(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.oj_code_lab.console_dashboard_assets',
            'oj-code-lab-console-dashboard',
            array('resources/css/console.css', 'resources/css/markdown.css'),
            array('resources/vendor/markdown-it/markdown-it.js', 'resources/js/markdown.js', 'resources/js/dashboard.js'),
            array(),
            OJ_CODE_LAB_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.oj_code_lab.dashboard',
            '/oj-console/addons/oj-code-lab',
            new Console_Dashboard_Page_Handler(),
            Capabilities::REVIEW,
            array('addon.oj_code_lab.console_dashboard_assets')
        ));

        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            'addon.oj_code_lab.dashboard_menu',
            __('코드 실습실', 'oj-code-lab'),
            'learning_support',
            'addon.oj_code_lab.dashboard',
            Capabilities::REVIEW,
            97
        ));
    }

    private static function register_console_datasets(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.oj_code_lab.console_datasets_assets',
            'oj-code-lab-console-datasets',
            array('resources/css/console.css'),
            array('resources/js/datasets-admin.js'),
            array(),
            OJ_CODE_LAB_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.oj_code_lab.datasets',
            '/oj-console/addons/oj-code-lab/datasets',
            new Console_Datasets_Page_Handler(),
            Capabilities::MANAGE_DATASETS,
            array('addon.oj_code_lab.console_datasets_assets')
        ));

        // Platform 2.1+ shows the page as the local segment "추천 데이터".
        // 2.0.x has no local navigation, so keep a path to it with its own menu.
        if ($addon instanceof AddonConsoleLocalNavBuilderV1) {
            return;
        }

        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            'addon.oj_code_lab.datasets_menu',
            __('코드 실습실 추천 데이터', 'oj-code-lab'),
            'learning_support',
            'addon.oj_code_lab.datasets',
            Capabilities::MANAGE_DATASETS,
            98
        ));
    }

    private static function register_console_settings(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.oj_code_lab.console_settings_assets',
            'oj-code-lab-console-settings',
            array('resources/css/console.css'),
            array('resources/js/settings-admin.js'),
            array(),
            OJ_CODE_LAB_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.oj_code_lab.settings',
            '/oj-console/addons/oj-code-lab/settings',
            new Console_Settings_Page_Handler(),
            Capabilities::SETTINGS,
            array('addon.oj_code_lab.console_settings_assets')
        ));

        // Platform 2.1+ shows the page as the last local segment "설정"
        // (register_console_local_nav). 2.0.x has no local navigation, so
        // keep a path to it with its own menu under the same anchor.
        if ($addon instanceof AddonConsoleLocalNavBuilderV1) {
            return;
        }

        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            'addon.oj_code_lab.settings_menu',
            __('코드 실습실 설정', 'oj-code-lab'),
            'learning_support',
            'addon.oj_code_lab.settings',
            Capabilities::SETTINGS,
            99
        ));
    }

    /**
     * Platform 2.1+ (Addon API 1.3) only: the dashboard, the 추천 데이터
     * page and the settings page share the single "코드 실습실" sidebar
     * menu as local segments, settings last.
     * Older platforms and the test builders skip this.
     */
    private static function register_console_local_nav(AddonManifestBuilderV1 $addon): void {
        if (! $addon instanceof AddonConsoleLocalNavBuilderV1) {
            return;
        }

        $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
            'addon.oj_code_lab.nav-dashboard',
            __('학생 노트북', 'oj-code-lab'),
            'addon.oj_code_lab.dashboard_menu',
            'addon.oj_code_lab.dashboard',
            10
        ));
        $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
            'addon.oj_code_lab.nav-datasets',
            __('추천 데이터', 'oj-code-lab'),
            'addon.oj_code_lab.dashboard_menu',
            'addon.oj_code_lab.datasets',
            20
        ));
        $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
            'addon.oj_code_lab.nav-settings',
            __('설정', 'oj-code-lab'),
            'addon.oj_code_lab.dashboard_menu',
            'addon.oj_code_lab.settings',
            90
        ));
    }
}

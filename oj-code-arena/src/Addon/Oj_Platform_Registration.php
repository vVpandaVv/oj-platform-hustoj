<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

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
use OjCodeArena\Support\Capabilities;

/**
 * OJ Platform Addon API v1 registration: console pages for teachers
 * (class games, problem bank, records, projector board), the operator
 * settings page and the learner play page.
 *
 * D-010: one sidebar menu ("코드 아레나") whose pages are listed as local
 * navigation; 설정 is its last segment and needs its own capability
 * (site admins pass the platform check without it).
 */
final class Oj_Platform_Registration {
    public const ADDON_ID = 'oj-code-arena';
    public const CONSOLE_BASE = '/oj-console/addons/oj-code-arena';
    public const PLAY_PATH = '/oj-addons/oj-code-arena/play';
    public const SETTINGS_PATH = self::CONSOLE_BASE . '/settings';
    private const MENU = 'addon.oj_code_arena.teacher_menu';

    private const NET = array('shared/arena-moves.js', 'shared/arena-grade.js', 'resources/js/arena-net.js', 'resources/js/arena-look.js');

    public static function register(): void {
        add_action('oj_platform_register_addons', array(__CLASS__, 'build'), 10, 1);
    }

    public static function build(AddonRegistrationGatewayV1 $gateway): void {
        $gateway->register(self::ADDON_ID, array(__CLASS__, 'manifest'));
    }

    public static function manifest(AddonManifestBuilderV1 $addon): void {
        $addon->manifest(
            namespace: 'oj_code_arena',
            version: OJ_CODE_ARENA_VERSION,
            pluginFile: OJ_CODE_ARENA_FILE,
            requiresPlatform: '>=2.0.0-dev.30 <3.0.0',
            requiresAddonApi: '^1.0',
            diagnosticLabel: '코드 아레나 애드온',
            supportReference: 'oj-code-arena/addon-api-v1'
        );

        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::PLAY, '코드 아레나 참가', 'learner'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::TEACH, '코드 아레나 수업 게임 운영', 'educator'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::SETTINGS, '코드 아레나 설정', 'educator'));

        self::console_page($addon, 'teacher', '', new Teacher_Page_Handler(), array('resources/css/console.css'),
            array('resources/js/arena-net.js', 'resources/js/arena-look.js', 'resources/js/teacher.js'));
        self::console_page($addon, 'bank', '/bank', new Bank_Page_Handler(), array('resources/css/console.css'),
            array('shared/arena-bank.js', 'shared/arena-grade.js', 'resources/js/arena-net.js', 'resources/js/bank.js'));
        self::console_page($addon, 'records', '/records', new Records_Page_Handler(), array('resources/css/console.css'),
            array('resources/js/arena-net.js', 'resources/js/records.js'));
        self::console_page($addon, 'board', '/board', new Board_Page_Handler(), array('resources/css/board.css'),
            array('resources/js/arena-net.js', 'resources/js/arena-look.js', 'resources/js/board.js'));
        self::console_page($addon, 'settings', '/settings', new Settings_Page_Handler(), array('resources/css/console.css'),
            array('resources/js/settings.js', 'resources/js/admin-loadtest.js'), Capabilities::SETTINGS);

        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            self::MENU,
            '코드 아레나',
            'learning_support',
            'addon.oj_code_arena.teacher',
            Capabilities::TEACH,
            93
        ));
        // Local navigation exists from OJ Platform 2.1.0 (Addon API 1.3).
        if ($addon instanceof AddonConsoleLocalNavBuilderV1) {
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1('addon.oj_code_arena.nav-teacher', '수업 게임', self::MENU, 'addon.oj_code_arena.teacher', 10));
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1('addon.oj_code_arena.nav-bank', '문제은행', self::MENU, 'addon.oj_code_arena.bank', 20));
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1('addon.oj_code_arena.nav-records', '경기 기록', self::MENU, 'addon.oj_code_arena.records', 30));
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1('addon.oj_code_arena.nav-settings', '설정', self::MENU, 'addon.oj_code_arena.settings', 40));
        } else {
            // OJ Platform 2.0.x has no local navigation: keep a way in with
            // a separate menu next to the main one.
            $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
                'addon.oj_code_arena.settings_menu',
                '코드 아레나 설정',
                'learning_support',
                'addon.oj_code_arena.settings',
                Capabilities::SETTINGS,
                94
            ));
        }

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.oj_code_arena.play_assets',
            'oj-code-arena-play',
            array('resources/css/arena.css'),
            array_merge(self::NET, array('resources/js/play.js')),
            array(),
            OJ_CODE_ARENA_VERSION
        ));
        $addon->publicPage(new AddonPublicPageDefinitionV1(
            'addon.oj_code_arena.play',
            self::PLAY_PATH,
            new Play_Page_Handler(),
            Capabilities::PLAY,
            array('addon.oj_code_arena.play_assets')
        ));
        $addon->publicMenu(new AddonPublicMenuDefinitionV1(
            'addon.oj_code_arena.play_menu',
            __('코드 아레나', 'oj-code-arena'),
            'learning_support',
            'addon.oj_code_arena.play',
            Capabilities::PLAY,
            42,
            'learner'
        ));

        $addon->onReady(static function (AddonRuntimeContextV1 $runtime): void {
            Runtime_Bridge::connect($runtime);
        });
    }

    /**
     * @param list<string> $styles
     * @param list<string> $scripts
     */
    private static function console_page(AddonManifestBuilderV1 $addon, string $key, string $suffix, \CodingPanda\OjPlatform\AddonApi\V1\AddonPageHandlerV1 $handler, array $styles, array $scripts, string $capability = Capabilities::TEACH): void {
        $bundle = 'addon.oj_code_arena.' . $key . '_assets';
        $addon->assetBundle(new AddonAssetBundleDefinitionV1($bundle, 'oj-code-arena-' . $key, $styles, $scripts, array(), OJ_CODE_ARENA_VERSION));
        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.oj_code_arena.' . $key,
            self::CONSOLE_BASE . $suffix,
            $handler,
            $capability,
            array($bundle)
        ));
    }
}

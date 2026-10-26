<?php
declare(strict_types=1);

namespace TeachingTools\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonSiteAssetDefinitionV1;
use TeachingTools\Addon\Classroom\Global_Classroom_Bridge;
use TeachingTools\Addon\Classroom\Classroom_Host_Page_Handler;
use TeachingTools\Addon\Grouping\Grouping_Host_Page_Handler;
use TeachingTools\Addon\Ladder\Ladder_Host_Page_Handler;
use TeachingTools\Addon\Picker\Picker_Host_Page_Handler;
use TeachingTools\Addon\Quiz\Quiz_Host_Page_Handler;
use TeachingTools\Addon\Quickboard\Quickboard_Host_Page_Handler;
use TeachingTools\Addon\Operations\Operations_Host_Page_Handler;
use TeachingTools\Addon\Seating\Runtime_Bridge;
use TeachingTools\Addon\Seating\Seating_Host_Page_Handler;
use TeachingTools\Addon\Timeattack\Timeattack_Host_Page_Handler;
use TeachingTools\Addon\Timer\Timer_Host_Page_Handler;
use TeachingTools\Addon\Whiteboard\Whiteboard_Host_Page_Handler;
use TeachingTools\Support\Capabilities;

/**
 * Registers this plugin as an oj-platform Addon API v1 addon: one console
 * hub page (with a card per tool), one console page per built tool, and one
 * public join page shared by every tool's live/participant mode.
 */
final class Oj_Platform_Registration {
    public const CONSOLE_BASE = '/oj-console/addons/teaching-tools';
    public const SETTINGS_PATH = self::CONSOLE_BASE . '/settings';

    private const MENU = 'addon.teaching_tools.hub_menu';

    public static function register(): void {
        add_action('oj_platform_register_addons', array(__CLASS__, 'build'), 10, 1);
    }

    public static function build(AddonRegistrationGatewayV1 $gateway): void {
        $gateway->register('teaching-tools', array(__CLASS__, 'manifest'));
    }

    public static function manifest(AddonManifestBuilderV1 $addon): void {
        $addon->manifest(
            namespace: 'teaching_tools',
            version: TEACHING_TOOLS_VERSION,
            pluginFile: TEACHING_TOOLS_FILE,
            requiresPlatform: '>=2.3.0 <3.0.0',
            requiresAddonApi: '^1.4',
            diagnosticLabel: '교실 도구 애드온',
            supportReference: 'teaching-tools/addon-api-v1'
        );

        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::HOST, '교실 도구 진행', 'educator'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::JOIN, '교실 도구 참여', 'learner'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::SETTINGS, '교실 도구 설정', 'educator'));

        self::register_console($addon);
        self::register_public_join($addon);
        self::register_platform_integrations($addon);

        // Grants access to the platform's class/group roster (Context gateway),
        // used by seating and classroom auto-enrollment. Registering it here is
        // safe even if rejected/incompatible — onReady simply never fires.
        $addon->onReady(static function (AddonRuntimeContextV1 $runtime): void {
            Runtime_Bridge::connect($runtime);
        });
    }

    private static function register_console(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.console_assets',
            'teaching-tools-console',
            array('resources/css/console.css'),
            array(),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.timer_host_assets',
            'teaching-tools-timer-host',
            array('resources/css/console.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/timer-sound.js', 'resources/js/host-timer.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.hub',
            self::CONSOLE_BASE,
            new Console_Hub_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.console_assets')
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.timer',
            '/oj-console/addons/teaching-tools/timer',
            new Timer_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.timer_host_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.qr_assets',
            'teaching-tools-qr',
            array(),
            array('resources/vendor/qrcode-generator/qrcode.js', 'resources/js/qr-code.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.seating_host_assets',
            'teaching-tools-seating-host',
            array('resources/css/console.css', 'resources/css/seating.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/student-identities.js', 'resources/js/seating-engine.js', 'resources/js/host-seating.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.seating',
            '/oj-console/addons/teaching-tools/seating',
            new Seating_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.seating_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.picker_host_assets',
            'teaching-tools-picker-host',
            array('resources/css/console.css', 'resources/css/picker.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/student-identities.js', 'resources/js/picker-engine.js', 'resources/js/host-picker.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.picker',
            '/oj-console/addons/teaching-tools/picker',
            new Picker_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.picker_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.ladder_host_assets',
            'teaching-tools-ladder-host',
            array('resources/css/console.css', 'resources/css/ladder.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/ladder-engine.js', 'resources/js/ladder-render.js', 'resources/js/host-ladder.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.ladder',
            '/oj-console/addons/teaching-tools/ladder',
            new Ladder_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.ladder_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.whiteboard_host_assets',
            'teaching-tools-whiteboard-host',
            array('resources/css/console.css', 'resources/css/whiteboard.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/pdf-pages.js', 'resources/js/whiteboard-render.js', 'resources/js/realtime-client.js', 'resources/js/host-whiteboard.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.whiteboard',
            '/oj-console/addons/teaching-tools/whiteboard',
            new Whiteboard_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.whiteboard_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.quickboard_host_assets',
            'teaching-tools-quickboard-host',
            array('resources/css/console.css', 'resources/css/quickboard.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/whiteboard-render.js', 'resources/js/realtime-client.js', 'resources/js/host-quickboard.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.quickboard',
            '/oj-console/addons/teaching-tools/quickboard',
            new Quickboard_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.quickboard_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.quiz_host_assets',
            'teaching-tools-quiz-host',
            array('resources/css/console.css', 'resources/css/quiz.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/host-quiz.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.quiz',
            '/oj-console/addons/teaching-tools/quiz',
            new Quiz_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.quiz_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.grouping_host_assets',
            'teaching-tools-grouping-host',
            array('resources/css/console.css', 'resources/css/grouping.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/student-identities.js', 'resources/js/grouping-engine.js', 'resources/js/host-grouping.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.grouping',
            '/oj-console/addons/teaching-tools/grouping',
            new Grouping_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.grouping_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.timeattack_host_assets',
            'teaching-tools-timeattack-host',
            array('resources/css/console.css', 'resources/css/timeattack.css'),
            array('resources/js/host-audience.js', 'resources/js/usage-modal.js', 'resources/js/student-identities.js', 'resources/js/host-timeattack.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.timeattack',
            '/oj-console/addons/teaching-tools/timeattack',
            new Timeattack_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.timeattack_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.classroom_host_assets',
            'teaching-tools-classroom-host',
            array('resources/css/console.css', 'resources/css/classroom.css'),
            array('resources/js/usage-modal.js', 'resources/js/student-identities.js', 'resources/js/realtime-client.js', 'resources/js/host-classroom.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.classroom',
            '/oj-console/addons/teaching-tools/classroom',
            new Classroom_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.classroom_host_assets', 'addon.teaching_tools.qr_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.operations_host_assets',
            'teaching-tools-operations-host',
            array('resources/css/console.css', 'resources/css/operations.css'),
            array('resources/js/usage-modal.js', 'resources/js/timer-sound.js', 'resources/js/host-operations.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.host.operations',
            '/oj-console/addons/teaching-tools/operations',
            new Operations_Host_Page_Handler(),
            Capabilities::HOST,
            array('addon.teaching_tools.operations_host_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.settings_assets',
            'teaching-tools-settings',
            array('resources/css/console.css'),
            array('resources/js/realtime-settings.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.teaching_tools.settings',
            self::SETTINGS_PATH,
            new Settings_Page_Handler(),
            Capabilities::SETTINGS,
            array('addon.teaching_tools.settings_assets')
        ));

        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            self::MENU,
            __('교실 도구', 'teaching-tools'),
            'classes',
            'addon.teaching_tools.hub',
            Capabilities::HOST,
            70
        ));

        // OJ Platform 2.1+ (Addon API 1.3): every tool page is a segment of
        // the one menu, so the sidebar keeps "교실 도구" highlighted on them.
        if ($addon instanceof AddonConsoleLocalNavBuilderV1) {
            $segments = array(
                'hub' => array(__('전체 도구', 'teaching-tools'), 'addon.teaching_tools.hub'),
                'timer' => array(__('타이머', 'teaching-tools'), 'addon.teaching_tools.host.timer'),
                'seating' => array(__('자리배치', 'teaching-tools'), 'addon.teaching_tools.host.seating'),
                'picker' => array(__('뽑기', 'teaching-tools'), 'addon.teaching_tools.host.picker'),
                'ladder' => array(__('사다리타기', 'teaching-tools'), 'addon.teaching_tools.host.ladder'),
                'whiteboard' => array(__('판서', 'teaching-tools'), 'addon.teaching_tools.host.whiteboard'),
                'quickboard' => array(__('미니 판서', 'teaching-tools'), 'addon.teaching_tools.host.quickboard'),
                'quiz' => array(__('빠른 응답', 'teaching-tools'), 'addon.teaching_tools.host.quiz'),
                'grouping' => array(__('조 짜기', 'teaching-tools'), 'addon.teaching_tools.host.grouping'),
                'timeattack' => array(__('타임어택', 'teaching-tools'), 'addon.teaching_tools.host.timeattack'),
                'classroom' => array(__('수업 신호', 'teaching-tools'), 'addon.teaching_tools.host.classroom'),
                'operations' => array(__('운영 보드', 'teaching-tools'), 'addon.teaching_tools.host.operations'),
                'settings' => array(__('설정', 'teaching-tools'), 'addon.teaching_tools.settings'),
            );
            $order = 10;
            foreach ($segments as $key => $segment) {
                $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
                    'addon.teaching_tools.nav-' . $key,
                    $segment[0],
                    self::MENU,
                    $segment[1],
                    $order
                ));
                $order += 10;
            }
        } else {
            $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
                'addon.teaching_tools.settings_menu',
                __('교실 도구 설정', 'teaching-tools'),
                'classes',
                'addon.teaching_tools.settings',
                Capabilities::SETTINGS,
                71
            ));
        }
    }

    private static function register_public_join(AddonManifestBuilderV1 $addon): void {
        // Token mapping (console.css) and the join layout load first; a bundle
        // holds at most eight stylesheets, so the tool styles follow in a second one.
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.join_base_assets',
            'teaching-tools-join-base',
            array('resources/css/console.css', 'resources/css/public.css'),
            array(),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.join_assets',
            'teaching-tools-join',
            array('resources/css/seating.css', 'resources/css/picker.css', 'resources/css/ladder.css', 'resources/css/whiteboard.css', 'resources/css/quiz.css', 'resources/css/grouping.css', 'resources/css/timeattack.css', 'resources/css/classroom.css'),
            array('resources/js/ladder-render.js', 'resources/js/pdf-pages.js', 'resources/js/whiteboard-render.js', 'resources/js/whiteboard-viewer.js', 'resources/js/timer-sound.js', 'resources/js/realtime-client.js', 'resources/js/activity-navigation.js', 'resources/js/join.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.join_quickboard_assets',
            'teaching-tools-join-quickboard',
            array('resources/css/quickboard.css'),
            array(),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        $addon->publicPage(new AddonPublicPageDefinitionV1(
            'addon.teaching_tools.join',
            '/oj-addons/teaching-tools/join',
            new Public_Join_Page_Handler(),
            Capabilities::JOIN,
            array('addon.teaching_tools.join_base_assets', 'addon.teaching_tools.join_assets', 'addon.teaching_tools.join_quickboard_assets')
        ));

        $addon->publicMenu(new AddonPublicMenuDefinitionV1(
            'addon.teaching_tools.join_menu',
            __('수업 참여', 'teaching-tools'),
            'classes',
            'addon.teaching_tools.join',
            Capabilities::JOIN,
            50,
            'learner'
        ));
    }

    private static function register_platform_integrations(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.teaching_tools.global_classroom_assets',
            'teaching-tools-global-classroom',
            array('resources/css/global-classroom.css'),
            array('resources/js/realtime-client.js', 'resources/js/discovery-client.js', 'resources/js/global-classroom.js', 'resources/js/activity-navigation.js', 'resources/js/assigned-activities.js'),
            array(),
            TEACHING_TOOLS_VERSION
        ));

        if (! $addon instanceof AddonIntegrationManifestBuilderV1) {
            return;
        }
        $addon->siteAsset(new AddonSiteAssetDefinitionV1(
            'addon.teaching_tools.global_classroom',
            'addon.teaching_tools.global_classroom_assets',
            'htt-global-classroom-config',
            new Global_Classroom_Bridge()
        ));
        $addon->privacyData(new AddonPrivacyDataDefinitionV1(
            'addon.teaching_tools.privacy_sessions',
            __('수업 참여 및 학습지원 도구', 'teaching-tools'),
            array(
                __('학생 사용자 ID', 'teaching-tools'),
                __('표시 이름과 관리용 이름', 'teaching-tools'),
                __('수업 응답·신호·판서', 'teaching-tools'),
                __('저장한 자리배치', 'teaching-tools'),
            ),
            __('설정한 보존기간 후 자동 삭제하거나 관리자가 즉시 삭제', 'teaching-tools'),
            __('플랫폼의 동의 문서 화면에서 수업 자료의 보존기간과 이용 목적을 검토하세요.', 'teaching-tools')
        ));
    }
}

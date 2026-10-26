<?php
declare(strict_types=1);

namespace EduGames\Addon;

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
use EduGames\Addon\AiModelLab\Runtime_Bridge;
use EduGames\Blocks\AI_Classifier_Lab_Renderer;
use EduGames\Blocks\AI_Model_Lab_Renderer;
use EduGames\Blocks\Binary_Arcade_Renderer;
use EduGames\Blocks\Character_Code_Renderer;
use EduGames\Blocks\Code_Typing_Renderer;
use EduGames\Blocks\Data_Extraction_Renderer;
use EduGames\Blocks\Data_Structure_Lab_Renderer;
use EduGames\Blocks\Data_Visualization_Renderer;
use EduGames\Blocks\Device_Matching_Renderer;
use EduGames\Blocks\Image_Data_Renderer;
use EduGames\Blocks\Keyword_Rain_Renderer;
use EduGames\Blocks\Missing_Values_Renderer;
use EduGames\Blocks\Outlier_Detection_Renderer;
use EduGames\Games\Modules\AI_Classifier_Lab\AI_Classifier_Lab_Module;
use EduGames\Games\Modules\AI_Model_Lab\AI_Model_Lab_Module;
use EduGames\Games\Modules\Binary_Conversion\Binary_Conversion_Module;
use EduGames\Games\Modules\Character_Code\Character_Code_Module;
use EduGames\Games\Modules\Code_Typing\Code_Typing_Module;
use EduGames\Games\Modules\Data_Extraction\Data_Extraction_Module;
use EduGames\Games\Modules\Data_Structure_Lab\Data_Structure_Lab_Module;
use EduGames\Games\Modules\Data_Visualization\Data_Visualization_Module;
use EduGames\Games\Modules\Device_Matching\Device_Matching_Module;
use EduGames\Games\Modules\Image_Data\Image_Data_Module;
use EduGames\Games\Modules\Keyword_Rain\Keyword_Rain_Module;
use EduGames\Games\Modules\Missing_Values\Missing_Values_Module;
use EduGames\Games\Modules\Outlier_Detection\Outlier_Detection_Module;
use EduGames\Support\Capabilities;

/**
 * Registers this plugin as an oj-platform Addon API v1 addon: one Console
 * menu over three console pages (local navigation), one PublicUI hub page/menu, and one PublicUI play
 * page per playable game — all wired to the same shortcode/block Renderer
 * classes so markup is never duplicated across entry points.
 */
final class Oj_Platform_Registration {
    public static function register(): void {
        add_action('oj_platform_register_addons', array(__CLASS__, 'build'), 10, 1);
    }

    public static function build(AddonRegistrationGatewayV1 $gateway): void {
        $gateway->register('edu-games', array(__CLASS__, 'manifest'));
    }

    public static function manifest(AddonManifestBuilderV1 $addon): void {
        $addon->manifest(
            namespace: 'edu_games',
            version: EDU_GAMES_VERSION,
            pluginFile: EDU_GAMES_FILE,
            requiresPlatform: '>=2.0.0-dev.30 <3.0.0',
            requiresAddonApi: '^1.0',
            diagnosticLabel: '교육 게임 애드온',
            supportReference: 'edu-games/addon-api-v1'
        );

        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::PLAY, '교육 게임 플레이', 'learner'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::MANAGE, '교육 게임 관리', 'educator'));

        self::register_console($addon);
        self::register_public_hub($addon);
        self::register_play_assets($addon);
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.edu_games.shell_assets', 'edu-games-shell',
            array('assets/shell/shell.css'), array('assets/shell/shell.js'),
            array(), self::asset_version(array('assets/shell/shell.css','assets/shell/shell.js'))
        ));
        self::register_public_ranking($addon);
        self::register_game_pages($addon);

        // Grants access to the platform's class/group roster (Context
        // gateway), needed only by the AI Model Lab teacher report.
        // Registering it here is safe even if rejected/incompatible —
        // onReady simply never fires in that case.
        $addon->onReady(static function (AddonRuntimeContextV1 $runtime): void {
            Runtime_Bridge::connect($runtime);
        });
    }

    private static function register_console(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.edu_games.report_assets', 'edu-games-report',
            array('resources/css/console-game-report.css','assets/rankings/pager.css'),
            array('assets/rankings/pager.js','resources/js/console-game-report.js'), array(), EDU_GAMES_VERSION
        ));
        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.edu_games.report', '/oj-console/addons/edu-games/report',
            new Console_Game_Report_Page_Handler(), Capabilities::MANAGE, array('addon.edu_games.report_assets')
        ));
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.edu_games.console_assets',
            'edu-games-console',
            array('resources/css/console-manage.css'),
            array('resources/js/console-manage.js'),
            array(),
            EDU_GAMES_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.edu_games.manage',
            '/oj-console/addons/edu-games/manage',
            new Console_Manage_Page_Handler(),
            Capabilities::MANAGE,
            array('addon.edu_games.console_assets')
        ));

        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.edu_games.ai_model_lab_report_assets',
            'edu-games-ai-model-lab-report',
            array('resources/css/console-manage.css'),
            array('resources/js/console-ai-model-lab-report.js'),
            array(),
            EDU_GAMES_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.edu_games.ai_model_lab_report',
            '/oj-console/addons/edu-games/report/ai-model-lab',
            new Console_Ai_Model_Lab_Report_Page_Handler(),
            Capabilities::MANAGE,
            array('addon.edu_games.ai_model_lab_report_assets')
        ));

        // One sidebar menu (D-010). The record and AI report screens are
        // reached through the local navigation under it; their addresses
        // stay the same.
        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            'addon.edu_games.manage_menu',
            __('교육 게임', 'edu-games'),
            'learning_support',
            'addon.edu_games.manage',
            Capabilities::MANAGE,
            90
        ));

        // Addon API 1.3 only (OJ Platform 2.1.0+); older builders skip it.
        if ($addon instanceof AddonConsoleLocalNavBuilderV1) {
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
                'addon.edu_games.nav_manage', __('게임 공개·팀', 'edu-games'), 'addon.edu_games.manage_menu', 'addon.edu_games.manage', 10
            ));
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
                'addon.edu_games.nav_report', __('게임 기록', 'edu-games'), 'addon.edu_games.manage_menu', 'addon.edu_games.report', 20
            ));
            $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1(
                'addon.edu_games.nav_ai_model_lab', __('AI 모델 훈련소 성적', 'edu-games'), 'addon.edu_games.manage_menu', 'addon.edu_games.ai_model_lab_report', 30
            ));
        }
    }

    private static function register_public_hub(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.edu_games.hub_assets',
            'edu-games-hub',
            array('resources/css/public-hub.css'),
            array(),
            array(),
            EDU_GAMES_VERSION
        ));

        $addon->publicPage(new AddonPublicPageDefinitionV1(
            'addon.edu_games.games',
            '/oj-addons/edu-games/games',
            new Public_Game_Hub_Page_Handler(),
            Capabilities::PLAY,
            array('addon.edu_games.hub_assets')
        ));

        $addon->publicMenu(new AddonPublicMenuDefinitionV1(
            'addon.edu_games.games_menu',
            __('교육 게임', 'edu-games'),
            'learning_support',
            'addon.edu_games.games',
            Capabilities::PLAY,
            40,
            'learner'
        ));
    }

    private static function register_play_assets(AddonManifestBuilderV1 $addon): void {
        // Addon API v1 permits at most eight scripts per bundle. Pages load
        // their base bundle first (Chart.js and rankings), then this runtime.
        $styles = array('assets/play/style.css', 'assets/play/hands.css');
        $scripts = array('assets/play/missing.js', 'assets/play/rescue-scenes.js', 'assets/play/rescue.js', 'assets/play/hands.js', 'assets/play/core.js');
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.edu_games.play_assets', 'edu-games-play', $styles, $scripts,
            array(), self::asset_version(array_merge($styles, $scripts))
        ));
    }

    private static function register_public_ranking(AddonManifestBuilderV1 $addon): void {
        $styles = array('assets/rankings/pager.css', 'blocks/group-leaderboard/style.css', 'assets/learning/style.css', 'assets/warehouse/style.css', 'assets/strategy/style.css');
        $scripts = array('assets/rankings/pager.js', 'assets/learning/progress.js', 'blocks/group-leaderboard/view.js', 'assets/vendor/chartjs/chart.umd.min.js', 'assets/learning/view.js', 'assets/warehouse/view.js', 'assets/strategy/board-views.js', 'assets/strategy/view.js');
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.edu_games.ranking_assets',
            'edu-games-ranking',
            $styles,
            $scripts,
            array(),
            self::asset_version(array_merge($styles, $scripts))
        ));

        $addon->publicPage(new AddonPublicPageDefinitionV1(
            'addon.edu_games.ranking',
            '/oj-addons/edu-games/ranking',
            new Public_Ranking_Page_Handler(),
            Capabilities::PLAY,
            array('addon.edu_games.ranking_assets', 'addon.edu_games.play_assets', 'addon.edu_games.shell_assets')
        ));

        $addon->publicMenu(new AddonPublicMenuDefinitionV1(
            'addon.edu_games.ranking_menu',
            __('게임 순위', 'edu-games'),
            'learning_support',
            'addon.edu_games.ranking',
            Capabilities::PLAY,
            45,
            'learner'
        ));
    }

    private static function register_game_pages(AddonManifestBuilderV1 $addon): void {
        $games = function_exists('edu_games_registry') ? \edu_games_registry()->all(false) : array();

        foreach (self::renderer_map() as $game_id => $renderer) {
            if (! isset($games[$game_id])) {
                continue;
            }

            $definition = $games[$game_id];
            if ('playable' !== (string) ($definition['status'] ?? 'planned')) {
                continue;
            }

            $block_name = (string) ($definition['block'] ?? '');
            $block_folder = str_contains($block_name, '/') ? substr($block_name, strpos($block_name, '/') + 1) : $game_id;
            $key_suffix = str_replace('-', '_', $game_id);
            $title = (string) ($definition['title'] ?? $game_id);

            $styles = array('assets/rankings/pager.css', 'blocks/' . $block_folder . '/style.css');
            $scripts = array('assets/rankings/pager.js', 'assets/learning/progress.js', 'blocks/' . $block_folder . '/view.js');
            if (isset(\EduGames\Play\Catalog::GAMES[$game_id])) {
                $scripts[] = 'assets/vendor/chartjs/chart.umd.min.js';
            }
            if ($game_id === Data_Structure_Lab_Module::ID) {
                $styles[] = 'assets/warehouse/style.css';
                $scripts[] = 'assets/warehouse/view.js';
            }
            if (isset(\EduGames\Boards\Catalog::GAMES[$game_id])) {
                $styles[] = 'assets/strategy/style.css';
                $scripts[] = 'assets/strategy/board-views.js';
                $scripts[] = 'assets/strategy/view.js';
            }

            $addon->assetBundle(new AddonAssetBundleDefinitionV1(
                'addon.edu_games.assets_' . $key_suffix,
                'edu-games-addon-' . $game_id,
                $styles,
                $scripts,
                array(),
                self::asset_version(array_merge($styles, $scripts))
            ));

            $bundles = array('addon.edu_games.assets_' . $key_suffix);
            if (isset(\EduGames\Play\Catalog::GAMES[$game_id])) {
                $bundles[] = 'addon.edu_games.play_assets';
            }
            $bundles[] = 'addon.edu_games.shell_assets';
            $addon->publicPage(new AddonPublicPageDefinitionV1(
                'addon.edu_games.games.' . $game_id,
                '/oj-addons/edu-games/games/' . $game_id,
                new Game_Play_Page_Handler($game_id, $title, $renderer),
                Capabilities::PLAY,
                $bundles
            ));
        }
    }

    /**
     * @return array<string, callable>
     */
    private static function renderer_map(): array {
        return array(
            Binary_Conversion_Module::ID => array(Binary_Arcade_Renderer::class, 'render'),
            AI_Classifier_Lab_Module::ID => array(AI_Classifier_Lab_Renderer::class, 'render'),
            AI_Model_Lab_Module::ID => array(AI_Model_Lab_Renderer::class, 'render'),
            Character_Code_Module::ID => array(Character_Code_Renderer::class, 'render'),
            Code_Typing_Module::ID => array(Code_Typing_Renderer::class, 'render'),
            Data_Extraction_Module::ID => array(Data_Extraction_Renderer::class, 'render'),
            Data_Structure_Lab_Module::ID => array(Data_Structure_Lab_Renderer::class, 'render'),
            Data_Visualization_Module::ID => array(Data_Visualization_Renderer::class, 'render'),
            Device_Matching_Module::ID => array(Device_Matching_Renderer::class, 'render'),
            Image_Data_Module::ID => array(Image_Data_Renderer::class, 'render'),
            Keyword_Rain_Module::ID => array(Keyword_Rain_Renderer::class, 'render'),
            Missing_Values_Module::ID => array(Missing_Values_Renderer::class, 'render'),
            Outlier_Detection_Module::ID => array(Outlier_Detection_Renderer::class, 'render'),
        );
    }

    private static function asset_version(array $files): string {
        $times = array_map(static fn(string $file): int => is_file(EDU_GAMES_PATH.$file) ? (int)filemtime(EDU_GAMES_PATH.$file) : 0, $files);
        return EDU_GAMES_VERSION.'+assets.'.substr(hash('sha256', implode('|', $times)), 0, 12);
    }
}

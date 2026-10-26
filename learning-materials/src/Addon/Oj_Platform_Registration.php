<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use LearningMaterials\Support\Capabilities;

/**
 * Registers this plugin as an oj-platform Addon API v1 addon: one console
 * page (list + register/edit forms) and two public pages (list, detail with
 * the PDF viewer). Form submission, file upload and file streaming happen
 * outside the Addon API entirely — see Materials_Action_Controller — since
 * AddonPageRequestV1/AddonPageResponseV1 only support GET/HTML.
 */
final class Oj_Platform_Registration {
    public static function register(): void {
        add_action('oj_platform_register_addons', array(__CLASS__, 'build'), 10, 1);
    }

    public static function build(AddonRegistrationGatewayV1 $gateway): void {
        $gateway->register('learning-materials', array(__CLASS__, 'manifest'));
    }

    public static function manifest(AddonManifestBuilderV1 $addon): void {
        $addon->manifest(
            namespace: 'learning_materials',
            version: LEARNING_MATERIALS_VERSION,
            pluginFile: LEARNING_MATERIALS_FILE,
            requiresPlatform: '>=2.3.0 <3.0.0',
            requiresAddonApi: '^1.4',
            diagnosticLabel: '학습자료 공유 애드온',
            supportReference: 'learning-materials/addon-api-v1'
        );

        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::MANAGE, '학습자료 관리', 'educator'));
        $addon->capability(new AddonCapabilityDefinitionV1(Capabilities::VIEW, '학습자료 열람', 'learner'));

        self::register_console($addon);
        self::register_public($addon);

        if ($addon instanceof AddonIntegrationManifestBuilderV1) {
            $addon->activityProvider(new AddonActivityProviderDefinitionV1(
                'addon.learning_materials.activity_provider',
                new Material_Activity_Provider()
            ));
            $addon->privacyData(new AddonPrivacyDataDefinitionV1(
                'addon.learning_materials.privacy_materials',
                __('학습자료 공유', 'learning-materials'),
                array(__('업로더 사용자 ID', 'learning-materials'), __('자료 제목·설명', 'learning-materials'), __('접근 그룹', 'learning-materials')),
                __('관리자가 자료를 삭제할 때까지', 'learning-materials'),
                __('플랫폼 개인정보 처리방침에서 학습자료 보관·삭제 기준을 검토하세요.', 'learning-materials')
            ));
        }

        $addon->onReady(static function (AddonRuntimeContextV1 $runtime): void {
            Runtime_Bridge::connect($runtime);
        });
    }

    private static function register_console(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.learning_materials.console_assets',
            'learning-materials-console',
            array('resources/css/console.css', 'resources/css/catalog.css'),
            array(),
            array(),
            LEARNING_MATERIALS_VERSION
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.learning_materials.console',
            '/oj-console/addons/learning-materials',
            new Console_List_Page_Handler(),
            Capabilities::MANAGE,
            array('addon.learning_materials.console_assets')
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.learning_materials.console_new',
            '/oj-console/addons/learning-materials/new',
            new Console_Form_Page_Handler(),
            Capabilities::MANAGE,
            array('addon.learning_materials.console_assets')
        ));

        $addon->consolePage(new AddonConsolePageDefinitionV1(
            'addon.learning_materials.console_edit',
            '/oj-console/addons/learning-materials/{material_id}/edit',
            new Console_Form_Page_Handler(),
            Capabilities::MANAGE,
            array('addon.learning_materials.console_assets')
        ));

        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1(
            'addon.learning_materials.console_menu',
            __('학습자료', 'learning-materials'),
            'classes',
            'addon.learning_materials.console',
            Capabilities::MANAGE,
            75
        ));
    }

    private static function register_public(AddonManifestBuilderV1 $addon): void {
        $addon->assetBundle(new AddonAssetBundleDefinitionV1(
            'addon.learning_materials.public_assets',
            'learning-materials-public',
            array('resources/css/public.css', 'resources/css/catalog.css'),
            array('resources/js/preview.js'),
            array(),
            LEARNING_MATERIALS_VERSION
        ));

        $addon->publicPage(new AddonPublicPageDefinitionV1(
            'addon.learning_materials.index',
            '/oj-addons/learning-materials',
            new Public_List_Page_Handler(),
            Capabilities::VIEW,
            array('addon.learning_materials.public_assets')
        ));

        $addon->publicPage(new AddonPublicPageDefinitionV1(
            'addon.learning_materials.show',
            '/oj-addons/learning-materials/{material_id}',
            new Public_Detail_Page_Handler(),
            Capabilities::VIEW,
            array('addon.learning_materials.public_assets')
        ));

        $addon->publicMenu(new AddonPublicMenuDefinitionV1(
            'addon.learning_materials.public_menu',
            __('학습자료', 'learning-materials'),
            'classes',
            'addon.learning_materials.index',
            Capabilities::VIEW,
            60,
            'learner'
        ));
    }
}

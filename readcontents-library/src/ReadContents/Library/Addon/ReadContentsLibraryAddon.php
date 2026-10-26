<?php

declare(strict_types=1);

namespace ReadContents\Library\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;

final class ReadContentsLibraryAddon
{
    public static function register(AddonRegistrationGatewayV1 $gateway): void
    {
        $gateway->register(AddonRoutes::ADDON_ID, static function (AddonManifestBuilderV1 $builder): void {
            $builder->manifest(
                AddonRoutes::NAMESPACE,
                READCONTENTS_LIBRARY_VERSION,
                READCONTENTS_LIBRARY_FILE,
                '>=2.0.0-dev.28 <3.0.0',
                '^1.0.0',
                '읽기자료 라이브러리',
                'readcontents-library/support'
            );

            $builder->capability(new AddonCapabilityDefinitionV1(
                AddonRoutes::CAPABILITY_VIEW,
                '읽기자료 열람',
                'learner'
            ));
            $builder->capability(new AddonCapabilityDefinitionV1(
                AddonRoutes::CAPABILITY_EDIT,
                '읽기자료 콘텐츠 편집',
                'educator'
            ));
            $builder->capability(new AddonCapabilityDefinitionV1(
                AddonRoutes::CAPABILITY_PUBLISH,
                '읽기자료 공개 전환',
                'operator'
            ));

            $builder->assetBundle(new AddonAssetBundleDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.public_styles',
                'readcontents-library-public-styles',
                ['assets/readcontents-library.css', 'assets/editor/public.css', 'assets/reader-theme.css', 'assets/reader-layout.css'],
                [],
                [],
                READCONTENTS_LIBRARY_VERSION
            ));
            $builder->assetBundle(new AddonAssetBundleDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.editor_styles',
                'readcontents-library-editor-styles',
                ['assets/editor/admin.css', 'assets/editor/workspace-layout.css'],
                [],
                [],
                READCONTENTS_LIBRARY_VERSION
            ));

            $builder->publicPage(new AddonPublicPageDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.library',
                AddonRoutes::BASE_PATH,
                new LibraryPageHandler(),
                AddonRoutes::CAPABILITY_VIEW,
                ['addon.' . AddonRoutes::NAMESPACE . '.public_styles']
            ));
            $builder->publicPage(new AddonPublicPageDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.topic',
                AddonRoutes::BASE_PATH . '/{topic_id}',
                new TopicPageHandler(),
                AddonRoutes::CAPABILITY_VIEW,
                ['addon.' . AddonRoutes::NAMESPACE . '.public_styles']
            ));
            $builder->publicPage(new AddonPublicPageDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.topic_overview',
                AddonRoutes::BASE_PATH . '/topic/{topic_slug}',
                new TopicOverviewPageHandler(),
                AddonRoutes::CAPABILITY_VIEW,
                ['addon.' . AddonRoutes::NAMESPACE . '.public_styles']
            ));
            $builder->publicMenu(new AddonPublicMenuDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.menu',
                '읽기자료',
                'learning_support',
                'addon.' . AddonRoutes::NAMESPACE . '.library',
                AddonRoutes::CAPABILITY_VIEW,
                40,
                'learner',
                true,
                // D-010 9장: 학습 활동 › 읽기자료 is on by default.
                true
            ));

            $builder->consolePage(new AddonConsolePageDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.console_list',
                AddonRoutes::CONSOLE_BASE_PATH,
                new ConsoleLessonListPageHandler(),
                AddonRoutes::CAPABILITY_EDIT,
                ['addon.' . AddonRoutes::NAMESPACE . '.editor_styles']
            ));
            $builder->consolePage(new AddonConsolePageDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.console_edit',
                AddonRoutes::CONSOLE_BASE_PATH . '/{topic_id}',
                new ConsoleLessonEditPageHandler(),
                AddonRoutes::CAPABILITY_EDIT,
                ['addon.' . AddonRoutes::NAMESPACE . '.editor_styles']
            ));
            $builder->consoleMenu(new AddonConsoleMenuDefinitionV1(
                'addon.' . AddonRoutes::NAMESPACE . '.console_menu',
                '읽기자료',
                'learning_support',
                'addon.' . AddonRoutes::NAMESPACE . '.console_list',
                AddonRoutes::CAPABILITY_EDIT,
                40
            ));
        });
    }
}

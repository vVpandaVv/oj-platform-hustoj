<?php
declare(strict_types=1);
namespace OjMicrobit\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use OjMicrobit\Core\Plugin;

final class Registration
{
    public static function register(): void
    {
        add_action('oj_platform_register_addons', [self::class, 'build']);
        add_action('rest_api_init', [RestController::class, 'registerRoutes']);
    }
    public static function build(AddonRegistrationGatewayV1 $gateway): void
    {
        $gateway->register('oj-microbit', static function (AddonManifestBuilderV1 $addon): void {
            $addon->manifest('oj_microbit', OJ_MICROBIT_VERSION, OJ_MICROBIT_FILE, '>=2.5.9 <3.0.0', '^1.9', '마이크로비트 실습과 채점', 'oj-microbit/README.md');
            $addon->capability(new AddonCapabilityDefinitionV1('oj_microbit_teach', '마이크로비트 과제 관리', 'educator'));
            $addon->capability(new AddonCapabilityDefinitionV1('oj_microbit_learn', '마이크로비트 실습 참여', 'learner'));
            $addon->assetBundle(new AddonAssetBundleDefinitionV1('addon.oj_microbit.ui', 'oj-microbit-ui',
                ['resources/css/microbit.css'], ['resources/vendor/microbit-usb.js', 'resources/js/microbit.js'], [], OJ_MICROBIT_VERSION));
            $addon->consolePage(new AddonConsolePageDefinitionV1('addon.oj_microbit.teacher', '/oj-console/addons/oj-microbit', new PageHandler('teacher', 'problems'),
                'oj_microbit_teach', ['addon.oj_microbit.ui']));
            $addon->consolePage(new AddonConsolePageDefinitionV1('addon.oj_microbit.teacher_new', '/oj-console/addons/oj-microbit/new', new PageHandler('teacher', 'problems', 'new'),
                'oj_microbit_teach', ['addon.oj_microbit.ui']));
            $addon->consolePage(new AddonConsolePageDefinitionV1('addon.oj_microbit.teacher_lessons', '/oj-console/addons/oj-microbit/lessons', new PageHandler('teacher', 'lessons'),
                'oj_microbit_teach', ['addon.oj_microbit.ui']));
            $addon->publicPage(new AddonPublicPageDefinitionV1('addon.oj_microbit.learn', '/oj-addons/oj-microbit/learn', new PageHandler('student', 'problems'),
                'oj_microbit_learn', ['addon.oj_microbit.ui']));
            $addon->publicPage(new AddonPublicPageDefinitionV1('addon.oj_microbit.lessons', '/oj-addons/oj-microbit/lessons', new PageHandler('student', 'lessons'),
                'oj_microbit_learn', ['addon.oj_microbit.ui']));
            $addon->consoleMenu(new AddonConsoleMenuDefinitionV1('addon.oj_microbit.teacher_menu', '마이크로비트 문제', 'problems', 'addon.oj_microbit.teacher', 'oj_microbit_teach', 74));
            $addon->consoleMenu(new AddonConsoleMenuDefinitionV1('addon.oj_microbit.teacher_lessons_menu', '마이크로비트 수업', 'classes', 'addon.oj_microbit.teacher_lessons', 'oj_microbit_teach', 75));
            if ($addon instanceof AddonConsoleLocalNavBuilderV1) {
                $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1('addon.oj_microbit.teacher_list_nav', '문제 목록', 'addon.oj_microbit.teacher_menu', 'addon.oj_microbit.teacher', 10));
                $addon->consoleLocalNav(new AddonConsoleLocalNavItemV1('addon.oj_microbit.teacher_new_nav', '문제 생성', 'addon.oj_microbit.teacher_menu', 'addon.oj_microbit.teacher_new', 20));
            }
            $addon->publicMenu(new AddonPublicMenuDefinitionV1('addon.oj_microbit.problem_menu', '마이크로비트 문제', 'problems', 'addon.oj_microbit.learn', 'oj_microbit_learn', 74, 'learner'));
            $addon->publicMenu(new AddonPublicMenuDefinitionV1('addon.oj_microbit.lesson_menu', '마이크로비트 수업', 'classes', 'addon.oj_microbit.lessons', 'oj_microbit_learn', 75, 'learner'));
            if ($addon instanceof AddonIntegrationManifestBuilderV1) {
                $addon->privacyData(new AddonPrivacyDataDefinitionV1('addon.oj_microbit.records', '마이크로비트 학습 기록',
                    ['사용자·과제 식별자와 과제 버전', 'MakeCode 프로젝트와 소스 코드', '핀 입력·출력과 채점 결과'],
                    '학생 기록은 기본 365일 보관하며 설정에서 변경합니다. 과제는 교사가 회수할 때까지 유지합니다.',
                    '코드는 자체 실행 서비스와 Microsoft MakeCode 편집기에서 처리합니다. 개인정보 도구로 프로젝트·채점 기록을 내보내거나 삭제할 수 있습니다. 기본 플러그인 삭제는 기록을 보존합니다.'));
            }
            $addon->onReady(static fn (AddonRuntimeContextV1 $runtime) => Plugin::connect($runtime));
        });
    }
}

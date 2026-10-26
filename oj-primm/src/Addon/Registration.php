<?php

declare(strict_types=1);

namespace OjPrimm\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskInstructionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPrivacyDataDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use OjPrimm\Core\Plugin;
use OjPrimm\Generation\PlatformLessonGenerator;

final class Registration
{
    public const TEACH = 'oj_primm_teach';
    public const LEARN = 'oj_primm_learn';

    public static function register(): void
    {
        add_action('oj_platform_register_addons', [self::class, 'build'], 10, 1);
        add_action('rest_api_init', [RestController::class, 'registerRoutes']);
    }

    public static function build(AddonRegistrationGatewayV1 $gateway): void
    {
        $gateway->register('oj-primm', static function (AddonManifestBuilderV1 $addon): void {
            $addon->manifest('oj_primm', OJ_PRIMM_VERSION, OJ_PRIMM_FILE,
                '>=2.4.3 <3.0.0', '^1.7', 'PRIMM 프로그래밍 수업', 'oj-primm/addon-api-v1');
            $addon->capability(new AddonCapabilityDefinitionV1(self::TEACH, 'PRIMM 수업 관리', 'educator'));
            $addon->capability(new AddonCapabilityDefinitionV1(self::LEARN, 'PRIMM 활동 참여', 'learner'));
            $addon->aiTask(new AddonAiTaskDefinitionV1(
                'addon.oj_primm.generate_lesson', '문제로 PRIMM 수업 자료 생성',
                'primm_lesson.v1', 'addon_payload', new AddonAiTaskInstructionV1('primm_lesson.v1', PlatformLessonGenerator::instruction())
            ));
            if ($addon instanceof AddonIntegrationManifestBuilderV1) {
                $addon->privacyData(new AddonPrivacyDataDefinitionV1(
                    'addon.oj_primm.learning_records', 'PRIMM 학습 과정 기록',
                    ['사용자·수업·활동·문제·제출 식별자', '단계별 응답과 최초 예측',
                        '코드 초안·도움 활동 상태', '현재 단계·진행 이력', '본인 제출 마지막 확인 기록',
                        '문제 본문·입출력 설명·공개 예제의 외부 AI 전송', 'AI 생성 수업 자료·생성 작업 상태',
                        '생성 예제의 OJ 실행 기록·오류 진단과 자동 수정용 AI 전송', '학생 수정 코드·연습 테스트 결과',
                        '학급 생성 기본값·자료 버전·공개와 회수 기록', '생성 사용량·취소·재시도·운영 오류 집계'],
                    '학습 기록은 기본 기간 제한 없이 보관합니다. 설정 → OJ PRIMM에서 보관 일수를 바꿀 수 있습니다.',
                    '공개 문제와 생성 자료·OJ 오류 진단을 AI에 보내 생성·수정합니다. 학생 코드·응답·명단은 AI에 보내지 않습니다. 학생 코드는 기존 OJ에서 연습 실행합니다. 교사는 담당 명단의 진행 보고서와 CSV를 조회합니다. WordPress 개인정보 도구로 개인별 내보내기·삭제가 가능합니다. 일반 삭제는 기록을 보존하며 설정에서 데이터 제거를 켠 뒤 삭제하면 해당 사이트의 PRIMM 자료를 제거합니다.'
                ));
            }
            $addon->assetBundle(new AddonAssetBundleDefinitionV1(
                'addon.oj_primm.ui', 'oj-primm-ui', ['resources/css/primm.css'],
                ['resources/js/primm.js'], [], OJ_PRIMM_VERSION
            ));
            $addon->consolePage(new AddonConsolePageDefinitionV1('addon.oj_primm.teacher',
                '/oj-console/addons/oj-primm', new PageHandler('teacher'), self::TEACH, ['addon.oj_primm.ui']));
            $addon->consoleMenu(new AddonConsoleMenuDefinitionV1('addon.oj_primm.teacher_menu',
                'PRIMM 수업', 'learning_support', 'addon.oj_primm.teacher', self::TEACH, 73));
            $addon->publicPage(new AddonPublicPageDefinitionV1('addon.oj_primm.learn',
                '/oj-addons/oj-primm/learn', new PageHandler('student'), self::LEARN, ['addon.oj_primm.ui']));
            $addon->publicMenu(new AddonPublicMenuDefinitionV1('addon.oj_primm.learn_menu',
                'PRIMM 활동', 'learning_support', 'addon.oj_primm.learn', self::LEARN, 73, 'learner'));
            $addon->onReady(static function (AddonRuntimeContextV1 $runtime): void {
                RuntimeBridge::connect($runtime);
                Plugin::connect($runtime);
            });
        });
    }
}

<?php
declare(strict_types=1);
namespace OjNetworkLab\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
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
use OjNetworkLab\Core\Plugin;
final class Registration
{
    public const LEARN = 'oj_network_lab_learn';
    public const TEACH = 'oj_network_lab_teach';
    public static function register(): void
    {
        add_action('oj_platform_register_addons', [self::class, 'build']);
        add_action('rest_api_init', [RestController::class, 'registerRoutes']);
        add_filter('oj_platform_unprivileged_addon_capabilities', [self::class, 'unprivileged']);
    }
    public static function unprivileged(array $keys): array
    {
        if (class_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::class)) { $keys[] = \CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress(self::LEARN); }
        return array_values(array_unique($keys));
    }
    public static function build(AddonRegistrationGatewayV1 $gateway): void
    {
        $gateway->register('oj-network-lab', static function (AddonManifestBuilderV1 $addon): void {
            $addon->manifest('oj_network_lab', OJ_NETWORK_LAB_VERSION, OJ_NETWORK_LAB_FILE, '>=2.5.9 <3.0.0', '^1.9', '네트워크 구성과 장애 해결 실습', 'oj-network-lab/README.md');
            $addon->capability(new AddonCapabilityDefinitionV1(self::LEARN, '네트워크 실습 참여', 'learner'));
            $addon->capability(new AddonCapabilityDefinitionV1(self::TEACH, '네트워크 실습 교사', 'educator'));
            $addon->assetBundle(new AddonAssetBundleDefinitionV1('addon.oj_network_lab.ui', 'oj-network-lab-ui', ['resources/css/network-lab.css'], ['resources/js/app.js'], [], OJ_NETWORK_LAB_VERSION));
            $addon->publicPage(new AddonPublicPageDefinitionV1('addon.oj_network_lab.learn', '/oj-addons/oj-network-lab/learn', new PageHandler(), self::LEARN, ['addon.oj_network_lab.ui']));
            $addon->publicMenu(new AddonPublicMenuDefinitionV1('addon.oj_network_lab.learn_menu', '네트워크 실습', 'learning_support', 'addon.oj_network_lab.learn', self::LEARN, 76, 'learner'));
            $addon->consolePage(new AddonConsolePageDefinitionV1('addon.oj_network_lab.teacher', '/oj-console/addons/oj-network-lab', new PageHandler(), self::TEACH, ['addon.oj_network_lab.ui']));
            $addon->consoleMenu(new AddonConsoleMenuDefinitionV1('addon.oj_network_lab.teacher_menu', '네트워크 실습', 'learning_support', 'addon.oj_network_lab.teacher', self::TEACH, 76));
            if ($addon instanceof AddonIntegrationManifestBuilderV1) {
                $addon->privacyData(new AddonPrivacyDataDefinitionV1('addon.oj_network_lab.projects', '네트워크 실습 프로젝트', ['사용자 식별자', '프로젝트 제목·네트워크 설정·가상 VPN 키', '갱신 시각·충돌 방지 버전'], '보관 기간은 설정 → OJ Network Lab에서 정합니다. 기본은 무기한이며 계정별 최대 20개입니다.', '통신 계산은 브라우저에서 수행하고 외부 실습 서버나 AI에 보내지 않습니다. 패킷·명령 이력은 서버에 저장하지 않습니다. 브라우저 초안은 별도 저장되며 화면에서 삭제할 수 있습니다. WordPress 개인정보 도구로 서버 프로젝트 내보내기·삭제를 지원합니다. 플러그인 삭제 시 기본으로 서버 기록을 보존합니다.'));
            }
            $addon->onReady(static function (AddonRuntimeContextV1 $runtime): void { Plugin::$runtime = $runtime; });
        });
    }
}

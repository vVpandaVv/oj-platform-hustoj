<?php
declare(strict_types=1);
namespace OjLogicLab\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
final class Registration
{
    public const LEARN = 'oj_logic_lab_learn';
    public static function register(): void
    {
        add_action('oj_platform_register_addons', [self::class, 'build']);
        add_filter('oj_platform_unprivileged_addon_capabilities', static function (array $keys): array {
            if (class_exists(AddonCapabilityKeyV1::class)) { $keys[] = AddonCapabilityKeyV1::wordpress(self::LEARN); }
            return array_values(array_unique($keys));
        });
    }
    public static function build(AddonRegistrationGatewayV1 $gateway): void
    {
        $gateway->register('oj-logic-lab', static function (AddonManifestBuilderV1 $addon): void {
            $addon->manifest('oj_logic_lab', OJ_LOGIC_LAB_VERSION, OJ_LOGIC_LAB_FILE, '>=2.5.9 <3.0.0', '^1.9', '논리회로·컴퓨터구조 타이밍 실습', 'oj-logic-lab/README.md');
            $addon->capability(new AddonCapabilityDefinitionV1(self::LEARN, '논리회로 실습 참여', 'learner'));
            $addon->assetBundle(new AddonAssetBundleDefinitionV1('addon.oj_logic_lab.ui', 'oj-logic-lab-ui', ['resources/css/logic-lab.css'], ['resources/js/bootstrap.js'], [], OJ_LOGIC_LAB_VERSION));
            $addon->publicPage(new AddonPublicPageDefinitionV1('addon.oj_logic_lab.learn', '/oj-addons/oj-logic-lab/learn', new PageHandler(), self::LEARN, ['addon.oj_logic_lab.ui']));
            $addon->publicMenu(new AddonPublicMenuDefinitionV1('addon.oj_logic_lab.menu', '논리회로 실습', 'learning_support', 'addon.oj_logic_lab.learn', self::LEARN, 77, 'learner'));
        });
    }
}

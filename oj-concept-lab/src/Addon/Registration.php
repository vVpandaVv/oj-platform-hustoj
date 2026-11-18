<?php
declare(strict_types=1);
namespace OjConceptLab\Addon;
use CodingPanda\OjPlatform\AddonApi\V1\{AddonAssetBundleDefinitionV1,AddonCapabilityDefinitionV1,AddonCapabilityKeyV1,AddonConsoleMenuDefinitionV1,AddonConsolePageDefinitionV1,AddonManifestBuilderV1,AddonPublicMenuDefinitionV1,AddonPublicPageDefinitionV1,AddonRegistrationGatewayV1};
use OjConceptLab\Activities\Catalog;
final class Registration {
    public const LEARN='oj_concept_lab_learn', MANAGE='oj_concept_lab_manage';
    public static function register(): void {
        add_action('oj_platform_register_addons', [self::class,'build']);
        add_filter('oj_platform_unprivileged_addon_capabilities', static function(array $keys): array {
            if(class_exists(AddonCapabilityKeyV1::class)) $keys[]=AddonCapabilityKeyV1::wordpress(self::LEARN);
            return array_values(array_unique($keys));
        });
    }
    public static function build(AddonRegistrationGatewayV1 $gateway): void { $gateway->register('oj-concept-lab',[self::class,'manifest']); }
    public static function manifest(AddonManifestBuilderV1 $addon): void {
        $addon->manifest('oj_concept_lab',OJ_CONCEPT_LAB_VERSION,OJ_CONCEPT_LAB_FILE,'>=2.0.0-dev.30 <3.0.0','^1.0','정보 개념 체험','oj-concept-lab/README.md');
        $addon->capability(new AddonCapabilityDefinitionV1(self::LEARN,'정보 개념 체험 참여','learner'));
        $addon->capability(new AddonCapabilityDefinitionV1(self::MANAGE,'정보 개념 체험 공개 관리','educator'));
        $addon->assetBundle(new AddonAssetBundleDefinitionV1('addon.oj_concept_lab.ui','oj-concept-lab-ui',['resources/css/lab.css'],['resources/vendor/chartjs/chart.umd.min.js','resources/js/lab.js'],[],OJ_CONCEPT_LAB_VERSION));
        $addon->publicPage(new AddonPublicPageDefinitionV1('addon.oj_concept_lab.index','/oj-addons/oj-concept-lab/activities',new PageHandler(),self::LEARN,['addon.oj_concept_lab.ui']));
        $addon->publicMenu(new AddonPublicMenuDefinitionV1('addon.oj_concept_lab.menu','개념 체험','learning_support','addon.oj_concept_lab.index',self::LEARN,42,'learner'));
        foreach(Catalog::ITEMS as $id=>$item) $addon->publicPage(new AddonPublicPageDefinitionV1('addon.oj_concept_lab.activity.'.str_replace('-','_',$id),'/oj-addons/oj-concept-lab/activities/'.$id,new PageHandler($id),self::LEARN,['addon.oj_concept_lab.ui']));
        $addon->consolePage(new AddonConsolePageDefinitionV1('addon.oj_concept_lab.manage','/oj-console/addons/oj-concept-lab/manage',new PageHandler('',true),self::MANAGE,['addon.oj_concept_lab.ui']));
        $addon->consoleMenu(new AddonConsoleMenuDefinitionV1('addon.oj_concept_lab.manage_menu','개념 체험','learning_support','addon.oj_concept_lab.manage',self::MANAGE,92));
    }
    public static function can_manage(): bool {
        $key=class_exists(AddonCapabilityKeyV1::class)?AddonCapabilityKeyV1::wordpress(self::MANAGE):self::MANAGE;
        return current_user_can('manage_options')||current_user_can($key);
    }
    public static function can_learn(): bool {
        $key=class_exists(AddonCapabilityKeyV1::class)?AddonCapabilityKeyV1::wordpress(self::LEARN):self::LEARN;
        return is_user_logged_in()&&(current_user_can($key)||self::can_manage());
    }
}

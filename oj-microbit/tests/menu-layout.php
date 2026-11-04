<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use CodingPanda\OjPlatform\Kernel\Addon\{AddonManifestBuilder, AddonMenuContributor, AddonPublicMenuContributions};
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;
use CodingPanda\OjPlatform\Modules\Settings\Contract\{SettingsManager,SettingsSnapshot,FeatureFlags};
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\{RouteDefinition,RouteHandlerCollection,RouteHandler,RouteUrlProvider,RouteArea,AuthenticationRequirement};
use CodingPanda\OjPlatform\Modules\Permission\Contract\{Authorization,PermissionContext};
$gateway = new class implements AddonRegistrationGatewayV1 {
    public AddonManifestBuilder $builder;
    public function apiVersion(): string { return '1.9.0'; }
    public function register(string $addonId, callable $build): void { $this->builder = new AddonManifestBuilder(); $build($this->builder); }
};
\OjMicrobit\Addon\Registration::build($gateway); $manifest=$gateway->builder->close();
verify(array_map(fn($m)=>$m->anchor(),$manifest->consoleMenus()) === ['problems','classes'], 'Console menus belong below their respective first-level roots');
verify(array_map(fn($m)=>$m->parent(),$manifest->publicMenus()) === ['problems','classes'], 'Learner menus belong below problem and class roots');
$menus = new DefinitionRegistry('menus',\CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition::class); $addons = new AddonPublicMenuContributions();
(new AddonMenuContributor($menus,$addons))->contribute($manifest);
$stored = [
    (new PublicMenuEntry('addon.oj_microbit.learn_menu','마이크로비트 문제',2,'learning_support','addon.oj_microbit.learn',null,'learner',74,'primary',true))->toArray(),
    (new PublicMenuEntry('addon.oj_microbit.lessons_menu','마이크로비트 수업',2,'learning_support','addon.oj_microbit.lessons',null,'learner',75,'primary',true))->toArray(),
    (new PublicMenuEntry('problems.index','맞춤 문제 이름',2,'problems','problems.index',null,'all',11,'primary',true))->toArray(),
];
$settings=new class($stored) implements SettingsManager {
    public int $writes=0;
    public function __construct(private array $items) {}
    public function read(string $key,mixed $default=null): SettingsSnapshot { return new SettingsSnapshot(4,['items'=>$this->items]); }
    public function save(string $key,mixed $value,int $expectedRevision): SettingsSnapshot { $this->writes++; throw new LogicException('Rendering must not rewrite menu settings'); }
};
$routes=new DefinitionRegistry('routes',RouteDefinition::class);
$handlers=new class implements RouteHandlerCollection { public function register(string $name,RouteHandler $handler):void{} public function find(string $name):?RouteHandler{return null;}public function keys():array{return [];} };
$urls=new class implements RouteUrlProvider {public function path(string $name,array $parameters=[]):string{return '/'.str_replace('.','/',$name);} };
$flags=new class implements FeatureFlags {public function isEnabled(string $key):bool{return true;} };
$auth=new class implements Authorization {public function can(int $userId,string $capability,?PermissionContext $context=null):bool{return true;}public function canAny(int $userId,array $capabilities):bool{return true;}public function isSuperAdmin(int $userId):bool{return false;} };
$service=new PublicMenuSettingsService($settings,$routes,$handlers,$urls,$flags,$auth,$addons);
foreach($service->defaults() as $index=>$entry){if($entry->routeKey() && $entry->routeKey()!=='first_accessible_child'){$routes->register(new RouteDefinition($entry->routeKey(),new ModuleId('public_ui'),'public_ui','/fixture-'.$index,RouteArea::PublicArea,['GET'],AuthenticationRequirement::Authenticated,'html'));}}
$entries=$service->entries();
foreach(['addon.oj_microbit.learn'=>'problems','addon.oj_microbit.lessons'=>'classes'] as $route=>$parent){$matches=array_values(array_filter($entries,fn($entry)=>$entry->routeKey()===$route));verify(count($matches)===1 && $matches[0]->parentKey()===$parent,'Stored rc.5 menu is reconciled once under its requested new root');}
$other=array_values(array_filter($entries,fn($entry)=>$entry->key()==='problems.index'));
verify($other[0]->label()==='맞춤 문제 이름','Unrelated menu customization survives the microbit move');
verify($settings->writes===0,'New defaults reconcile without DB writes or a full settings migration');
echo '[menu-layout] '.$testChecks." checks passed (real public menu reconciliation, stored rc.5 entries; no WordPress server)\n";

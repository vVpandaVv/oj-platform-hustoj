<?php
declare(strict_types=1);
$workspace = dirname(__DIR__, 3);
$platform = $workspace . '/oj-integration-rebuild/oj-platform';
if (($argv[1] ?? '') !== '--render') { fwrite(STDERR, "Usage: php tests/fixtures/browser-host.php --render [temporary-output-directory]\n"); exit(2); }
$browserHostOutput = $argv[2] ?? $workspace . '/_notes/tmp/microbit-ux-rc8';
$browserHostTempRoot = realpath($workspace . '/_notes/tmp');
$browserHostParent = realpath(dirname($browserHostOutput));
if ($browserHostTempRoot === false || $browserHostParent === false || strcasecmp(str_replace('\\', '/', $browserHostParent), str_replace('\\', '/', $browserHostTempRoot)) !== 0 || preg_match('/^[a-zA-Z0-9_-]+$/D', basename($browserHostOutput)) !== 1 || is_link($browserHostOutput)) { throw new RuntimeException('Fixture output must be a direct, regular child of workspace _notes/tmp.'); }
if (!is_dir($browserHostOutput) && !mkdir($browserHostOutput, 0700)) { throw new RuntimeException('Could not create browser fixture directory.'); }
$browserHostOutput = realpath($browserHostOutput);
// Load the existing in-memory problem declarations without running unrelated
// filesystem mutation checks from the full core unit-test script.
$browserHostUnitSource = file_get_contents($platform . '/tests/Unit/problems.php');
$browserHostBoundary = strpos($browserHostUnitSource, '$judgeLanguages = new JudgeLanguageCatalog();');
if ($browserHostBoundary === false) { throw new RuntimeException('Core problem fixture declaration boundary changed.'); }
$browserHostDeclarations = substr($browserHostUnitSource, 0, $browserHostBoundary);
$browserHostDeclarations = str_replace('$pluginRoot = dirname(__DIR__, 2);', '$pluginRoot = ' . var_export($platform, true) . ';', $browserHostDeclarations, $browserHostRootReplacements);
if ($browserHostRootReplacements !== 1) { throw new RuntimeException('Core problem fixture bootstrap changed.'); }
file_put_contents($browserHostOutput . '/problem-fixtures.php', $browserHostDeclarations);
$browserHostArguments = $argv; $argv = [];
ob_start();
require $browserHostOutput . '/problem-fixtures.php';
require $platform . '/tests/Unit/class-management.php';
ob_end_clean();
$argv = $browserHostArguments;
require_once $workspace . '/oj-microbit/src/Core/Autoloader.php';
\OjMicrobit\Core\Autoloader::register();
define('OJ_MICROBIT_VERSION','1.0.0-rc.9');
define('OJ_MICROBIT_FILE',$workspace.'/oj-microbit/oj-microbit.php');
if(!function_exists('wp_json_encode')){function wp_json_encode(mixed $value,int $flags=0):string{return json_encode($value,$flags|JSON_THROW_ON_ERROR);}}
if(!function_exists('rest_url')){function rest_url(string $path):string{return 'https://example.test/wp-json/'.$path;}}
if(!function_exists('wp_create_nonce')){function wp_create_nonce(string $action):string{return 'fixture-nonce';}}
if(!function_exists('get_current_user_id')){function get_current_user_id():int{return 10;}}

$gateway = new class implements \CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1 {
    public ?\CodingPanda\OjPlatform\Kernel\Addon\AddonManifestBuilder $builder = null;
    public function apiVersion(): string { return '1.9.0'; }
    public function register(string $addonId, callable $build): void { $this->builder = new \CodingPanda\OjPlatform\Kernel\Addon\AddonManifestBuilder(); $build($this->builder); }
};
\OjMicrobit\Addon\Registration::build($gateway);
$manifest = $gateway->builder->close();
if ($manifest->nativeLearningProviders() !== []) { throw new RuntimeException('Actual microbit registration must have no native provider.'); }
$accepted = new \CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog();
$accepted->commitAndFreeze([new \CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry('oj-microbit', $manifest, 'accepted', '')]);
$actor = new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(10, 'owner', 'owner@example.test', '교사');
$fixtureAccounts = new class($actor) implements \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider {
    public function __construct(private $actor) {}
    public function current(): \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount { return new \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount($this->actor, null); }
};
$fixturePermissions = new class implements \CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization {
    public function can(int $userId, string $capability, ?\CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext $context = null): bool { return true; }
    public function canAny(int $userId, array $capabilities): bool { return true; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$fixtureFlags = new class implements \CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags { public function isEnabled(string $featureKey): bool { return true; } };
$routes = new \CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry('routes', \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::class);
foreach (['problems.index' => '/problems', 'classes.index' => '/classes', 'console.problems.index' => '/oj-console/problems', 'console.classes.index' => '/oj-console/classes'] as $name => $routePath) {
    $routes->register(\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray(['name'=>$name,'owner_module'=>'public_ui','feature_key'=>'public_ui','path'=>$routePath,'area'=>str_starts_with($name,'console.')?'console':'public','methods'=>['GET'],'authentication'=>'authenticated','response_type'=>'html']));
}
$urls = new \CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteUrlGenerator($routes);
$nativeCatalog = new \CodingPanda\OjPlatform\Kernel\Addon\NativeLearningCatalog($accepted, $fixtureAccounts, $fixturePermissions, $urls, $fixtureFlags);
$assets = new class implements \CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator {
    public function stylesheetUrl(): string { return '/platform-assets/oj-ui-system.css'; }
    public function scriptUrl(): string { return '/platform-assets/oj-ui.js'; }
    public function mathJaxLoaderUrl(): string { return '/platform-assets/mathjax-loader.js'; }
    public function iconSpriteUrl(): string { return '/platform-assets/lucide.svg'; }
    public function faviconUrl(): string { return '/platform-assets/favicon.svg'; }
};
$documents = new \CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer($assets);
$publicShell = (new ReflectionClass(\CodingPanda\OjPlatform\Modules\PublicUi\Shell\PublicShell::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($publicShell,'documents'))->setValue($publicShell,$documents);
$publicMenu = [new \CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuItem('problems.index','문제','/problems'),new \CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuItem('classes.index','수업','/classes')];
$publicPages = new class($documents, $publicShell, $publicMenu) implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder {
    public array $menu;
    public function __construct(private $documents, private $shell, array $menu) { $this->menu=$menu; }
    public function guest(string $title,string $content,\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request,int $status=200): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse { return $this->authenticated($title,$content,$request,$status); }
    public function authenticated(string $title,string $content,\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request,int $status=200): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse {
        $nav=(new ReflectionMethod($this->shell,'renderMenu'))->invoke($this->shell,$this->menu,$request->navigationPath());
        return \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse::html($this->documents->render($title,'<nav class="ojp-public-nav" aria-label="사용자 메뉴">'.$nav.'</nav><main class="ojp-shell">'.$content.'</main>','ojp-app'),$status);
    }
};
$links = new class implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\AccountLinkProvider { public function logoutUrl(string $redirectPath='/login'):string{return '/logout';}public function logoutFields():string{return '';} };
$branding = new class implements \CodingPanda\OjPlatform\Modules\Settings\Contract\SiteBrandingProvider { public function serviceName():string{return 'OJ Platform';}public function shortDescription():string{return '';}public function supportLine():string{return '';} };
$consoleShell = new \CodingPanda\OjPlatform\Modules\AdminConsole\Shell\ConsoleShell($documents,$links,$branding);
$consolePages = new class($consoleShell,$actor) implements \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder {
    public array $menu=[];public string $selected='';
    public function __construct(private $shell,private $actor){}
    public function respond(string $title,string $content,string $currentMenu,\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request,int $status=200):\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse { $this->selected=$currentMenu;return \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse::html($this->shell->render($title,$content,$currentMenu,$this->actor,$this->menu),$status); }
};
$handlers = new \CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry();
$contributor = new \CodingPanda\OjPlatform\Kernel\Addon\AddonRouteContributor($routes,$handlers,$fixtureAccounts,$fixturePermissions,new \CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory(),$urls,$consolePages,$publicPages);
$contributor->contribute($manifest);

$menus = new \CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry('menus',\CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition::class);
foreach(['problems'=>'문제','classes'=>'수업','learning_support'=>'학습 지원'] as $key=>$label){$menus->register(new \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition($key,$label,null,'first_accessible_child',null,[],70,null));}
(new \CodingPanda\OjPlatform\Kernel\Addon\AddonMenuContributor($menus,$publicContributions=new \CodingPanda\OjPlatform\Kernel\Addon\AddonPublicMenuContributions()))->contribute($manifest);
$consoleResolver=new \CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuResolver(new \CodingPanda\OjPlatform\Modules\AdminConsole\Application\MenuTree($menus),$handlers,$urls,$fixtureFlags,$fixturePermissions,new \CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuAccessRuleRegistry());
$consolePages->menu=$consoleResolver->resolve(10);
$menuSettings = new class implements \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager {
    public function read(string $key,mixed $default=null): \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot { return new \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot(1,$default); }
    public function save(string $key,mixed $value,int $expectedRevision): \CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot { throw new LogicException('No menu writes'); }
};
$publicMenuService = new \CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService($menuSettings,$routes,$handlers,$urls,$fixtureFlags,$fixturePermissions,$publicContributions);
foreach($publicMenuService->defaults() as $index=>$entry){ $key=$entry->routeKey();if($key && $key!=='first_accessible_child' && $routes->find($key)===null){$routes->register(\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray(['name'=>$key,'owner_module'=>'public_ui','feature_key'=>'public_ui','path'=>'/menu-fixture-'.$index,'area'=>'public','methods'=>['GET'],'authentication'=>'authenticated','response_type'=>'html']));}}
foreach(['problems.index','classes.index'] as $key){$handlers->register($key,new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler {public function handle($route,$request): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse {throw new LogicException('Menu eligibility stub, direct actual handlers render core bodies');}});}
$learnerActor = new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(10,'learner','learner@example.test','학생',['subscriber']);
$publicPages->menu=$publicMenuService->resolve($learnerActor);

$memory=new MemoryProblemGateway();
$memory->items[1000]=problemFromDraft(new \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId(1000),problemDraft('두 수의 합'));
$memory->items[1001]=problemFromDraft(new \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId(1001),problemDraft('최댓값 찾기'));
$coreProblemAccess=new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemAccessPolicy($fixturePermissions,new MemoryAssignments(),new MemoryProblemAiAvailability([]),new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemContextAccessRegistry());
$catalog=new \CodingPanda\OjPlatform\Modules\Problems\Application\ProblemCatalogService($memory,new MemoryMetadata(),new MemoryAssignments(),$coreProblemAccess,$fixturePermissions);
$coreHandler=new \CodingPanda\OjPlatform\Modules\Problems\Presentation\Route\ProblemListPageHandler($publicPages,$catalog,new \CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml(new MemoryTopics()),new \CodingPanda\OjPlatform\Ui\Component\PaginationView(),$nativeCatalog);
$matcher=new \CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher($routes);
$request=new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET','/problems',['type'=>'addon.oj_microbit.native_learning']);
$coreHtml=$coreHandler->handle($matcher->match('GET','/problems'),$request)->body();
if(str_contains($coreHtml,'data-native-provider')||str_contains($coreHtml,'aria-label="문제 유형"')||!str_contains($coreHtml,'두 수의 합')){throw new RuntimeException('Core problem handler separation failed.');}
file_put_contents($browserHostOutput.'/core-problems.html',$coreHtml);
$classAccounts->actor=10;
$coreClass=new \CodingPanda\OjPlatform\Modules\Classes\Presentation\Route\ClassCatalogPageHandler($publicPages,$classAccounts,$classService,$classActions,$classSecurity,$classHtml,new \CodingPanda\OjPlatform\Ui\Component\PaginationView(),$nativeCatalog);
$classRoute=new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch(\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray(['name'=>'classes.show','owner_module'=>'classes','feature_key'=>'classes','path'=>'/classes/{class_id}','area'=>'public','methods'=>['GET'],'authentication'=>'authenticated','response_type'=>'html']),['class_id'=>'1']);
$classOutput=$coreClass->handle($classRoute,new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET','/classes/1'))->body();
if(str_contains($classOutput,'data-native-provider')){throw new RuntimeException('Core class handler separation failed.');}
file_put_contents($browserHostOutput.'/core-class.html',$classOutput);
$testUserId=10;$testCapabilities=['manage_options'=>true];
$selection=[];
foreach(['microbit-problems'=>'addon.oj_microbit.learn','microbit-lessons'=>'addon.oj_microbit.lessons','microbit-teacher'=>'addon.oj_microbit.teacher','microbit-create'=>'addon.oj_microbit.teacher_new','microbit-teacher-lessons'=>'addon.oj_microbit.teacher_lessons'] as $file=>$route){
    $routePath=$urls->path($route);$response=$handlers->find($route)->handle($matcher->match('GET',$routePath),new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET',$routePath));
    file_put_contents($browserHostOutput.'/'.$file.'.html',$response->body());
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$response->body());$xp=new DOMXPath($dom);
    $selection[$file]=array_map(static fn($node)=>['label'=>trim($node->textContent),'href'=>$node->getAttribute('href')],iterator_to_array($xp->query('//a[@aria-current="page"]')));
}
$createDom = new DOMDocument(); @$createDom->loadHTML('<?xml encoding="UTF-8">' . file_get_contents($browserHostOutput . '/microbit-create.html'));
$createXPath = new DOMXPath($createDom);
$teacherCreateSelection = ['route' => '/oj-console/addons/oj-microbit/new', 'registeredAlias' => null,
    'sidebarOwnProblemSelected' => $createXPath->query('//aside[contains(@class,"ojp-console-sidebar")]//a[@aria-current="page" and @href="/oj-console/addons/oj-microbit"]')->length === 1,
    'localCreateSelected' => $createXPath->query('//nav[contains(@class,"ojp-local-nav")]//a[@aria-current="page" and @href="/oj-console/addons/oj-microbit/new"]')->length === 1,
    'localListUnselected' => $createXPath->query('//nav[contains(@class,"ojp-local-nav")]//a[@aria-current="page" and @href="/oj-console/addons/oj-microbit"]')->length === 0,
    'localCurrentCount' => $createXPath->query('//nav[contains(@class,"ojp-local-nav")]//a[@aria-current="page"]')->length];
if (!$teacherCreateSelection['sidebarOwnProblemSelected'] || !$teacherCreateSelection['localCreateSelected'] || !$teacherCreateSelection['localListUnselected'] || $teacherCreateSelection['localCurrentCount'] !== 1) { throw new RuntimeException('Actual teacher creation shell selection failed.'); }
file_put_contents($browserHostOutput . '/teacher-create-selection.json', json_encode($teacherCreateSelection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($browserHostOutput.'/evidence.json',json_encode(['nativeProviders'=>count($manifest->nativeLearningProviders()),'coreProblems'=>'actual ProblemListPageHandler with actual accepted microbit manifest; no provider','coreClass'=>'actual ClassCatalogPageHandler with actual accepted microbit manifest; no provider','selection'=>$selection,'teacherCreateSelection'=>$teacherCreateSelection],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo 'Actual no-provider core handlers and independent menu producer fixtures generated.' . PHP_EOL;

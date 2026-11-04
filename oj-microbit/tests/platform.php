<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestBuilder;
use OjMicrobit\Addon\PageHandler;
use OjMicrobit\Addon\Registration;
use OjMicrobit\Addon\RestController;
use OjMicrobit\Core\Plugin;
use OjMicrobit\Support\LearnerCapabilities;
use OjMicrobit\Support\PlatformAccess;
use OjMicrobit\Support\Settings;

final class RuntimeFixture implements AddonRuntimeContextV1
{
    public function __construct(private readonly string $status = 'accepted') {}
    public function snapshot(): AddonRuntimeSnapshotV1 { return new AddonRuntimeSnapshotV1('oj-microbit', OJ_MICROBIT_VERSION, '>=2.5.9 <3.0.0', '2.5.9', '^1.9', '1.9.0', $this->status, $this->status === 'accepted' ? '' : 'addon_runtime_unavailable', 'fixture-revision', [], 'fixture-correlation'); }
    public function contexts(): AddonContextGatewayV1 { throw new LogicException('Not used by this addon.'); }
    public function aiTasks(): AddonAiTaskGatewayV1 { throw new LogicException('Not used by this addon.'); }
}
$gateway = new class implements AddonRegistrationGatewayV1 {
    public ?AddonManifestBuilder $builder = null; public string $id = '';
    public function apiVersion(): string { return '1.9.0'; }
    public function register(string $addonId, callable $build): void { $this->id = $addonId; $this->builder = new AddonManifestBuilder(); $build($this->builder); }
};
Registration::build($gateway);
$manifest = $gateway->builder->close();
verify($gateway->id === 'oj-microbit' && $manifest->namespace() === 'oj_microbit', 'Registration constructs real platform manifest');
verify(count($manifest->capabilities()) === 2 && count($manifest->privacyData()) === 1, 'Capabilities and privacy declarations accepted by real value objects');
verify(count($manifest->consolePages()) === 3 && count($manifest->publicPages()) === 2, 'Independent teacher problem, creation and lesson routes plus both learner routes are registered');
verify($manifest->nativeLearningProviders() === [], 'No microbit provider is registered into core programming problem or class lists');
verify(count($manifest->consoleMenus()) === 2 && count($manifest->publicMenus()) === 2, 'Teacher and public problem and lesson menus all have independent registered destinations');
verify(array_map(static fn ($menu) => $menu->fallbackForNativeMenu(), $manifest->consoleMenus()) === [null,null], 'Independent microbit menus remain visible without depending on native problem or class menu access');
verify(array_map(static fn ($page) => $page->navigationMenuKey(), $manifest->consolePages()) === [null,null,null] && array_map(static fn ($page) => $page->navigationMenuKey(), $manifest->publicPages()) === [null,null], 'All microbit pages keep their own menu instead of aliasing core problem or class menus');
verify(array_map(static fn ($item) => [$item->label(),$item->menu(),$item->route()], $manifest->consoleLocalNav()) === [
    ['문제 목록','addon.oj_microbit.teacher_menu','addon.oj_microbit.teacher'],
    ['문제 생성','addon.oj_microbit.teacher_menu','addon.oj_microbit.teacher_new'],
], 'Teacher problem list and creation select distinct registered routes under the independent problem menu');

$accepted = new \CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonCatalog();
$accepted->commitAndFreeze([new \CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry('oj-microbit', $manifest, 'accepted', '')]);
$nativeAccounts = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider {
    public function current(): \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount { return new \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount(new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(20,'teacher','teacher@example.test','교사'), null); }
};
$nativePermissions = new class implements \CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization {
    public function can(int $userId, string $capability, ?\CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext $context = null): bool { return true; }
    public function canAny(int $userId, array $capabilities): bool { return true; }
    public function isSuperAdmin(int $userId): bool { return false; }
};
$nativeUrls = new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider {
    public int $calls = 0;
    public function path(string $routeName, array $parameters = []): string { $this->calls++; throw new LogicException('Core lists must not render microbit links.'); }
};
$nativeCatalog = new \CodingPanda\OjPlatform\Kernel\Addon\NativeLearningCatalog($accepted, $nativeAccounts, $nativePermissions, $nativeUrls);
$nativeRequest = new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET','/problems',['type'=>'addon.oj_microbit.native_learning']);
verify(!$nativeCatalog->hasProviders() && !$nativeCatalog->hasProviders(true) && $nativeCatalog->problemFilters($nativeRequest) === '' && $nativeCatalog->problemFilters($nativeRequest,true) === '', 'Actual accepted microbit catalog adds no filters or providers to standard problem lists');
verify($nativeCatalog->showsProgramming($nativeRequest) && $nativeCatalog->showsProgramming($nativeRequest,true), 'An old microbit type query cannot hide ordinary programming problems after menu separation');
verify($nativeCatalog->problems($nativeRequest) === '' && $nativeCatalog->problems($nativeRequest,true) === '' && $nativeCatalog->classAssignments($nativeRequest,7,false) === '' && $nativeCatalog->classAssignments($nativeRequest,7,true) === '' && $nativeUrls->calls === 0, 'All four core surfaces render no microbit output or links with no registered provider calls');
foreach ($manifest->assetBundles() as $bundle) {
    foreach (array_merge($bundle->styles(), $bundle->scripts()) as $path) { verify(is_file(dirname(__DIR__) . '/' . $path), 'Declared asset exists: ' . $path); }
}
verify($manifest->consolePages()[0]->capability() === 'oj_microbit_teach' && $manifest->publicPages()[0]->capability() === 'oj_microbit_learn', 'Teacher and learner routes use distinct capabilities');
$testUserId = 20; $testCapabilities = [AddonCapabilityKeyV1::wordpress('oj_microbit_learn') => true];
$request = new WP_REST_Request([], ['X-WP-Nonce' => 'valid-rest']);
verify(RestController::authorize($request, false) instanceof WP_Error, 'REST denied until runtime accepted');
($gateway->builder->onReadyCallback())(new RuntimeFixture());
verify(RestController::authorize($request, false) === true, 'Accepted runtime plus learner capability permits REST');
verify(RestController::authorize($request, true) instanceof WP_Error, 'Learner capability does not permit teacher REST');
$testCapabilities[AddonCapabilityKeyV1::wordpress('oj_microbit_teach')] = true;
verify(RestController::authorize($request, true) === true, 'Explicit teacher capability permits management');
$testCapabilities = ['manage_options' => true];
verify(RestController::authorize($request, true) === true, 'Administrator permitted with accepted runtime');
Plugin::connect(new RuntimeFixture('disabled'));
verify(RestController::authorize($request, true) instanceof WP_Error, 'Even administrator needs accepted addon runtime');
Plugin::connect(new RuntimeFixture());
verify(RestController::authorize(new WP_REST_Request([], ['X-WP-Nonce' => 'invalid']), true)->get_error_data()['status'] === 403, 'Bad REST nonce denied');
$testUserId = 0;
verify(RestController::authorize($request, false)->get_error_data()['status'] === 401, 'Anonymous REST denied');
$testUserId = 20; $testCapabilities = [];
throws(fn () => (new PlatformAccess())->requireLearner(), DomainException::class, 'Signed-in user without capability denied');
RestController::registerRoutes();
verify(count($testRoutes) === 35, 'All expected REST routes including bounded-history detail, group and legacy lesson pickers and publication review registered');
foreach ($testRoutes as [$namespace, $route, $definition]) { verify($namespace === 'oj-microbit/v1' && is_callable($definition['permission_callback']), 'Every REST route has an authorization callback'); }
$pageParameters = new ReflectionMethod(RestController::class, 'page');
verify($pageParameters->invoke(null, new WP_REST_Request()) === [0, 20], 'REST listing defaults to twenty rows and newest cursor');
verify($pageParameters->invoke(null, new WP_REST_Request(['beforeId' => '42', 'limit' => '50'])) === [42, 50], 'REST query strings accept bounded integer cursors and limits');
foreach ([['limit' => '51'], ['limit' => '0'], ['beforeId' => '-1'], ['beforeId' => '9223372036854775808'], ['limit' => ['20']], ['beforeId' => '1 OR 1=1']] as $badPage) {
    throws(fn () => $pageParameters->invoke(null, new WP_REST_Request($badPage)), InvalidArgumentException::class, 'Malformed or unbounded REST page rejected before querying');
}

$testOptions[Settings::OPTION] = ['runnerUrl' => 'http://127.0.0.1:3037', 'runnerToken' => str_repeat('s', 40)];
$pageRequest = new AddonPageRequestV1('addon.oj_microbit.learn', 20, [], [], 'ko-KR', 'fixture-correlation');
$page = (new PageHandler('student'))->handle($pageRequest);
verify($page->status() === 200 && $page->layout() === 'wide', 'Page response constructed using real Addon API');
verify(str_contains($page->body(), 'data-oj-microbit') && !str_contains($page->body(), str_repeat('s', 40)) && !str_contains($page->body(), '127.0.0.1'), 'Browser bootstrap contains no runner token or private runner URL');
verify(str_contains($page->body(), 'data-microbit-status'), 'Shared loading status is addressable by frontend');
verify(str_contains($page->body(), 'data-microbit-content'), 'Shared content mount is addressable by frontend');
$teacherNavigation = ['addon.oj_microbit.teacher'=>'/school/oj-console/addons/oj-microbit','addon.oj_microbit.teacher_new'=>'/school/oj-console/addons/oj-microbit/new','addon.oj_microbit.teacher_lessons'=>'/school/oj-console/addons/oj-microbit/lessons'];
$create = (new PageHandler('teacher','problems','new'))->handle(new AddonPageRequestV1('addon.oj_microbit.teacher_new',20,[],[],'ko-KR','creation-fixture',$teacherNavigation));
preg_match('/data-config="([^"]+)"/', $create->body(), $createMatch); $createConfig = json_decode(html_entity_decode($createMatch[1],ENT_QUOTES,'UTF-8'),true,64,JSON_THROW_ON_ERROR);
verify($create->title() === '마이크로비트 문제 생성' && $createConfig['initialView'] === 'new' && $createConfig['initialSection'] === 'problems', 'The registered creation page explicitly boots a new teacher problem without query parameters');
verify($createConfig['nativeNavigation'] === true && $createConfig['createPageUrl'] === $teacherNavigation['addon.oj_microbit.teacher_new'] && $createConfig['problemMenuUrl'] === $teacherNavigation['addon.oj_microbit.teacher'] && $createConfig['lessonMenuUrl'] === $teacherNavigation['addon.oj_microbit.teacher_lessons'], 'Teacher navigation uses independent registry paths without requiring core problem or class URLs');
verify($manifest->consolePages()[1]->key() === 'addon.oj_microbit.teacher_new' && $manifest->consolePages()[1]->capability() === 'oj_microbit_teach', 'The creation route keeps the teacher capability instead of using a learner or core write grant');
$learnerNavigation = ['addon.oj_microbit.learn'=>'/school/oj-addons/oj-microbit/learn','addon.oj_microbit.lessons'=>'/school/oj-addons/oj-microbit/lessons'];
$learnerLesson = (new PageHandler('student','lessons'))->handle(new AddonPageRequestV1('addon.oj_microbit.lessons',20,[],[],'ko-KR','lesson-fixture',$learnerNavigation));
preg_match('/data-config="([^"]+)"/', $learnerLesson->body(), $learnerMatch); $learnerConfig = json_decode(html_entity_decode($learnerMatch[1],ENT_QUOTES,'UTF-8'),true,64,JSON_THROW_ON_ERROR);
verify($learnerConfig['nativeNavigation'] === true && $learnerConfig['lessonMenuUrl'] === $learnerNavigation['addon.oj_microbit.lessons'] && $learnerConfig['createPageUrl'] === '' && $learnerConfig['initialView'] === '', 'Learner lesson navigation stays in its own menu and exposes no teacher creation destination');
throws(fn () => new PageHandler('student','problems','new'), InvalidArgumentException::class, 'A learner cannot be bootstrapped into teacher problem creation');
throws(fn () => new PageHandler('teacher','lessons','new'), InvalidArgumentException::class, 'Creation cannot be bootstrapped as a lesson context');

final class RoleFixture
{
    public function __construct(public array $capabilities = [], public bool $failPersistence = false) {}
    public function add_cap(string $key, bool $grant): void { $this->capabilities[$key] = $grant; if (!$this->failPersistence) { $GLOBALS['testOptions']['wp_user_roles']['subscriber']['capabilities'][$key] = $grant; } }
    public function remove_cap(string $key): void { unset($this->capabilities[$key]); if (!$this->failPersistence) { unset($GLOBALS['testOptions']['wp_user_roles']['subscriber']['capabilities'][$key]); } }
}
function get_role(string $role): ?RoleFixture { return $GLOBALS['testRole'] ?? null; }
function wp_roles(): object { return (object) ['role_key' => 'wp_user_roles']; }
function wp_cache_delete(string $key, string $group): bool { return true; }
function wp_clear_scheduled_hook(string $hook): void {}
$key = AddonCapabilityKeyV1::wordpress('oj_microbit_learn');
$testRole = new RoleFixture(); $testOptions['wp_user_roles'] = ['subscriber' => ['capabilities' => []]];
LearnerCapabilities::apply();
verify(($testOptions['wp_user_roles']['subscriber']['capabilities'][$key] ?? false) === true, 'Learner grant actually persisted');
verify($testOptions['oj_microbit_owned_learner_cap'] === ['status' => 'complete', 'owned' => true], 'Successful grant tracks ownership');
$testRole->failPersistence = true;
verify(LearnerCapabilities::removeOwnedGrant() === false && isset($testOptions['oj_microbit_owned_learner_cap']), 'Failed persistent revoke retains owned marker for retry');
Plugin::deactivate();
verify(isset($testOptions['oj_microbit_cap_cleanup_error']), 'Deactivation records revoke failure');
$testRole->failPersistence = false;
Plugin::deactivate();
verify(!isset($testOptions['oj_microbit_owned_learner_cap'], $testOptions['oj_microbit_cap_cleanup_error']) && !isset($testOptions['wp_user_roles']['subscriber']['capabilities'][$key]), 'Retry revokes persisted cap and clears ownership only after success');
$testRole = new RoleFixture([$key => true]); $testOptions['wp_user_roles']['subscriber']['capabilities'][$key] = true;
LearnerCapabilities::apply();
verify($testOptions['oj_microbit_owned_learner_cap']['owned'] === false, 'Preexisting cap is not claimed by addon');
verify(LearnerCapabilities::removeOwnedGrant() && $testRole->capabilities[$key] === true, 'Preexisting learner grant survives deactivation');
echo '[platform] ' . $testChecks . " checks passed (real Addon API classes; WordPress boundary fixtures)\n";

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require $platformRoot . '/tests/Support/wordpress-stubs.php';

use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\{CompositionRoot, FoundationServiceIds};
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\WordPress\{WordPressHookRegistrar, WordPressErrorReporter};
use CodingPanda\OjPlatform\Kernel\Setup\{SetupCoordinator, SetupMode};
use CodingPanda\OjPlatform\Kernel\Integration\Learning\UnavailableResearchPolicy;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\{SchemaOwnershipMarker, InstallationPlanner, SchemaInstaller};
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Infrastructure\WordPress\WordPressApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Kernel\Addon\AddonExtensionRegistry;
use CodingPanda\OjResearch\Core\ResearchExtension;

function do_action(string $hook, mixed ...$args): void
{
    $callbacks = $GLOBALS['oj_platform_test_actions'][$hook] ?? [];
    usort($callbacks, static fn($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($callbacks as $entry) { ($entry['callback'])(...array_slice($args, 0, $entry['accepted_arguments'])); }
}
function register_activation_hook(string $file, callable $callback): void { $GLOBALS['research_activate'] = $callback; }
function register_deactivation_hook(string $file, callable $callback): void { $GLOBALS['research_deactivate'] = $callback; }
function plugin_basename(string $file): string { return basename(dirname($file)) . '/' . basename($file); }
function plugins_url(string $path, string $file): string { return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/' . $path; }
function wp_die(string $message): never { throw new DomainException($message); }

final class ResearchLifecycleDatabase
{
    public string $dbname = 'wordpress';
    public array $tables = [];
    public array $studies = [];
    public array $participants = [];
    public array $writes = [];
    public bool $fail = false;
    public function db_server_info(): string { return '8.0.36'; }
    public function prepare(string $sql, mixed ...$values): string
    {
        return preg_replace_callback('/%[sd]/', static function ($m) use (&$values): string {
            $v = array_shift($values); return $m[0] === '%d' ? (string)(int)$v : "'" . str_replace("'", "''", (string)$v) . "'";
        }, $sql);
    }
    public function get_results(string $sql, mixed $mode = null): array
    {
        if ($sql === 'SHOW TABLE STATUS') { return array_values($this->tables); }
        if (str_contains($sql, 'FROM research_studies')) { return array_values($this->studies); }
        return [];
    }
    public function get_var(string $sql): mixed
    {
        if ($this->fail) { return null; }
        if (str_contains($sql, 'information_schema.tables')) {
            preg_match("/table_name = '([^']+)'/", $sql, $m);
            return isset($this->tables[$m[1]]) ? 1 : 0;
        }
        if (str_contains($sql, 'FROM research_studies')) { return isset($this->tables['research_studies']) ? count($this->studies) : null; }
        if (str_contains($sql, 'FROM research_participants')) {
            if (! isset($this->tables['research_participants'])) { return null; }
            preg_match('/active_user_id = (\d+)/', $sql, $m);
            return count(array_filter($this->participants, static fn($row) => $row['active_user_id'] === (int)($m[1] ?? 0)));
        }
        return 0;
    }
    public function query(string $sql): int
    {
        $this->writes[] = $sql;
        if (preg_match('/^CREATE TABLE `([^`]+)`.*COMMENT\s*=\s*\x27([^\x27]+)\x27/s', $sql, $m)) {
            $this->tables[$m[1]] = ['Name'=>$m[1], 'Comment'=>$m[2], 'Engine'=>'InnoDB', 'Collation'=>'utf8mb4_unicode_ci'];
        }
        return 1;
    }
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { ++$checks; if (! $condition) { throw new RuntimeException($message); } };
$denied = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); } catch (DomainException|LogicException $error) { $assert(true, $message); return; }
    $assert(false, $message);
};
define('ABSPATH', $platformRoot . '/');
$GLOBALS['wp_version'] = '6.6';
$file = dirname(__DIR__, 2) . '/oj-research.php';
// Both WordPress load orders must work; OJ Platform composes only on plugins_loaded.
if (($argv[1] ?? '') === 'platform-first') { require $platformRoot . '/oj-platform.php'; require $file; }
else { require $file; require $platformRoot . '/oj-platform.php'; }
($GLOBALS['research_activate'])();
$assert(defined('OJ_RESEARCH_VERSION'), 'Separate WordPress entrypoint loads');
$assert(OJ_PLATFORM_ADDON_API_VERSION === '1.2.0' && ! defined('OJ_PLATFORM_RESEARCH_API_VERSION'), 'General and research addons share one API version');

// An unchanged 1.1 addon coexists with the early research extension in the real host boot flow.
$legacyContexts = [];
add_action('oj_platform_register_addons', static function (\CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1 $gateway) use (&$legacyContexts, $platformRoot): void {
    $gateway->register('addon-a', static function (\CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1 $builder) use (&$legacyContexts, $platformRoot): void {
        $builder->manifest('addonns_a', '1.0.0', $platformRoot . '/tests/Fixtures/addons/addon-a/plugin.php', '>=2.0.0-dev.1 <3.0.0', '^1.1.0', 'Existing addon', 'example.test/addon-a');
        $builder->aiTask(new \CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1('addon.addonns_a.legacy', 'Legacy task', '1.0', 'none'));
        $builder->onReady(static function (\CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1 $context) use (&$legacyContexts): void { $legacyContexts[] = $context; });
    });
});

$db = new ResearchLifecycleDatabase();
$GLOBALS['wpdb'] = $db;
$installationId = str_repeat('a', 32);
$GLOBALS['oj_platform_test_options']['oj_platform_installation_id'] = ['revision'=>1, 'value'=>$installationId];
$GLOBALS['oj_platform_test_options']['oj_platform_install_state'] = ['revision'=>1, 'value'=>['status'=>'complete']];
$schemaFiles = [...glob($platformRoot . '/config/*-schema.php'), dirname($file) . '/config/schema.php'];
$bundledSchema = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/bundled-schema-2.0.4.json'), true, 512, JSON_THROW_ON_ERROR);
$assert((require dirname($file) . '/config/schema.php') === $bundledSchema, 'Plugin schema exactly matches the independently captured OJ Platform 2.0.4 schema');
foreach ($schemaFiles as $path) {
    foreach (require $path as $definition) {
        $schema = SchemaTableDefinition::fromArray($definition);
        $db->tables[$schema->key()] = ['Name'=>$schema->key(), 'Comment'=>(new SchemaOwnershipMarker())->create($installationId, $schema), 'Engine'=>'InnoDB', 'Collation'=>'utf8mb4_unicode_ci'];
    }
}
$db->studies = [['id'=>7, 'owner_id'=>1, 'status'=>'practice', 'data'=>json_encode(['protocol'=>['raters'=>[1], 'snapshot'=>'preserved']])]];
$db->participants = [['active_user_id'=>10, 'baseline'=>['level'=>'intermediate','algorithm_version'=>'original'], 'arm'=>'A']];
$original = serialize([$db->tables, $db->studies, $db->participants]);
$build = static function () use ($platformRoot) {
    $root = new CompositionRoot(PluginEnvironment::fromPluginFile($platformRoot . '/oj-platform.php', OJ_PLATFORM_VERSION, '6.6', '8.1'), new WordPressHookRegistrar(), new WordPressErrorReporter());
    $container = $root->build(); $container->get(Kernel::class)->boot(); return $container;
};
$container = $build();
$assert(count($legacyContexts) === 1 && $legacyContexts[0]->snapshot()->status() === 'accepted', 'Existing addon register/onReady are invoked once alongside research');
$assert($legacyContexts[0]->snapshot()->requiredAddonApi() === '^1.1.0' && $legacyContexts[0]->snapshot()->actualAddonApi() === '1.2.0', 'An existing addon executes against API 1.2 without rewriting its manifest');
$assert($legacyContexts[0]->contexts() instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1 && $legacyContexts[0]->aiTasks() instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1, 'Existing Context and AI task gateways remain available');
$assert($legacyContexts[0] instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonEditorRuntimeContextV1 && $legacyContexts[0]->editor() instanceof \CodingPanda\OjPlatform\AddonApi\V1\AddonEditorGatewayV1, 'API 1.1 editor extension remains available');
$assert($container->get(FoundationServiceIds::TABLE_SCHEMAS)->count() === 52, 'Companion contributes its three schemas before registry freeze');
$catalog = $container->get(\CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCatalog::class);
$assert(count($catalog->forWave(\CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWave::Research)) === 3, 'Legacy migration includes companion tables when active');
$assert($container->get(FoundationServiceIds::ROUTES)->find('console.research.new') !== null, 'Research UI connected through host routes');
$assert($container->get(FoundationServiceIds::ACTIONS)->find('oj_research_command') !== null, 'Authenticated actions connected');
$assert($container->get(SetupCoordinator::class)->inspect()->mode() === SetupMode::AlreadyInstalled, 'Existing bundled research tables are adopted without migration');
$assert($db->writes === [] && $original === serialize([$db->tables,$db->studies,$db->participants]), 'Adoption never rewrites studies, assignments, levels or schema');
$denied($GLOBALS['research_deactivate'], 'Active study prevents accidental WordPress deactivation');
$db->studies[0]['status'] = 'archived';
($GLOBALS['research_deactivate'])();
$assert($db->writes === [], 'Inactive study permits deactivation without deleting data');
$db->studies[0]['status'] = 'practice';

// Simulate plugin removed/disabled: host must boot with no class or configuration dependency on it.
$saved = $GLOBALS['oj_platform_test_actions']['oj_platform_register_extensions'];
unset($GLOBALS['oj_platform_test_actions']['oj_platform_register_extensions']);
$without = $build();
$assert(count($legacyContexts) === 2 && $legacyContexts[1]->snapshot()->status() === 'accepted', 'Existing addons also boot when the research plugin is absent');
$assert($without->get(FoundationServiceIds::TABLE_SCHEMAS)->count() === 49, 'Host-only installation has no research schemas');
$assert($without->get(\CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCatalog::class)->forWave(\CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWave::Research) === [], 'Legacy migration does not request unavailable companion schemas');
$assert($without->get(FoundationServiceIds::ROUTES)->find('research.index') === null, 'Inactive companion contributes no research routes');
$assert($without->get(FoundationServiceIds::FEATURES)->find('research') === null, 'Inactive companion contributes no management switch');
$assert($without->get(SetupCoordinator::class)->inspect()->mode() === SetupMode::AlreadyInstalled, 'Dormant retained tables do not block ordinary OJ boot');
$policy = new UnavailableResearchPolicy(new WordPressApplicationDatabase($db));
$denied(fn() => $policy->assertAiAccess(10), 'Missing companion cannot release research learner AI restrictions');
$denied(fn() => $policy->bindSubmission(10, 1000, 900), 'Unrecorded research submission is not published');
$denied(fn() => $policy->recommendationIds(10), 'Missing companion cannot substitute normal recommendations');
$assert($policy->recommendationIds(999) === null, 'Unrelated learner retains normal recommendations');
$submission = new AiFeedbackSubmission(900,1000,999,0,6,0,0,'2026-09-16 00:00:00','print(1)','','',true,false);
$assert($policy->context($submission) === null, 'Ordinary feedback works with companion disabled');
$denied(fn() => $policy->record([], 'generated', []), 'Late research event is not silently dropped');
$assert($policy->blockers(999) !== [], 'Account erasure does not skip dormant research data');
$db->fail = true; $denied(fn() => $policy->assertAiAccess(999), 'Read failure cannot bypass protection'); $db->fail = false;

$GLOBALS['oj_platform_test_actions']['oj_platform_register_extensions'] = $saved;
$again = $build();
$assert($again->get(SetupCoordinator::class)->inspect()->mode() === SetupMode::AlreadyInstalled, 'Reactivation reconnects the same data');
$assert($original === serialize([$db->tables,$db->studies,$db->participants]), 'Disable/reactivate round-trip preserves stored baseline and allocation exactly');

// A site that never installed research gets only three additive tables, using existing OJ installer.
$researchTables = ['research_studies','research_participants','research_records'];
foreach ($researchTables as $name) { unset($db->tables[$name]); }
$db->studies = []; $db->participants = [];
$assert($policy->context($submission) === null, 'Never-installed research does not disable ordinary feedback');
$assert($policy->blockers(999) === [], 'Never-installed research does not block erasure');
$new = $build();
($GLOBALS['research_deactivate'])();
$assert($db->writes === [], 'Unfinished first installation can be deactivated without a missing-table query');
$assert($new->get(SetupCoordinator::class)->inspect()->mode() === SetupMode::Resume, 'New plugin requests additive setup, not legacy migration');
$plan = $new->get(InstallationPlanner::class)->plan($installationId);
$installed = $new->get(SchemaInstaller::class)->execute($plan);
$created = $installed->createdTables(); sort($created); sort($researchTables);
$assert($created === $researchTables, 'Install creates exactly the three plugin tables');
$assert(count($db->writes) === 3, 'Additive installation does not rewrite any host table');
$assert($new->get(SetupCoordinator::class)->inspect()->mode() === SetupMode::AlreadyInstalled, 'Research installation restores normal runtime');
$r = new AddonExtensionRegistry('1.2.0'); $extension = new ResearchExtension($file); $r->registerResearch($extension);
$denied(fn() => $r->registerResearch($extension), 'Duplicate research providers rejected'); $r->close();
$denied(fn() => $r->registerResearch($extension), 'Late research provider rejected');
printf("Research plugin lifecycle (%s): %d checks passed\n", $argv[1] ?? 'research-first', $checks);

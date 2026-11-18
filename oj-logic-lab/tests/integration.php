<?php
declare(strict_types=1);
use CodingPanda\OjPlatform\AddonApi\V1 as Api;
$root = dirname(__DIR__);
$platform = dirname($root) . '/oj-integration-rebuild/oj-platform';
require_once $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
require_once $root . '/src/Core/Autoloader.php';
\OjLogicLab\Core\Autoloader::register();
define('OJ_LOGIC_LAB_VERSION', '1.0.2');
define('OJ_LOGIC_LAB_FILE', $root . '/oj-logic-lab.php');
function home_url(string $path): string { return 'https://example.test' . $path; }
function get_current_user_id(): int { return 7; }
$builder = new class implements Api\AddonManifestBuilderV1 {
    public array $items = [];
    public function onReady(callable $callback): void {}
    public function manifest(string $namespace, string $version, string $pluginFile, string $requiresPlatform, string $requiresAddonApi, string $diagnosticLabel, string $supportReference): void { $this->items['manifest'] = func_get_args(); }
    public function capability(Api\AddonCapabilityDefinitionV1 $definition): void { $this->items['capability'] = $definition; }
    public function consolePage(Api\AddonConsolePageDefinitionV1 $definition): void { throw new LogicException(); }
    public function publicPage(Api\AddonPublicPageDefinitionV1 $definition): void { $this->items['page'] = $definition; }
    public function consoleMenu(Api\AddonConsoleMenuDefinitionV1 $definition): void { throw new LogicException(); }
    public function publicMenu(Api\AddonPublicMenuDefinitionV1 $definition): void { $this->items['menu'] = $definition; }
    public function assetBundle(Api\AddonAssetBundleDefinitionV1 $definition): void { $this->items['assets'] = $definition; }
    public function aiTask(Api\AddonAiTaskDefinitionV1 $definition): void { throw new LogicException(); }
};
$gateway = new class($builder) implements Api\AddonRegistrationGatewayV1 {
    public function __construct(private Api\AddonManifestBuilderV1 $builder) {}
    public function apiVersion(): string { return '1.9.0'; }
    public function register(string $addonId, callable $build): void { if ($addonId !== 'oj-logic-lab') throw new RuntimeException('addon id'); $build($this->builder); }
};
\OjLogicLab\Addon\Registration::build($gateway);
if ($builder->items['page']->capability() !== $builder->items['capability']->key()) throw new RuntimeException('capability mismatch');
foreach (array_merge($builder->items['assets']->styles(), $builder->items['assets']->scripts()) as $asset) if (!is_file($root . '/' . $asset)) throw new RuntimeException('missing asset');
$request = new Api\AddonPageRequestV1('addon.oj_logic_lab.learn', 7, [], [], 'ko_KR', 'test:logic');
$response = $builder->items['page']->handler()->handle($request);
if ($response->status() !== 200 || $response->layout() !== 'immersive' || !str_contains($response->body(), 'data-logic-lab')) throw new RuntimeException('page response');
$metadata = require $root . '/plugin-package.php';
if ($metadata['version'] !== OJ_LOGIC_LAB_VERSION) throw new RuntimeException('version mismatch');
echo "Addon API definitions, capability, asset paths, page response and version checks passed.\n";

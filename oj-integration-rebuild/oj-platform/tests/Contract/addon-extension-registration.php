<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonExtensionRegistrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchExtensionV1;
use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchHostV1;
use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchLearningDataV1;
use CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchRuntimeV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonExtensionRegistry;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressAddonExtensionLoader;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($root);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
};
$denied = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); } catch (LogicException) { $assert(true, $message); return; }
    $assert(false, $message);
};
$extension = new class implements ResearchExtensionV1 {
    public function configuration(): array { return []; }
    public function build(ResearchHostV1 $host): ResearchRuntimeV1 { throw new LogicException('Not built during registration'); }
};

$empty = new AddonExtensionRegistry('1.2.0');
$assert($empty instanceof AddonExtensionRegistrationGatewayV1, 'Only the common public gateway is required');
$assert($empty->apiVersion() === '1.2.0', 'Extension gateway reports the common API version');
$denied(fn() => $empty->research(), 'Unfinished registration cannot be composed');
$empty->close();
$assert($empty->research() === null, 'Research is optional for ordinary OJ sites');
$denied(fn() => $empty->registerResearch($extension), 'Late registration cannot mutate the host');
$denied(fn() => $empty->close(), 'Double close is rejected');

foreach (['1.2.0', '1.3.0', '1.4.0'] as $version) {
    $registry = new AddonExtensionRegistry($version);
    $registry->registerResearch($extension);
    $denied(fn() => $registry->registerResearch($extension), 'Duplicate research providers cannot overwrite policies');
    $registry->close();
    $assert($registry->research() === $extension, 'The registered provider is preserved');
    $denied(fn() => $registry->registerResearch($extension), 'Captured handles cannot replace composed policies');
}
foreach (['1.1.0', '1.2.0-dev.1', '2.0.0'] as $version) {
    $registry = new AddonExtensionRegistry($version);
    $denied(fn() => $registry->registerResearch($extension), 'Unsupported API versions cannot accept research providers');
    $registry->close();
    $assert($registry->research() === null, 'Incompatible registration does not leave a partial provider');
}

// Exercise the actual WordPress adapter, including a callback that fails after capturing its handle.
$hooks = [];
$callback = static function (AddonExtensionRegistrationGatewayV1 $gateway) use ($extension): void {
    $gateway->registerResearch($extension);
};
function do_action(string $name, mixed ...$arguments): void
{
    global $hooks, $callback;
    $hooks[] = $name;
    $callback(...$arguments);
}
$loaded = (new WordPressAddonExtensionLoader())->load('1.2.0');
$assert($loaded->research() === $extension, 'Early common hook loads the research extension');
$assert($hooks === ['oj_platform_register_extensions'], 'No separately versioned research hook is published');
$denied(fn() => $loaded->registerResearch($extension), 'Loader freezes successful registration');
$captured = null;
$callback = static function (AddonExtensionRegistrationGatewayV1 $gateway) use (&$captured): void {
    $captured = $gateway;
    throw new DomainException('Extension failed');
};
try {
    (new WordPressAddonExtensionLoader())->load('1.2.0');
    $assert(false, 'Extension failure must not silently release learning restrictions');
} catch (DomainException $error) {
    $assert($error->getMessage() === 'Extension failed', 'Original registration failure is propagated');
}
$denied(fn() => $captured->registerResearch($extension), 'Failed registration also freezes captured handles');

$symbols = [ResearchExtensionV1::class, ResearchHostV1::class, ResearchLearningDataV1::class, ResearchRuntimeV1::class];
$expected = [];
foreach ($symbols as $symbol) {
    $reflection = new ReflectionClass($symbol);
    $assert(str_starts_with($symbol, 'CodingPanda\\OjPlatform\\AddonApi\\V1\\Research\\'), 'Research contracts belong to common major version 1');
    $expected[] = $reflection->getShortName() . '.php';
}
$actual = array_map('basename', glob($root . '/src/AddonApi/V1/Research/*.php'));
sort($expected); sort($actual);
$assert($actual === $expected, 'All research API symbols are explicitly covered');
$assert(! is_dir($root . '/src/ResearchApi'), 'There is no separate research API tree');
$assert(! str_contains(file_get_contents($root . '/oj-platform.php'), 'OJ_PLATFORM_RESEARCH_API_VERSION'), 'There is only one API version constant');
printf("Addon API 1.2 optional extension contract: %d checks passed\n", $checks);

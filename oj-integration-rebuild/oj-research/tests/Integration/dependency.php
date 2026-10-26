<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/Core/Autoloader.php';
\CodingPanda\OjResearch\Core\Autoloader::register(dirname(__DIR__, 2));
function wp_die(string $message): never { throw new DomainException($message); }
$case = $argv[1] ?? 'missing';
if ($case !== 'missing') {
    define('OJ_PLATFORM_ADDON_API_VERSION', $case === 'missing-contract' ? '1.2.0' : $case);
    if ($case !== 'missing-contract') {
        $platform = dirname(__DIR__, 3) . '/oj-platform';
        require $platform . '/src/Kernel/Autoloader.php';
        \CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
    }
}
$plugin = new \CodingPanda\OjResearch\Core\Plugin(dirname(__DIR__, 2) . '/oj-research.php');
$expected = in_array($case, ['1.2.0', '1.3.0'], true);
if ($plugin->compatible() !== $expected) { throw new RuntimeException('Wrong dependency decision for ' . $case); }
if ($expected) {
    $plugin->activate();
} else {
    try { $plugin->activate(); throw new RuntimeException('Incompatible platform must not load research'); }
    catch (DomainException $error) { if (! str_contains($error->getMessage(), '1.2')) { throw $error; } }
}
echo "Research dependency guard ({$case}): 2 checks passed\n";

<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Setup\SetupScreen;

$pluginRoot = dirname(__DIR__, 2);
$GLOBALS['wp_version'] = '6.6';

if (! defined('ABSPATH')) {
    define('ABSPATH', $pluginRoot . DIRECTORY_SEPARATOR);
}

require $pluginRoot . '/tests/Support/wordpress-stubs.php';
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

final class VisualHookRegistrar implements HookRegistrar
{
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 0
    ): void {
    }
}

final class VisualErrorReporter implements ErrorReporter
{
    public function report(Throwable $error): void
    {
    }
}

$environment = PluginEnvironment::fromPluginFile(
    $pluginRoot . '/oj-platform.php',
    '2.0.0-dev.102',
    '6.6',
    '8.1'
);
$container = (new CompositionRoot(
    $environment,
    new VisualHookRegistrar(),
    new VisualErrorReporter()
))->build();
$kernel = $container->get(Kernel::class);
if (! $kernel instanceof Kernel) {
    throw new RuntimeException('Kernel 서비스를 찾지 못했습니다.');
}
$kernel->boot();

$screen = $container->get(SetupScreen::class);
if (! $screen instanceof SetupScreen || ! method_exists($screen, 'render')) {
    throw new RuntimeException('설치 화면을 렌더링할 수 없습니다.');
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OJ Platform 설치 화면 검사</title>
  <link rel="stylesheet" href="/resources/ui/css/oj-ui-system.css">
</head>
<body>
<?php $screen->render(); ?>
<script src="/resources/ui/js/oj-ui.js"></script>
</body>
</html>

<?php
declare(strict_types=1);
$platformRoot = dirname(__DIR__, 2) . '/oj-platform';
require_once $platformRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platformRoot);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\CodingPanda\OjResearch\Core\Autoloader::register(dirname(__DIR__));

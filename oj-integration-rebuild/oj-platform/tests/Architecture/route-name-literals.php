<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$sourceRoot = $pluginRoot . '/src';
$moduleRoot = $pluginRoot . '/modules';
$errors = [];
$checkedCallCount = 0;

$routes = require $pluginRoot . '/config/routes.php';
$registeredRouteNames = array_flip(array_column($routes, 'name'));

$sourceFiles = [];
foreach ([$sourceRoot, $moduleRoot] as $root) {
    if (! is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($pluginRoot) + 1));
        if (str_contains($relativePath, '/tests/')) {
            continue;
        }

        $sourceFiles[] = $file->getPathname();
    }
}

sort($sourceFiles);

$lineNumberAt = static function (string $contents, int $offset): int {
    return substr_count(substr($contents, 0, $offset), "\n") + 1;
};

foreach ($sourceFiles as $sourceFile) {
    $relativePath = str_replace('\\', '/', substr($sourceFile, strlen($pluginRoot) + 1));
    $contents = (string) file_get_contents($sourceFile);

    $callSites = [];

    // RouteTarget::route('...') is unambiguous: its only literal-string argument is a route name.
    if (preg_match_all(
        '/RouteTarget::route\(\s*[\'"]([a-z][a-z0-9_.]*)[\'"]/',
        $contents,
        $matches,
        PREG_OFFSET_CAPTURE
    ) > 0) {
        foreach ($matches[1] as [$routeName, $offset]) {
            $callSites[] = [$routeName, $offset];
        }
    }

    // ->path('...') is ambiguous elsewhere in the codebase (filesystem path builders, Route::path()
    // accessors), so only treat it as a route lookup in files that actually depend on
    // RouteUrlProvider (via import or inline type reference).
    if (
        str_contains($contents, 'RouteUrlProvider')
        && preg_match_all(
            '/->path\(\s*[\'"]([a-z][a-z0-9_.]*)[\'"]/',
            $contents,
            $matches,
            PREG_OFFSET_CAPTURE
        ) > 0
    ) {
        foreach ($matches[1] as [$routeName, $offset]) {
            $callSites[] = [$routeName, $offset];
        }
    }

    foreach ($callSites as [$routeName, $offset]) {
        ++$checkedCallCount;
        if (! isset($registeredRouteNames[$routeName])) {
            $errors[] = sprintf(
                '%s:%d에서 등록되지 않은 Route 이름을 사용합니다: %s',
                $relativePath,
                $lineNumberAt($contents, $offset),
                $routeName
            );
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Route 이름 무결성 검사 실패\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "Route 이름 무결성 검사 통과: 등록 Route %d개, 리터럴 참조 %d개, 미등록 참조 0개\n",
        count($registeredRouteNames),
        $checkedCallCount
    )
);

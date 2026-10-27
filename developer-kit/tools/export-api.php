<?php

declare(strict_types=1);

// Read public contracts without booting WordPress or the platform.
$platform = dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform';
$check = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--check') {
        $check = true;
    } elseif (str_starts_with($argument, '--platform=')) {
        $platform = substr($argument, strlen('--platform='));
    } else {
        fwrite(STDERR, "Usage: php export-api.php [--check] [--platform=/path/to/oj-platform]\n");
        exit(2);
    }
}
$platform = rtrim($platform, '/\\');
$main = file_get_contents($platform . '/oj-platform.php');
if ($main === false
    || ! preg_match("/define\('OJ_PLATFORM_VERSION',\s*'([^']+)'\)/", $main, $platformMatch)
    || ! preg_match("/define\('OJ_PLATFORM_ADDON_API_VERSION',\s*'([^']+)'\)/", $main, $apiMatch)) {
    throw new RuntimeException('Cannot read platform and Addon API versions.');
}
require_once $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);

function apiType(?ReflectionType $type): ?string
{
    return $type === null ? null : (string) $type;
}

function apiDoc(ReflectionClass|ReflectionMethod $reflection): ?string
{
    $comment = $reflection->getDocComment();
    if ($comment === false) {
        return null;
    }
    $comment = preg_replace('/^\s*\/\*\*\s*|\s*\*\/\s*$/', '', $comment);
    return trim(preg_replace('/^\s*\* ?/m', '', str_replace("\r\n", "\n", $comment)));
}

$apiRoot = $platform . '/src/AddonApi/V1';
$symbols = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($apiRoot) + 1));
    if ($file->getExtension() !== 'php' || str_starts_with($relative, 'Internal/')) {
        continue;
    }
    $name = 'CodingPanda\\OjPlatform\\AddonApi\\V1\\'
        . str_replace('/', '\\', substr($relative, 0, -4));
    $reflection = new ReflectionClass($name);
    $methods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $entry = [
                'name' => $parameter->getName(),
                'type' => apiType($parameter->getType()),
                'required' => ! $parameter->isOptional(),
                'byReference' => $parameter->isPassedByReference(),
                'variadic' => $parameter->isVariadic(),
            ];
            if ($parameter->isDefaultValueAvailable()) {
                $entry['default'] = $parameter->getDefaultValue();
                if ($parameter->isDefaultValueConstant()) {
                    $entry['defaultConstant'] = $parameter->getDefaultValueConstantName();
                }
            }
            $parameters[] = $entry;
        }
        $methods[] = [
            'name' => $method->getName(),
            'declaredBy' => $method->getDeclaringClass()->getName(),
            'static' => $method->isStatic(),
            'parameters' => $parameters,
            'returnType' => apiType($method->getReturnType()),
            'doc' => apiDoc($method),
        ];
    }
    usort($methods, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    $interfaces = $reflection->getInterfaceNames();
    sort($interfaces);
    $source = str_replace("\r\n", "\n", file_get_contents($file->getPathname()));
    $symbols[$name] = [
        'name' => $name,
        'kind' => $reflection->isInterface() ? 'interface' : 'class',
        'final' => $reflection->isFinal(),
        'interfaces' => $interfaces,
        'parent' => ($reflection->getParentClass() ?: null)?->getName(),
        'source' => 'src/AddonApi/V1/' . $relative,
        'sourceSha256' => hash('sha256', $source),
        'doc' => apiDoc($reflection),
        'constants' => $reflection->getConstants(ReflectionClassConstant::IS_PUBLIC),
        'methods' => $methods,
    ];
}
ksort($symbols);
$catalog = [
    'schemaVersion' => 1,
    'platformVersion' => $platformMatch[1],
    'addonApiVersion' => $apiMatch[1],
    'namespace' => 'CodingPanda\\OjPlatform\\AddonApi\\V1',
    'sourceHashFormat' => 'SHA-256 of UTF-8 source with LF line endings',
    'symbolCount' => count($symbols),
    'symbols' => array_values($symbols),
];
$json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$output = dirname(__DIR__) . '/api-reference.json';
if ($check) {
    if (! is_file($output) || file_get_contents($output) !== $json) {
        fwrite(STDERR, "API catalog differs from the platform source. Run export-api.php.\n");
        exit(1);
    }
    printf("API catalog verified: %d public symbols, platform %s, Addon API %s\n", count($symbols), $platformMatch[1], $apiMatch[1]);
} else {
    if (file_put_contents($output, $json) === false) {
        throw new RuntimeException('Cannot write API catalog.');
    }
    printf("API catalog exported: %d public symbols\n", count($symbols));
}

<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$sourceRoot = $pluginRoot . '/src';
$moduleRoot = $pluginRoot . '/modules';
$entryPath = $pluginRoot . '/oj-platform.php';
$errors = [];
$sourceFiles = [];

foreach ([$sourceRoot, $moduleRoot] as $root) {
    if (! is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $sourceFiles[] = $file->getPathname();
        }
    }
}

sort($sourceFiles);

$requiredFiles = [
    'src/Kernel/Autoloader.php',
    'src/Kernel/Bootstrap.php',
    'src/Kernel/Boot/Kernel.php',
    'src/Kernel/Composition/BuildContainer.php',
    'src/Kernel/Composition/CompositionRoot.php',
    'src/Kernel/Module/ModuleGraph.php',
    'src/Kernel/Registry/RegistrySet.php',
    'src/Shared/Result/Result.php',
];

foreach ($requiredFiles as $requiredFile) {
    if (! is_file($pluginRoot . '/' . $requiredFile)) {
        $errors[] = sprintf('R-02 필수 파일이 없습니다: %s', $requiredFile);
    }
}

$wordpressCalls = [
    '/\badd_action\s*\(/',
    '/\badd_management_page\s*\(/',
    '/\badd_option\s*\(/',
    '/\badd_role\s*\(/',
    '/\badmin_url\s*\(/',
    '/\bcheck_admin_referer\s*\(/',
    '/\bcurrent_user_can\s*\(/',
    '/\bdelete_option\s*\(/',
    '/\besc_html\s*\(/',
    '/\berror_log\s*\(/',
    '/\bget_option\s*\(/',
    '/\bget_role\s*\(/',
    '/\bis_super_admin\s*\(/',
    '/\bis_multisite\s*\(/',
    '/\bplugins_url\s*\(/',
    '/\bupdate_option\s*\(/',
    '/\buser_can\s*\(/',
    '/\bwp_die\s*\(/',
    '/\bwp_enqueue_style\s*\(/',
    '/\bwp_enqueue_script\s*\(/',
    '/\bwp_nonce_field\s*\(/',
    '/\bwp_safe_redirect\s*\(/',
    '/\$GLOBALS\s*\[/',
];

foreach ($sourceFiles as $sourceFile) {
    $relativePath = str_replace('\\', '/', substr($sourceFile, strlen($pluginRoot) + 1));
    $contents = (string) file_get_contents($sourceFile);

    if (preg_match('//u', $contents) !== 1) {
        $errors[] = sprintf('UTF-8이 아닌 PHP 파일입니다: %s', $relativePath);
    }

    if (! str_contains($contents, 'declare(strict_types=1);')) {
        $errors[] = sprintf('strict_types가 없습니다: %s', $relativePath);
    }

    if (! str_contains($contents, 'namespace CodingPanda\\OjPlatform\\')) {
        $errors[] = sprintf('목표 namespace가 없습니다: %s', $relativePath);
    }

    if (preg_match('/\\\\u[0-9a-f]{4}/i', $contents) === 1) {
        $errors[] = sprintf('유니코드 이스케이프 문구가 있습니다: %s', $relativePath);
    }

    if (
        str_contains($contents, 'CodingPanda\\OjPlatform\\Core\\')
        || str_contains($contents, 'CodingPanda\\OjPlatform\\Infrastructure\\')
    ) {
        $errors[] = sprintf('레거시 namespace 참조가 있습니다: %s', $relativePath);
    }

    $isWordPressAdapter = str_starts_with($relativePath, 'src/Kernel/WordPress/')
        || str_starts_with($relativePath, 'src/Kernel/Setup/WordPress/')
        || str_starts_with($relativePath, 'src/Ui/Infrastructure/WordPress/')
        || (
            str_starts_with($relativePath, 'modules/')
            && str_contains($relativePath, '/Infrastructure/WordPress/')
        )
        || (
            str_starts_with($relativePath, 'src/Kernel/Addon/')
            && str_starts_with(basename($relativePath), 'WordPress')
        );
    if (! $isWordPressAdapter) {
        foreach ($wordpressCalls as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $errors[] = sprintf('WordPress 전역 호출이 Adapter 밖에 있습니다: %s', $relativePath);
                break;
            }
        }
    }

    $isCompositionCode = str_starts_with($relativePath, 'src/Kernel/Composition/');
    if (! $isCompositionCode && str_contains($contents, 'BuildContainer')) {
        $errors[] = sprintf('BuildContainer가 조립 경계 밖에서 사용됩니다: %s', $relativePath);
    }
}

$entryContents = is_file($entryPath) ? (string) file_get_contents($entryPath) : '';
foreach (['register_activation_hook', 'dbDelta', 'CREATE TABLE', '$wpdb'] as $forbiddenEntryFragment) {
    if (str_contains($entryContents, $forbiddenEntryFragment)) {
        $errors[] = sprintf('진입 파일에 금지된 설치 동작이 있습니다: %s', $forbiddenEntryFragment);
    }
}

if (substr_count($entryContents, 'Bootstrap::start(') !== 1) {
    $errors[] = '진입 파일은 단일 Composition Root 호출만 가져야 합니다.';
}

require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

foreach ($sourceFiles as $sourceFile) {
    $contents = (string) file_get_contents($sourceFile);
    if (
        preg_match(
            '/namespace\s+([^;]+);.*?\b(?:final\s+)?(?:class|interface|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/s',
            $contents,
            $matches
        ) !== 1
    ) {
        $errors[] = sprintf(
            'autoload 검사 대상 타입을 찾지 못했습니다: %s',
            str_replace('\\', '/', substr($sourceFile, strlen($pluginRoot) + 1))
        );
        continue;
    }

    $type = trim($matches[1]) . '\\' . $matches[2];
    if (! class_exists($type) && ! interface_exists($type) && ! enum_exists($type)) {
        $errors[] = sprintf('PSR-4 autoload에 실패했습니다: %s', $type);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "R-02 구조 검사 실패\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "R-03 구조 검사 통과: PHP 타입 %d개, 레거시 namespace 0개, 조립 경계 위반 0개, 활성화 DDL 0개\n",
        count($sourceFiles)
    )
);

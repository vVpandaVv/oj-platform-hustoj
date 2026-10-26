<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$baselineManifest = $pluginRoot . '/docs/artifacts/i-00/files.json';
$errors = [];

$expectedDirectories = [
    'config',
    'src/Kernel',
    'src/Shared',
    'modules',
    'resources/ui',
    'resources/public',
    'resources/console',
    'resources/features',
    'templates',
    'tests/Architecture',
    'tests/Contract',
    'tests/Unit',
    'tests/Integration',
    'tests/E2E',
    'tools',
];

foreach ($expectedDirectories as $relativePath) {
    if (! is_dir($pluginRoot . '/' . $relativePath)) {
        $errors[] = sprintf('필수 디렉터리가 없습니다: %s', $relativePath);
    }
}

$forbiddenLegacyPaths = [
    'README.md',
    'languages',
    'src/Contract',
    'src/Core',
    'src/Infrastructure',
    'resources/prompts',
    'resources/vendor',
];

foreach ($forbiddenLegacyPaths as $relativePath) {
    if (file_exists($pluginRoot . '/' . $relativePath)) {
        $errors[] = sprintf('레거시 경로가 남아 있습니다: %s', $relativePath);
    }
}

$entryPath = $pluginRoot . '/oj-platform.php';
if (! is_file($entryPath)) {
    $errors[] = 'WordPress 플러그인 진입 파일이 없습니다.';
} else {
    $entryContents = (string) file_get_contents($entryPath);
    foreach ([
        'Plugin Name: OJ Platform',
        'Text Domain: oj-platform',
        'Requires PHP: 8.3',
    ] as $requiredEntryFragment) {
        if (! str_contains($entryContents, $requiredEntryFragment)) {
            $errors[] = sprintf('플러그인 진입 계약이 없습니다: %s', $requiredEntryFragment);
        }
    }

    preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $entryContents, $headerVersion);
    preg_match("/define\('OJ_PLATFORM_VERSION',\s*'([^']+)'\)/", $entryContents, $runtimeVersion);
    if (
        ! isset($headerVersion[1], $runtimeVersion[1])
        || $headerVersion[1] !== $runtimeVersion[1]
        || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $headerVersion[1]) !== 1
    ) {
        $errors[] = '플러그인 헤더와 런타임 버전은 동일한 유효 버전이어야 합니다.';
    }

    foreach (['src/Core/', 'src/Infrastructure/', 'modules/AI/'] as $legacyReference) {
        if (str_contains($entryContents, $legacyReference)) {
            $errors[] = sprintf('진입 파일이 레거시 경로를 참조합니다: %s', $legacyReference);
        }
    }
}

$composerPath = $pluginRoot . '/composer.json';
if (! is_file($composerPath)) {
    $errors[] = 'PSR-4 빌드 계약 composer.json이 없습니다.';
} else {
    try {
        $composer = json_decode(
            (string) file_get_contents($composerPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        $composer = [];
        $errors[] = 'composer.json 형식이 올바르지 않습니다.';
    }

    $expectedAutoload = [
        'CodingPanda\\OjPlatform\\Modules\\' => 'modules/',
        'CodingPanda\\OjPlatform\\' => 'src/',
    ];

    if (($composer['autoload']['psr-4'] ?? null) !== $expectedAutoload) {
        $errors[] = 'composer.json의 PSR-4 경계가 목표 구조와 다릅니다.';
    }

    if (($composer['require']['php'] ?? null) !== '>=8.3') {
        $errors[] = 'composer.json의 PHP 요구 버전은 >=8.3이어야 합니다.';
    }

    $requiredExtensions = [
        'ext-dom',
        'ext-fileinfo',
        'ext-mysqli',
        'ext-openssl',
        'ext-xmlwriter',
        'ext-zip',
    ];
    foreach ($requiredExtensions as $requiredExtension) {
        if (($composer['require'][$requiredExtension] ?? null) !== '*') {
            $errors[] = sprintf(
                'composer.json에 필수 PHP 확장 계약이 없습니다: %s',
                $requiredExtension
            );
        }
    }

    $extensionCatalogPath = $pluginRoot
        . '/src/Kernel/Environment/RequiredPhpExtensions.php';
    if (! is_file($extensionCatalogPath)) {
        $errors[] = '필수 PHP 확장 공통 계약을 찾을 수 없습니다.';
    } else {
        require_once $extensionCatalogPath;
        $catalogExtensions = array_map(
            static fn (string $extension): string => 'ext-' . $extension,
            array_keys(
                \CodingPanda\OjPlatform\Kernel\Environment\RequiredPhpExtensions::labels()
            )
        );
        sort($catalogExtensions);
        $composerExtensions = $requiredExtensions;
        sort($composerExtensions);
        if ($catalogExtensions !== $composerExtensions) {
            $errors[] = '런타임과 composer의 필수 PHP 확장 계약이 다릅니다.';
        }
    }
    $unitScripts = $composer['scripts']['test:unit'] ?? [];
    if (! is_array($unitScripts) || ! in_array('@php tests/Unit/ai-routing.php', $unitScripts, true)) {
        $errors[] = 'AI 라우팅 단위 검사가 composer test:unit 실행 목록에 없습니다.';
    }
}

$documentationContracts = [
    'docs/d-002-route-inventory.html' => [
        '152개 경로 키',
        'console.problems.test_data_settings',
    ],
    'docs/d-006-current-state-baseline.html' => [
        'C-27 · 실행 필수 제출 문맥·대회 문제 배점 스키마 누락',
        '최종 무접두어 스키마는 42개',
    ],
    'docs/d-007-users-terminology-navigation.html' => [
        '목표 관리자 메뉴 Registry · 58개',
        'operations.test_data',
    ],
    'docs/d-008-module-database-ownership.html' => [
        '8개 소유 영역 · 42개',
        'submission_contexts',
        'contest_problem_settings',
        'problem</code>의 기존 <code>bann</code>',
    ],
    'docs/development-ds-10-page-contracts.html' => [
        'Problems가 직접 제공하는 24개 화면',
        'oj_save_problem_test_data_settings',
    ],
    'docs/development-ds-11-submissions-blockly.html' => [
        'PlatformDb <code>submission_contexts</code>',
    ],
    'docs/development-ds-12-learning-spaces.html' => [
        '직접 경로 47개·테이블 14개',
        '<code>contest_problem_settings</code>',
    ],
    'docs/development-ds-15-admin-operations-ui.html' => [
        'data-screen="test-data-settings"',
        '/oj-console/settings/test-data',
    ],
    'docs/development-ds-16-integration-freeze.html' => [
        '정상 운영 GET 152개, 액션 128개, 관리자 메뉴 58개',
        '플랫폼 테이블 42개',
        'C-26',
    ],
    'docs/development-sequence.html' => [
        'GET 152개·고유 액션 128개·관리 메뉴 58개',
        'C-26 테스트 환경 설정',
        'C-27 스키마 정합화',
    ],
];
foreach ($documentationContracts as $relativePath => $needles) {
    $absolutePath = $pluginRoot . '/' . $relativePath;
    $contents = is_file($absolutePath)
        ? (string) file_get_contents($absolutePath)
        : '';
    foreach ($needles as $needle) {
        if (! str_contains($contents, $needle)) {
            $errors[] = sprintf(
                '설계 문서 계약이 코드 Registry와 다릅니다: %s (%s)',
                $relativePath,
                $needle
            );
        }
    }
}

if (! is_file($baselineManifest)) {
    $errors[] = 'I-00 파일 기준 manifest를 찾을 수 없습니다.';
} else {
    try {
        $baselineFiles = json_decode(
            (string) file_get_contents($baselineManifest),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        $baselineFiles = [];
        $errors[] = 'I-00 파일 기준 manifest JSON이 올바르지 않습니다.';
    }

    foreach ($baselineFiles as $entry) {
        $relativePath = str_replace('\\', '/', (string) ($entry['path'] ?? ''));

        if ($relativePath === '' || str_starts_with($relativePath, 'docs/')) {
            continue;
        }

        $currentPath = $pluginRoot . '/' . $relativePath;

        if (
            is_file($currentPath)
            && isset($entry['sha256'])
            && hash_equals((string) $entry['sha256'], (string) hash_file('sha256', $currentPath))
        ) {
            $errors[] = sprintf('I-00과 동일한 레거시 파일이 남아 있습니다: %s', $relativePath);
        }
    }
}

$runtimeExtensions = ['css', 'html', 'js', 'json', 'php'];
$runtimeRoots = ['config', 'modules', 'resources', 'src', 'templates'];

foreach ($runtimeRoots as $runtimeRoot) {
    $absoluteRoot = $pluginRoot . '/' . $runtimeRoot;

    if (! is_dir($absoluteRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $runtimeExtensions, true)) {
            continue;
        }

        $relativeRuntimePath = str_replace(
            '\\',
            '/',
            substr($file->getPathname(), strlen($pluginRoot) + 1)
        );
        $contents = (string) file_get_contents($file->getPathname());
        if (
            preg_match(
                '#^resources/features/[^/]+/vendor/#',
                $relativeRuntimePath
            ) === 1
        ) {
            preg_match_all(
                '/\\\\u([0-9a-f]{4})/i',
                $contents,
                $escapedCodePoints,
                PREG_OFFSET_CAPTURE
            );
            foreach ($escapedCodePoints[1] as $index => $escapedCodePoint) {
                $fullMatch = $escapedCodePoints[0][$index] ?? null;
                $offset = is_array($fullMatch) ? (int) $fullMatch[1] : -1;
                $length = is_array($fullMatch) ? strlen((string) $fullMatch[0]) : 0;
                $before = $offset > 0 ? $contents[$offset - 1] : '';
                $afterOffset = $offset + $length;
                $after = $afterOffset >= 0 && $afterOffset < strlen($contents)
                    ? $contents[$afterOffset]
                    : '';

                // Unicode character-class bounds are not escaped UI text.
                if ($before === '-' || $after === '-') {
                    continue;
                }

                $codePoint = hexdec((string) $escapedCodePoint[0]);
                if (
                    ($codePoint >= 0x1100 && $codePoint <= 0x11FF)
                    || ($codePoint >= 0x3130 && $codePoint <= 0x318F)
                    || ($codePoint >= 0xA960 && $codePoint <= 0xA97F)
                    || ($codePoint >= 0xAC00 && $codePoint <= 0xD7A3)
                    || ($codePoint >= 0xD7B0 && $codePoint <= 0xD7FF)
                ) {
                    $errors[] = sprintf(
                        '고정 자산에 한글 유니코드 이스케이프가 있습니다: %s',
                        $relativeRuntimePath
                    );
                    break;
                }
            }
            continue;
        }

        if (preg_match('/\\\\u[0-9a-f]{4}/i', $contents) === 1) {
            $errors[] = sprintf(
                '런타임 파일에 유니코드 이스케이프 문구가 있습니다: %s',
                $relativeRuntimePath
            );
        }
    }
}

foreach (['oj-platform.php', 'composer.json'] as $relativePath) {
    $absolutePath = $pluginRoot . '/' . $relativePath;

    if (
        is_file($absolutePath)
        && preg_match('/\\\\u[0-9a-f]{4}/i', (string) file_get_contents($absolutePath)) === 1
    ) {
        $errors[] = sprintf('루트 런타임 파일에 유니코드 이스케이프 문구가 있습니다: %s', $relativePath);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "R-01 재구축 트리 검사 실패\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "R-01 재구축 트리 검사 통과: 디렉터리 %d개, 레거시 경로 0개, 동일 레거시 파일 0개\n",
        count($expectedDirectories)
    )
);

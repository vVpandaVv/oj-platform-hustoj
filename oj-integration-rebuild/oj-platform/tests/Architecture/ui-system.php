<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Ui\Icon\IconName;

$pluginRoot = dirname(__DIR__, 2);
$errors = [];
$requiredFiles = [
    'resources/ui/css/oj-ui-system.css',
    'resources/ui/js/oj-ui.js',
    'resources/features/mathjax/js/loader.js',
    'resources/features/mathjax/vendor/tex-chtml.js',
    'resources/features/mathjax/vendor/ui/safe.js',
    'resources/features/mathjax/vendor/LICENSE.txt',
    'resources/ui/icons/lucide.svg',
    'tests/Visual/r04-account-shell.html',
    'tests/Visual/r05-problems.html',
    'tests/Visual/r12-runtime-shell.html',
    'src/Ui/Asset/UiAssetEnqueuer.php',
    'src/Ui/Component/StatusPresentation.php',
    'src/Ui/Icon/IconName.php',
    'src/Ui/Icon/IconRenderer.php',
    'src/Ui/Message/UiMessage.php',
    'src/Ui/View/PageView.php',
];

foreach ($requiredFiles as $relativePath) {
    if (! is_file($pluginRoot . '/' . $relativePath)) {
        $errors[] = sprintf('DS-01 필수 UI 파일이 없습니다: %s', $relativePath);
    }
}

$cssPath = $pluginRoot . '/resources/ui/css/oj-ui-system.css';
$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
if (substr_count($css, ':root') !== 1) {
    $errors[] = '공통 CSS는 :root 토큰 원본을 정확히 하나 가져야 합니다.';
}

if (str_contains($css, '!important')) {
    $errors[] = '공통 CSS에 !important를 사용할 수 없습니다.';
}

if (preg_match('/letter-spacing\s*:\s*-[^;]+;/', $css) === 1) {
    $errors[] = '공통 CSS에 음수 letter-spacing을 사용할 수 없습니다.';
}

if (preg_match('/font-size\s*:\s*[^;]*(?:vw|vmin|vmax)/', $css) === 1) {
    $errors[] = '글자 크기를 viewport 단위로 조절할 수 없습니다.';
}

$responsiveContracts = [
    'grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));'
        => '통계 영역의 가변 열 규칙이 없습니다.',
    'overflow-x: auto;' => '넓은 표를 감싸는 가로 스크롤 규칙이 없습니다.',
    '@media (max-width: 782px)' => '태블릿 이하 공통 반응형 구간이 없습니다.',
    '@media (max-width: 480px)' => '모바일 공통 반응형 구간이 없습니다.',
    '.ojp-console-menu-button' => '모바일 관리자 메뉴 버튼 규칙이 없습니다.',
    '.ojp-filter-bar__primary' => '검색·필터 공통 배치 규칙이 없습니다.',
];
foreach ($responsiveContracts as $contract => $message) {
    if (! str_contains($css, $contract)) {
        $errors[] = $message;
    }
}

if (
    ! str_contains($css, '.ojp-file-workbench > * {')
    || preg_match('/\.ojp-file-workbench\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $css) !== 1
    || preg_match('/\.ojp-file-side\s*\{[^}]*position:\s*static;/s', $css) !== 1
) {
    $errors[] = '테스트 데이터 작업대는 화면 폭과 관계없이 겹침 없는 한 열이어야 합니다.';
}

$confirmedDesignContracts = [
    '--ojp-color-primary: #087d78;' => '확정 시안의 청록색 주요 색상이 다릅니다.',
    '--ojp-content-width: 1220px;' => '확정 시안의 작업 영역 너비가 다릅니다.',
    '--ojp-header-height: 64px;' => '확정 시안의 데스크톱 헤더 높이가 다릅니다.',
    '.ojp-brand__mark' => '확정 시안의 OJ 브랜드 표식 규칙이 없습니다.',
    '.ojp-account-button' => '확정 시안의 원형 계정 버튼 규칙이 없습니다.',
    '.ojp-mobile-bottom-nav' => '확정 시안의 모바일 하단 메뉴 규칙이 없습니다.',
    '.ojp-console-sidebar__title' => '확정 시안의 관리자 사이드바 규칙이 없습니다.',
];
foreach ($confirmedDesignContracts as $contract => $message) {
    if (! str_contains($css, $contract)) {
        $errors[] = $message;
    }
}

$buttonColorContracts = [
    '.ojp-app .ojp-button,' => '링크형 공통 버튼의 글자색 우선순위 규칙이 없습니다.',
    '.ojp-app .ojp-button--primary' => '링크형 주요 버튼의 대비 보장 규칙이 없습니다.',
    '.ojp-app .ojp-button--danger' => '링크형 위험 버튼의 대비 보장 규칙이 없습니다.',
];
foreach ($buttonColorContracts as $contract => $message) {
    if (! str_contains($css, $contract)) {
        $errors[] = $message;
    }
}

$mathJaxLoaderPath = $pluginRoot . '/resources/features/mathjax/js/loader.js';
$mathJaxLoader = is_file($mathJaxLoaderPath)
    ? (string) file_get_contents($mathJaxLoaderPath)
    : '';
$mathJaxContracts = [
    "'[data-ojp-latex]'" => 'MathJax 로더에 문제 본문 대상 선택자가 없습니다.',
    "'../vendor/tex-chtml.js'" => 'MathJax 로더가 로컬 실행 파일을 사용하지 않습니다.',
    "'ui/safe'" => 'MathJax Safe 확장이 활성화되지 않았습니다.',
    "addUnique(removedPackages, 'require')" => 'MathJax require 확장을 차단하지 않았습니다.',
    "addUnique(removedPackages, 'autoload')" => 'MathJax 자동 확장 로딩을 차단하지 않았습니다.',
    "processHtmlClass = 'ojp-math-content'" => 'MathJax 처리 범위가 문제 본문으로 제한되지 않았습니다.',
    "ignoreHtmlClass = 'ojp-math-ignore'" => 'MathJax 원문 제외 계약이 없습니다.',
];
foreach ($mathJaxContracts as $contract => $message) {
    if (! str_contains($mathJaxLoader, $contract)) {
        $errors[] = $message;
    }
}
if (
    preg_match('//u', $mathJaxLoader) !== 1
    || preg_match('/\\\\u[0-9a-f]{4}/i', $mathJaxLoader) === 1
) {
    $errors[] = 'MathJax 로더는 유니코드 이스케이프 없는 UTF-8이어야 합니다.';
}
if (
    str_contains($mathJaxLoader, 'cdn.jsdelivr.net')
    || str_contains($mathJaxLoader, 'cdnjs.cloudflare.com')
) {
    $errors[] = 'MathJax 실행 파일은 외부 CDN이 아닌 플러그인 로컬 자산이어야 합니다.';
}

$mathJaxVendorHashes = [
    'resources/features/mathjax/vendor/tex-chtml.js'
        => '0a6ded5abbce13331658dd239f34382abd06492c74b71b61e8caa8112ec55fa5',
    'resources/features/mathjax/vendor/ui/safe.js'
        => '9fef48d868277a8bc1852f7ba796b33ead812d38916a7131b0a29ea48961a6a4',
    'resources/features/mathjax/vendor/LICENSE.txt'
        => 'cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30',
];
foreach ($mathJaxVendorHashes as $relativePath => $expectedHash) {
    $absolutePath = $pluginRoot . '/' . $relativePath;
    if (
        ! is_file($absolutePath)
        || ! hash_equals($expectedHash, (string) hash_file('sha256', $absolutePath))
    ) {
        $errors[] = sprintf('MathJax 로컬 자산 무결성이 다릅니다: %s', $relativePath);
    }
}
$mathJaxFonts = glob(
    $pluginRoot . '/resources/features/mathjax/vendor/output/chtml/fonts/woff-v2/*.woff'
);
if (! is_array($mathJaxFonts) || count($mathJaxFonts) !== 23) {
    $errors[] = 'MathJax CommonHTML 폰트 23개가 모두 포함되어야 합니다.';
}

if (preg_match('/\.ojp-table\s+(?:th|td):nth-child/', $css) === 1) {
    $errors[] = '모든 표에 적용되는 고정 열 폭 규칙을 사용할 수 없습니다.';
}

$featureCssRoot = $pluginRoot . '/resources/ui/css/features';
if (is_dir($featureCssRoot)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($featureCssRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'css') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        foreach ([':root', '--ojp-', '.ojp-button', '.ojp-input', '.ojp-table', '!important'] as $forbidden) {
            if (str_contains($contents, $forbidden)) {
                $errors[] = sprintf(
                    '기능 CSS가 공통 UI를 재정의합니다: %s (%s)',
                    $file->getFilename(),
                    $forbidden
                );
            }
        }
    }
}

$phpRoots = [$pluginRoot . '/src', $pluginRoot . '/modules', $pluginRoot . '/templates'];
foreach ($phpRoots as $root) {
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

        $contents = (string) file_get_contents($file->getPathname());
        if (preg_match('/\bstyle\s*=\s*["\']/', $contents) === 1) {
            $errors[] = sprintf(
                'PHP 화면 코드에 인라인 style이 있습니다: %s',
                str_replace('\\', '/', substr($file->getPathname(), strlen($pluginRoot) + 1))
            );
        }
    }
}

$classRoots = [
    $pluginRoot . '/modules',
    $pluginRoot . '/src/Ui',
];
$usedUiClasses = [];
foreach ($classRoots as $root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        preg_match_all('/\bclass=(["\'])([^"\']+)\1/i', $contents, $matches);
        foreach ($matches[2] as $classValue) {
            foreach (preg_split('/\s+/', trim($classValue)) ?: [] as $className) {
                if (preg_match('/^ojp-[a-z0-9_-]+$/i', $className) === 1) {
                    $usedUiClasses[$className] = true;
                }
            }
        }
    }
}

foreach (array_keys($usedUiClasses) as $className) {
    if (str_ends_with($className, '-')) {
        if (preg_match('/\.' . preg_quote($className, '/') . '[a-z0-9_-]+/i', $css) !== 1) {
            $errors[] = sprintf('동적 UI 클래스 계열에 공통 CSS가 없습니다: %s', $className);
        }
        continue;
    }

    if (! str_contains($css, '.' . $className)) {
        $errors[] = sprintf('화면에서 사용하는 공통 CSS 클래스가 없습니다: %s', $className);
    }
}

$visualFixtures = [
    'R-04' => $pluginRoot . '/tests/Visual/r04-account-shell.html',
    'R-05' => $pluginRoot . '/tests/Visual/r05-problems.html',
    'R-12' => $pluginRoot . '/tests/Visual/r12-runtime-shell.html',
];
foreach ($visualFixtures as $stage => $visualFixturePath) {
    $visualFixture = is_file($visualFixturePath)
        ? (string) file_get_contents($visualFixturePath)
        : '';
    if ($visualFixture === '') {
        continue;
    }
    if (preg_match('//u', $visualFixture) !== 1) {
        $errors[] = sprintf('%s 화면 검증 파일이 UTF-8이 아닙니다.', $stage);
    }
    if (preg_match('/\\\\u[0-9a-f]{4}/i', $visualFixture) === 1) {
        $errors[] = sprintf('%s 화면 검증 파일에 유니코드 이스케이프가 있습니다.', $stage);
    }
    if (
        str_contains($visualFixture, '<style')
        || preg_match('/\bstyle\s*=\s*["\']/', $visualFixture) === 1
    ) {
        $errors[] = sprintf('%s 화면 검증 파일에 별도 CSS가 있습니다.', $stage);
    }
    if (! str_contains(
        $visualFixture,
        'href="../../resources/ui/css/oj-ui-system.css"'
    )) {
        $errors[] = sprintf('%s 화면 검증 파일이 단일 공통 CSS를 사용하지 않습니다.', $stage);
    }
}

$assetRoot = $pluginRoot . '/resources/ui';
$assetIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($assetRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($assetIterator as $file) {
    // Bundled web fonts are binary; every text asset must still be UTF-8.
    if (! $file->isFile() || strtolower($file->getExtension()) === 'woff2') {
        continue;
    }

    $contents = (string) file_get_contents($file->getPathname());
    if (preg_match('//u', $contents) !== 1) {
        $errors[] = sprintf('UTF-8이 아닌 UI 자산입니다: %s', $file->getFilename());
    }

    if (preg_match('/\\\\u[0-9a-f]{4}/i', $contents) === 1) {
        $errors[] = sprintf('UI 자산에 유니코드 이스케이프가 있습니다: %s', $file->getFilename());
    }
}

require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);
$sprite = (string) file_get_contents($pluginRoot . '/resources/ui/icons/lucide.svg');
foreach (IconName::cases() as $icon) {
    if (! str_contains($sprite, 'id="' . $icon->value . '"')) {
        $errors[] = sprintf('Lucide sprite에 허용 아이콘이 없습니다: %s', $icon->value);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "DS-01 UI 구조 검사 실패\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "DS-01 UI 구조 검사 통과: 단일 CSS 원본, 공통 JS, Lucide %d개, "
        . "인라인 style 0개, 전체 화면 클래스 %d개\n",
        count(IconName::cases()),
        count($usedUiClasses)
    )
);

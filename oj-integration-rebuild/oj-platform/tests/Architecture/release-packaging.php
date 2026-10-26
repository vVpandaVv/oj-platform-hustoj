<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$repositoryRoot = dirname($pluginRoot);
$scriptPath = $repositoryRoot . '/scripts/build-release.ps1';
$packagePath = $repositoryRoot . '/package.json';
$composerPath = $pluginRoot . '/composer.json';

function releaseAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

releaseAssert(is_file($scriptPath), '공식 배포 스크립트가 없습니다.');
$script = (string) file_get_contents($scriptPath);
releaseAssert(
    preg_match('//u', $script) === 1,
    '배포 스크립트는 UTF-8이어야 합니다.'
);
releaseAssert(
    str_contains($script, '_releases/oj-platform/$version')
        && str_contains($script, '$expectedOutputDirectory')
        && str_contains($script, 'oj-platform-$version.zip'),
    '배포 위치는 _releases/oj-platform/<version> 단일 규칙을 사용해야 합니다.'
);
foreach ([
    'Read-PluginVersion',
    'Find-VersionCollisions',
    'Read-GitState',
    'Test-ReleaseArchive',
    'source_commit',
    'source_dirty',
    'Get-FileHash',
    'manifest.json',
    'tools/run-tests.php',
    '--release',
    'rev-list -n 1',
] as $required) {
    releaseAssert(
        str_contains($script, $required),
        '배포 안전 검사 계약이 없습니다: ' . $required
    );
}
foreach (['config', 'modules', 'resources', 'src'] as $directory) {
    releaseAssert(
        str_contains($script, "'$directory'"),
        '배포 허용 디렉터리가 없습니다: ' . $directory
    );
}
releaseAssert(
    ! str_contains($script, 'Compress-Archive'),
    '플러그인 전체 폴더를 무차별 압축할 수 없습니다.'
);
releaseAssert(
    str_contains($script, "'oj-platform/docs/*'")
        && str_contains($script, "'oj-platform/tests/*'"),
    '문서와 테스트는 운영 배포본에서 제외해야 합니다.'
);

$package = json_decode(
    (string) file_get_contents($packagePath),
    true,
    512,
    JSON_THROW_ON_ERROR
);
releaseAssert(
    isset($package['scripts']['release:build'])
        && str_contains(
            (string) $package['scripts']['release:build'],
            'scripts/build-release.ps1'
        ),
    'package.json의 공식 배포 명령이 없습니다.'
);

$composer = json_decode(
    (string) file_get_contents($composerPath),
    true,
    512,
    JSON_THROW_ON_ERROR
);
releaseAssert(
    in_array(
        '@php tests/Architecture/release-packaging.php',
        $composer['scripts']['check:architecture'] ?? [],
        true
    ),
    '배포 정책 검사가 아키텍처 관문에 연결되지 않았습니다.'
);

fwrite(STDOUT, "배포 패키징 정책 검사 통과\n");

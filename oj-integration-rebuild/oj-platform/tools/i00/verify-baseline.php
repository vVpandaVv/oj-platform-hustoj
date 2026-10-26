<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI에서만 실행할 수 있습니다.\n");
    exit(1);
}

$pluginRoot = realpath(dirname(__DIR__, 2));
$repositoryRoot = $pluginRoot !== false ? realpath(dirname($pluginRoot)) : false;
$artifactRoot = $pluginRoot !== false
    ? $pluginRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'i-00'
    : '';
$expectedCommit = 'a238a8ff6151b881240be9df04d1d4e5ddbc9a9b';
$errors = array();
$protectedFileCount = 0;
$documentationFileCount = 0;

if ($pluginRoot === false || $repositoryRoot === false || ! is_dir($artifactRoot)) {
    fwrite(STDERR, "I-00 경로를 확인할 수 없습니다.\n");
    exit(1);
}

$jsonFiles = glob($artifactRoot . DIRECTORY_SEPARATOR . '*.json') ?: array();

foreach ($jsonFiles as $jsonFile) {
    $raw = (string) file_get_contents($jsonFile);

    if (preg_match('//u', $raw) !== 1) {
        $errors[] = basename($jsonFile) . ': UTF-8 형식이 아닙니다.';
        continue;
    }

    if (preg_match('/\\\\u[0-9a-fA-F]{4}/', $raw) === 1) {
        $errors[] = basename($jsonFile) . ': 유니코드 이스케이프가 있습니다.';
    }

    try {
        json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = basename($jsonFile) . ': JSON 오류 - ' . $exception->getMessage();
    }
}

$summary = read_json($artifactRoot . DIRECTORY_SEPARATOR . 'summary.json');
$files = read_json($artifactRoot . DIRECTORY_SEPARATOR . 'files.json');
$gap = read_json($artifactRoot . DIRECTORY_SEPARATOR . 'target-gap.json');
$readiness = read_json($artifactRoot . DIRECTORY_SEPARATOR . 'recovery-readiness.json');
$judgeSchema = read_json($artifactRoot . DIRECTORY_SEPARATOR . 'judge-schema-reference.json');

$expectedCounts = array(
    'tracked_files' => 274,
    'php_files' => 175,
    'modules' => 19,
    'features' => 30,
    'capabilities' => 60,
    'roles' => 15,
    'presets' => 9,
    'routes' => 176,
    'direct_routes' => 137,
    'compatibility_routes' => 39,
    'actions' => 122,
    'console_menu_items' => 51,
    'platform_tables' => 32,
    'judge_tables' => 14,
    'judge_tables_required_by_current_code' => 4,
    'judge_schema_tables' => 36,
);

if (($summary['baseline_commit'] ?? '') !== $expectedCommit) {
    $errors[] = 'summary.json: 기준 커밋이 다릅니다.';
}

foreach ($expectedCounts as $key => $expected) {
    $actual = $summary['counts'][$key] ?? null;

    if ($actual !== $expected) {
        $errors[] = "summary.json: {$key} 수량 {$expected} 예상, 실제 " . json_encode($actual);
    }
}

if (($judgeSchema['available'] ?? null) !== true) {
    $errors[] = 'judge-schema-reference.json: Judge 기준 스키마가 없습니다.';
}
if (($judgeSchema['sha256'] ?? '') !== 'c5f9b82a9d07461a1335d11c409c4f12d3049267b78cd010e95da7edda596784') {
    $errors[] = 'judge-schema-reference.json: 기준 SQL SHA-256이 다릅니다.';
}
$judgeSchemaTableNames = array_column($judgeSchema['tables'] ?? array(), 'name');
foreach (array(
    'problem',
    'solution',
    'source_code',
    'source_code_user',
    'compileinfo',
    'runtimeinfo',
    'contest',
    'contest_problem',
) as $requiredTable) {
    if (! in_array($requiredTable, $judgeSchemaTableNames, true)) {
        $errors[] = "judge-schema-reference.json: 필수 Judge 테이블 누락 - {$requiredTable}";
    }
}

foreach (($summary['invariants'] ?? array()) as $key => $value) {
    if ($value !== true) {
        $errors[] = "summary.json: {$key} 불변식이 실패했습니다.";
    }
}

$baselineTree = array();
$treeOutput = command_output(
    'git -C ' . escapeshellarg($repositoryRoot)
    . ' ls-tree -r ' . escapeshellarg($expectedCommit)
    . ' -- ' . escapeshellarg('oj-platform')
);

foreach (preg_split('/\R/', $treeOutput) ?: array() as $line) {
    if (preg_match('/^(\d{6}) blob ([0-9a-f]{40})\t(.+)$/', $line, $matches) !== 1) {
        continue;
    }

    $repositoryPath = str_replace('\\', '/', $matches[3]);
    if (! str_starts_with($repositoryPath, 'oj-platform/')) {
        continue;
    }

    $relativePath = substr($repositoryPath, strlen('oj-platform/'));
    $baselineTree[$relativePath] = array(
        'mode' => $matches[1],
        'git_blob' => $matches[2],
    );
}

if (count($baselineTree) !== count($files)) {
    $errors[] = sprintf(
        'files.json: 기준 commit 파일 수 %d개와 manifest %d개가 다릅니다.',
        count($baselineTree),
        count($files)
    );
}

foreach ($files as $file) {
    $relativePath = (string) ($file['path'] ?? '');

    if (str_starts_with($relativePath, 'docs/')) {
        ++$documentationFileCount;
    } else {
        ++$protectedFileCount;
    }

    if (! isset($baselineTree[$relativePath])) {
        $errors[] = "기준 commit에서 파일 누락: {$relativePath}";
        continue;
    }

    if (! hash_equals(
        (string) ($file['git_blob'] ?? ''),
        (string) $baselineTree[$relativePath]['git_blob']
    )) {
        $errors[] = "기준 commit Git blob 불일치: {$relativePath}";
    }
}

$targetContracts = array();
foreach (($gap['comparison'] ?? array()) as $contract) {
    $targetContracts[(string) ($contract['contract'] ?? '')] = $contract;
}

$targetChecks = array(
    'modules' => array('target_transition', 20),
    'features' => array('target', 30),
    'routes' => array('target', 151),
    'actions' => array('target', 132),
    'console_menu_items' => array('target', 57),
    'capabilities' => array('target', 61),
    'roles' => array('target', 16),
    'presets' => array('target', 10),
    'platform_tables' => array('target', 40),
    'judge_tables' => array('target_required', 8),
);

foreach ($targetChecks as $contract => [$field, $expected]) {
    $actual = $targetContracts[$contract][$field] ?? null;
    if ($actual !== $expected) {
        $errors[] = "target-gap.json: {$contract}.{$field} 값이 {$expected}가 아닙니다.";
    }
}

if (($targetContracts['routes']['target_bootstrap_wp_admin'] ?? null) !== 1) {
    $errors[] = 'target-gap.json: routes.target_bootstrap_wp_admin 값이 1이 아닙니다.';
}

if (($targetContracts['actions']['target_bootstrap'] ?? null) !== 1) {
    $errors[] = 'target-gap.json: actions.target_bootstrap 값이 1이 아닙니다.';
}

if (($readiness['secrets_recorded'] ?? null) !== false) {
    $errors[] = 'recovery-readiness.json: 비밀값 미기록 증거가 없습니다.';
}

$recoveryToolContracts = array(
    'backup-databases.ps1' => array(
        'required' => array(
            '"--single-transaction"',
            '"--hex-blob"',
            'storage_encrypted = $true',
            'secrets_recorded = $false',
            'MYSQL_PWD',
        ),
        'forbidden' => array(
            '"--databases"',
            'DROP DATABASE',
            'Remove-Item',
        ),
    ),
    'restore-rehearsal.ps1' => array(
        'required' => array(
            'Get-FileHash -LiteralPath $backupFile.path',
            '$actualBytes -ne [long]$backupFile.entry.bytes',
            '$backupManifest.databases',
            '"_test$"',
            '자동 삭제나 덮어쓰기는 수행하지 않습니다:',
            'secrets_recorded = $false',
            'automatic_cleanup_performed = $false',
        ),
        'forbidden' => array(
            'DROP DATABASE',
            'Remove-Item',
        ),
    ),
);
$toolRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'i00';

foreach ($recoveryToolContracts as $filename => $contract) {
    $path = $toolRoot . DIRECTORY_SEPARATOR . $filename;
    if (! is_file($path)) {
        $errors[] = "복구 도구 누락: {$filename}";
        continue;
    }

    $contents = (string) file_get_contents($path);
    foreach ($contract['required'] as $requiredFragment) {
        if (! str_contains($contents, $requiredFragment)) {
            $errors[] = "{$filename}: 필수 보호 계약 누락 - {$requiredFragment}";
        }
    }
    foreach ($contract['forbidden'] as $forbiddenFragment) {
        if (str_contains($contents, $forbiddenFragment)) {
            $errors[] = "{$filename}: 금지된 동작 발견 - {$forbiddenFragment}";
        }
    }
}

$mergeBase = trim(
    command_output(
        'git -C ' . escapeshellarg($repositoryRoot)
        . ' merge-base ' . escapeshellarg($expectedCommit) . ' HEAD'
    )
);
if ($mergeBase !== $expectedCommit) {
    $errors[] = '현재 worktree가 I-00 기준 커밋에서 분기되지 않았습니다.';
}

if ($errors !== array()) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL: {$error}\n");
    }
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "I-00 기준선 검증 통과: JSON %d개, 기준 commit runtime %d개 고정, 문서 기준 %d개 고정, 현재 %d Route·%d Action, 정상 운영 목표 %d Route·%d Action, 설치 중 setup 화면·액션 %d/%d\n",
        count($jsonFiles),
        $protectedFileCount,
        $documentationFileCount,
        $summary['counts']['routes'],
        $summary['counts']['actions'],
        $targetContracts['routes']['target'],
        $targetContracts['actions']['target'],
        $targetContracts['routes']['target_bootstrap_wp_admin'],
        $targetContracts['actions']['target_bootstrap']
    )
);

/**
 * @return array<mixed>
 */
function read_json(string $path): array
{
    if (! is_file($path)) {
        return array();
    }

    $raw = (string) file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    return json_decode(
        $raw,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
}

function command_output(string $command): string
{
    $lines = array();
    $exitCode = 0;
    exec($command . ' 2>&1', $lines, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException(implode(PHP_EOL, $lines));
    }

    return implode(PHP_EOL, $lines);
}

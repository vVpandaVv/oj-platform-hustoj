param(
    [switch]$SkipConstituentChecks
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}
$phpArgs = @()
$extensionDir = Join-Path (Split-Path -Parent $php) 'ext'
if (Test-Path -LiteralPath $extensionDir) {
    $phpArgs += @('-d', "extension_dir=$extensionDir")
    foreach ($extension in @('intl', 'mbstring')) {
        if (Test-Path -LiteralPath (Join-Path $extensionDir "php_$extension.dll")) {
            $phpArgs += @('-d', "extension=$extension")
        }
    }
}

function Invoke-Checked([string]$Command, [string[]]$Arguments) {
    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed ($LASTEXITCODE): $Command $($Arguments -join ' ')"
    }
}

function Assert-Markers([string]$Path, [string[]]$Markers, [string]$Label) {
    $source = Get-Content -LiteralPath (Join-Path $root $Path) -Raw -Encoding UTF8
    foreach ($marker in $Markers) {
        if (-not $source.Contains($marker)) {
            throw "G5-IMP-02-S04 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($gate in @(
        'check-report-snapshot-g5-imp-02-s01.ps1',
        'check-report-snapshot-query-g5-imp-02-s02.ps1',
        'check-report-source-manifest-g5-imp-02-s03.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $gate),
            '-SkipConstituentChecks'
        )
    }
}

$phpFiles = @(
    'src\Reporting\Report\Application\Create_Report_Snapshot.php'
    'src\Reporting\Report\Application\Read_Report_Snapshot.php'
    'src\Reporting\Report\Application\Read_Report_Source_Manifest_Result.php'
    'src\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Repository.php'
    'src\Reporting\Report\Infrastructure\WordPress_Report_Snapshot_Query.php'
    'src\Reporting\Report\Presentation\Report_Snapshot_Rest_Controller.php'
    'src\Reporting\Report\Presentation\Report_Snapshot_Query_Rest_Controller.php'
    'tests\Contract\report-snapshot-integration-g5-imp-02-s04.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php (@($phpArgs) + @('-l', (Join-Path $root $file)))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object {
    Get-Content -LiteralPath (Join-Path $root $_) -Raw -Encoding UTF8
}) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) {
        throw "G5-IMP-02-S04 contains a forbidden boundary marker: $forbidden"
    }
}
if ($runtimeSource -match '(?i)\b(?:UPDATE|DELETE)\s+oj_ai_report_snapshots\b') {
    throw 'G5-IMP-02-S04 report snapshots are no longer append-only.'
}

$presentationSource = (Get-ChildItem -LiteralPath (Join-Path $root 'src\Reporting\Report\Presentation') -Filter '*.php' |
    ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw -Encoding UTF8 }) -join [Environment]::NewLine
if ($presentationSource.Contains('read_source_manifest')) {
    throw 'G5-IMP-02-S04 internal source manifest acquired a presentation adapter.'
}

Assert-Markers 'tests\Contract\report-snapshot-create-g5-imp-02-s01.php' @(
    'A source race must fail as report_source_stale.'
    'A concurrent exact C45 winner must be returned as a durable replay.'
    'C45 rollback left a partial snapshot row.'
) 'S01 vertical regression'
Assert-Markers 'tests\Contract\report-snapshot-query-g5-imp-02-s02.php' @(
    'Q22 disclosed a snapshot after source access was revoked.'
    'Q22 returned a projection containing an unknown private field.'
    'Q22 report object revision must be the snapshot identity hash.'
) 'S02 vertical regression'
Assert-Markers 'tests\Contract\report-source-manifest-g5-imp-02-s03.php' @(
    'S03 disclosed provenance after worksheet source access was revoked.'
    'S03 accepted noncanonical manifest bytes with internally consistent hashes.'
    'S03 accepted a manifest source hash not linked by the immutable projection.'
) 'S03 vertical regression'
Assert-Markers 'tests\Contract\report-snapshot-integration-g5-imp-02-s04.php' @(
    'A later live source changed an immutable Q22 snapshot.'
    'S04 Context revocation must stop before loading snapshot content.'
    'S04 accepted noncanonical stored manifest bytes with matching hashes.'
    'S04 same-key concurrency committed more than one snapshot.'
    'S04 Q22 revocation must remain enumeration-safe 404.'
    'G5-IMP-02-S04 passed:'
) 'integration contract'
Assert-Markers 'docs\ai-assessment\g5-imp-02-s04-report-snapshot-integration-freeze.html' @(
    'G5-IMP-02-S04'
    'IMP-02 4/4'
    'report_source_stale'
    'id="authorization"'
    'check:report-snapshot-integration-g5'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-02-s04-report-snapshot-integration-freeze.html'
    'IMP-02 4/4'
    'G5-IMP-03-S01'
    'g5-imp-03-s02-submission-bundle-enqueue.html'
    'G5-IMP-03-S02'
    'g5-imp-03-s03-report-json-package-enqueue.html'
    'G5-IMP-03-S03'
    'G5-IMP-03-S04'
    'Q23'
    'Q24'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:report-snapshot-integration-g5'
    'check-report-snapshot-integration-g5-imp-02-s04.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-report-snapshot-integration-g5-imp-02-s04.ps1'
) 'aggregate runner'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\report-snapshot-integration-g5-imp-02-s04.php')))

Write-Output 'G5-IMP-02-S04 passed: C45 to Q22/provenance immutability, generation stale, stored damage, current authorization, replay, and one concurrent winner are frozen.'

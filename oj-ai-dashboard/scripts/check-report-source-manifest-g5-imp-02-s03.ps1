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
            throw "G5-IMP-02-S03 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-report-snapshot-query-g5-imp-02-s02.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Reporting\Report\Application\Read_Report_Snapshot.php'
    'src\Reporting\Report\Application\Read_Report_Source_Manifest_Result.php'
    'tests\Contract\report-source-manifest-g5-imp-02-s03.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php (@($phpArgs) + @('-l', (Join-Path $root $file)))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw -Encoding UTF8 }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) {
        throw "G5-IMP-02-S03 contains a forbidden boundary marker: $forbidden"
    }
}

$presentationSource = (Get-ChildItem -LiteralPath (Join-Path $root 'src\Reporting\Report\Presentation') -Filter '*.php' |
    ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw -Encoding UTF8 }) -join [Environment]::NewLine
if ($presentationSource.Contains('read_source_manifest')) {
    throw 'G5-IMP-02-S03 internal source manifest handoff acquired a presentation adapter.'
}

Assert-Markers 'src\Reporting\Report\Application\Read_Report_Snapshot.php' @(
    'read_source_manifest('
    'authorize_and_validate('
    "'source_manifest_json'"
    "'source_manifest_hash'"
    "'authorized_worksheet_count'"
) 'shared authorization and manifest handoff'
Assert-Markers 'src\Reporting\Report\Application\Read_Report_Source_Manifest_Result.php' @(
    'Report_Snapshot_Profile::SOURCE_MANIFEST_SCHEMA'
    "hash( 'sha256', `$source_manifest_json )"
    "'snapshot_hash'"
    "'filter_hash'"
    "'projection_hash'"
    "'source_manifest_json'"
) 'immutable result'
Assert-Markers 'tests\Contract\report-source-manifest-g5-imp-02-s03.php' @(
    'S03 exposed the internal manifest through Q22.'
    'S03 accepted noncanonical manifest bytes with internally consistent hashes.'
    'S03 accepted a manifest source hash not linked by the immutable projection.'
    'S03 disclosed provenance after worksheet source access was revoked.'
) 'security regression fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-02-s03-report-source-manifest.html' @(
    'G5-IMP-02-S03'
    'oj_ai.report_source_manifest@1.0.0'
    'read_source_manifest()'
    'check:report-source-manifest-g5'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-02-s03-report-source-manifest.html'
    'g5-imp-02-s04-report-snapshot-integration-freeze.html'
    'G5-IMP-03-S01'
    'IMP-02 4/4'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:report-source-manifest-g5'
    'check-report-source-manifest-g5-imp-02-s03.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-report-source-manifest-g5-imp-02-s03.ps1'
) 'aggregate runner'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\report-source-manifest-g5-imp-02-s03.php')))

Write-Output 'G5-IMP-02-S03 passed: canonical source manifest bytes and hashes remain snapshot-linked, currently authorized and internal-only.'

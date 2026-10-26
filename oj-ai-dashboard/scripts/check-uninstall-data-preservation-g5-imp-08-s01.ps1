[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
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
            throw "G5-IMP-08-S01 $Label marker is missing: $marker"
        }
    }
}

foreach ($file in @(
    'uninstall.php',
    'tests\Contract\release-package-boundary.php',
    'tests\Contract\uninstall-data-preservation-g5-imp-08-s01.php'
)) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$uninstall = Get-Content -LiteralPath (Join-Path $root 'uninstall.php') -Raw -Encoding UTF8
foreach ($marker in @('WP_UNINSTALL_PLUGIN', 'A-DS15-D22', 'G5-IMP-08-S01', 'Intentionally no mutation')) {
    if (-not $uninstall.Contains($marker)) {
        throw "G5-IMP-08-S01 uninstall marker is missing: $marker"
    }
}
foreach ($forbidden in @(
    '$wpdb', '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_',
    'DROP TABLE', 'TRUNCATE TABLE', 'DELETE FROM', 'RENAME TABLE',
    'delete_option', 'delete_site_option', 'update_option', 'wp_delete_file',
    'unlink(', 'rmdir(', 'require ', 'require_once', 'include ', 'include_once',
    'CodingPanda\OjPlatform\Kernel\'
)) {
    if ($uninstall.Contains($forbidden)) {
        throw "G5-IMP-08-S01 uninstall contains a forbidden mutation or dependency: $forbidden"
    }
}

$release = Get-Content -LiteralPath (Join-Path $root 'scripts\build-release.ps1') -Raw -Encoding UTF8
$requiredStart = $release.IndexOf('$requiredEntries = @(')
$optionalStart = $release.IndexOf('$optionalEntries = @(')
$copyStart = $release.IndexOf('foreach ($relativePath in $requiredEntries)')
if ($requiredStart -lt 0 -or $optionalStart -le $requiredStart -or $copyStart -le $optionalStart) {
    throw 'G5-IMP-08-S01 release entry sections are invalid.'
}
$requiredEntries = $release.Substring($requiredStart, $optionalStart - $requiredStart)
$optionalEntries = $release.Substring($optionalStart, $copyStart - $optionalStart)
if (-not $requiredEntries.Contains("'uninstall.php'")) {
    throw 'G5-IMP-08-S01 uninstall.php is not a required release entry.'
}
if ($optionalEntries.Contains("'uninstall.php'")) {
    throw 'G5-IMP-08-S01 uninstall.php remains optional.'
}

Assert-Markers 'docs\ai-assessment\g5-imp-08-s01-uninstall-preservation.html' @(
    '<title>G5-IMP-08-S01', 'id="result"', 'A-DS15-A20', 'A-DS15-D22',
    'check:uninstall-preservation-g5', 'IMP-08 1/6', 'Platform API change: 0', 'G5-IMP-08-S02'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-08-s01-uninstall-preservation.html', 'G5-IMP-08-S01', 'IMP-08 1/6',
    'check:uninstall-preservation-g5', 'G5-IMP-08-S02'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:uninstall-preservation-g5', 'check-uninstall-data-preservation-g5-imp-08-s01.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-uninstall-data-preservation-g5-imp-08-s01.ps1',
    'Uninstall preservation G5-IMP-08-S01 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\uninstall-data-preservation-g5-imp-08-s01.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\release-package-boundary.php'))

Write-Output 'G5-IMP-08-S01 passed: preservation-only uninstall is required in release packages and performs zero database, option, private-file, runtime, or platform mutation.'

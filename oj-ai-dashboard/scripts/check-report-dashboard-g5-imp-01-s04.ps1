[CmdletBinding()]
param(
    [switch]$SkipConstituentChecks,
    [switch]$SkipBrowser
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = if ($env:OJAI_PHP) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$Command,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )
    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed ($LASTEXITCODE): $Command $($Arguments -join ' ')"
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($script in @(
        'check-worksheet-report-list-g5-imp-01-s01.ps1',
        'check-worksheet-report-detail-g5-imp-01-s02.ps1',
        'check-worksheet-report-filter-denominator-g5-imp-01-s03.ps1'
    )) {
        Invoke-Checked 'powershell' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $PSScriptRoot $script))
    }
}

foreach ($relative in @(
    'src\Platform\Report_Dashboard_Page_Handler.php',
    'src\Platform\Addon_Manifest.php',
    'src\Platform\Worksheet_Workbench_Page_Handler.php',
    'src\Platform\Assignment_Workbench_Page_Handler.php',
    'src\Platform\Worksheet_Evaluation_Workbench_Page_Handler.php',
    'src\Platform\External_Ai_Consent_Settings_Page_Handler.php',
    'tests\Contract\report-dashboard-g5-imp-01-s04.php'
)) {
    Invoke-Checked $php @('-l', (Join-Path $root $relative))
}

foreach ($relative in @(
    'resources\js\report-dashboard.js',
    'tests\Contract\report-dashboard-g5-imp-01-s04.mjs',
    'scripts\e2e-report-dashboard.mjs'
)) {
    Invoke-Checked 'node' @('--check', (Join-Path $root $relative))
}

Invoke-Checked $php @((Join-Path $root 'tests\Contract\report-dashboard-g5-imp-01-s04.php'))
Invoke-Checked 'node' @((Join-Path $root 'tests\Contract\report-dashboard-g5-imp-01-s04.mjs'))

$boundaryFiles = @(
    'src\Platform\Report_Dashboard_Page_Handler.php',
    'resources\js\report-dashboard.js',
    'resources\css\report-dashboard.css',
    'tests\fixtures\report-dashboard-visual.html'
)
foreach ($relative in $boundaryFiles) {
    $content = [System.IO.File]::ReadAllText((Join-Path $root $relative))
    if ($content.Contains('$wpdb->prefix') -or $content.Contains('wp_oj_ai_')) {
        throw "Forbidden prefixed table reference in $relative"
    }
    if ($content -match 'OJ\\Platform\\(?!Contracts\\Addon\\)') {
        throw "Private OJ Platform namespace reference in $relative"
    }
}

$manifest = [System.IO.File]::ReadAllText((Join-Path $root 'src\Platform\Addon_Manifest.php'))
foreach ($marker in @(
    'Report_Dashboard_Page_Handler',
    'CONSOLE_REPORTS',
    "'resources/css/report-dashboard.css'",
    "'resources/js/report-dashboard.js'",
    'Addon_Capabilities::VIEW_REPORTS'
)) {
    if (-not $manifest.Contains($marker)) {
        throw "Report manifest marker missing: $marker"
    }
}

if (-not $SkipBrowser) {
    Invoke-Checked 'node' @((Join-Path $root 'scripts\e2e-report-dashboard.mjs'))
}

Write-Host 'G5-IMP-01-S04 gate passed: Q20/Q21 report UI, shared filters/history, fail-closed permission revocation, direct Console menus without duplicate page navigation, and responsive browser evidence.'

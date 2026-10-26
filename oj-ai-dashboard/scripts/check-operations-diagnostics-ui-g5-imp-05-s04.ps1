[CmdletBinding()]
param(
    [switch]$SkipConstituentChecks,
    [switch]$SkipBrowser
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')
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
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G5-IMP-05-S04 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-operations-storage-g5-imp-05-s03.ps1')
    )
}

foreach ($file in @(
    'src\Platform\External_Ai_Consent_Settings_Page_Handler.php',
    'src\Platform\Addon_Manifest.php',
    'tests\Contract\operations-diagnostics-ui-g5-imp-05-s04.php'
)) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

foreach ($file in @(
    'resources\js\operations-diagnostics.js',
    'resources\js\assessment-configuration-settings.js',
    'tests\Contract\operations-diagnostics-ui-g5-imp-05-s04.mjs',
    'scripts\e2e-operations-diagnostics.mjs'
)) {
    Invoke-Checked 'node' @('--check', (Join-Path $root $file))
}

foreach ($file in @(
    'src\Platform\External_Ai_Consent_Settings_Page_Handler.php',
    'src\Platform\Addon_Manifest.php',
    'resources\js\operations-diagnostics.js',
    'resources\css\operations-diagnostics.css',
    'tests\fixtures\assessment-configuration-settings-visual.html'
)) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-05-S04 boundary file contains a prefixed table marker: $file -> $forbidden"
        }
    }
    if ($source -match 'OJ\\Platform\\(?!Contracts\\Addon\\)') {
        throw "G5-IMP-05-S04 boundary file references a private OJ Platform namespace: $file"
    }
}

Assert-Markers 'src\Platform\External_Ai_Consent_Settings_Page_Handler.php' @(
    "'system' =>"
    'id="oj-ai-operations-diagnostics"'
    'data-platform-operations-url'
    'data-platform-ai-url'
    'data-diagnostics-role="list"'
) 'settings Page'
Assert-Markers 'resources\js\operations-diagnostics.js' @(
    'oj_ai.diagnostics@1.0.0'
    "['bridge', 'schema', 'queue', 'storage', 'ai_routing']"
    'aggregateStatus(checks) !== value.status'
    'root.dataset.access = access'
    'clearRows()'
) 'safe renderer'
Assert-Markers 'resources\css\operations-diagnostics.css' @(
    '.oj-ai-diagnostics__row'
    '@media (max-width: 700px)'
    '@media (max-width: 390px)'
    'overflow-wrap: anywhere'
) 'responsive styles'
Assert-Markers 'src\Platform\Addon_Manifest.php' @(
    'resources/css/operations-diagnostics.css'
    'resources/js/operations-diagnostics.js'
) 'Console asset registration'
Assert-Markers 'tests\fixtures\assessment-configuration-settings-visual.html' @(
    'window.__diagnosticsPermissionRevoked'
    'window.__diagnosticsMode'
    'secret-file.pdf'
) 'browser fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-05-s04-operations-ui-regression.html' @(
    'G5-IMP-05-S04'
    'IMP-05 4/4'
    'Q28 5-check'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-05-s04-operations-ui-regression.html'
    'IMP-05 4/4'
    'G5 6/8'
    'G5-IMP-06-S01'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R100'
    'G5-IMP-05-S04'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:operations-diagnostics-g5-s03'
    'check:operations-diagnostics-g5'
    'check-operations-diagnostics-ui-g5-imp-05-s04.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-operations-diagnostics-ui-g5-imp-05-s04.ps1'
    'Operations diagnostics UI and five-check regression G5-IMP-05-S04 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\operations-diagnostics-ui-g5-imp-05-s04.php'))
Invoke-Checked 'node' @((Join-Path $root 'tests\Contract\operations-diagnostics-ui-g5-imp-05-s04.mjs'))

if (-not $SkipBrowser) {
    Invoke-Checked 'node' @((Join-Path $root 'scripts\e2e-operations-diagnostics.mjs'))
}

Write-Output 'G5-IMP-05-S04 passed: settings diagnostics UI, exact five-check rendering, fail-closed revocation, responsive browser evidence, and platform boundary are frozen.'

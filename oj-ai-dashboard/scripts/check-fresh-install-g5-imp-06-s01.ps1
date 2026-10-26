[CmdletBinding()]
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
            throw "G5-IMP-06-S01 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    foreach ($script in @(
        'check-addon-api-consumer.ps1',
        'check-database-foundation.ps1',
        'check-database-infrastructure.ps1',
        'check-activation-database.ps1',
        'check-runtime-schema-readiness.ps1',
        'check-assessment-configuration-bootstrap-s01.ps1'
    )) {
        Invoke-Checked 'powershell' @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
            (Join-Path $PSScriptRoot $script)
        )
    }
}

foreach ($file in @(
    'src\Core\Activation.php',
    'src\Core\Installation_Manager.php',
    'src\Core\Activation_Preflight.php',
    'src\Core\Activation_Workflow.php',
    'src\Storage\Infrastructure\Local_Private_Storage_Root.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Database\Foundation\Schema_Runtime_Readiness_Verifier.php',
    'src\Platform\Addon_Runtime_Readiness_Gate.php',
    'tests\Contract\fresh-install-g5-imp-06-s01.php'
)) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

foreach ($file in @(
    'src\Core\Activation.php',
    'src\Core\Installation_Manager.php',
    'src\Core\Activation_Preflight.php',
    'src\Core\Activation_Workflow.php',
    'src\Storage\Infrastructure\Local_Private_Storage_Root.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Database\Foundation\Schema_Runtime_Readiness_Verifier.php',
    'src\Platform\Addon_Runtime_Readiness_Gate.php'
)) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'add_role(', 'add_cap(')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-06-S01 boundary contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'OJ\\Platform\\(?!Contracts\\Addon\\)') {
        throw "G5-IMP-06-S01 boundary references a private OJ Platform namespace: $file"
    }
}

Assert-Markers 'src\Core\Activation_Preflight.php' @(
    "'network_wide'"
    "'platform_missing'"
    "'addon_api_missing'"
    "'mbstring'"
    'Local_Private_Storage_Path_Diagnostics'
    'OJ_AI_PRIVATE_STORAGE_DIR'
) 'activation preflight'
Assert-Markers 'src\Storage\Infrastructure\Local_Private_Storage_Root.php' @(
    "'HOME'"
    "'USERPROFILE'"
    "'.oj-ai-private'"
    'candidate_is_available'
) 'private storage root fallback'
Assert-Markers 'src\Core\Activation.php' @(
    'Activation_Preflight::inspect('
) 'activation preflight order'
Assert-Markers 'src\Core\Activation.php' @(
    "Installation_Manager::run( 'activation' )"
) 'activation delegation'
Assert-Markers 'src\Core\Installation_Manager.php' @(
    'WordPress_Schema_Installation_Runner_Factory::create'
    'seed_defaults'
    'oj_ai_dashboard_version'
) 'installation order'
Assert-Markers 'docs\ai-assessment\g5-imp-06-s01-fresh-install.html' @(
    'G5-IMP-06-S01'
    'IMP-06 1/5'
    'registration_pending'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-06-s01-fresh-install.html'
    'G5-IMP-06-S01-G'
    'G5-IMP-06-S02'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R101'
    'G5-IMP-06-S01'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:fresh-install-g5'
    'check-fresh-install-g5-imp-06-s01.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-fresh-install-g5-imp-06-s01.ps1'
    'Fresh installation G5-IMP-06-S01 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\fresh-install-g5-imp-06-s01.php'))
Write-Output 'G5-IMP-06-S01 passed: preflight, 45 literal tables, ordered activation, registration_pending, SELECT-only onReady, and platform boundary are frozen.'

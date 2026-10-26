[CmdletBinding()]
param(
    [switch]$SkipConstituentChecks,
    [switch]$SkipBrowser
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
            throw "G5-IMP-06-S04 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-failure-restart-g5-imp-06-s03.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Database\Foundation\Port\Schema_Repairer_Port_Interface.php',
    'src\Database\Foundation\Schema_Installation_Runner.php',
    'src\Operations\SchemaRepair\Application\Repair_Addon_Schema_Command.php',
    'src\Operations\SchemaRepair\Application\Repair_Addon_Schema_Result.php',
    'src\Operations\SchemaRepair\Application\Repair_Addon_Schema.php',
    'src\Operations\SchemaRepair\Application\Schema_Repair_Failure.php',
    'src\Operations\SchemaRepair\Domain\Schema_Repair_State.php',
    'src\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repair_Receipt.php',
    'src\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repair_State.php',
    'src\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repairer.php',
    'src\Operations\SchemaRepair\Presentation\Schema_Repair_Rest_Controller.php',
    'src\Operations\SchemaRepair\Presentation\Schema_Repair_Rest_Module.php',
    'src\Platform\Addon_Recovery_Runtime_Readiness.php',
    'src\Platform\External_Ai_Consent_Settings_Page_Handler.php',
    'src\Security\Policy\Access_Policy.php',
    'src\Security\Policy\Schema_Recovery_Access_Policy.php',
    'src\Core\Composition_Root.php',
    'tests\Contract\schema-forward-repair-g5-imp-06-s04.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

foreach ($file in @(
    'resources\js\operations-diagnostics.js',
    'scripts\e2e-operations-diagnostics.mjs'
)) {
    Invoke-Checked 'node' @('--check', (Join-Path $root $file))
}

$runtimeFiles = @(
    'src\Database\Foundation\Port\Schema_Repairer_Port_Interface.php',
    'src\Operations\SchemaRepair\Application\Repair_Addon_Schema_Command.php',
    'src\Operations\SchemaRepair\Application\Repair_Addon_Schema.php',
    'src\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repair_Receipt.php',
    'src\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repair_State.php',
    'src\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repairer.php',
    'src\Operations\SchemaRepair\Presentation\Schema_Repair_Rest_Controller.php',
    'src\Platform\Addon_Recovery_Runtime_Readiness.php',
    'src\Security\Policy\Schema_Recovery_Access_Policy.php'
)
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'function down(', 'dbDelta(')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-06-S04 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-06-S04 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Security\Policy\Access_Policy.php' @(
    'authorize_schema_recovery'
    "array( 'operations.schema.repair', 'operations.schema.repair.view' )"
    'schema_recovery_action_denied'
) 'single-policy recovery allowlist'
Assert-Markers 'src\Operations\SchemaRepair\Application\Repair_Addon_Schema.php' @(
    'Schema_Repairer_Port_Interface'
    '$command->authorization()'
    'postcondition_ready'
    'complete_converged'
) 'forward repair application'
Assert-Markers 'src\Operations\SchemaRepair\Infrastructure\WordPress_Schema_Repair_Receipt.php' @(
    'oj_ai_dashboard_schema_repair_'
    'oj_ai_audit_events'
    'oj_ai_outbox_events'
    'operations.schema.repaired'
    'START TRANSACTION'
) 'bootstrap and durable receipt'
Assert-Markers 'src\Operations\SchemaRepair\Presentation\Schema_Repair_Rest_Controller.php' @(
    '/operations/schema/commands/repair'
    'Addon_Capabilities::MANAGE_MIGRATIONS'
    '202'
    "'forward_only'      => true"
) 'C75 REST contract'
Assert-Markers 'src\Platform\External_Ai_Consent_Settings_Page_Handler.php' @(
    'operations.schema.repair.view'
    'data-recovery-mode'
    'data-diagnostics-role="repair"'
    'data-diagnostics-field="backup-reference"'
) 'recovery Page'
Assert-Markers 'resources\js\operations-diagnostics.js' @(
    'normalizeRepair'
    'crypto.subtle.digest'
    '/operations/schema/commands/repair'
    'clearRepair'
) 'recovery UI command'
Assert-Markers 'tests\Contract\schema-forward-repair-g5-imp-06-s04.php' @(
    'C75 strict forward repair'
    'schema_repair_converged'
    'operations.schema.repair.view'
    '24 hours'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-06-s04-forward-repair.html' @(
    'G5-IMP-06-S04'
    'IMP-06 4/5'
    'POST /operations/schema/commands/repair'
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-06-s04-forward-repair.html'
    'G5-IMP-06-S04-H'
    'G5-IMP-06-S05'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\a-ds-15-install-upgrade-migration-operations.html' @(
    'g5-imp-06-s04-forward-repair.html'
    'check:forward-repair-g5'
) 'A-DS-15 synchronization'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R104'
    'G5-IMP-06-S04'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:forward-repair-g5'
    'check-forward-repair-g5-imp-06-s04.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-forward-repair-g5-imp-06-s04.ps1'
    'Forward schema repair C75 G5-IMP-06-S04 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\schema-forward-repair-g5-imp-06-s04.php'))
if (-not $SkipBrowser) {
    Invoke-Checked 'node' @((Join-Path $root 'scripts\e2e-operations-diagnostics.mjs'))
}

Write-Output 'G5-IMP-06-S04 passed: C75 remains forward-only, backup-bound, single-policy authorized, idempotent across interruption, durably audited, and responsive in the settings recovery surface.'

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
            throw "G5-IMP-05-S02 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-operations-diagnostics-g5-imp-05-s01.ps1')
    )
}

$phpFiles = @(
    'src\Operations\Diagnostics\Application\Read_System_Diagnostics.php'
    'src\Operations\Diagnostics\Domain\Ai_Routing_Diagnostics_Result.php'
    'src\Operations\Diagnostics\Domain\Ai_Routing_Task_Diagnostics.php'
    'src\Operations\Diagnostics\Port\Ai_Routing_Diagnostics_Port_Interface.php'
    'src\Operations\Diagnostics\Infrastructure\Addon_Runtime_Ai_Routing_Diagnostics.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\operations-ai-routing-g5-imp-05-s02.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$adapterPath = Join-Path $root 'src\Operations\Diagnostics\Infrastructure\Addon_Runtime_Ai_Routing_Diagnostics.php'
$adapterSource = Get-Content -LiteralPath $adapterPath -Raw -Encoding UTF8
foreach ($forbidden in @(
    '$wpdb->prefix',
    'wp_oj_ai_',
    'CodingPanda\OjPlatform\Kernel\',
    '->execute(',
    '->providerKey(',
    '->modelKey(',
    '->message(',
    '->details(',
    '->correlationId('
)) {
    if ($adapterSource.Contains($forbidden)) {
        throw "G5-IMP-05-S02 contains a forbidden boundary marker: $forbidden"
    }
}

Assert-Markers 'src\Operations\Diagnostics\Infrastructure\Addon_Runtime_Ai_Routing_Diagnostics.php' @(
    'AddonAiTaskGatewayV1'
    'AddonApiProblemV1'
    'Addon_Manifest::TASK_WORKSHEET_QUALITY'
    'Addon_Manifest::TASK_STUDENT_ASSESSMENT'
    '->canRoute('
    '->snapshot('
    'hash_equals('
    "'ai_task_routing_stale'"
    "'ai_task_assignment_required'"
    "'ai_task_routing_temporarily_unavailable'"
) 'public AI routing adapter'
Assert-Markers 'src\Operations\Diagnostics\Domain\Ai_Routing_Task_Diagnostics.php' @(
    "SUBJECT_WORKSHEET_QUALITY  = 'worksheet_quality'"
    "SUBJECT_STUDENT_SUBMISSION = 'student_submission'"
    "UNROUTABLE_TRANSIENT = 'unroutable_transient'"
    "ROUTING_STALE        = 'routing_stale'"
    "RUNTIME_UNAVAILABLE  = 'runtime_unavailable'"
    "'routing_revision'"
    "'profile_fingerprint'"
) 'safe task projection'
Assert-Markers 'src\Operations\Diagnostics\Application\Read_System_Diagnostics.php' @(
    "`$checks['ai_routing']"
    "'open_platform_ai_assignments'"
    "'refresh_ai_routing'"
    "'retry_ai_routing'"
) 'Q28 integration'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new Addon_Runtime_Ai_Routing_Diagnostics( $runtime )'
) 'Composition Root'
Assert-Markers 'tests\Contract\operations-ai-routing-g5-imp-05-s02.php' @(
    'Q28 did not inspect both AI task route decisions exactly once.'
    'Q28 did not inspect both AI task snapshots exactly once.'
    'Q28 diagnostics executed AI work.'
    'A retryable platform routing problem did not fail closed.'
    'Routing revision drift did not raise attention.'
    'A mismatched snapshot task key was accepted.'
    'Platform canRoute public signature changed.'
    'G5-IMP-05-S02 passed:'
) 'contract test'
Assert-Markers 'docs\ai-assessment\g5-imp-05-s02-ai-routing-diagnostics.html' @(
    'IMP-05 2/4'
    'Q28'
    'canRoute()'
    'snapshot()'
    'execute() 0'
    'Platform API change: 0'
    'G5-IMP-05-S03'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-05-s02-ai-routing-diagnostics.html'
    'g5-imp-05-s03-storage-secret-diagnostics.html'
    'g5-imp-05-s04-operations-ui-regression.html'
    'Q28 5-check'
    'IMP-05 4/4'
    'G5 6/8'
    'G5-IMP-06-S01'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R98'
    'G5-IMP-05-S02'
    'AddonAiTaskGatewayV1::canRoute'
    'AddonAiTaskGatewayV1::snapshot'
    'API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:operations-diagnostics-g5'
    'check-operations-ai-routing-g5-imp-05-s02.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-operations-ai-routing-g5-imp-05-s02.ps1'
    'Operations diagnostics Q28 AI routing G5-IMP-05-S02 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\operations-ai-routing-g5-imp-05-s02.php'))

Write-Output 'G5-IMP-05-S02 passed: Q28 AI routing status is frozen for both add-on tasks without provider data, secrets, writes, or AI execution.'

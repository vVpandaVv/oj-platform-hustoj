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
$node = (Get-Command node -ErrorAction Stop).Source

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
            throw "G6-IMP-01 $Label marker is missing: $marker"
        }
    }
}

$scenarioEvidence = [ordered]@{
    'A-DS16-S01' = @('scripts\check-addon-api-consumer.ps1', 'scripts\check-fresh-install-g5-imp-06-s01.ps1')
    'A-DS16-S02' = @('scripts\check-worksheet-approval-publication-ui-s05.ps1')
    'A-DS16-S03' = @('scripts\check-worksheet-evaluation-integration-s09.ps1')
    'A-DS16-S04' = @('scripts\check-assignment-lifecycle-g3-imp-02.ps1')
    'A-DS16-S05' = @('scripts\check-student-assignment-g3-imp-03.ps1')
    'A-DS16-S06' = @('scripts\check-submission-evaluation-integration-g4-imp-02-s05.ps1')
    'A-DS16-S07' = @('scripts\check-submission-evaluation-review-integration-g4-imp-03-s04.ps1', 'scripts\check-submission-feedback-integration-g4-imp-04-s04.ps1', 'scripts\check-submission-result-ui-integration-g4-imp-06-s05.ps1')
    'A-DS16-S08' = @('scripts\check-report-dashboard-g5-imp-01-s04.ps1', 'scripts\check-report-snapshot-integration-g5-imp-02-s04.ps1', 'scripts\check-report-export-integration-g5-imp-03-s06.ps1')
    'A-DS16-S09' = @('scripts\check-worksheet-pdf-package-g2-imp-07.ps1', 'scripts\check-pdf-integration-g3-imp-08-s04.ps1', 'scripts\check-result-pdf-ui-integration-g4-imp-07-s04.ps1', 'scripts\check-report-export-integration-g5-imp-03-s06.ps1')
    'A-DS16-S10' = @('scripts\check-legacy-migration-integration-g5-imp-07-s06.ps1')
    'A-DS16-S11' = @('scripts\check-fresh-install-g5-imp-06-s01.ps1', 'scripts\check-sequential-upgrade-g5-imp-06-s02.ps1', 'scripts\check-failure-restart-g5-imp-06-s03.ps1', 'scripts\check-forward-repair-g5-imp-06-s04.ps1', 'scripts\check-data-preservation-g5-imp-06-s05.ps1')
    'A-DS16-S12' = @('scripts\check-purge-integration-g5-imp-08-s06.ps1')
}

$runnerSource = Get-Content -LiteralPath (Join-Path $root 'scripts\run-tests.mjs') -Raw -Encoding UTF8
foreach ($scenarioId in $scenarioEvidence.Keys) {
    foreach ($relativePath in $scenarioEvidence[$scenarioId]) {
        if (-not (Test-Path -LiteralPath (Join-Path $root $relativePath))) {
            throw "G6-IMP-01 evidence file is missing: $relativePath"
        }
        $runnerPath = $relativePath.Replace('\', '/')
        if (-not $runnerSource.Contains($runnerPath)) {
            throw "G6-IMP-01 aggregate runner evidence is missing: $runnerPath"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Push-Location $root
    try {
        Invoke-Checked $node @('scripts\run-tests.mjs')
    } finally {
        Pop-Location
    }
}

$contract = Join-Path $root 'tests\Contract\business-scenario-acceptance-g6-imp-01.php'
Invoke-Checked $php @('-l', $contract)

$runtimeFiles = Get-ChildItem -LiteralPath (Join-Path $root 'src') -Recurse -File -Filter '*.php'
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath $file.FullName -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
        if ($source.Contains($forbidden)) {
            throw "G6-IMP-01 crossed a frozen runtime boundary: $($file.FullName) -> $forbidden"
        }
    }
}

Assert-Markers 'docs\ai-assessment\g6-imp-01-business-scenario-acceptance.html' @(
    '<title>G6-IMP-01'
    'A-DS16-S01'
    'A-DS16-S12'
    'check:business-scenarios-g6'
    'G6 1/5'
    'Platform API change: 0'
    'G6-IMP-02'
) 'acceptance HTML'
Assert-Markers 'docs\ai-assessment\a-ds-16-cross-validation-freeze.html' @(
    'id="amendment-09"'
    'G6-IMP-01'
    'check:business-scenarios-g6'
    'G6-IMP-02'
) 'A-DS-16 synchronization'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g6-imp-01-business-scenario-acceptance.html'
    'G6-IMP-01'
    'G6 1/5'
    'G6-IMP-02'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\design-procedure.html' @(
    'g6-imp-01-business-scenario-acceptance.html'
    'G6-IMP-01'
    'G6 1/5'
) 'design procedure'
Assert-Markers 'docs\ai-assessment\index.html' @(
    'g6-imp-01-business-scenario-acceptance.html'
    'check:business-scenarios-g6'
    'G6 1/5'
) 'document index'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R126-OBSERVED'
    'G6-IMP-01'
    'Platform API change: 0'
    'G6-IMP-02'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:business-scenarios-g6'
    'check-business-scenarios-g6-imp-01.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-business-scenarios-g6-imp-01.ps1'
    '-SkipConstituentChecks'
    'G6-IMP-01 business scenarios passed.'
) 'aggregate runner'

Invoke-Checked $php @($contract)

Write-Output 'G6-IMP-01 passed: A-DS16-S01-S12 business scenarios are accepted through the existing aggregate regression, browser, PDF, migration, upgrade, and purge evidence.'

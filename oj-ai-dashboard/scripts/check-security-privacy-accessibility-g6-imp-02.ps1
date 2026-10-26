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
            throw "G6-IMP-02 $Label marker is missing: $marker"
        }
    }
}

$dimensionEvidence = [ordered]@{
    'G6-IMP-02-S01' = @(
        'scripts\check-access-policy.ps1', 'scripts\check-rest-foundation.ps1', 'scripts\check-context-rest-q01.ps1',
        'scripts\check-assignment-lifecycle-g3-imp-02.ps1', 'scripts\check-student-assignment-g3-imp-03.ps1',
        'scripts\check-submission-evaluation-review-integration-g4-imp-03-s04.ps1',
        'scripts\check-submission-feedback-integration-g4-imp-04-s04.ps1',
        'scripts\check-submission-result-ui-integration-g4-imp-06-s05.ps1',
        'scripts\check-report-dashboard-g5-imp-01-s04.ps1', 'scripts\check-purge-approval-g5-imp-08-s03.ps1'
    )
    'G6-IMP-02-S02' = @(
        'scripts\check-worksheet-projection-safety-s03.ps1', 'scripts\check-external-ai-consent-r01.ps1',
        'scripts\check-submission-assessment-input-g4-imp-01-s02.ps1',
        'scripts\check-submission-evaluation-worker-g4-imp-02-s02.ps1',
        'scripts\check-submission-external-ai-consent-g4-imp-02-r01.ps1',
        'scripts\check-submission-result-query-g4-imp-06-s02.ps1',
        'scripts\check-worksheet-report-detail-g5-imp-01-s02.ps1',
        'scripts\check-operations-diagnostics-g5-imp-05-s01.ps1',
        'scripts\check-operations-ai-routing-g5-imp-05-s02.ps1',
        'scripts\check-operations-storage-g5-imp-05-s03.ps1'
    )
    'G6-IMP-02-S03' = @(
        'scripts\check-worksheet-asset-s01.ps1', 'scripts\check-worksheet-asset-s02.ps1',
        'scripts\check-submission-zip-g3-imp-07.ps1', 'scripts\check-pdf-integration-g3-imp-08-s04.ps1',
        'scripts\check-result-pdf-access-g4-imp-07-s03.ps1',
        'scripts\check-report-export-job-download-g5-imp-03-s04.ps1',
        'scripts\check-report-export-integration-g5-imp-03-s06.ps1'
    )
    'G6-IMP-02-S04' = @(
        'scripts\check-worksheet-assets-ui-s05.ps1', 'scripts\check-worksheet-evaluation-workbench-s08.ps1',
        'scripts\check-assignment-g3-imp-01.ps1', 'scripts\check-assignment-lifecycle-g3-imp-02.ps1',
        'scripts\check-student-assignment-g3-imp-03.ps1',
        'scripts\check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1',
        'scripts\check-submission-feedback-integration-g4-imp-04-s04.ps1',
        'scripts\check-submission-result-ui-integration-g4-imp-06-s05.ps1',
        'scripts\check-report-dashboard-g5-imp-01-s04.ps1',
        'scripts\check-assessment-configuration-bootstrap-s01.ps1', 'scripts\check-external-ai-consent-r01.ps1',
        'scripts\check-operations-diagnostics-ui-g5-imp-05-s04.ps1'
    )
    'G6-IMP-02-S05' = @(
        'scripts\check-worksheet-assets-ui-s05.ps1', 'scripts\check-worksheet-evaluation-workbench-s08.ps1',
        'scripts\check-assignment-g3-imp-01.ps1', 'scripts\check-assignment-lifecycle-g3-imp-02.ps1',
        'scripts\check-student-assignment-g3-imp-03.ps1',
        'scripts\check-submission-evaluation-review-workbench-g4-imp-03-s03.ps1',
        'scripts\check-submission-feedback-integration-g4-imp-04-s04.ps1',
        'scripts\check-submission-result-ui-integration-g4-imp-06-s05.ps1',
        'scripts\check-report-dashboard-g5-imp-01-s04.ps1',
        'scripts\check-assessment-configuration-bootstrap-s01.ps1', 'scripts\check-external-ai-consent-r01.ps1',
        'scripts\check-operations-diagnostics-ui-g5-imp-05-s04.ps1'
    )
}

$runnerSource = Get-Content -LiteralPath (Join-Path $root 'scripts\run-tests.mjs') -Raw -Encoding UTF8
foreach ($dimension in $dimensionEvidence.Keys) {
    foreach ($relativePath in $dimensionEvidence[$dimension]) {
        if (-not (Test-Path -LiteralPath (Join-Path $root $relativePath))) {
            throw "G6-IMP-02 evidence file is missing: $relativePath"
        }
        if (-not $runnerSource.Contains($relativePath.Replace('\', '/'))) {
            throw "G6-IMP-02 aggregate runner evidence is missing: $relativePath"
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

$contract = Join-Path $root 'tests\Contract\security-privacy-accessibility-g6-imp-02.php'
Invoke-Checked $php @('-l', $contract)
Invoke-Checked $php @($contract)

# The PHP contract above verifies settings semantics in rendered HTML, including dynamic tabs.
Assert-Markers 'resources\js\assessment-configuration-settings.js' @(
    'moveTabFocus', 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'tabIndex', '.focus()'
) 'settings keyboard'
Assert-Markers 'resources\js\external-ai-consent-settings.js' @(
    "selectScope('worksheet')", 'ArrowLeft', 'ArrowRight', 'Home', 'End', 'tabIndex', '.focus()'
) 'consent keyboard'
Assert-Markers 'resources\css\assessment-configuration-settings.css' @(':focus-visible', 'outline: 3px solid var(--ojp-color-focus, #f59e0b)') 'settings focus'
Assert-Markers 'resources\css\external-ai-consent-settings.css' @(':focus-visible', 'outline: 3px solid var(--ojp-color-focus, #f59e0b)') 'consent focus'
Assert-Markers 'docs\ai-assessment\g6-imp-02-security-privacy-accessibility.html' @(
    '<title>G6-IMP-02', 'G6-IMP-02-S01', 'G6-IMP-02-S05', ([regex]::Unescape('\uAE30\uC874 \uAD00\uBB38 33\uAC1C')), ([regex]::Unescape('\uBE0C\uB77C\uC6B0\uC800 12\uC885')),
    ([regex]::Unescape('\uC911\uB300 \uBBF8\uD574\uACB0 \uACB0\uD568 0\uAC74')), 'check:security-privacy-accessibility-g6', 'G6 2/5', 'Platform API change: 0', 'G6-IMP-03'
) 'acceptance HTML'
Assert-Markers 'docs\ai-assessment\a-ds-16-cross-validation-freeze.html' @(
    'id="amendment-10"', 'G6-IMP-02', ([regex]::Unescape('\uAE30\uC874 \uAD00\uBB38 33\uAC1C')), ([regex]::Unescape('\uBE0C\uB77C\uC6B0\uC800 12\uC885')),
    'check:security-privacy-accessibility-g6', 'G6 2/5', 'G6-IMP-03'
) 'A-DS-16 synchronization'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g6-imp-02-security-privacy-accessibility.html', 'G6-IMP-02', 'G6 2/5', 'G6-IMP-03'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\design-procedure.html' @(
    'g6-imp-02-security-privacy-accessibility.html', 'G6-IMP-02', 'G6 2/5', 'G6-IMP-03'
) 'design procedure'
Assert-Markers 'docs\ai-assessment\index.html' @(
    'g6-imp-02-security-privacy-accessibility.html', 'check:security-privacy-accessibility-g6', 'G6 2/5', 'G6-IMP-03'
) 'document index'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R127-OBSERVED', 'G6-IMP-02', 'Platform API change: 0', 'G6-IMP-03'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:security-privacy-accessibility-g6', 'check-security-privacy-accessibility-g6-imp-02.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-security-privacy-accessibility-g6-imp-02.ps1', '-SkipConstituentChecks',
    'G6-IMP-02 security, privacy, and accessibility passed.'
) 'aggregate runner'

Write-Output 'G6-IMP-02 passed: permission negatives, privacy and secret non-disclosure, private upload/download, keyboard/focus/screen-reader semantics, and desktop/mobile visual checks have zero unresolved critical findings.'

[CmdletBinding()]
param(
    [switch]$SkipConstituentChecks
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
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G6-IMP-03 $Label marker is missing: $marker"
        }
    }
}

$dimensionEvidence = [ordered]@{
    'G6-IMP-03-S01' = @(
        'scripts\check-assignment-g3-imp-01.ps1'
    )
    'G6-IMP-03-S02' = @(
        'scripts\check-worksheet-evaluation-worker-s04.ps1',
        'scripts\check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1',
        'scripts\check-submission-evaluation-worker-g4-imp-02-s02.ps1',
        'scripts\check-submission-feedback-g4-imp-04-s01.ps1',
        'scripts\check-student-note-draft-g4-imp-05-s01.ps1',
        'scripts\check-worksheet-pdf-package-g2-imp-07.ps1',
        'scripts\check-submission-pdf-g3-imp-08.ps1',
        'scripts\check-result-pdf-job-g4-imp-07-s02.ps1',
        'scripts\check-report-export-job-download-g5-imp-03-s04.ps1'
    )
    'G6-IMP-03-S03' = @(
        'scripts\check-worksheet-document-profile-s03.ps1',
        'scripts\check-worksheet-asset-s01.ps1',
        'scripts\check-student-assignment-g3-imp-03.ps1',
        'scripts\check-submission-zip-g3-imp-07.ps1',
        'scripts\check-submission-bundle-enqueue-g5-imp-03-s02.ps1'
    )
    'G6-IMP-03-S04' = @(
        'scripts\check-worksheet-report-list-g5-imp-01-s01.ps1',
        'scripts\check-worksheet-report-detail-g5-imp-01-s02.ps1',
        'scripts\check-report-snapshot-integration-g5-imp-02-s04.ps1',
        'scripts\check-report-export-enqueue-g5-imp-03-s01.ps1',
        'scripts\check-submission-bundle-enqueue-g5-imp-03-s02.ps1',
        'scripts\check-report-json-package-enqueue-g5-imp-03-s03.ps1',
        'scripts\check-report-export-job-download-g5-imp-03-s04.ps1',
        'scripts\check-report-export-control-g5-imp-03-s05.ps1',
        'scripts\check-report-export-integration-g5-imp-03-s06.ps1'
    )
    'G6-IMP-03-S05' = @(
        'scripts\check-failure-restart-g5-imp-06-s03.ps1',
        'scripts\check-forward-repair-g5-imp-06-s04.ps1',
        'scripts\check-legacy-migration-recovery-g5-imp-07-s03-f.ps1',
        'scripts\check-legacy-migration-integration-g5-imp-07-s06.ps1',
        'scripts\check-purge-execution-g5-imp-08-s04.ps1',
        'scripts\check-purge-integration-g5-imp-08-s06.ps1',
        'scripts\check-submission-evaluation-queue-lease-g4-imp-02-s01.ps1',
        'scripts\check-report-export-job-download-g5-imp-03-s04.ps1'
    )
}

$runnerSource = Get-Content -LiteralPath (Join-Path $root 'scripts\run-tests.mjs') -Raw -Encoding UTF8
foreach ($dimension in $dimensionEvidence.Keys) {
    foreach ($relativePath in $dimensionEvidence[$dimension]) {
        if (-not (Test-Path -LiteralPath (Join-Path $root $relativePath))) {
            throw "G6-IMP-03 evidence file is missing: $relativePath"
        }
        if (-not (Test-OjaiPhpMarker -Source $runnerSource -Marker $relativePath.Replace('\', '/') -Php $php)) {
            throw "G6-IMP-03 aggregate runner evidence is missing: $relativePath"
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

$contract = Join-Path $root 'tests\Contract\performance-resilience-g6-imp-03.php'
Invoke-Checked $php @('-l', $contract)
Invoke-Checked $php @($contract)

Assert-Markers 'src\Submission\Assignment\Infrastructure\Platform_Assignment_Roster.php' @(
    'private const PAGE_LIMIT = 100;', 'private const MAXIMUM_PAGES = 50;', 'private const MAXIMUM_LEARNERS = 5000;'
) 'roster bounds'
Assert-Markers 'src\Submission\Assessment\Application\Run_Next_Submission_Evaluation.php' @(
    'public const LEASE_SECONDS = 300;', 'public const MAXIMUM_ATTEMPTS = 3;', '60 : 300', 'worker_lost'
) 'queue recovery'
Assert-Markers 'src\Worksheet\Authoring\Domain\Worksheet_Document_Profile.php' @(
    'MAX_DOCUMENT_BYTES', 'MAX_NODES', 'MAX_DEPTH', 'MAX_FILE_BYTES'
) 'document bounds'
Assert-Markers 'src\Submission\ZipImport\Domain\Submission_Zip_Upload_Profile.php' @(
    'MAX_ARCHIVE_BYTES', 'MAX_REGULAR_ENTRIES', 'MAX_ENTRY_BYTES', 'MAX_UNCOMPRESSED_BYTES', 'MAX_COMPRESSION_RATIO'
) 'archive bounds'
Assert-Markers 'src\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot.php' @(
    'MAXIMUM_ROWS', 'MAXIMUM_BYTES'
) 'report export bounds'
Assert-Markers 'src\Operations\LegacyMigration\Application\Execute_Legacy_Migration.php' @(
    'CHECKPOINT_PROFILE', 'BATCH_SIZE'
) 'migration recovery'
Assert-Markers 'src\Operations\Purge\Application\Execute_Purge.php' @(
    'CHECKPOINT_PROFILE', 'BATCH_SIZE', 'BACKUP_MAXIMUM_AGE_SECONDS'
) 'purge recovery'
Assert-Markers 'docs\ai-assessment\g6-imp-03-performance-resilience.html' @(
    '<title>G6-IMP-03', 'G6-IMP-03-S01', 'G6-IMP-03-S05', '28 existing gates',
    'check:performance-resilience-g6', 'G6 3/5', 'Platform API change: 0', 'G6-IMP-04'
) 'acceptance HTML'
Assert-Markers 'docs\ai-assessment\a-ds-16-cross-validation-freeze.html' @(
    'id="amendment-11"', 'G6-IMP-03', '28 existing gates',
    'check:performance-resilience-g6', 'G6 3/5', 'G6-IMP-04'
) 'A-DS-16 synchronization'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g6-imp-03-performance-resilience.html', 'G6-IMP-03', 'G6 3/5', 'G6-IMP-04'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\design-procedure.html' @(
    'g6-imp-03-performance-resilience.html', 'G6-IMP-03', 'G6 3/5', 'G6-IMP-04'
) 'design procedure'
Assert-Markers 'docs\ai-assessment\index.html' @(
    'g6-imp-03-performance-resilience.html', 'check:performance-resilience-g6', 'G6 3/5', 'G6-IMP-04'
) 'document index'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R128-OBSERVED', 'G6-IMP-03', 'Platform API change: 0', 'G6-IMP-04'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:performance-resilience-g6', 'check-performance-resilience-g6-imp-03.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-performance-resilience-g6-imp-03.ps1', '-SkipConstituentChecks',
    'G6-IMP-03 performance and resilience passed.'
) 'aggregate runner'

Write-Output 'G6-IMP-03 passed: 28 existing gates enforce the frozen roster, queue, document, archive, report, export, checkpoint, repair, and restart bounds.'

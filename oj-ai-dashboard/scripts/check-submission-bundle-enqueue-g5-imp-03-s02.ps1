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
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) {
            throw "G5-IMP-03-S02 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-report-export-enqueue-g5-imp-03-s01.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle.php'
    'src\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle_Command.php'
    'src\Reporting\SubmissionBundle\Application\Enqueue_Submission_Bundle_Result.php'
    'src\Reporting\SubmissionBundle\Application\Submission_Bundle_Failure.php'
    'src\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot.php'
    'src\Reporting\SubmissionBundle\Port\Submission_Bundle_Queue_Wakeup_Port_Interface.php'
    'src\Reporting\SubmissionBundle\Port\Submission_Bundle_Repository_Port_Interface.php'
    'src\Reporting\SubmissionBundle\Port\Submission_Bundle_Source_Port_Interface.php'
    'src\Reporting\SubmissionBundle\Infrastructure\WordPress_Submission_Bundle_Queue_Wakeup.php'
    'src\Reporting\SubmissionBundle\Infrastructure\WordPress_Submission_Bundle_Repository.php'
    'src\Reporting\SubmissionBundle\Presentation\Submission_Bundle_Rest_Controller.php'
    'src\Reporting\SubmissionBundle\Presentation\Submission_Bundle_Rest_Module.php'
    'src\Submission\Assignment\Infrastructure\WordPress_Submission_Bundle_Source_Query.php'
    'src\Submission\Assignment\Infrastructure\Assignment_Object_Authorization.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\submission-bundle-enqueue-g5-imp-03-s02.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php (@($phpArgs) + @('-l', (Join-Path $root $file)))
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object {
    Get-Content -LiteralPath (Join-Path $root $_) -Raw -Encoding UTF8
}) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) {
        throw "G5-IMP-03-S02 contains a forbidden boundary marker: $forbidden"
    }
}

Assert-Markers 'src\Reporting\SubmissionBundle\Domain\Submission_Bundle_Request_Snapshot.php' @(
    "SCHEMA = 'oj_ai.submission_bundle_request'"
    "SOURCE_SCHEMA = 'oj_ai.assignment_submission_bundle_source'"
    "PROFILE = 'submission_bundle.v1'"
    "ARTIFACT_KIND = 'submission_bundle'"
    'MAXIMUM_ARCHIVE_FILES = 500'
    'MAXIMUM_ARCHIVE_BYTES = 524_288_000'
    'manifest.csv'
    'checksums.sha256'
    'pdf_path'
    'archive_path'
) 'immutable bundle request'
Assert-Markers 'src\Submission\Assignment\Infrastructure\WordPress_Submission_Bundle_Source_Query.php' @(
    "TABLE_ASSIGNMENTS = 'oj_ai_assignments'"
    "TABLE_TARGETS = 'oj_ai_assignment_targets'"
    "TABLE_SUBMISSIONS = 'oj_ai_submissions'"
    "TABLE_REVISIONS = 'oj_ai_submission_revisions'"
    "TABLE_ATTACHMENTS = 'oj_ai_submission_attachments'"
    's.official_revision_public_id'
    "AND state = %s"
    'ORDER BY t.public_id ASC'
    "'attachments/%04d-%s/%s.%s'"
) 'Submission-owner projection'
Assert-Markers 'src\Reporting\SubmissionBundle\Presentation\Submission_Bundle_Rest_Controller.php' @(
    '/commands/export-submissions-zip'
    'Addon_Capabilities::EXPORT_REPORTS'
    'Context_Scope::OPERATION_REPORT'
    'Object_Scope::TYPE_ASSIGNMENT'
    "'export_submissions'"
    'expected_assignment_hash'
    'expected_schedule_version'
    'include_allowed_originals'
    "'source_type' => 'assignment'"
) 'C50 REST policy'
Assert-Markers 'src\Reporting\SubmissionBundle\Infrastructure\WordPress_Submission_Bundle_Repository.php' @(
    "TABLE_JOBS = 'oj_ai_export_jobs'"
    "TABLE_AUDIT = 'oj_ai_audit_events'"
    "TABLE_OUTBOX = 'oj_ai_outbox_events'"
    "'report_snapshot_id' => null"
    "'source_type' => 'assignment'"
    'START TRANSACTION'
    'FOR UPDATE'
    'active_identity_key'
) 'literal atomic persistence'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Submission_Bundle_Source_Query( null, $canonical )'
    'new WordPress_Submission_Bundle_Repository( $canonical )'
    'new Enqueue_Submission_Bundle('
    'new Submission_Bundle_Rest_Module( $submission_bundle_rest )'
) 'composition root'
Assert-Markers 'tests\Contract\submission-bundle-enqueue-g5-imp-03-s02.php' @(
    'C50 did not collect the full official submission source twice.'
    'C50 placed a user filename in the archive path.'
    'C50 accepted more than 500 archive files.'
    'C50 accepted an assignment bundle larger than 500 MiB.'
    'C50 rollback left a partial export job.'
    'G5-IMP-03-S02 passed:'
) 'vertical contract'
Assert-Markers 'docs\ai-assessment\g5-imp-03-s02-submission-bundle-enqueue.html' @(
    'G5-IMP-03-S02'
    'IMP-03 2/6'
    'submission_bundle.v1'
    '500 files'
    '500 MiB'
    'Platform API change: 0'
    'G5-IMP-03-S03'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-03-s02-submission-bundle-enqueue.html'
    'G5-IMP-03-S02'
    'g5-imp-03-s03-report-json-package-enqueue.html'
    'G5-IMP-03-S03'
    'g5-imp-03-s04-report-export-job-download.html'
    'G5-IMP-03-S04'
    'Q23'
    'Q24'
) 'implementation roadmap'
Assert-Markers 'package.json' @(
    'check:submission-bundle-enqueue-g5'
    'check-submission-bundle-enqueue-g5-imp-03-s02.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-submission-bundle-enqueue-g5-imp-03-s02.ps1'
    'Submission bundle C50 G5-IMP-03-S02 passed.'
) 'aggregate runner'

Invoke-Checked $php (@($phpArgs) + @((Join-Path $root 'tests\Contract\submission-bundle-enqueue-g5-imp-03-s02.php')))

Write-Output 'G5-IMP-03-S02 passed: C50 exact assignment and official revisions, server-generated paths, 500-file/500-MiB bounds, durable idempotency, and atomic enqueue are frozen.'

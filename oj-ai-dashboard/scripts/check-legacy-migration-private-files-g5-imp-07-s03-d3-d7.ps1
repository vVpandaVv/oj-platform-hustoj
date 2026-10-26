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
            throw "G5-IMP-07-S03-D3-D7 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-legacy-migration-evaluation-g5-imp-07-s03-d3-d6.ps1'),
        '-SkipConstituentChecks'
    )
}

$phpFiles = @(
    'src\Operations\LegacyMigration\Domain\Legacy_Source_Blob.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Source_Blob_Reader.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Submission_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Zip_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository.php',
    'src\Operations\LegacyMigration\Port\Legacy_Source_Blob_Reader_Port_Interface.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Zip_Writer_Port_Interface.php',
    'src\Operations\LegacyMigration\Port\Legacy_Target_Batch_Lifecycle_Port_Interface.php',
    'tests\Contract\legacy-migration-private-files-g5-imp-07-s03-d3-d7.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$boundaryFiles = @(
    'src\Operations\LegacyMigration\Domain\Legacy_Source_Blob.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Source_Blob_Reader.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Submission_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Zip_Writer.php',
    'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php',
    'src\Operations\LegacyMigration\Port\Legacy_Source_Blob_Reader_Port_Interface.php',
    'src\Operations\LegacyMigration\Port\Legacy_Migration_Zip_Writer_Port_Interface.php',
    'src\Operations\LegacyMigration\Port\Legacy_Target_Batch_Lifecycle_Port_Interface.php'
)
foreach ($file in $boundaryFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $file) -Raw -Encoding UTF8
    foreach ($forbidden in @('$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'START TRANSACTION', 'COMMIT', 'ROLLBACK', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'ALTER TABLE', 'CREATE TABLE', 'oj_ai_audit_events', 'oj_ai_outbox_events')) {
        if ($source.Contains($forbidden)) {
            throw "G5-IMP-07-S03-D3-D7 runtime contains a forbidden marker: $file -> $forbidden"
        }
    }
    if ($source -match 'CodingPanda\\OjPlatform\\Kernel\\') {
        throw "G5-IMP-07-S03-D3-D7 runtime references an OJ Platform implementation namespace: $file"
    }
}

Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Source_Blob_Reader.php' @(
    'get_attached_file',
    'wp_get_upload_dir',
    'realpath',
    'is_link',
    "hash_file( 'sha256'"
) 'uploads-root source reader'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Submission_Writer.php' @(
    "'oj_ai_private_files'",
    "'oj_ai_submission_attachments'",
    'Submission_Attachment_Upload_Profile',
    'Submission_Attachment_Content_Inspector',
    'Submission_Response_Validator',
    'put_quarantined',
    'commit_attachment_finalization',
    'compensate_from'
) 'private attachment writer'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Zip_Writer.php' @(
    "'oj_ai_zip_uploads'",
    "'oj_ai_zip_upload_items'",
    "'oj_ai_private_files'",
    'Submission_Zip_Upload_Profile',
    'Submission_Zip_Entry_Reader',
    'Submission_Zip_Item_Content_Inspector',
    'source_item_manifest',
    'entry_for_path',
    "'selection' => null",
    "array( 'byte_size','compressed_entry_bytes','entry_count','sha256','uncompressed_bytes' )",
    'compensate_from'
) 'private ZIP writer'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Target_Writer.php' @(
    'Legacy_Migration_Zip_Writer_Port_Interface',
    "array( 'zip_uploads', 'zip_upload_items'",
    'zip_writer->write',
    'lifecycle_writers'
) 'owner dispatch'
Assert-Markers 'src\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository.php' @(
    'Legacy_Target_Batch_Lifecycle_Port_Interface',
    'begin_batch',
    'commit_batch',
    'rollback_batch'
) 'SQL and object lifecycle bridge'
Assert-Markers 'tests\Contract\legacy-migration-private-files-g5-imp-07-s03-d3-d7.php' @(
    'Uploads-root escape was accepted',
    'Migration receipt accepted private source metadata',
    'ZIP rollback did not compensate newly created objects in reverse order.',
    'Attachment rollback did not compensate newly created objects in reverse order.',
    'Target writer did not dispatch both ZIP source types.'
) 'contract fixture'
Assert-Markers 'docs\ai-assessment\g5-imp-07-s03-d3-d-owner-migration.html' @(
    'G5-IMP-07-S03-D3-D7',
    'id="d3-d7-result"',
    'check:legacy-migration-private-files-g5',
    'Platform API change: 0'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'G5-IMP-07-S03-D3-D7',
    'D3-D 7/8',
    'G5-IMP-07-S03-D3-D8',
    'check:legacy-migration-private-files-g5'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'API-AUD-2026-08-26-R113-OBSERVED',
    'G5-IMP-07-S03-D3-D7',
    'check:legacy-migration-private-files-g5',
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:legacy-migration-private-files-g5',
    'check-legacy-migration-private-files-g5-imp-07-s03-d3-d7.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-legacy-migration-private-files-g5-imp-07-s03-d3-d7.ps1',
    'Legacy migration private files G5-IMP-07-S03-D3-D7 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-private-files-g5-imp-07-s03-d3-d7.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\legacy-migration-batch-g5-imp-07-s03.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\submission-zip-stage-g3-imp-07-s01.php'))
Invoke-Checked $php @((Join-Path $root 'tests\Contract\submission-zip-scan-preview-g3-imp-07-s02.php'))

Write-Output 'G5-IMP-07-S03-D3-D7 passed: private attachment and ZIP byte restoration, current safety reuse, compensation, receipt privacy, caller rollback, and zero platform writes are frozen.'

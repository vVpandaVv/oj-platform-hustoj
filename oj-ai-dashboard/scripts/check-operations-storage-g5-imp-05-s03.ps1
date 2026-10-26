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
            throw "G5-IMP-05-S03 $Label marker is missing: $marker"
        }
    }
}

if (-not $SkipConstituentChecks) {
    Invoke-Checked 'powershell' @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $PSScriptRoot 'check-operations-ai-routing-g5-imp-05-s02.ps1')
    )
}

$phpFiles = @(
    'src\Storage\Infrastructure\Local_Private_Storage_Root.php'
    'src\Storage\Infrastructure\Local_Private_File_Store.php'
    'src\Operations\Diagnostics\Application\Read_System_Diagnostics.php'
    'src\Operations\Diagnostics\Domain\Storage_Path_Diagnostics.php'
    'src\Operations\Diagnostics\Domain\Storage_Diagnostics_Result.php'
    'src\Operations\Diagnostics\Port\Storage_Diagnostics_Port_Interface.php'
    'src\Operations\Diagnostics\Infrastructure\Local_Private_Storage_Path_Diagnostics.php'
    'src\Operations\Diagnostics\Infrastructure\WordPress_Private_Storage_Diagnostics.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\operations-storage-g5-imp-05-s03.php'
)
foreach ($file in $phpFiles) {
    Invoke-Checked $php @('-l', (Join-Path $root $file))
}

$adapterPath = Join-Path $root 'src\Operations\Diagnostics\Infrastructure\WordPress_Private_Storage_Diagnostics.php'
$adapterSource = Get-Content -LiteralPath $adapterPath -Raw -Encoding UTF8
foreach ($forbidden in @(
    '$wpdb->prefix',
    'wp_oj_ai_',
    'storage_key',
    'original_name',
    'sha256',
    '->insert(',
    '->update(',
    '->delete(',
    '->query('
)) {
    if ($adapterSource.Contains($forbidden)) {
        throw "G5-IMP-05-S03 storage adapter contains a forbidden boundary marker: $forbidden"
    }
}

$pathPath = Join-Path $root 'src\Operations\Diagnostics\Infrastructure\Local_Private_Storage_Path_Diagnostics.php'
$pathSource = Get-Content -LiteralPath $pathPath -Raw -Encoding UTF8
foreach ($forbidden in @('mkdir(', 'fopen(', 'file_put_contents(', 'rename(', 'unlink(', 'chmod(')) {
    if ($pathSource.Contains($forbidden)) {
        throw "G5-IMP-05-S03 path diagnostic contains a filesystem mutation marker: $forbidden"
    }
}

Assert-Markers 'src\Operations\Diagnostics\Infrastructure\WordPress_Private_Storage_Diagnostics.php' @(
    "private const TABLE_PRIVATE_FILES = 'oj_ai_private_files';"
    "'FROM ' . self::TABLE_PRIVATE_FILES"
    'Submission_Attachment_Scan_Worker_Module::SWEEP_HOOK'
    'Submission_Attachment_Cleanup_Worker_Module::SWEEP_HOOK'
    'Submission_Zip_Scan_Worker_Module::SWEEP_HOOK'
    'Submission_Zip_Cleanup_Worker_Module::SWEEP_HOOK'
    'private const WINDOW_MINUTES = 15;'
    "self::WINDOW_MINUTES . ' minutes'"
    "'expired'"
    "'storage_metadata_inconsistent'"
    "'storage_scan_delayed'"
    "'storage_cleanup_delayed'"
) 'private storage adapter'
Assert-Markers 'src\Operations\Diagnostics\Infrastructure\Local_Private_Storage_Path_Diagnostics.php' @(
    'Local_Private_Storage_Root::resolve('
    "'storage_path_not_private'"
    "'storage_path_not_readable'"
    "'storage_path_not_writable'"
    "'storage_space_unavailable'"
    'disk_free_space('
) 'private path projection'
Assert-Markers 'src\Storage\Infrastructure\Local_Private_Storage_Root.php' @(
    'OJ_AI_PRIVATE_STORAGE_DIR'
    "'oj-ai-private'"
    "'site-'"
) 'canonical private root'
Assert-Markers 'src\Storage\Infrastructure\Local_Private_File_Store.php' @(
    'Local_Private_Storage_Root::resolve( $root )'
) 'file store root reuse'
Assert-Markers 'src\Operations\Diagnostics\Application\Read_System_Diagnostics.php' @(
    "`$checks['storage']"
    "'resume_storage_jobs'"
    "'check_private_storage'"
    "'retry_storage_check'"
) 'Q28 storage integration'
Assert-Markers 'src\Core\Composition_Root.php' @(
    'new WordPress_Private_Storage_Diagnostics()'
) 'Composition Root'
Assert-Markers 'tests\Contract\operations-storage-g5-imp-05-s03.php' @(
    'Q28 did not inspect the four storage scan and cleanup workers exactly once.'
    'Storage diagnostics selected a private storage key.'
    'Storage diagnostics rejected the cleanup worker expired state.'
    'Read-only storage diagnostics created the private root.'
    'Writable HOME fallback was not selected outside the web root.'
    'An existing legacy private root was not preserved.'
    'Q28 five-check order drifted.'
    'Storage diagnostics crossed a read-only or privacy boundary:'
    'G5-IMP-05-S03 passed:'
) 'contract test'
Assert-Markers 'docs\ai-assessment\g5-imp-05-s03-storage-secret-diagnostics.html' @(
    'IMP-05 3/4'
    'Q28 5-check'
    'oj_ai_private_files'
    'cleanup worker'
    'Platform API change: 0'
    'G5-IMP-05-S04'
) 'implementation evidence HTML'
Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g5-imp-05-s03-storage-secret-diagnostics.html'
    'g5-imp-05-s04-operations-ui-regression.html'
    'Q28 5-check'
    'IMP-05 4/4'
    'G5 6/8'
    'G5-IMP-06-S01'
) 'implementation roadmap'
Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'R99'
    'G5-IMP-05-S03'
    'oj_ai_private_files'
    'Platform API change: 0'
) 'platform API audit'
Assert-Markers 'package.json' @(
    'check:operations-diagnostics-g5-s02'
    'check:operations-diagnostics-g5'
    'check-operations-storage-g5-imp-05-s03.ps1'
) 'package command'
Assert-Markers 'scripts\run-tests.mjs' @(
    'check-operations-storage-g5-imp-05-s03.ps1'
    'Operations diagnostics Q28 storage and secret protection G5-IMP-05-S03 passed.'
) 'aggregate runner'

Invoke-Checked $php @((Join-Path $root 'tests\Contract\operations-storage-g5-imp-05-s03.php'))

Write-Output 'G5-IMP-05-S03 passed: Q28 private storage status is frozen without paths, file identity, secrets, writes, or AI execution.'

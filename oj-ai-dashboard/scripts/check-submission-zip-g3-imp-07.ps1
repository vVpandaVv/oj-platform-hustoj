param()

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = if (Test-Path -LiteralPath 'D:\php\php.exe') { 'D:\php\php.exe' } else { (Get-Command php -ErrorAction Stop).Source }
$phpFiles = @(
    'src\Submission\ZipImport\Application\Zip_Import_Failure.php',
    'src\Submission\ZipImport\Application\Confirm_Submission_Zip_Command.php',
    'src\Submission\ZipImport\Application\Confirm_Submission_Zip_Result.php',
    'src\Submission\ZipImport\Application\Confirm_Submission_Zip.php',
    'src\Submission\ZipImport\Application\Cancel_Submission_Zip_Command.php',
    'src\Submission\ZipImport\Application\Cancel_Submission_Zip_Result.php',
    'src\Submission\ZipImport\Application\Cancel_Submission_Zip.php',
    'src\Submission\ZipImport\Application\Map_Submission_Zip_Items_Command.php',
    'src\Submission\ZipImport\Application\Run_Next_Submission_Zip_Cleanup.php',
    'src\Submission\ZipImport\Application\Run_Submission_Zip_Cleanup_Result.php',
    'src\Submission\ZipImport\Application\Map_Submission_Zip_Items_Result.php',
    'src\Submission\ZipImport\Application\Map_Submission_Zip_Items.php',
    'src\Submission\ZipImport\Application\Read_Submission_Zip_Preview.php',
    'src\Submission\ZipImport\Application\Run_Next_Submission_Zip_Scan.php',
    'src\Submission\ZipImport\Application\Run_Submission_Zip_Scan_Result.php',
    'src\Submission\ZipImport\Application\Stage_Submission_Zip_Command.php',
    'src\Submission\ZipImport\Application\Stage_Submission_Zip_Result.php',
    'src\Submission\ZipImport\Application\Stage_Submission_Zip.php',
    'src\Submission\ZipImport\Domain\Submission_Zip_Upload_Profile.php',
    'src\Submission\ZipImport\Domain\Submission_Zip_Cleanup_Item.php',
    'src\Submission\ZipImport\Domain\Submission_Zip_Entry.php',
    'src\Submission\ZipImport\Domain\Submission_Zip_Entry_Reader.php',
    'src\Submission\ZipImport\Port\Submission_Zip_Cleanup_Repository_Port_Interface.php',
    'src\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Inspector.php',
    'src\Submission\ZipImport\Domain\Submission_Zip_Item_Content_Verdict.php',
    'src\Submission\ZipImport\Domain\Submission_Zip_Scan_Job.php',
    'src\Submission\ZipImport\Domain\Submission_Zip_Suggestion_Matcher.php',
    'src\Submission\ZipImport\Port\Submission_Zip_Repository_Port_Interface.php',
    'src\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Repository.php',
    'src\Submission\ZipImport\Infrastructure\Submission_Zip_Cleanup_Worker_Module.php',
    'src\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Cleanup_Repository.php',
    'src\Submission\ZipImport\Port\Submission_Zip_Finalization_Repository_Port_Interface.php',
    'src\Submission\ZipImport\Port\Submission_Zip_Mapping_Repository_Port_Interface.php',
    'src\Submission\ZipImport\Port\Submission_Zip_Preview_Query_Port_Interface.php',
    'src\Submission\ZipImport\Port\Submission_Zip_Scan_Repository_Port_Interface.php',
    'src\Submission\ZipImport\Presentation\Submission_Zip_Rest_Controller.php',
    'src\Submission\ZipImport\Infrastructure\Submission_Zip_Scan_Worker_Module.php',
    'src\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Finalization_Repository.php',
    'src\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Mapping_Repository.php',
    'src\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Preview_Query.php',
    'src\Submission\ZipImport\Infrastructure\WordPress_Submission_Zip_Scan_Repository.php',
    'src\Submission\ZipImport\Presentation\Submission_Zip_Rest_Module.php',
    'src\Submission\Assignment\Infrastructure\Assignment_Object_Authorization.php',
    'src\Storage\Port\Private_File_Staging_Port_Interface.php',
    'src\Storage\Port\Private_File_Store_Port_Interface.php',
    'src\Storage\Infrastructure\WordPress_Private_File_Staging_Repository.php',
    'src\Storage\Infrastructure\Local_Private_File_Store.php',
    'src\Core\Composition_Root.php',
    'src\Core\Deactivation.php',
    'tests\Contract\submission-zip-stage-g3-imp-07-s01.php',
    'tests\Contract\submission-zip-scan-preview-g3-imp-07-s02.php',
    'tests\Contract\submission-zip-mapping-g3-imp-07-s03.php',
    'tests\Contract\submission-zip-confirm-cancel-g3-imp-07-s04.php',
    'tests\Contract\submission-zip-integration-freeze-g3-imp-07-s05.php'
)

foreach ($relative in $phpFiles) {
    & $php -l (Join-Path $root $relative)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { -not $_.StartsWith('tests\') }
$forbiddenRuntimePrefix = '$wpdb' + '->prefix'
$legacyTablePrefix = 'wp_' + 'oj_ai_'
foreach ($relative in $runtimeFiles) {
    $source = Get-Content -LiteralPath (Join-Path $root $relative) -Raw
    if ($source.Contains($forbiddenRuntimePrefix) -or $source.Contains($legacyTablePrefix)) {
        throw "Forbidden add-on table prefix marker found in $relative"
    }
    if ($source.Contains('CodingPanda\OjPlatform\Kernel\')) {
        throw "Platform internal namespace found in $relative"
    }
}

$zipSources = Get-ChildItem -LiteralPath (Join-Path $root 'src\Submission\ZipImport') -File -Recurse
foreach ($sourceFile in $zipSources) {
    $source = Get-Content -LiteralPath $sourceFile.FullName -Raw
    if ($source.Contains('Zip_Safety_Validator') -or $source.Contains('Zip_Upload_Service')) {
        throw "Legacy ZIP implementation dependency found in $($sourceFile.FullName)"
    }
}

& $php (Join-Path $root 'tests\Contract\submission-zip-stage-g3-imp-07-s01.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\submission-zip-scan-preview-g3-imp-07-s02.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\submission-zip-mapping-g3-imp-07-s03.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\submission-zip-confirm-cancel-g3-imp-07-s04.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $php (Join-Path $root 'tests\Contract\submission-zip-integration-freeze-g3-imp-07-s05.php')
exit $LASTEXITCODE

param()

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

$runtimeFiles = @(
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Worksheet_Package_Failure.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package_Command.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package_Result.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Build_Worksheet_Package_Source.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Worksheet_Package_Source.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Run_Worksheet_Package_Result.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Run_Next_Worksheet_Package.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Domain\Worksheet_Package_Request_Snapshot.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Domain\Worksheet_Package_Archive.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Domain\Worksheet_Package_Work_Item.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Infrastructure\Deterministic_Zip_Worksheet_Package_Writer.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Infrastructure\WordPress_Worksheet_Package_Repository.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Infrastructure\WordPress_Worksheet_Package_Queue_Wakeup.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Infrastructure\Worksheet_Package_Worker_Module.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Presentation\Worksheet_Package_Rest_Controller.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Presentation\Worksheet_Package_Rest_Module.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Failure.php')
    (Join-Path $root 'src\Reporting\Export\Application\Export_Download.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Job.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Artifact.php')
    (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php')
    (Join-Path $root 'src\Reporting\Export\Presentation\Export_Rest_Controller.php')
    (Join-Path $root 'src\Reporting\Export\Presentation\Export_Rest_Module.php')
    (Join-Path $root 'src\Storage\Infrastructure\WordPress_Worksheet_Package_Private_File_Metadata.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Repository.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
    (Join-Path $root 'src\Core\Deactivation.php')
)
$contract = Join-Path $root 'tests\Contract\worksheet-package-g2-imp-07-s03.php'
$phpArgs = @()
$extensionDir = Join-Path (Split-Path -Parent $php) 'ext'
if (Test-Path -LiteralPath $extensionDir) {
    $phpArgs += @('-d', "extension_dir=$extensionDir")
    foreach ($extension in @('zip', 'intl', 'mbstring')) {
        if (Test-Path -LiteralPath (Join-Path $extensionDir "php_$extension.dll")) {
            $phpArgs += @('-d', "extension=$extension")
        }
    }
}

foreach ($file in @($runtimeFiles + $contract)) {
    & $php @phpArgs -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenPrefix = '$wpdb' + '->prefix'
$legacyPrefix = 'wp_' + 'oj_ai_'
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenPrefix) -or $source.Contains($legacyPrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
}

& $php @phpArgs $contract
exit $LASTEXITCODE

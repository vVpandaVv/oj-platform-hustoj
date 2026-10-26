param()

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction SilentlyContinue).Source
}

if (-not $php) {
    Write-Host 'PHP executable not found. Set OJAI_PHP or install PHP.'
    exit 1
}

$files = @(
    (Join-Path $root 'src\Worksheet\Authoring\Application\Worksheet_Template_Import_Failure.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Worksheet_Template_Package.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Import_Worksheet_Template_Command.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Import_Worksheet_Template_Result.php')
    (Join-Path $root 'src\Worksheet\Authoring\Application\Import_Worksheet_Template.php')
    (Join-Path $root 'src\Storage\Application\Stage_Worksheet_Asset_Command.php')
    (Join-Path $root 'src\Storage\Application\Stage_Worksheet_Asset.php')
    (Join-Path $root 'src\Worksheet\Authoring\Domain\Worksheet_Template_Id_Rewriter.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\Zip_Worksheet_Template_Package_Reader.php')
    (Join-Path $root 'src\Worksheet\Authoring\Port\Worksheet_Template_Package_Reader_Port_Interface.php')
    (Join-Path $root 'src\Worksheet\Authoring\Presentation\Worksheet_Template_Import_Rest_Controller.php')
    (Join-Path $root 'src\Worksheet\Authoring\Presentation\Worksheet_Template_Import_Rest_Module.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
    (Join-Path $root 'tests\Contract\worksheet-template-import-s03.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-template-import-s03.php')
exit $LASTEXITCODE

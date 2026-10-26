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
    (Join-Path $root 'src\Storage\Application\Read_Worksheet_Asset_Content.php')
    (Join-Path $root 'src\Storage\Application\Worksheet_Asset_Content_Result.php')
    (Join-Path $root 'src\Storage\Application\Discard_Staged_Worksheet_Asset.php')
    (Join-Path $root 'src\Storage\Application\Discard_Staged_Worksheet_Asset_Command.php')
    (Join-Path $root 'src\Storage\Application\Discard_Staged_Worksheet_Asset_Result.php')
    (Join-Path $root 'src\Storage\Infrastructure\Local_Private_File_Store.php')
    (Join-Path $root 'src\Storage\Infrastructure\WordPress_Worksheet_Asset_Repository.php')
    (Join-Path $root 'src\Storage\Presentation\Worksheet_Asset_Access_Rest_Controller.php')
    (Join-Path $root 'src\Storage\Presentation\Worksheet_Asset_Access_Rest_Module.php')
    (Join-Path $root 'src\Worksheet\Authoring\Infrastructure\WordPress_Worksheet_Asset_Source_Reader.php')
    (Join-Path $root 'src\Core\Composition_Root.php')
    (Join-Path $root 'tests\Contract\worksheet-asset-s02.php')
)

foreach ($file in $files) {
    & $php -l $file
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\worksheet-asset-s02.php')
exit $LASTEXITCODE

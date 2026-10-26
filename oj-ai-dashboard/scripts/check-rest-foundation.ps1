param()

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')

function Get-PhpExecutable {
    if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
        return $env:OJAI_PHP
    }

    $localPhp = 'D:\php\php.exe'
    if (Test-Path -LiteralPath $localPhp) {
        return $localPhp
    }

    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    return $null
}

$php = Get-PhpExecutable
if (-not $php) {
    Write-Host 'PHP executable not found. Set OJAI_PHP or install PHP.'
    exit 1
}

$files = @(
    'src\Rest\Correlation_Id_Factory.php',
    'src\Rest\Idempotency_Key.php',
    'src\Rest\Rest_Authorization_Result.php',
    'src\Rest\Rest_Envelope_Factory.php',
    'src\Rest\Rest_Permission_Grant.php',
    'src\Rest\Rest_Permission_Handoff.php',
    'src\Rest\Rest_Problem.php',
    'src\Rest\Rest_Request_Authorizer.php',
    'src\Rest\Rest_Request_Metadata.php',
    'src\Rest\Infrastructure\WordPress_Current_Actor.php',
    'src\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier.php',
    'src\Rest\Infrastructure\WordPress_Rest_Request_Adapter.php',
    'src\Rest\Port\Current_Actor_Port_Interface.php',
    'src\Rest\Port\Rest_Nonce_Verifier_Port_Interface.php',
    'tests\Contract\rest-foundation.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\rest-foundation.php')
exit $LASTEXITCODE

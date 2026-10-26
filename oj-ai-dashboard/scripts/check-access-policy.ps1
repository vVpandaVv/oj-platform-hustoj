param(
    [string] $PlatformSource = ''
)

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

if ($PlatformSource) {
    $env:OJ_PLATFORM_SOURCE = $PlatformSource
}

$files = @(
    'src\Contracts\Platform_Context_Runtime_Port_Interface.php',
    'src\Core\Composition_Root.php',
    'src\Platform\Addon_Integration.php',
    'src\Platform\Addon_Manifest.php',
    'src\Platform\Addon_Page_Handler.php',
    'src\Platform\Addon_Runtime_Bridge.php',
    'src\Security\Addon_Capabilities.php',
    'src\Security\Policy\Access_Policy.php',
    'src\Security\Policy\Access_Policy_Decision.php',
    'src\Security\Policy\Access_Policy_Request.php',
    'src\Security\Policy\Context_Authorization_Result.php',
    'src\Security\Policy\Context_Scope.php',
    'src\Security\Policy\Object_Authorization_Result.php',
    'src\Security\Policy\Object_Scope.php',
    'src\Security\Policy\Policy_Value.php',
    'src\Security\Policy\Infrastructure\Platform_Context_Authorization.php',
    'src\Security\Policy\Infrastructure\Unavailable_Object_Authorization.php',
    'src\Security\Policy\Infrastructure\WordPress_Actor_Authorization.php',
    'src\Security\Policy\Port\Access_Policy_Port_Interface.php',
    'src\Security\Policy\Port\Actor_Authorization_Port_Interface.php',
    'src\Security\Policy\Port\Context_Authorization_Port_Interface.php',
    'src\Security\Policy\Port\Object_Authorization_Port_Interface.php',
    'tests\Contract\access-policy.php'
)

foreach ($file in $files) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

& $php (Join-Path $root 'tests\Contract\access-policy.php')
exit $LASTEXITCODE

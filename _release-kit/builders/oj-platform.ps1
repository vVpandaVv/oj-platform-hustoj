# Invoke the UTF-8 platform builder from Windows PowerShell without ANSI decoding.
param(
    [Parameter(Mandatory = $true)] [string] $OutputDirectory,
    [Parameter(Mandatory = $true)] [string] $PhpBinary
)

$ErrorActionPreference = 'Stop'

# The Windows PowerShell host may lose module auto-loading after the platform
# test runner exits. Keep release hashing independent of that host behavior.
function Get-FileHash {
    param(
        [Parameter(Mandatory = $true)] [string] $LiteralPath,
        [ValidateSet('SHA256')] [string] $Algorithm = 'SHA256'
    )

    $stream = [IO.File]::OpenRead($LiteralPath)
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $hash = ([BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-', '')
    } finally {
        $sha.Dispose()
        $stream.Dispose()
    }

    return [PSCustomObject]@{
        Algorithm = $Algorithm
        Hash = $hash
        Path = [IO.Path]::GetFullPath($LiteralPath)
    }
}

$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../oj-integration-rebuild'))
$buildPath = Join-Path $repositoryRoot 'scripts/build-release.ps1'
$source = [IO.File]::ReadAllText($buildPath, (New-Object Text.UTF8Encoding($false, $true)))
$builder = [ScriptBlock]::Create($source)
& $builder -RepositoryRoot $repositoryRoot -OutputDirectory $OutputDirectory -PhpBinary $PhpBinary

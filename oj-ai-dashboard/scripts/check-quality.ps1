param(
    [switch] $Strict,
    [ValidatePattern('^[1-9][0-9]*[MG]$')]
    [string] $PhpAnalysisMemoryLimit = '1536M'
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$errors = New-Object System.Collections.Generic.List[string]
$warnings = New-Object System.Collections.Generic.List[string]

function Invoke-Step {
    param(
        [string] $Label,
        [scriptblock] $Command
    )

    Write-Host ""
    Write-Host "== $Label =="
    & $Command
    if ($LASTEXITCODE -ne 0) {
        $errors.Add($Label) | Out-Null
    }
}

function Get-OptionalCommand {
    param(
        [string] $VendorRelativePath,
        [string] $CommandName
    )

    $vendorPath = Join-Path $root $VendorRelativePath
    if (Test-Path -LiteralPath $vendorPath) {
        return $vendorPath
    }

    $cmd = Get-Command $CommandName -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    return $null
}

Set-Location -LiteralPath $root

Invoke-Step 'Harness validation' {
    if ($Strict) {
        powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\validate-plugin-harness.ps1 -Strict
    } else {
        powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\validate-plugin-harness.ps1
    }
}

Invoke-Step 'PHP lint' {
    powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\lint-php.ps1
}

$phpcs = Get-OptionalCommand 'vendor\bin\phpcs.bat' 'phpcs'
if ($phpcs) {
    Invoke-Step 'PHPCS' {
        & $phpcs -d "memory_limit=$PhpAnalysisMemoryLimit" --standard=phpcs.xml.dist
    }
} else {
    $warnings.Add('PHPCS not found. Install project dev dependencies or add phpcs to PATH to enable this gate.') | Out-Null
}

$phpstan = Get-OptionalCommand 'vendor\bin\phpstan.bat' 'phpstan'
if ($phpstan) {
    Invoke-Step 'PHPStan' {
        & $phpstan analyse --configuration=phpstan.neon.dist --memory-limit=$PhpAnalysisMemoryLimit
    }
} else {
    $warnings.Add('PHPStan not found. Install project dev dependencies or add phpstan to PATH to enable this gate.') | Out-Null
}

$phpunit = Get-OptionalCommand 'vendor\bin\phpunit.bat' 'phpunit'
if ($phpunit) {
    Invoke-Step 'PHPUnit' {
        & $phpunit --configuration=phpunit.xml.dist
    }
} else {
    $warnings.Add('PHPUnit not found. Install project dev dependencies or add phpunit to PATH to enable this gate.') | Out-Null
}

$node = Get-Command node -ErrorAction SilentlyContinue
if ($node) {
    Invoke-Step 'Asset lint' {
        node .\scripts\lint-assets.mjs
    }
    Invoke-Step 'Asset build' {
        node .\scripts\build-assets.mjs
    }
    Invoke-Step 'Node test command' {
        node .\scripts\run-tests.mjs
    }
} else {
    $warnings.Add('Node.js not found. JS/CSS lint and build gates skipped.') | Out-Null
}

if ($warnings.Count -gt 0) {
    Write-Host ""
    Write-Host "Warnings:"
    foreach ($warning in $warnings) {
        Write-Host " - $warning"
    }
}

if ($errors.Count -gt 0) {
    Write-Host ""
    Write-Host "Failed steps:"
    foreach ($err in $errors) {
        Write-Host " - $err"
    }
    exit 1
}

if ($Strict -and $warnings.Count -gt 0) {
    Write-Host 'Strict mode failed because warnings were found.'
    exit 1
}

Write-Host ""
Write-Host 'Quality checks passed.'
exit 0

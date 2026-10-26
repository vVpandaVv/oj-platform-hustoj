param(
    [string] $Path = '.'
)

$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$target = Resolve-Path (Join-Path $root $Path)
$errors = New-Object System.Collections.Generic.List[string]

function Get-PhpExecutable {
    if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
        return $env:OJAI_PHP
    }

    $localPhp = 'D:\php\php.exe'
    if (Test-Path -LiteralPath $localPhp) {
        return $localPhp
    }

    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    return $null
}

$php = Get-PhpExecutable
if (-not $php) {
    Write-Host 'PHP executable not found. Set OJAI_PHP or install PHP.'
    exit 1
}

$phpFiles = Get-ChildItem -LiteralPath $target -Recurse -Filter '*.php' -File |
    Where-Object {
        $_.FullName -notmatch '\\(?:vendor|node_modules|build|\.cache|\.git|_notes|dist)\\'
    }

if ($phpFiles.Count -eq 0) {
    Write-Host 'No PHP files found.'
    exit 0
}

foreach ($file in $phpFiles) {
    $result = & $php -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        $errors.Add("PHP lint failed: $($file.FullName)`n$result") | Out-Null
    } else {
        Write-Host "[OK] PHP lint: $($file.FullName.Substring($root.Path.Length + 1))"
    }
}

if ($errors.Count -gt 0) {
    foreach ($err in $errors) {
        Write-Host $err
    }
    exit 1
}

Write-Host 'PHP lint passed.'
exit 0

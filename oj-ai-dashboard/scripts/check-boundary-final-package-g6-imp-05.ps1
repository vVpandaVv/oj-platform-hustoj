[CmdletBinding()]
param(
    [switch]$SkipConstituentChecks,
    [switch]$SkipProductionPackage,
    [string]$PlatformSource = ''
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}
$node = (Get-Command node -ErrorAction Stop).Source
$git = (Get-Command git -ErrorAction Stop).Source
$platformRoot = if ($PlatformSource) {
    Resolve-Path -LiteralPath $PlatformSource
} else {
    Resolve-Path -LiteralPath (Join-Path $root '..\oj-integration-rebuild\oj-platform')
}

function Invoke-Checked([string]$Command, [string[]]$Arguments) {
    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed ($LASTEXITCODE): $Command $($Arguments -join ' ')"
    }
}

$dimensions = @(
    'G6-IMP-05-S01',
    'G6-IMP-05-S02',
    'G6-IMP-05-S03',
    'G6-IMP-05-S04',
    'G6-IMP-05-S05'
)

if (-not $SkipConstituentChecks) {
    Invoke-Checked (Get-Command powershell -ErrorAction Stop).Source @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $root 'scripts\check-addon-api-consumer.ps1'),
        '-PlatformSource', $platformRoot.Path
    )
    Invoke-Checked (Get-Command powershell -ErrorAction Stop).Source @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $root 'scripts\check-release-package-boundary.ps1')
    )
}

$platformApiStatus = @(
    & $git -C $platformRoot.Path status --porcelain --untracked-files=all -- src/AddonApi/V1
)
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to inspect the OJ Platform public Addon API source.'
}
if ($platformApiStatus.Count -gt 0) {
    throw "G6-IMP-05-S02 failed: OJ Platform src/AddonApi/V1 has $($platformApiStatus.Count) changed file(s)."
}

Invoke-Checked $node @(
    'scripts/fingerprint-release-boundaries.mjs',
    '--verify',
    'resources/contracts/release-boundary-fingerprint-v1.json',
    '--platform-root',
    $platformRoot.Path
)

$contract = Join-Path $root 'tests\Contract\boundary-final-package-g6-imp-05.php'
Invoke-Checked $php @('-l', $contract)
Invoke-Checked $php @($contract)

if ($SkipProductionPackage) {
    Write-Output 'G6-IMP-05 S01-S04 prepackage checks passed; S05 requires a clean committed source tree.'
    exit 0
}

$sourceStatus = @(
    & $git -C $root.Path status --porcelain --untracked-files=all
)
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to inspect the add-on release source state.'
}
if ($sourceStatus.Count -gt 0) {
    throw "G6-IMP-05-S05 blocked: source_dirty=true ($($sourceStatus.Count) entries). Commit the accepted source before production packaging."
}

Invoke-Checked (Get-Command powershell -ErrorAction Stop).Source @(
    '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
    (Join-Path $root 'scripts\build-release.ps1')
)

Write-Output "G6-IMP-05 passed: $($dimensions.Count) frozen dimensions and the clean production ZIP are verified."

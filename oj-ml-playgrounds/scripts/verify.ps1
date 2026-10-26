param(
    [string]$Php = 'php',
    [string]$Node = 'node',
    [string]$ExtensionDirectory = '',
    [string]$UpstreamFrontend = '',
    [switch]$RequirePlatform
)
$ErrorActionPreference = 'Stop'
$mlPlugin = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$mlPhpArgs = @()
$env:OJ_ML_TEST_PHP = $Php
if ($UpstreamFrontend) { $env:OJ_ML_UPSTREAM_FRONTEND = [IO.Path]::GetFullPath($UpstreamFrontend) }
if ($ExtensionDirectory) {
    $mlPhpArgs = @('-d', "extension_dir=$ExtensionDirectory", '-d', 'extension=zip', '-d', 'extension=mbstring', '-d', 'extension=mysqli')
}
# Single quotes inside the PHP code: Windows PowerShell 5.1 strips embedded double quotes from native arguments.
& $Php @mlPhpArgs -r 'exit(class_exists(''ZipArchive'') && function_exists(''mb_check_encoding'') ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { throw 'Verification requires PHP zip and mbstring. Do not accept skipped archive tests.' }
$mlFailures = @()
$mlLintCount = 0
Get-ChildItem -LiteralPath $mlPlugin -Recurse -Filter '*.php' -File | ForEach-Object {
    $mlOutput = & $Php @mlPhpArgs -l $_.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { $mlFailures += $_.FullName; $mlOutput | Write-Output }
    $mlLintCount++
}
foreach ($mlScript in @('addon.js', 'public-pc.js', 'public-pc-bootstrap.js', 'teacher-workflow.js', 'portfolio-bridge.js', 'rubric-tools.js', 'assignment-policy-tools.js', 'display-mode.js')) {
    & $Node --check (Join-Path $mlPlugin "resources/js/$mlScript")
    if ($LASTEXITCODE -ne 0) { $mlFailures += $mlScript }
}
$mlTestCount = 0
Get-ChildItem -LiteralPath (Join-Path $mlPlugin 'tests') -Filter '*regression.php' -File | ForEach-Object {
    $mlOutput = & $Php @mlPhpArgs -d "auto_prepend_file=$mlPlugin/tests/bootstrap.php" $_.FullName 2>&1
    if ($LASTEXITCODE -ne 0 -or ($mlOutput -join [Environment]::NewLine) -match 'SKIP' -or ($mlOutput -join [Environment]::NewLine) -notmatch 'passed') { $mlFailures += $_.Name; $mlOutput | Select-Object -First 25 | Write-Output }
    else { Write-Output ("PASS " + $_.Name) }
    $mlTestCount++
}
Get-ChildItem -LiteralPath (Join-Path $mlPlugin 'tests') -Filter '*.mjs' -File | ForEach-Object {
    $mlOutput = & $Node $_.FullName 2>&1
    if ($LASTEXITCODE -ne 0 -or ($mlOutput -join [Environment]::NewLine) -notmatch 'passed') { $mlFailures += $_.Name; $mlOutput | Select-Object -First 25 | Write-Output }
    else { Write-Output ("PASS " + $_.Name) }
    $mlTestCount++
}
$mlContract = & $Php @mlPhpArgs (Join-Path $mlPlugin 'tests/Contract/addon-api-consumer.php') 2>&1
$mlContract | Write-Output
if ($LASTEXITCODE -ne 0 -or ($RequirePlatform -and ($mlContract -join [Environment]::NewLine) -match 'SKIP')) { $mlFailures += 'Addon API consumer' }
if ($mlFailures.Count) { throw ("Verification failed: " + ($mlFailures -join ', ')) }
Write-Output ("PASS: {0} PHP files linted; {1} regression scripts; Addon API status above." -f $mlLintCount,$mlTestCount)

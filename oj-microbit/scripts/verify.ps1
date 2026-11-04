param([string]$Php = '', [string]$Node = '', [string]$Python = '')
$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path $PSScriptRoot -Parent
$phpBinary = $Php
if (-not $phpBinary) { $phpBinary = (Get-Command php -ErrorAction SilentlyContinue).Source }
if (-not $phpBinary) { $phpBinary = 'D:\php\php.exe' }
if (-not (Test-Path -LiteralPath $phpBinary)) { throw 'PHP CLI is required.' }
$nodeBinary = $Node
if (-not $nodeBinary) { $nodeBinary = (Get-Command node -ErrorAction Stop).Source }
$pythonBinary = $Python
if (-not $pythonBinary) { $pythonBinary = $env:PYTHON_BINARY }
if (-not $pythonBinary) { $pythonBinary = (Get-Command python -ErrorAction Stop).Source }
$acceptanceRoot = Join-Path $pluginRoot 'scripts/acceptance'
$strictUtf8 = New-Object System.Text.UTF8Encoding($false, $true)
foreach ($source in Get-ChildItem -LiteralPath $acceptanceRoot -Recurse -File) {
    if ($source.Extension -notin @('.php', '.py', '.sh', '.mjs', '.json')) { continue }
    $bytes = [IO.File]::ReadAllBytes($source.FullName)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 239 -and $bytes[1] -eq 187 -and $bytes[2] -eq 191) { throw "UTF-8 BOM: $($source.Name)" }
    $null = $strictUtf8.GetString($bytes)
}

$sources = @((Get-ChildItem -LiteralPath (Join-Path $pluginRoot 'src') -Recurse -File -Filter '*.php'))
$sources += Get-ChildItem -LiteralPath $pluginRoot -File -Filter '*.php'
$sources += Get-ChildItem -LiteralPath (Join-Path $pluginRoot 'runner/install-tools') -Recurse -File -Filter '*.php'
$sources += Get-ChildItem -LiteralPath $acceptanceRoot -Recurse -File -Filter '*.php'
foreach ($source in $sources) {
    $null = & $phpBinary -l $source.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax failed: $($source.FullName)" }
}
$phpTestArgs = @()
$phpModules = & $phpBinary -m
if ($LASTEXITCODE -ne 0) { throw 'PHP module inspection failed.' }
if ($phpModules -notcontains 'pdo_sqlite') { $phpTestArgs = @('-d', 'extension=pdo_sqlite') }
foreach ($test in Get-ChildItem -LiteralPath (Join-Path $pluginRoot 'tests') -File -Filter '*.php') {
    & $phpBinary @phpTestArgs $test.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP test failed: $($test.Name)" }
}
& $nodeBinary --check (Join-Path $pluginRoot 'resources/js/microbit.js')
if ($LASTEXITCODE -ne 0) { throw 'Browser JavaScript syntax failed.' }
& $nodeBinary --test (Join-Path $pluginRoot 'tests/feature-freeze.test.cjs')
if ($LASTEXITCODE -ne 0) { throw 'Frozen product baseline verification failed.' }
& $nodeBinary --test (Join-Path $pluginRoot 'resources/tools/frontend.test.cjs')
if ($LASTEXITCODE -ne 0) { throw 'Browser protocol tests failed.' }
& $nodeBinary --test (Join-Path $pluginRoot 'tests/installer-shell.test.cjs')
if ($LASTEXITCODE -ne 0) { throw 'Installer shell and mock integration tests failed.' }
& $nodeBinary --test (Join-Path $pluginRoot 'tests/acceptance-rest.test.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Acceptance REST tool mock tests failed.' }
& $pythonBinary -B -m unittest discover -s (Join-Path $pluginRoot 'tests') -p 'acceptance_*_test.py'
if ($LASTEXITCODE -ne 0) { throw 'Acceptance host/cycle offline tests failed.' }
Push-Location (Join-Path $pluginRoot 'runner')
try {
    $runnerTests = @(Get-ChildItem -LiteralPath (Join-Path $pluginRoot 'runner/tests') -Filter '*.test.mjs' -File | ForEach-Object { $_.FullName })
    & $nodeBinary --test @runnerTests
    if ($LASTEXITCODE -ne 0) { throw 'PXT runner tests failed.' }
} finally { Pop-Location }
Write-Output '[oj-microbit] Verification passed. Remote browser, WordPress, Docker and physical USB acceptance are separate.'

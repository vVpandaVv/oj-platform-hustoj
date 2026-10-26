param(
    [Parameter(Mandatory = $true)][string]$Php,
    [string]$Node = ''
)

$ErrorActionPreference = 'Stop'
$primmRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$primmUtf8 = New-Object Text.UTF8Encoding($false, $true)
$primmCount = 0
foreach ($primmFile in Get-ChildItem -LiteralPath $primmRoot -Recurse -File) {
    if ($primmFile.Extension -notin @('.php', '.js', '.css', '.md', '.txt', '.json', '.ps1')) { continue }
    $primmBytes = [IO.File]::ReadAllBytes($primmFile.FullName)
    if ($primmBytes.Length -ge 3 -and $primmBytes[0] -eq 239 -and $primmBytes[1] -eq 187 -and $primmBytes[2] -eq 191) {
        throw "UTF-8 BOM: $($primmFile.FullName)"
    }
    $null = $primmUtf8.GetString($primmBytes)
    if ($primmFile.Extension -eq '.php') {
        $primmLint = & $Php -l $primmFile.FullName 2>&1
        if ($LASTEXITCODE -ne 0) { throw ($primmLint -join [Environment]::NewLine) }
        $primmCount++
    }
    if ($primmFile.Extension -eq '.js' -and $Node -ne '' -and $primmFile.FullName -notmatch '[\\/]tests[\\/]') {
        & $Node --check $primmFile.FullName
        if ($LASTEXITCODE -ne 0) { throw "JavaScript syntax failed: $($primmFile.FullName)" }
    }
}
foreach ($primmRequired in @('oj-primm.php', 'plugin-package.php', 'uninstall.php', 'README.md', 'LICENSE.txt', 'tests/backend.php')) {
    if (-not (Test-Path -LiteralPath (Join-Path $primmRoot $primmRequired) -PathType Leaf)) { throw "Missing PRIMM file: $primmRequired" }
}
$primmTests = @('tests/backend.php', 'tests/ui.php', 'tests/platform-integration.php', 'tests/learning-projection-fallback.php', 'tests/generation-contract.php', 'tests/lesson-verification.php', 'tests/execution-contract.php', 'tests/generation-service.php', 'tests/generation-storage.php', 'tests/actor-refresh.php', 'tests/curriculum.php', 'tests/management.php', 'tests/practice.php', 'tests/submission.php', 'tests/generation-management-integration.php', 'tests/installer.php')
$primmHasSqlite = @(& $Php -m) -contains 'pdo_sqlite'
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect PHP extensions.' }
foreach ($primmTest in $primmTests) {
    $primmTestPath = Join-Path $primmRoot $primmTest
    if (-not (Test-Path -LiteralPath $primmTestPath -PathType Leaf)) { throw "Missing PRIMM check: $primmTest" }
    if ($primmTest -in @('tests/generation-storage.php', 'tests/management.php', 'tests/practice.php', 'tests/submission.php', 'tests/generation-management-integration.php') -and -not $primmHasSqlite) {
        & $Php -d extension=pdo_sqlite $primmTestPath
    } else {
        & $Php $primmTestPath
    }
    if ($LASTEXITCODE -ne 0) { throw "PRIMM check failed: $primmTest" }
}
Write-Output "PRIMM verification passed: $primmCount PHP files, UTF-8 and behavior checks."

param([Parameter(Mandatory = $true)][string]$Php, [string]$Node = '')
$ErrorActionPreference = 'Stop'
$networkRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$networkUtf8 = New-Object Text.UTF8Encoding($false, $true)
foreach ($networkFile in Get-ChildItem -LiteralPath $networkRoot -Recurse -File) {
    $networkBytes = [IO.File]::ReadAllBytes($networkFile.FullName)
    if ($networkBytes.Length -ge 3 -and $networkBytes[0] -eq 239 -and $networkBytes[1] -eq 187 -and $networkBytes[2] -eq 191) { throw "UTF-8 BOM: $($networkFile.FullName)" }
    $networkText = $networkUtf8.GetString($networkBytes)
    if ($networkText -match '\\u(?:[0-9a-fA-F]{4}|\{[0-9a-fA-F]+\})') { throw "Unicode escape: $($networkFile.FullName)" }
    if ($networkFile.Extension -eq '.php') { & $Php -l $networkFile.FullName; if ($LASTEXITCODE -ne 0) { throw "PHP syntax: $($networkFile.FullName)" } }
    if ($networkFile.Extension -in @('.js', '.mjs', '.cjs') -and $Node -ne '') { & $Node --check $networkFile.FullName; if ($LASTEXITCODE -ne 0) { throw "JS syntax: $($networkFile.FullName)" } }
}
if (-not $Node) { throw 'Node is required to verify the browser engine.' }
& $Node (Join-Path $networkRoot 'tests/curriculum.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Curriculum link checks failed.' }
& $Node (Join-Path $networkRoot 'tests/goals.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Goal context checks failed.' }
& $Node (Join-Path $networkRoot 'tests/engine.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Engine checks failed.' }
& $Node (Join-Path $networkRoot 'tests/devices.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Device CLI checks failed.' }
& $Node (Join-Path $networkRoot 'tests/tools.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Output tool checks failed.' }
& $Node (Join-Path $networkRoot 'tests/windows.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Windows CLI checks failed.' }
$networkHasSqlite = @(& $Php -m) -contains 'pdo_sqlite'
if ($networkHasSqlite) { & $Php (Join-Path $networkRoot 'tests/backend.php') }
else { & $Php -d extension=pdo_sqlite (Join-Path $networkRoot 'tests/backend.php') }
if ($LASTEXITCODE -ne 0) { throw 'Backend checks failed.' }
Write-Output 'Network Lab verification passed.'

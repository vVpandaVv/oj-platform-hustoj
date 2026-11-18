param([Parameter(Mandatory = $true)][string]$Php, [string]$Node = '')
$ErrorActionPreference = 'Stop'
$logicRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$logicUtf8 = New-Object Text.UTF8Encoding($false, $true)
foreach ($logicFile in Get-ChildItem -LiteralPath $logicRoot -Recurse -File) {
    $logicBytes = [IO.File]::ReadAllBytes($logicFile.FullName)
    if ($logicBytes.Length -ge 3 -and $logicBytes[0] -eq 239 -and $logicBytes[1] -eq 187 -and $logicBytes[2] -eq 191) { throw "UTF-8 BOM: $($logicFile.FullName)" }
    $null = $logicUtf8.GetString($logicBytes)
    if ($logicFile.Extension -eq '.php') { & $Php -l $logicFile.FullName; if ($LASTEXITCODE -ne 0) { throw 'PHP syntax failed.' } }
    if ($logicFile.Extension -in @('.js', '.mjs') -and $Node) { & $Node --check $logicFile.FullName; if ($LASTEXITCODE -ne 0) { throw 'JS syntax failed.' } }
}
if (-not $Node) { throw 'Node is required.' }
& $Node (Join-Path $logicRoot 'tests/engine.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Engine checks failed.' }
& $Node (Join-Path $logicRoot 'tests/router.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Router checks failed.' }
& $Node (Join-Path $logicRoot 'tests/modes.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Mode checks failed.' }
& $Node (Join-Path $logicRoot 'tests/features.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Feature checks failed.' }
& $Node (Join-Path $logicRoot 'tests/drag.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Live drag checks failed.' }
& $Node (Join-Path $logicRoot 'tests/wire-move.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Manual wire checks failed.' }
& $Node (Join-Path $logicRoot 'tests/wire-points.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Wire point checks failed.' }
& $Php (Join-Path $logicRoot 'tests/integration.php')
if ($LASTEXITCODE -ne 0) { throw 'Addon integration checks failed.' }
& $Node (Join-Path $logicRoot 'tests/rc.mjs')
if ($LASTEXITCODE -ne 0) { throw 'RC checks failed.' }
& $Node (Join-Path $logicRoot 'tests/migrations.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Migration checks failed.' }
& $Node (Join-Path $logicRoot 'tests/performance.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Performance checks failed.' }
& $Php (Join-Path $logicRoot 'tests/db-budget.php')
if ($LASTEXITCODE -ne 0) { throw 'DB budget checks failed.' }

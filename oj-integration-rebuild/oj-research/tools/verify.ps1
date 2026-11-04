param([Parameter(Mandatory = $true)][string]$Php, [string]$Node = '')
$ErrorActionPreference = 'Stop'
$researchRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
& $Php (Join-Path $researchRoot 'tools/run-tests.php')
if ($LASTEXITCODE -ne 0) { throw 'OJ Research verification failed.' }
if ($Node -ne '') {
    & $Node --check (Join-Path $researchRoot 'assets/research.js')
    if ($LASTEXITCODE -ne 0) { throw 'OJ Research JavaScript syntax failed.' }
}

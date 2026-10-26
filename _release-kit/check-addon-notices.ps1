$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$registered = @('teaching-tools', 'oj-code-arena')
$registryPath = Join-Path $PSScriptRoot 'plugins.json'
if (Test-Path -LiteralPath $registryPath) {
    $registry = [IO.File]::ReadAllText($registryPath, (New-Object Text.UTF8Encoding($false, $true))) | ConvertFrom-Json
    $registered = @($registry.plugins | ForEach-Object { $_.id })
}
$required = @(
    'readcontents-library/assets/legal/Assistant-OFL.txt',
    'readcontents-library/assets/legal/editor/katex/LICENSE',
    'oj-ai-dashboard/LICENSE.txt',
    'edu-games/assets/vendor/chartjs/color-LICENSE.md',
    'oj-code-lab/docs/Lucide-Feather-LICENSE.txt',
    '_shared/oj-realtime-hub/LICENSE.txt',
    'teaching-tools/realtime-server/LICENSE.txt',
    'teaching-tools/resources/vendor/pdfjs/LICENSE',
    'teaching-tools/resources/vendor/pdfjs/README.md'
)
if ($registered -contains 'oj-code-arena') { $required += 'oj-code-arena/LICENSE.txt' }
# The Plate builder replaces this legacy bundle. Keep enforcing its notice
# in checkouts that still distribute it, without requiring a removed asset.
if (Test-Path -LiteralPath (Join-Path $root 'oj-ai-dashboard/resources/assets/builder/dash.all.min.js') -PathType Leaf) {
    $required += 'oj-ai-dashboard/resources/assets/builder/dash.all.min.js.LICENSE.txt'
}
foreach ($relative in $required) {
    $path = Join-Path $root $relative
    if (-not (Test-Path -LiteralPath $path -PathType Leaf) -or (Get-Item -LiteralPath $path).Length -eq 0) { throw "Missing license notice: $relative" }
}
foreach ($directory in @('readcontents-library/assets/legal/editor', 'oj-ai-dashboard/resources/assets/legal/editor')) {
    $base = Join-Path $root $directory
    $index = Join-Path $base 'INDEX.md'
    if (-not (Test-Path -LiteralPath $index)) { throw "Missing license inventory: $directory" }
    foreach ($line in [IO.File]::ReadAllLines($index)) {
        if (-not $line.StartsWith('- ')) { continue }
        $path = [IO.Path]::GetFullPath((Join-Path $base $line.Substring(2)))
        if (-not $path.StartsWith([IO.Path]::GetFullPath($base) + [IO.Path]::DirectorySeparatorChar) -or -not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Missing inventory notice: $line" }
        $bytes = [IO.File]::ReadAllBytes($path)
        if ($bytes.Length -eq 0 -or ($bytes.Length -ge 3 -and $bytes[0] -eq 239 -and $bytes[1] -eq 187 -and $bytes[2] -eq 191)) { throw "Empty or BOM-prefixed notice: $path" }
        $null = (New-Object System.Text.UTF8Encoding($false, $true)).GetString($bytes)
    }
}
foreach ($id in @('teaching-tools', 'oj-code-arena') | Where-Object { $registered -contains $_ }) {
    $manifest = [IO.File]::ReadAllText((Join-Path $root "$id/realtime-addon.json")) | ConvertFrom-Json
    if (-not @($manifest.include | Where-Object { $_ -match 'LICENSE.txt$' }).Count) { throw "Server manifest omits license: $id" }
}
Write-Output '[licenses] Required addon notices and inventories verified.'

param([switch]$Check)
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$utf8 = New-Object System.Text.UTF8Encoding($false, $true)
$targets = @(
    @{ source = '_notes/readcontents-server-import-2026-09-12/editor-source/node_modules'; target = 'readcontents-library/assets/legal/editor' },
    @{ source = 'oj-ai-dashboard/resources/plate/whole-editor/node_modules'; target = 'oj-ai-dashboard/resources/assets/legal/editor' }
)
foreach ($item in $targets) {
    $source = Join-Path $root $item.source
    $destination = Join-Path $root $item.target
    if (-not (Test-Path -LiteralPath $source)) { throw "Missing notice source: $source" }
    $records = @()
    foreach ($file in Get-ChildItem -LiteralPath $source -Recurse -File | Where-Object { $_.Name -match '^(LICENSE|LICENCE|COPYING|NOTICE|OFL)([._-].*)?$|\.(LICENSE|NOTICE)\.txt$' }) {
        $relative = $file.FullName.Substring($source.Length).TrimStart('\', '/')
        $noticeRelative = $relative.Replace('node_modules', 'nested-dependencies')
        $output = Join-Path $destination $noticeRelative
        $text = [System.IO.File]::ReadAllText($file.FullName, $utf8).TrimStart([char]0xfeff)
        if ($Check) {
            if (-not (Test-Path -LiteralPath $output) -or [System.IO.File]::ReadAllText($output, $utf8) -cne $text) { throw "Missing or stale notice: $output" }
        } else {
            [System.IO.Directory]::CreateDirectory((Split-Path $output -Parent)) | Out-Null
            [System.IO.File]::WriteAllText($output, $text, $utf8)
        }
        $records += $noticeRelative.Replace('\', '/')
    }
    if ($records.Count -eq 0) { throw "No licenses found in $source" }
    $index = "# Editor dependency notices`n`nSource snapshot: $($item.source)`n`nThis is a conservative inventory of license texts available in the editor source dependency tree, including build-only packages. It does not claim every package is present in the compiled runtime, or that the source snapshot exactly reproduces every historical bundle. Original asset headers remain unchanged. Missing historical build provenance must be reviewed separately.`n`n" + (($records | Sort-Object | ForEach-Object { '- ' + $_ }) -join "`n") + "`n"
    if (-not $Check) { [System.IO.File]::WriteAllText((Join-Path $destination 'INDEX.md'), $index, $utf8) }
    Write-Output "$($item.target): $($records.Count) notice texts verified"
}

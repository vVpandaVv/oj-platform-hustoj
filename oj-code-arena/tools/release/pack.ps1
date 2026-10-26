param(
    [Parameter(Mandatory = $true)][string]$Version,
    [Parameter(Mandatory = $true)][string]$OutDir
)
# Builds the two release zips with forward-slash entry names (WordPress
# needs them; Compress-Archive writes backslashes on Windows PowerShell).
#   powershell -File tools/release/pack.ps1 -Version 1.0.0 -OutDir D:\...\_releases
# Never overwrites an existing zip: every released version is kept.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$src = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path.TrimEnd('\')

function Pack([string]$OutZip, [string]$RootName, [string[]]$Include) {
    if (Test-Path -LiteralPath $OutZip) { throw "Refusing to overwrite $OutZip" }
    $zip = [System.IO.Compression.ZipFile]::Open($OutZip, [System.IO.Compression.ZipArchiveMode]::Create)
    $count = 0
    try {
        foreach ($item in $Include) {
            $path = Join-Path $src $item
            if (Test-Path -LiteralPath $path -PathType Leaf) {
                $files = @(Get-Item -LiteralPath $path)
            } elseif (Test-Path -LiteralPath $path -PathType Container) {
                $files = Get-ChildItem -LiteralPath $path -Recurse -File | Where-Object { $_.FullName -notmatch '\\node_modules\\' -and $_.Name -notlike '.env*' -and $_.Name -notlike '*.bak' -and $_.Name -ne 'oj-code-arena.env' -and $_.Name -ne 'unsent-results.jsonl' }
            } else {
                throw "Missing $item"
            }
            foreach ($f in $files) {
                $rel = $f.FullName.Substring($src.Length + 1).Replace([System.IO.Path]::DirectorySeparatorChar, '/')
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $f.FullName, $RootName + '/' + $rel, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
                $count++
            }
        }
        $shared = [IO.Path]::GetFullPath((Join-Path $src '..\_shared\oj-realtime-hub'))
        foreach ($directory in Get-ChildItem -LiteralPath $shared -Recurse -Force -Directory) {
            if ($directory.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked common runtime directory.' }
        }
        foreach ($f in Get-ChildItem -LiteralPath $shared -Recurse -File) {
            $relative = $f.FullName.Substring($shared.Length + 1).Replace('\', '/')
            if ($relative -match '(^|/)(node_modules|tests?|\.git)(/|$)|(^|/)\.[^/]+|\.log$') { continue }
            if ($f.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked common runtime file.' }
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $f.FullName, "$RootName/common-server/$relative", [IO.Compression.CompressionLevel]::Optimal) | Out-Null
            $count++
        }
    } finally {
        $zip.Dispose()
    }
    $check = [System.IO.Compression.ZipFile]::OpenRead($OutZip)
    $bad = @($check.Entries | Where-Object { $_.FullName -like '*\*' }).Count
    $check.Dispose()
    if ($bad) { throw "$OutZip has backslash entries" }
    "{0}: {1} files" -f (Split-Path $OutZip -Leaf), $count
}

if (-not (Test-Path -LiteralPath $OutDir)) { New-Item -ItemType Directory -Force $OutDir | Out-Null }
Pack (Join-Path $OutDir "oj-code-arena-$Version.zip") 'oj-code-arena' @(
    'oj-code-arena.php', 'uninstall.php', 'relay.php', 'README.md', 'CHANGELOG.md', 'LICENSE.md', 'LICENSE.txt', 'realtime-addon.json',
    'src', 'resources', 'shared', 'data\default-bank.json', 'data\default-bank.txt', 'docs',
    'game-server\install.sh', 'game-server\package.json', 'game-server\package-lock.json', 'game-server\src', 'game-server\deploy')
Pack (Join-Path $OutDir "oj-code-arena-server-$Version.zip") 'oj-code-arena-server' @(
    'README.md', 'CHANGELOG.md', 'LICENSE.md', 'LICENSE.txt', 'realtime-addon.json',
    'game-server\install.sh', 'game-server\package.json', 'game-server\package-lock.json', 'game-server\.env.example', 'game-server\Dockerfile',
    'game-server\src', 'game-server\deploy', 'game-server\test', 'shared', 'data\default-bank.json', 'tests\scenarios')

[CmdletBinding()]
param([string]$OutputDirectory = '', [string]$PhpBinary = '')

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$root = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$utf8 = [Text.UTF8Encoding]::new($false, $true)
$header = [IO.File]::ReadAllText((Join-Path $root 'edu-games.php'), $utf8)
$version = [regex]::Match($header, '(?m)^ \* Version: ([0-9.]+)').Groups[1].Value
if (!$version -or !$header.Contains("define('EDU_GAMES_VERSION', '$version');")) { throw 'Plugin header/runtime versions differ.' }
$package = [IO.File]::ReadAllText((Join-Path $root 'plugin-package.php'), $utf8)
if (!$package.Contains("'version' => '$version'")) { throw 'Plugin package version differs.' }
$npm = [IO.File]::ReadAllText((Join-Path $root 'package.json'), $utf8) | ConvertFrom-Json
if ($npm.version -ne $version) { throw 'Build package version differs.' }
if (!$OutputDirectory) { $OutputDirectory = Join-Path (Split-Path -Parent $root) '_releases' }
$output = [IO.Path]::GetFullPath($OutputDirectory)
[IO.Directory]::CreateDirectory($output) | Out-Null
$archive = Join-Path $output "edu-games-$version.zip"
$manifestPath = Join-Path $output "edu-games-$version.manifest.json"
$hashPath = Join-Path $output "edu-games-$version.zip.sha256"
foreach ($target in @($archive, $manifestPath, $hashPath)) {
    if (Test-Path -LiteralPath $target) { throw "Existing release will not be overwritten: $target" }
}

# Fail before creating a ZIP when the platform refuses the manifest.
if (!$PhpBinary) { $PhpBinary = $env:PHP_BINARY }
if (!$PhpBinary) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $PhpBinary = $phpCommand.Source }
    elseif (Test-Path -LiteralPath 'D:\php\php.exe') { $PhpBinary = 'D:\php\php.exe' }
    else { throw 'Provide -PhpBinary or PHP_BINARY to run the addon registration check.' }
}
& $PhpBinary (Join-Path $root 'tests/addon-assets.php')
if ($LASTEXITCODE -ne 0) { throw 'Addon registration check failed; no ZIP was created. Check OJ_PLATFORM_SOURCE and the reported error.' }

# Package the working tree, including uncommitted runtime assets, never Git history or test data.
$files = [Collections.Generic.List[IO.FileInfo]]::new()
foreach ($name in @('edu-games.php', 'plugin-package.php', 'uninstall.php', 'README.md')) {
    $files.Add((Get-Item -LiteralPath (Join-Path $root $name)))
}
foreach ($name in @('src', 'blocks', 'assets', 'resources', 'languages')) {
    foreach ($entry in Get-ChildItem -LiteralPath (Join-Path $root $name) -Recurse -Force) {
        if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Linked paths are not allowed: $($entry.FullName)" }
        if (!$entry.PSIsContainer) { $files.Add($entry) }
    }
}
$entries = [Collections.Generic.List[object]]::new()
$stream = [IO.File]::Open($archive, [IO.FileMode]::CreateNew)
$zip = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create, $false, $utf8)
try {
    foreach ($file in ($files | Sort-Object FullName)) {
        $relative = $file.FullName.Substring($root.Length + 1).Replace('\', '/')
        if ($relative -match '(^|/)(\.git|node_modules|__pycache__)(/|$)' -or $relative -match '\.(zip|log|pyc)$') { throw "Unexpected runtime file: $relative" }
        $name = 'edu-games/' + $relative
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $file.FullName, $name, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        $entries.Add([ordered]@{path=$name; bytes=$file.Length; sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()})
    }
} finally { $zip.Dispose(); $stream.Dispose() }

# Verify every archived byte against the source manifest before publishing the checksum.
$zip = [IO.Compression.ZipFile]::OpenRead($archive)
try {
    if ($zip.Entries.Count -ne $entries.Count) { throw 'Archive entry count differs.' }
    foreach ($item in $entries) {
        $entry = $zip.GetEntry($item.path)
        if (!$entry -or $entry.Length -ne $item.bytes) { throw "Archive entry mismatch: $($item.path)" }
        $reader = $entry.Open(); $sha = [Security.Cryptography.SHA256]::Create()
        try { $actual = ([BitConverter]::ToString($sha.ComputeHash($reader))).Replace('-', '').ToLowerInvariant() }
        finally { $sha.Dispose(); $reader.Dispose() }
        if ($actual -ne $item.sha256) { throw "Archive hash mismatch: $($item.path)" }
    }
} finally { $zip.Dispose() }
$hash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
$manifest = [ordered]@{plugin='edu-games'; version=$version; source='working-tree'; archive=[IO.Path]::GetFileName($archive); sha256=$hash; fileCount=$entries.Count; bytes=(Get-Item -LiteralPath $archive).Length; excluded=@('.git','tests','scripts','docs','node_modules'); files=@($entries.ToArray())}
[IO.File]::WriteAllText($manifestPath, ($manifest | ConvertTo-Json -Depth 5), $utf8)
[IO.File]::WriteAllText($hashPath, "$hash  $([IO.Path]::GetFileName($archive))`n", $utf8)
Write-Output "Verified $($entries.Count) runtime files: $archive"
Write-Output "SHA256 $hash"

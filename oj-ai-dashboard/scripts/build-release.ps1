param(
    [string] $Version = '',
    [string] $ComposerPhar = '',
    [switch] $ValidationPackage
)

$ErrorActionPreference = 'Stop'

function Get-Sha256Hex {
    param(
        [Parameter(Mandatory = $true)]
        [string] $LiteralPath
    )

    $stream = [System.IO.File]::OpenRead($LiteralPath)
    try {
        $algorithm = [System.Security.Cryptography.SHA256]::Create()
        try {
            return ([System.BitConverter]::ToString($algorithm.ComputeHash($stream))).Replace('-', '').ToLowerInvariant()
        } finally {
            $algorithm.Dispose()
        }
    } finally {
        $stream.Dispose()
    }
}

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$mainFile = Join-Path $root 'oj-ai-dashboard.php'
$php = if ($env:OJAI_PHP) { $env:OJAI_PHP } else { (Get-Command php -ErrorAction Stop).Source }

if (-not $Version) {
    $mainContent = Get-Content -LiteralPath $mainFile -Raw -Encoding UTF8
    $match = [regex]::Match($mainContent, 'Version:\s*([0-9A-Za-z\.\-\+]+)')
    if ($match.Success) {
        $Version = $match.Groups[1].Value
    } else {
        $Version = '0.0.0'
    }
}

$mainContent = Get-Content -LiteralPath $mainFile -Raw -Encoding UTF8
$headerVersion = [regex]::Match($mainContent, 'Version:\s*([0-9A-Za-z\.\-\+]+)').Groups[1].Value
$constantVersion = [regex]::Match($mainContent, "define\( 'OJ_AI_DASHBOARD_VERSION', '([^']+)' \)").Groups[1].Value
$packageVersion = (Get-Content -LiteralPath (Join-Path $root 'package.json') -Raw -Encoding UTF8 | ConvertFrom-Json).version
if ($packageVersion -isnot [string] -or $Version -ne $headerVersion -or $Version -ne $constantVersion -or $Version -ne $packageVersion) {
    throw 'Package version must match package.json, the plugin header and runtime constant.'
}
if ($ValidationPackage) {
    if ($Version -notmatch '^\d+\.\d+\.\d+-rc\.\d+$') {
        throw 'Validation packages require a distinct -rc.N version and are not production releases.'
    }
} elseif ($Version -notmatch '^\d+\.\d+\.\d+$') {
    throw 'Production packages require a stable version; release candidates are validation packages only.'
}

$git = Get-Command git -ErrorAction SilentlyContinue
# The plugin lives inside the unified plugins repository, so look for the
# enclosing worktree instead of a .git folder in the plugin itself.
$insideWorktree = if ($git) { & $git.Source -C $root.Path rev-parse --is-inside-work-tree 2>$null } else { $null }
if (-not $git -or $LASTEXITCODE -ne 0 -or $insideWorktree -ne 'true') {
    throw 'A Git worktree is required to build a release package.'
}

# Only this plugin's files decide whether the source is clean.
$dirtyState = @(
    & $git.Source -C $root.Path status --porcelain --untracked-files=all -- .
)
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to inspect the release source state.'
}
if ($dirtyState.Count -gt 0) {
    if (-not $ValidationPackage) {
        throw 'Release packaging requires source_dirty=false. Commit or remove every tracked and untracked change first.'
    }
}

if (-not $ValidationPackage) {
    $node = (Get-Command node -ErrorAction Stop).Source
    & $node (Join-Path $root 'scripts\fingerprint-release-boundaries.mjs') --verify 'resources/contracts/release-boundary-fingerprint-v1.json'
    if ($LASTEXITCODE -ne 0) {
        throw 'Production packaging requires the exact approved release boundary fingerprint.'
    }
}

& $php (Join-Path $root 'tests\Contract\addon-api-consumer.php')
if ($LASTEXITCODE -ne 0) { throw 'Add-on registration contract failed; no package was created.' }

$sourceCommit = [string] (& $git.Source -C $root.Path rev-parse HEAD)
if ($LASTEXITCODE -ne 0 -or -not $sourceCommit.Trim()) {
    throw 'Unable to resolve the release source commit.'
}
$sourceCommit = $sourceCommit.Trim()

$dist = Join-Path $root 'dist'
if ($ValidationPackage) { $dist = Join-Path $dist 'validation' }
$stagingRoot = Join-Path $dist 'release-staging'
$packageRoot = Join-Path $stagingRoot 'oj-ai-dashboard'
$zipPath = Join-Path $dist "oj-ai-dashboard-$Version.zip"
$checksumPath = Join-Path $dist "oj-ai-dashboard-$Version.zip.sha256"

New-Item -ItemType Directory -Path $dist -Force | Out-Null

$resolvedDist = Resolve-Path $dist
foreach ($target in @($stagingRoot, $zipPath, $checksumPath)) {
    if (Test-Path -LiteralPath $target) {
        $resolvedTarget = Resolve-Path $target
        if (-not $resolvedTarget.Path.StartsWith($resolvedDist.Path, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to remove path outside dist: $resolvedTarget"
        }
        Remove-Item -LiteralPath $resolvedTarget -Recurse -Force
    }
}

New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null

function Copy-ReleaseEntry {
    param(
        [string] $RelativePath,
        [bool] $Required
    )

    $source = Join-Path $root $RelativePath
    if (-not (Test-Path -LiteralPath $source)) {
        if ($Required) {
            throw "Required release entry not found: $RelativePath"
        }
        return
    }

    $destination = Join-Path $packageRoot $RelativePath
    $destinationParent = Split-Path -Parent $destination
    New-Item -ItemType Directory -Path $destinationParent -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination -Recurse -Force
}

$requiredEntries = @(
    'oj-ai-dashboard.php',
    'uninstall.php',
    'composer.json',
    'composer.lock',
    'src',
    'resources\assets',
    'resources\contracts',
    'resources\css',
    'resources\fonts',
    'resources\icons',
    'resources\js',
    'resources\server'
)
$optionalEntries = @(
    'languages',
    'readme.txt',
    'LICENSE',
    'LICENSE.txt'
)

# Capture the approved entries before copying, then verify the source and stage.
function Get-SourceInventory {
    $inventory = @(
        foreach ($relativePath in @($requiredEntries + $optionalEntries)) {
            $source = Join-Path $root $relativePath
            if (-not (Test-Path -LiteralPath $source)) { continue }
            $files = if (Test-Path -LiteralPath $source -PathType Leaf) {
                @(Get-Item -LiteralPath $source)
            } else {
                @(Get-ChildItem -LiteralPath $source -File -Recurse -Force)
            }
            foreach ($file in $files) {
                [ordered]@{
                    path = $file.FullName.Substring($root.Path.Length + 1).Replace('\', '/')
                    sha256 = Get-Sha256Hex -LiteralPath $file.FullName
                }
            }
        }
    )
    return @($inventory | Sort-Object { $_.path })
}
$sourceInventory = @(Get-SourceInventory)

foreach ($relativePath in $requiredEntries) {
    Copy-ReleaseEntry -RelativePath $relativePath -Required $true
}
foreach ($relativePath in $optionalEntries) {
    Copy-ReleaseEntry -RelativePath $relativePath -Required $false
}

# Install from the lock into the isolated package, never copy development vendor.
if (-not $ComposerPhar) {
    $ComposerPhar = if ($env:OJAI_COMPOSER_PHAR) { $env:OJAI_COMPOSER_PHAR } else { Join-Path $root '.cache\tools\composer.phar' }
}
if (-not (Test-Path -LiteralPath $ComposerPhar -PathType Leaf)) {
    throw 'A verified Composer PHAR is required to install locked production dependencies.'
}
& $php $ComposerPhar install --working-dir=$packageRoot --no-dev --no-scripts --no-plugins --prefer-dist --optimize-autoloader --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Production dependency installation failed.' }
& $php (Join-Path $root 'scripts\validate-production-vendor.php') $packageRoot
if ($LASTEXITCODE -ne 0) { throw 'Production dependency boundary validation failed.' }

& $php (Join-Path $root 'tests\Contract\addon-api-consumer.php') $packageRoot
if ($LASTEXITCODE -ne 0) { throw 'Staged package registration contract failed; no ZIP was created.' }

$forbiddenDirectories = @('.git', '.agents', '.codex', 'node_modules', 'tests', 'docs', 'scripts')
$packagedForbidden = @(
    Get-ChildItem -LiteralPath $packageRoot -Directory -Force |
        Where-Object { $forbiddenDirectories -contains $_.Name }
)
$packagedForbidden += @(
    Get-ChildItem -LiteralPath $packageRoot -Recurse -Directory -Force |
        Where-Object { $_.Name -eq 'node_modules' }
)
if ($packagedForbidden.Count -gt 0) {
    $relative = $packagedForbidden[0].FullName.Substring($packageRoot.Length + 1)
    throw "Forbidden development directory entered the release package: $relative"
}

$plateDependency = Join-Path $packageRoot 'resources\plate\whole-editor\node_modules'
if (Test-Path -LiteralPath $plateDependency) {
    throw 'Plate development dependencies must never enter the release package.'
}

$vendorAutoload = Join-Path $packageRoot 'vendor\autoload.php'
$dompdfRuntime = Join-Path $packageRoot 'vendor\dompdf\dompdf\src\Dompdf.php'
$fontRuntime = Join-Path $packageRoot 'resources\fonts\noto-sans-kr\NotoSansKR-Variable.ttf'
$fontLicense = Join-Path $packageRoot 'resources\fonts\noto-sans-kr\OFL.txt'
$iconSprite = Join-Path $packageRoot 'resources\icons\lucide.svg'
foreach ($requiredRuntime in @($vendorAutoload, $dompdfRuntime, $fontRuntime, $fontLicense, $iconSprite)) {
    if (-not (Test-Path -LiteralPath $requiredRuntime -PathType Leaf)) {
        throw "Required PDF runtime entry is missing: $requiredRuntime"
    }
}

$boundaryContract = Join-Path $packageRoot 'resources\contracts\release-boundary-fingerprint-v1.json'
if (-not (Test-Path -LiteralPath $boundaryContract -PathType Leaf)) {
    throw 'The frozen release boundary fingerprint contract is missing.'
}

$contentEntries = @(
    Get-ChildItem -LiteralPath $packageRoot -File -Recurse -Force |
        Sort-Object FullName |
        ForEach-Object {
            [ordered]@{
                path = $_.FullName.Substring($packageRoot.Length + 1).Replace('\', '/')
                size_bytes = [int64] $_.Length
                sha256 = Get-Sha256Hex -LiteralPath $_.FullName
            }
        }
)

$sourceAfterCopy = @(Get-SourceInventory)
if (($sourceInventory | ConvertTo-Json -Depth 4 -Compress) -ne ($sourceAfterCopy | ConvertTo-Json -Depth 4 -Compress)) {
    throw 'Source changed during packaging; discard this stage and build again.'
}
foreach ($sourceEntry in $sourceInventory) {
    $copiedPath = Join-Path $packageRoot $sourceEntry.path
    if ((Get-Sha256Hex -LiteralPath $copiedPath) -ne $sourceEntry.sha256) {
        throw "Packaged file differs from the source snapshot: $($sourceEntry.path)"
    }
}

$releaseManifest = [ordered]@{
    contract = 'oj_ai.release_package@1'
    addon_version = $Version
    source_commit = $sourceCommit
    source_dirty = $false
    boundary_contract = 'resources/contracts/release-boundary-fingerprint-v1.json'
    boundary_contract_sha256 = Get-Sha256Hex -LiteralPath $boundaryContract
    content_hash_algorithm = 'sha256'
    content = $contentEntries
}
$manifestName = 'release-manifest.json'
if ($ValidationPackage) {
    $manifestName = 'validation-manifest.json'
    $releaseManifest.contract = 'oj_ai.validation_package@1'
    $releaseManifest.source_dirty = ($dirtyState.Count -gt 0)
    $releaseManifest['production_approved'] = $false
    $releaseManifest['boundary_approved'] = $false
    $releaseManifest['source_snapshot'] = $sourceInventory
}
$releaseManifestPath = Join-Path $packageRoot $manifestName
$releaseManifestJson = $releaseManifest | ConvertTo-Json -Depth 8
[System.IO.File]::WriteAllText(
    $releaseManifestPath,
    $releaseManifestJson + [Environment]::NewLine,
    [System.Text.UTF8Encoding]::new($false)
)

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::Open(
    $zipPath,
    [System.IO.Compression.ZipArchiveMode]::Create
)
try {
    $packageFiles = @(
        Get-ChildItem -LiteralPath $packageRoot -File -Recurse -Force |
            Sort-Object FullName
    )
    foreach ($file in $packageFiles) {
        $entryName = $file.FullName.Substring($stagingRoot.Length + 1).Replace('\', '/')
        if ($entryName.Contains('\')) {
            throw "Release ZIP entry is not portable: $entryName"
        }

        $entry = $archive.CreateEntry(
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        )
        $entry.LastWriteTime = [System.DateTimeOffset]::new($file.LastWriteTimeUtc)
        $inputStream = [System.IO.File]::OpenRead($file.FullName)
        $outputStream = $entry.Open()
        try {
            $inputStream.CopyTo($outputStream)
        } finally {
            $outputStream.Dispose()
            $inputStream.Dispose()
        }
    }
} finally {
    $archive.Dispose()
}

$archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $entryNames = @($archive.Entries | ForEach-Object { $_.FullName })
    if ($entryNames -notcontains 'oj-ai-dashboard/oj-ai-dashboard.php') {
        throw 'The release ZIP does not contain the canonical WordPress plugin file.'
    }
    if ($entryNames -notcontains "oj-ai-dashboard/$manifestName") {
        throw "The package ZIP does not contain $manifestName."
    }
    if ($entryNames -notcontains 'oj-ai-dashboard/resources/icons/lucide.svg') {
        throw 'The release ZIP does not contain the Lucide icon sprite.'
    }
    foreach ($entryName in $entryNames) {
        if ($entryName.Contains('\') -or
            $entryName.StartsWith('/') -or
            $entryName -match '(^|/)\.\.(/|$)' -or
            $entryName -notmatch '^oj-ai-dashboard/' -or
            $entryName -match '^oj-ai-dashboard/(\.git|\.agents|\.codex|node_modules|tests|docs|scripts)(/|$)' -or
            $entryName -match '(^|/)node_modules(/|$)') {
            throw "Invalid or forbidden entry entered the release ZIP: $entryName"
        }
    }
} finally {
    $archive.Dispose()
}

$zipHash = Get-Sha256Hex -LiteralPath $zipPath
$checksumLine = "$zipHash  $([System.IO.Path]::GetFileName($zipPath))$([Environment]::NewLine)"
[System.IO.File]::WriteAllText($checksumPath, $checksumLine, [System.Text.Encoding]::ASCII)

$resolvedStaging = Resolve-Path $stagingRoot
if ($resolvedStaging.Path.StartsWith($resolvedDist.Path, [System.StringComparison]::OrdinalIgnoreCase)) {
    Remove-Item -LiteralPath $resolvedStaging -Recurse -Force
}

$zip = Get-Item -LiteralPath $zipPath
if ($ValidationPackage) {
    Write-Host "Validation package created (not production approved): $($zip.FullName)"
} else {
    Write-Host "Release package created: $($zip.FullName)"
}
Write-Host "Size bytes: $($zip.Length)"
Write-Host "SHA-256: $zipHash"
Write-Host "Checksum: $checksumPath"

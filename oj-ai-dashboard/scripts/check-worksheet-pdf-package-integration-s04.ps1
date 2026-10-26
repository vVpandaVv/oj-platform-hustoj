param(
    [switch]$SkipRaster
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}
$phpArgs = @()
$extensionDir = Join-Path (Split-Path -Parent $php) 'ext'
if (Test-Path -LiteralPath $extensionDir) {
    $phpArgs += @('-d', "extension_dir=$extensionDir")
    foreach ($extension in @('zip', 'intl', 'mbstring')) {
        if (Test-Path -LiteralPath (Join-Path $extensionDir "php_$extension.dll")) {
            $phpArgs += @('-d', "extension=$extension")
        }
    }
}

$runtimeFiles = @(
    (Join-Path $root 'src\Reporting\Export\Application\Export_Download.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Job.php')
    (Join-Path $root 'src\Reporting\Export\Application\Read_Export_Artifact.php')
    (Join-Path $root 'src\Reporting\Export\Infrastructure\WordPress_Export_Query.php')
    (Join-Path $root 'src\Reporting\Export\Presentation\Export_Rest_Controller.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Application\Run_Next_Worksheet_Pdf.php')
    (Join-Path $root 'src\Reporting\WorksheetPdf\Infrastructure\WordPress_Worksheet_Pdf_Repository.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Application\Run_Next_Worksheet_Package.php')
    (Join-Path $root 'src\Reporting\WorksheetPackage\Infrastructure\WordPress_Worksheet_Package_Repository.php')
    (Join-Path $root 'src\Platform\Worksheet_Workbench_Page_Handler.php')
)
$contract = Join-Path $root 'tests\Contract\worksheet-pdf-package-integration-g2-imp-07-s04.php'
$fixture = Join-Path $root 'tests\fixtures\generate-worksheet-pdf-visual.php'
foreach ($file in @($runtimeFiles + $contract + $fixture)) {
    & $php @phpArgs -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$forbiddenPrefix = '$wpdb' + '->prefix'
$legacyPrefix = 'wp_' + 'oj_ai_'
foreach ($file in $runtimeFiles) {
    $source = Get-Content -LiteralPath $file -Raw
    if ($source.Contains($forbiddenPrefix) -or $source.Contains($legacyPrefix)) {
        throw "Forbidden add-on table prefix marker found in $file"
    }
}

if (-not (Test-Path -LiteralPath (Join-Path $root 'resources\fonts\noto-sans-kr\NotoSansKR-Regular.ttf'))) {
    throw 'The Dompdf-compatible static Noto Sans KR font is missing.'
}

foreach ($file in @(
    (Join-Path $root 'resources\js\worksheet-authoring-workbench.js')
    (Join-Path $root 'scripts\e2e-worksheet-workbench.mjs')
)) {
    & node --check $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $php @phpArgs $contract
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$outputDir = [System.IO.Path]::GetFullPath((Join-Path $root 'tmp\worksheet-pdf-s04'))
$rootPath = [System.IO.Path]::GetFullPath($root.Path).TrimEnd('\') + '\'
if (-not $outputDir.StartsWith($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Visual fixture output escaped the workspace.'
}
[System.IO.Directory]::CreateDirectory($outputDir) | Out-Null
$pdfPath = Join-Path $outputDir 'worksheet-visual.pdf'
& $php @phpArgs $fixture $pdfPath
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $pdfPath)) { exit 1 }

$pdfBytes = [System.IO.File]::ReadAllBytes($pdfPath)
$pdfAscii = [System.Text.Encoding]::ASCII.GetString($pdfBytes)
if ($pdfBytes.Length -lt 20000 -or -not $pdfAscii.Contains('NotoSansKR') -or -not $pdfAscii.Contains('/ToUnicode')) {
    throw 'The visual PDF does not contain the packaged Korean font and Unicode mapping.'
}

if (-not $SkipRaster) {
    $pdftoppmCommand = Get-Command pdftoppm -ErrorAction SilentlyContinue
    $popplerBin = Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin'
    $pdftoppm = if ($pdftoppmCommand) { $pdftoppmCommand.Source } else { Join-Path $popplerBin 'pdftoppm.exe' }
    $pdfinfo = Join-Path (Split-Path -Parent $pdftoppm) 'pdfinfo.exe'
    $pdftotext = Join-Path (Split-Path -Parent $pdftoppm) 'pdftotext.exe'
    if (-not (Test-Path -LiteralPath $pdftoppm) -or -not (Test-Path -LiteralPath $pdfinfo)) {
        throw 'Poppler pdftoppm/pdfinfo is required for the S04 visual freeze. Use -SkipRaster only in a constrained CI environment.'
    }

    $info = & $pdfinfo $pdfPath
    if ($LASTEXITCODE -ne 0 -or ($info -join "`n") -notmatch 'Pages:\s+([2-9]|[1-9][0-9]+)' -or ($info -join "`n") -notmatch 'Page size:\s+595(\.\d+)? x 841(\.\d+)? pts') {
        throw 'The visual PDF is not a multi-page A4 portrait document.'
    }
    $textVerified = $false
    if (Test-Path -LiteralPath $pdftotext) {
        $textPath = Join-Path $outputDir 'worksheet-visual.txt'
        & $pdftotext -layout $pdfPath $textPath
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        $text = Get-Content -LiteralPath $textPath -Raw
        if (-not $text.Contains('우리 동네 생태 관찰 활동지') -or $text.Contains('S04_VISUAL_PRIVATE_ANSWER') -or $text.Contains('S04_VISUAL_PRIVATE_NOTE')) {
            throw 'PDF text extraction failed the Korean/private-field boundary.'
        }
        $textVerified = $true
    } else {
        $bundledPython = Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
        $pythonCommand = Get-Command python -ErrorAction SilentlyContinue
        $python = if ($env:OJAI_PYTHON -and (Test-Path -LiteralPath $env:OJAI_PYTHON)) {
            $env:OJAI_PYTHON
        } elseif (Test-Path -LiteralPath $bundledPython) {
            $bundledPython
        } elseif ($pythonCommand) {
            $pythonCommand.Source
        } else {
            $null
        }
        if (-not $python) { throw 'pdftotext or Python with pypdf is required to verify Korean PDF text.' }
        $extract = "from pypdf import PdfReader; import sys; text=chr(10).join((page.extract_text() or '') for page in PdfReader(sys.argv[1]).pages); expected=''.join(map(chr,(0xc6b0,0xb9ac,32,0xb3d9,0xb124,32,0xc0dd,0xd0dc,32,0xad00,0xcc30,32,0xd65c,0xb3d9,0xc9c0))); raise SystemExit(0 if expected in text and 'S04_VISUAL_PRIVATE_ANSWER' not in text and 'S04_VISUAL_PRIVATE_NOTE' not in text else 1)"
        & $python -c $extract $pdfPath
        if ($LASTEXITCODE -ne 0) { throw 'pypdf extraction failed the Korean/private-field boundary.' }
        $textVerified = $true
    }

    Get-ChildItem -LiteralPath $outputDir -Filter 'worksheet-page-*.png' -File | ForEach-Object {
        Remove-Item -LiteralPath $_.FullName
    }
    $pagePrefix = Join-Path $outputDir 'worksheet-page'
    & $pdftoppm -png -r 120 $pdfPath $pagePrefix
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    $pages = @(Get-ChildItem -LiteralPath $outputDir -Filter 'worksheet-page-*.png' -File | Sort-Object Name)
    if ($pages.Count -lt 2) { throw 'Poppler did not render every worksheet page.' }

    Add-Type -AssemblyName System.Drawing
    foreach ($page in $pages) {
        $bitmap = [System.Drawing.Bitmap]::FromFile($page.FullName)
        try {
            $ratio = $bitmap.Width / $bitmap.Height
            if ($bitmap.Width -lt 900 -or $bitmap.Height -lt 1300 -or [Math]::Abs($ratio - 0.707) -gt 0.02) {
                throw "Unexpected A4 raster dimensions in $($page.Name): $($bitmap.Width)x$($bitmap.Height)"
            }
            $dark = 0
            $sampled = 0
            $minX = $bitmap.Width
            $minY = $bitmap.Height
            $maxX = 0
            $maxY = 0
            for ($y = 0; $y -lt $bitmap.Height; $y += 5) {
                for ($x = 0; $x -lt $bitmap.Width; $x += 5) {
                    $pixel = $bitmap.GetPixel($x, $y)
                    $brightness = ($pixel.R + $pixel.G + $pixel.B) / 3
                    $sampled++
                    if ($brightness -lt 245) {
                        $dark++
                        if ($x -lt $minX) { $minX = $x }
                        if ($x -gt $maxX) { $maxX = $x }
                        if ($y -lt $minY) { $minY = $y }
                        if ($y -gt $maxY) { $maxY = $y }
                    }
                }
            }
            if ($dark -lt [Math]::Max(250, [int]($sampled * 0.004))) {
                throw "Rendered page appears blank: $($page.Name)"
            }
            if ($minX -lt 15 -or $minY -lt 15 -or $maxX -gt ($bitmap.Width - 15) -or $maxY -gt ($bitmap.Height - 15)) {
                throw "Rendered content crossed the safe page edge: $($page.Name)"
            }
        } finally {
            $bitmap.Dispose()
        }
    }
    if (-not $textVerified) { throw 'Korean PDF text verification did not run.' }
    Write-Output "S04 visual raster passed: $($pages.Count) A4 pages, packaged Korean font mapping, extractable Korean text, safe edges, and no private fields."
}

Write-Output "G2-IMP-07-S04 integration freeze passed. PDF: $pdfPath"
exit 0

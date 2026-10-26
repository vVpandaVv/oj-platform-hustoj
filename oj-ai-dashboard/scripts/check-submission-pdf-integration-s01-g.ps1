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
    foreach ($extension in @('intl', 'mbstring')) {
        if (Test-Path -LiteralPath (Join-Path $extensionDir "php_$extension.dll")) {
            $phpArgs += @('-d', "extension=$extension")
        }
    }
}

$fixture = Join-Path $root 'tests\fixtures\generate-submission-pdf-visual.php'
$serializer = Join-Path $root 'src\Reporting\SubmissionPdf\Domain\Submission_Print_Html_Serializer.php'
$renderer = Join-Path $root 'src\Reporting\WorksheetPdf\Infrastructure\Dompdf_Html_To_Pdf_Renderer.php'
foreach ($file in @($fixture, $serializer, $renderer)) {
    & $php @phpArgs -l $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
foreach ($file in @(
    (Join-Path $root 'resources\js\assignment-workbench.js')
    (Join-Path $root 'scripts\e2e-assignment-lifecycle.mjs')
)) {
    & node --check $file
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$outputDir = [System.IO.Path]::GetFullPath((Join-Path $root 'tests\artifacts\submission-pdf-s01-g'))
$rootPath = [System.IO.Path]::GetFullPath($root.Path).TrimEnd('\') + '\'
if (-not $outputDir.StartsWith($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Submission PDF visual output escaped the workspace.'
}
[System.IO.Directory]::CreateDirectory($outputDir) | Out-Null
$pdfPath = Join-Path $outputDir 'teacher-submission-ko.pdf'
& $php @phpArgs $fixture $pdfPath
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $pdfPath)) { exit 1 }

$pdfBytes = [System.IO.File]::ReadAllBytes($pdfPath)
$pdfAscii = [System.Text.Encoding]::ASCII.GetString($pdfBytes)
if ($pdfBytes.Length -lt 20000 -or -not $pdfAscii.Contains('NotoSansKR') -or -not $pdfAscii.Contains('/ToUnicode')) {
    throw 'The submission PDF does not contain the packaged Korean font and Unicode mapping.'
}

if (-not $SkipRaster) {
    $pdftoppmCommand = Get-Command pdftoppm -ErrorAction SilentlyContinue
    $popplerBin = Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin'
    $pdftoppm = if ($pdftoppmCommand) { $pdftoppmCommand.Source } else { Join-Path $popplerBin 'pdftoppm.exe' }
    $pdfinfo = Join-Path (Split-Path -Parent $pdftoppm) 'pdfinfo.exe'
    $pdftotext = Join-Path (Split-Path -Parent $pdftoppm) 'pdftotext.exe'
    if (-not (Test-Path -LiteralPath $pdftoppm) -or -not (Test-Path -LiteralPath $pdfinfo)) {
        throw 'Poppler pdftoppm and pdfinfo are required for the S01-G visual freeze.'
    }

    $info = & $pdfinfo $pdfPath
    $infoText = $info -join "`n"
    if ($LASTEXITCODE -ne 0 -or $infoText -notmatch 'Pages:\s+([3-9]|[1-9][0-9]+)' -or $infoText -notmatch 'Page size:\s+595(\.\d+)? x 841(\.\d+)? pts') {
        throw 'The submission PDF is not a three-or-more-page A4 portrait document.'
    }

    $textPath = Join-Path $outputDir 'teacher-submission-ko.txt'
    if (Test-Path -LiteralPath $pdftotext) {
        & $pdftotext -layout $pdfPath $textPath
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
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
        $extract = "from pypdf import PdfReader; import sys; text=chr(10).join((page.extract_text() or '') for page in PdfReader(sys.argv[1]).pages); expected=[''.join(map(chr,x)) for x in ((0xc6b0,0xb9ac,0x20,0xbc18,0x20,0xc0dd,0xd0dc,0x20,0xad00,0xcc30,0x20,0xc81c,0xcd9c,0x20,0xacb0,0xacfc),(0xae40,0xd558,0xb298),(0xad00,0xcc30,0xd55c,0x20,0xc0dd,0xbb3c,0xacfc,0x20,0xd658,0xacbd,0xc758,0x20,0xad00,0xacc4))]; open(sys.argv[2],'w',encoding='utf-8').write(text); raise SystemExit(0 if all(item in text for item in expected) and 'S01G_PRIVATE_ANSWER' not in text and 'S01G_PRIVATE_STORAGE_KEY' not in text else 1)"
        & $python -c $extract $pdfPath $textPath
        if ($LASTEXITCODE -ne 0) { throw 'pypdf extraction failed the Korean/private-field boundary.' }
    }
    $text = Get-Content -LiteralPath $textPath -Raw -Encoding UTF8
    $expectedText = @(
        (-join ([char[]]@(0xC6B0,0xB9AC,0x20,0xBC18,0x20,0xC0DD,0xD0DC,0x20,0xAD00,0xCC30,0x20,0xC81C,0xCD9C,0x20,0xACB0,0xACFC)))
        (-join ([char[]]@(0xAE40,0xD558,0xB298)))
        (-join ([char[]]@(0xAD00,0xCC30,0xD55C,0x20,0xC0DD,0xBB3C,0xACFC,0x20,0xD658,0xACBD,0xC758,0x20,0xAD00,0xACC4)))
        ((-join ([char[]]@(0xD654,0xB2E8,0x20,0xAD00,0xCC30,0x20,0xC0AC,0xC9C4))) + '.png')
        ((-join ([char[]]@(0xC0DD,0xD0DC,0x20,0xAD00,0xCC30,0x20,0xAE30,0xB85D))) + '.pdf')
    )
    foreach ($expected in $expectedText) {
        if (-not $text.Contains($expected)) { throw "Submission PDF text is missing: $expected" }
    }
    foreach ($forbidden in @('S01G_PRIVATE_ANSWER', 'S01G_PRIVATE_STORAGE_KEY')) {
        if ($text.Contains($forbidden)) { throw "Submission PDF text leaked: $forbidden" }
    }

    Get-ChildItem -LiteralPath $outputDir -Filter 'teacher-submission-page-*.png' -File | ForEach-Object {
        Remove-Item -LiteralPath $_.FullName
    }
    $pagePrefix = Join-Path $outputDir 'teacher-submission-page'
    & $pdftoppm -png -r 120 $pdfPath $pagePrefix
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    $pages = @(Get-ChildItem -LiteralPath $outputDir -Filter 'teacher-submission-page-*.png' -File | Sort-Object Name)
    if ($pages.Count -lt 3) { throw 'Poppler did not render every submission PDF page.' }

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
                throw "Rendered submission page appears blank: $($page.Name)"
            }
            if ($minX -lt 15 -or $minY -lt 15 -or $maxX -gt ($bitmap.Width - 15) -or $maxY -gt ($bitmap.Height - 15)) {
                throw "Rendered submission content crossed the safe page edge: $($page.Name)"
            }
        } finally {
            $bitmap.Dispose()
        }
    }
    Write-Output "S01-G submission PDF visual passed: $($pages.Count) A4 pages, Korean text, safe pixels, and no private markers."
}

& node (Join-Path $root 'scripts\e2e-assignment-lifecycle.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output "G3-IMP-08-S01-G visual and responsive integration passed. PDF: $pdfPath"

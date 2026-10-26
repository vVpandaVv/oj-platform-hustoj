param(
    [switch]$SkipConstituentChecks,
    [switch]$SkipBrowser,
    [switch]$SkipVisual
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

if (-not $SkipConstituentChecks) {
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\check-result-pdf-access-g4-imp-07-s03.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$phpFiles = @(
    'src\Platform\Student_Result_Page_Handler.php'
    'src\Reporting\ResultPdf\Domain\Result_Print_Html_Serializer.php'
    'tests\Contract\result-pdf-ui-g4-imp-07-s04.php'
    'tests\fixtures\generate-result-pdf-visual.php'
)
foreach ($file in $phpFiles) {
    & $php @phpArgs -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
foreach ($file in @('resources\js\student-results.js', 'scripts\e2e-student-results.mjs')) {
    & node --check (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = @(
    'src\Platform\Student_Result_Page_Handler.php'
    'resources\js\student-results.js'
    'resources\css\student-results.css'
)
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G4-IMP-07-S04 runtime contains a forbidden boundary marker: $forbidden" }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw
    foreach ($marker in $markers) {
        if (-not $source.Contains($marker)) { throw "G4-IMP-07-S04 $label marker is missing: $marker" }
    }
}

Assert-Markers 'resources\js\student-results.js' @(
    '/commands/export-pdf'
    '/export-jobs/'
    '/artifacts/'
    "'Idempotency-Key': token"
    'expected_release_hash'
    'expected_release_state'
    "profile: 'student_result'"
    "job.artifact_kind === 'result_pdf'"
    "setPdfUi('download-result-pdf'"
    "setPdfUi('create-result-pdf'"
    "[401, 403, 404, 409].includes(status)"
    "Accept: 'application/pdf'"
    "kind !== 'result_pdf'"
    'blob.size !== expectedBytes'
    'cancelPdf();'
) 'C52/Q23/Q24 UI'

Assert-Markers 'resources\css\student-results.css' @(
    '.oj-ai-result-pdf'
    '.oj-ai-result-pdf__button'
    '.oj-ai-result-pdf__status'
    'min-height: 44px'
    '@media (max-width: 700px)'
    'overflow-wrap: anywhere'
) 'responsive PDF states'

Assert-Markers 'src\Platform\Student_Result_Page_Handler.php' @(
    'data-locale='
    '$request->locale()'
    "str_replace( '_', '-', trim( `$value ) )"
) 'PublicUI locale handoff'

Assert-Markers 'scripts\e2e-student-results.mjs' @(
    'data-role="result-pdf-button"'
    '/commands/export-pdf'
    '/export-jobs/'
    '/artifacts/'
    "'download_expired'"
    "'job_revoked'"
    "'enqueue_conflict'"
    "{ width: 1280, height: 900 }, 'desktop'"
    "{ width: 390, height: 844 }, 'mobile'"
) 'desktop/mobile browser evidence'

Assert-Markers 'tests\fixtures\generate-result-pdf-visual.php' @(
    'Result_Print_Html_Serializer'
    'Dompdf_Html_To_Pdf_Renderer'
    'S04_RESULT_PRIVATE_TEACHER_MEMO'
    "`$pdf->page_count() < 2"
) 'actual Korean A4 fixture'

Assert-Markers 'docs\ai-assessment\g4-imp-07-s04-result-pdf-ui-integration.html' @(
    'Implementation 07'
    'POST /oj-ai/v1/me/results/{release_id}/commands/export-pdf'
    'GET /oj-ai/v1/export-jobs/{job_id}'
    'GET /oj-ai/v1/artifacts/{artifact_id}/download'
    'Content-Length'
    '5 page'
    'check:result-pdf-ui-g4'
    'API-AUD-2026-08-24-R82-OBSERVED'
) 'implementation evidence HTML'

Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g4-imp-07-s04-result-pdf-ui-integration.html'
    'G5-IMP-01-S01'
) 'implementation roadmap'

Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'id="r82-observation"'
    'API-AUD-2026-08-24-R82-OBSERVED'
    'check:result-pdf-ui-g4'
    'G4-IMP-07-S04'
) 'platform API audit'

& $php @phpArgs (Join-Path $root 'tests\Contract\result-pdf-ui-g4-imp-07-s04.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if (-not $SkipBrowser) {
    & node (Join-Path $root 'scripts\e2e-student-results.mjs')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

if (-not $SkipVisual) {
    $outputDir = [System.IO.Path]::GetFullPath((Join-Path $root 'tmp\result-pdf-s04'))
    $rootPath = [System.IO.Path]::GetFullPath($root.Path).TrimEnd('\') + '\'
    if (-not $outputDir.StartsWith($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw 'Result PDF visual fixture output escaped the workspace.'
    }
    [System.IO.Directory]::CreateDirectory($outputDir) | Out-Null
    $pdfPath = Join-Path $outputDir 'student-result-visual.pdf'
    & $php @phpArgs (Join-Path $root 'tests\fixtures\generate-result-pdf-visual.php') $pdfPath
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $pdfPath)) { exit 1 }

    $pdfBytes = [System.IO.File]::ReadAllBytes($pdfPath)
    $pdfAscii = [System.Text.Encoding]::ASCII.GetString($pdfBytes)
    if ($pdfBytes.Length -lt 20000 -or -not $pdfAscii.Contains('NotoSansKR') -or -not $pdfAscii.Contains('/ToUnicode')) {
        throw 'The result PDF does not contain the packaged Korean font and Unicode mapping.'
    }

    $pdftoppmCommand = Get-Command pdftoppm -ErrorAction SilentlyContinue
    $popplerBin = Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin'
    $pdftoppm = if ($pdftoppmCommand) { $pdftoppmCommand.Source } else { Join-Path $popplerBin 'pdftoppm.exe' }
    $pdfinfo = Join-Path (Split-Path -Parent $pdftoppm) 'pdfinfo.exe'
    if (-not (Test-Path -LiteralPath $pdftoppm) -or -not (Test-Path -LiteralPath $pdfinfo)) {
        throw 'Poppler pdftoppm and pdfinfo are required for the S04 result visual freeze.'
    }
    $info = & $pdfinfo $pdfPath
    if ($LASTEXITCODE -ne 0 -or ($info -join "`n") -notmatch 'Pages:\s+([2-9]|[1-9][0-9]+)' -or ($info -join "`n") -notmatch 'Page size:\s+595(\.\d+)? x 841(\.\d+)? pts') {
        throw 'The result PDF is not a multi-page A4 portrait document.'
    }

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
    if (-not $python) { throw 'Python with pypdf is required to verify Korean result PDF text.' }
    $extract = "from pypdf import PdfReader; import sys; text=chr(10).join((page.extract_text() or '') for page in PdfReader(sys.argv[1]).pages); expected='\uc6b0\ub9ac \ubc18 \uc790\ub8cc \ud574\uc11d \ud559\uc2b5 \uacb0\uacfc'; forbidden=('S04_RESULT_PRIVATE_TEACHER_MEMO','S04_RESULT_PRIVATE_STUDENT_NOTE','S04_RESULT_PRIVATE_PROVIDER_KEY'); raise SystemExit(0 if expected in text and all(value not in text for value in forbidden) else 1)"
    & $python -c $extract $pdfPath
    if ($LASTEXITCODE -ne 0) { throw 'pypdf extraction failed the Korean/private-field result boundary.' }

    Get-ChildItem -LiteralPath $outputDir -Filter 'student-result-page-*.png' -File | ForEach-Object {
        Remove-Item -LiteralPath $_.FullName
    }
    $pagePrefix = Join-Path $outputDir 'student-result-page'
    & $pdftoppm -png -r 120 $pdfPath $pagePrefix
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    $pages = @(Get-ChildItem -LiteralPath $outputDir -Filter 'student-result-page-*.png' -File | Sort-Object Name)
    if ($pages.Count -lt 2) { throw 'Poppler did not render every student result page.' }

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
            if ($dark -lt [Math]::Max(180, [int]($sampled * 0.003))) {
                throw "Rendered result page appears blank: $($page.Name)"
            }
            if ($minX -lt 15 -or $minY -lt 15 -or $maxX -gt ($bitmap.Width - 15) -or $maxY -gt ($bitmap.Height - 15)) {
                throw "Rendered result content crossed the safe page edge: $($page.Name)"
            }
        } finally {
            $bitmap.Dispose()
        }
    }
    Write-Output "S04 result visual passed: $($pages.Count) A4 pages, packaged Korean font, extractable public text, safe edges, and no private fields."
}

Write-Output 'G4-IMP-07-S04 passed: C52/Q23/Q24 student UI, correction/revocation/expiry clearing, desktop/mobile layout, and actual Korean A4 result PDF are frozen.'
exit 0

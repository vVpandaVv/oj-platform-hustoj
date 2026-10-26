param(
    [switch]$SkipConstituentChecks
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'php-source-markers.ps1')

$root = Resolve-Path (Join-Path $PSScriptRoot '..')
# Windows PowerShell 5.1 reads BOM-free scripts as ANSI; keep literal text in UTF-8 JSON.
$textMarkers = Get-Content -LiteralPath (Join-Path $root 'tests\fixtures\submission-result-ui-markers.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$php = if ($env:OJAI_PHP -and (Test-Path -LiteralPath $env:OJAI_PHP)) {
    $env:OJAI_PHP
} elseif (Test-Path -LiteralPath 'D:\php\php.exe') {
    'D:\php\php.exe'
} else {
    (Get-Command php -ErrorAction Stop).Source
}

if (-not $SkipConstituentChecks) {
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\check-submission-result-correction-g4-imp-06-s04.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$phpFiles = @(
    'src\Platform\Student_Result_Page_Handler.php'
    'src\Platform\Addon_Manifest.php'
    'src\Core\Composition_Root.php'
    'tests\Contract\submission-result-ui-g4-imp-06-s05.php'
)
foreach ($file in $phpFiles) {
    & $php -l (Join-Path $root $file)
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$runtimeFiles = $phpFiles | Where-Object { $_ -like 'src\*' }
$runtimeSource = ($runtimeFiles | ForEach-Object { Get-Content -LiteralPath (Join-Path $root $_) -Raw -Encoding UTF8 }) -join [Environment]::NewLine
foreach ($forbidden in @('$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\OjPlatform\Kernel\')) {
    if ($runtimeSource.Contains($forbidden)) { throw "G4-IMP-06-S05 runtime contains forbidden boundary marker: $forbidden" }
}

function Assert-Markers([string]$path, [string[]]$markers, [string]$label) {
    $source = Get-Content -LiteralPath (Join-Path $root $path) -Raw -Encoding UTF8
    foreach ($marker in $markers) {
        if ([string]::IsNullOrWhiteSpace($marker)) { throw "G4-IMP-06-S05 $label contains an empty marker." }
        if (-not (Test-OjaiPhpMarker -Source $source -Marker $marker -Php $php)) { throw "G4-IMP-06-S05 $label marker is missing: $marker" }
    }
}

Assert-Markers 'src\Platform\Student_Result_Page_Handler.php' @(
    'implements AddonPageHandlerV1'
    'Addon_Manifest::PUBLIC_RESULT'
    'Submission_Result_Query_Port_Interface'
    'authorization_snapshot('
    'Context_Scope::OPERATION_PARTICIPATE'
    'Object_Scope::TYPE_RELEASE'
    "'self_view'"
    'Addon_Capabilities::PARTICIPATE'
    'id="oj-ai-student-result-app"'
    'data-release-id='
    $textMarkers.unavailable
) 'PublicUI Page authorization'

Assert-Markers 'src\Platform\Addon_Manifest.php' @(
    'resources/css/student-results.css'
    'resources/js/student-results.js'
    "self::PUBLIC_RESULT, 'path' => '/oj-addons/oj-ai-dashboard/results/{release_id}'"
) 'manifest result route and assets'

Assert-Markers 'src\Core\Composition_Root.php' @(
    'use OJAI\Dashboard\Platform\Student_Result_Page_Handler;'
    'new Student_Result_Page_Handler( $policy, $submission_result_queries )'
    'Addon_Manifest::PUBLIC_RESULT => $student_result_page_handler'
) 'Composition Root Page injection'

Assert-Markers 'resources\js\student-results.js' @(
    '/me/results/'
    "new Set(['released', 'corrected'])"
    "'result_updating'"
    "'result_withdrawn'"
    'root.replaceChildren()'
    $textMarkers.renderUnavailable
    $textMarkers.renderUpdating
    $textMarkers.renderWithdrawn
    $textMarkers.releaseLabels
    'projection.score.visibility'
    'projection.feedback.visibility'
    'teacher_memo'
) 'Q33 allowlist renderer'

Assert-Markers 'resources\css\student-results.css' @(
    '.oj-ai-student-result-page'
    '.oj-ai-result-score-list__row'
    '.oj-ai-result-feedback-item'
    '.oj-ai-result-message--withdrawn'
    '@media (max-width: 700px)'
    'letter-spacing: 0'
) 'responsive result presentation'

Assert-Markers 'tests\Contract\submission-result-ui-g4-imp-06-s05.php' @(
    'current release, exact Context, self-view authorization'
    'S05 revoked learner target must fail closed.'
    'S05 permission revocation must remain an explicit denial.'
    'The Page handler must not read the result body.'
) 'Page contract'

Assert-Markers 'scripts\e2e-student-results.mjs' @(
    "{ width: 1280, height: 900 }, 'desktop'"
    "{ width: 390, height: 844 }, 'mobile'"
    "window.__setStudentResultMode('forbidden')"
    'oldResultCount: document.querySelectorAll'
    "['score_only', 'feedback_only']"
    'invalid Q33 payload did not fail closed'
) 'desktop/mobile and revocation E2E'

Assert-Markers 'docs\ai-assessment\g4-imp-06-s05-student-result-ui-integration.html' @(
    $textMarkers.stepComplete
    'GET /oj-ai/v1/me/results/{release_id}'
    'Student_Result_Page_Handler'
    'result_updating'
    'result_withdrawn'
    $textMarkers.revocationClearsBody
    'check:submission-result-ui-g4'
    'API-AUD-2026-08-24-R78-OBSERVED'
    'G4-IMP-07-S01'
) 'implementation evidence HTML'

Assert-Markers 'docs\ai-assessment\implementation-roadmap.html' @(
    'g4-imp-06-s05-student-result-ui-integration.html'
    $textMarkers.phaseComplete
    $textMarkers.stepComplete
    'G4-IMP-07-S01'
) 'implementation roadmap'

Assert-Markers 'docs\ai-assessment\platform-api-implementation-audit.html' @(
    'id="r78-observation"'
    'API-AUD-2026-08-24-R78-OBSERVED'
    'check:submission-result-ui-g4'
    'Student_Result_Page_Handler'
    $textMarkers.noPlatformWrites
) 'platform API audit'

& $php (Join-Path $root 'tests\Contract\submission-result-ui-g4-imp-06-s05.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node --check (Join-Path $root 'resources\js\student-results.js')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node --check (Join-Path $root 'scripts\e2e-student-results.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& node (Join-Path $root 'scripts\e2e-student-results.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G4-IMP-06-S05 passed: PublicUI current-result Page authorization, Q33 allowlist rendering, corrected/updating/withdrawn states, permission-loss body clearing, and desktop/mobile integration are frozen.'

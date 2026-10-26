param()

$ErrorActionPreference = 'Stop'

& (Join-Path $PSScriptRoot 'check-worksheet-print-projection-s01.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& (Join-Path $PSScriptRoot 'check-worksheet-pdf-job-artifact-s02.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& (Join-Path $PSScriptRoot 'check-worksheet-package-s03.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& (Join-Path $PSScriptRoot 'check-worksheet-pdf-package-integration-s04.ps1') -SkipRaster
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'G2-IMP-07 aggregate passed through S04 integration, security, and visual-fixture generation freeze.'
exit 0

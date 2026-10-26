param([string]$Base = 'http://127.0.0.1:8898')
$ErrorActionPreference = 'Stop'
function Read-Page([string]$Path, [hashtable]$Headers = @{}, [string]$Method = 'GET') {
    try { return Invoke-WebRequest -Uri ($Base + $Path) -Headers $Headers -Method $Method -UseBasicParsing }
    catch {
        if ($_.Exception.Response) { return [pscustomobject]@{ StatusCode = [int]$_.Exception.Response.StatusCode; Content = ''; RawContentLength = 0 } }
        throw
    }
}
$public = Read-Page '/oj-addons/learning-materials?test_role=learner'
if ($public.StatusCode -ne 200 -or $public.Content -notmatch 'lmt-result-count">[^<]*19' -or $public.Content -match 'learning-materials/3"') { throw 'Public list failed' }
$search = Read-Page '/oj-addons/learning-materials?q=21&test_role=learner'
if ($search.Content -notmatch 'lmt-result-count">[^<]*1[^0-9]') { throw 'Search failed' }
$page2 = Read-Page '/oj-addons/learning-materials?lmt_page=2&test_role=learner'
if ($page2.Content -notmatch '2 / 2') { throw 'Pagination failed' }
foreach ($id in @(2, 3, 4, 5, 6)) {
    if ((Read-Page "/oj-addons/learning-materials/$($id)?test_role=learner").StatusCode -ne 404) { throw "Hidden detail exposed: $id" }
    if ((Read-Page "/wp-admin/admin-post.php?action=learning_materials_download&material_id=$id&test_role=learner").StatusCode -ne 404) { throw "Hidden download exposed: $id" }
}
$admin = Read-Page '/oj-addons/learning-materials/3?test_role=admin'
if ($admin.StatusCode -ne 200 -or $admin.Content -notmatch 'lmt-diagnostics') { throw 'Manager preview failed' }
$range = Read-Page '/wp-admin/admin-post.php?action=learning_materials_view&material_id=1&test_role=learner' @{ Range = 'bytes=0-7' }
if ($range.StatusCode -ne 206 -or $range.RawContentLength -ne 8) { throw 'Binary range failed' }
$head = Read-Page '/wp-admin/admin-post.php?action=learning_materials_view&material_id=1&test_role=learner' @{} 'HEAD'
if ($head.StatusCode -ne 200 -or $head.RawContentLength -ne 0) { throw 'HEAD failed' }
$form = Read-Page '/oj-console/addons/learning-materials/new?test_role=admin'
foreach ($name in @('category', 'tags', 'publication_status', 'publish_from', 'publish_until', 'is_pinned')) {
    if ($form.Content -notmatch ('name="' + $name + '"')) { throw "Missing editor field: $name" }
}
$diagnostic = Invoke-WebRequest -Uri ($Base + '/wp-admin/admin-post.php?test_role=admin') -Method Post -Body @{ action = 'learning_materials_diagnose'; material_id = 1; nonce = 'fixture-nonce' } -UseBasicParsing
if ($diagnostic.StatusCode -ne 200 -or -not ($diagnostic.Content | ConvertFrom-Json).success) { throw 'Diagnostics failed' }
Write-Output 'HTTP smoke: list/search/pagination, groups/states, manager preview, editor, ranges/HEAD and diagnostics passed.'

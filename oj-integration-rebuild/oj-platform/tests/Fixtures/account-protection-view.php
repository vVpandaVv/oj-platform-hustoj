<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountProtectionDiagnostic;
use CodingPanda\OjPlatform\Ui\Component\AccountProtectionDiagnosticView;

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
Autoloader::register($root);
$view = new AccountProtectionDiagnosticView();
$blocked = new AccountProtectionDiagnostic(true, 'capability_blocked', ['oj_manage_settings', 'oj_addon_' . str_repeat('a', 160)], null, null, 12);
$filter = new AccountProtectionDiagnostic(true, 'check_failed', [], 'addon_filter_failed', 'RuntimeException', 0, 'sample-addon/Defaults.php:25');
$lookup = new AccountProtectionDiagnostic(true, 'check_failed', [], 'user_lookup_failed', 'TypeError');
$css = file_get_contents($root . '/resources/ui/css/oj-ui-system.css');
echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>계정 보호 진단 검사</title><style>' . $css . '</style></head>'
    . '<body class="ojp-app"><main style="max-width:1200px;margin:24px auto;padding:0 16px">'
    . '<h1>test1</h1><section class="ojp-section"><h2>계정 보호 진단</h2>' . $view->render($blocked) . '</section>'
    . '<h2>비밀번호 초기화 결과</h2><div class="ojp-table-wrap"><table class="ojp-table ojp-table--password-resets">'
    . '<thead><tr><th>사용자 번호</th><th>결과</th><th>사유</th></tr></thead><tbody>';
foreach ([238 => $filter, 239 => $lookup, 240 => $blocked] as $id => $diagnostic) {
    echo '<tr><td>' . $id . '</td><td>제외</td><td>' . $view->render($diagnostic, true) . '</td></tr>';
}
echo '</tbody></table></div></main></body></html>';

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($root);
$reflection = new ReflectionClass(\CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\PasswordResetActionHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();
$field = $reflection->getMethod('passwordField');
$password = 'Synthetic-<>&"-Only!123';
$html = $field->invoke($handler, 15, $password) . $field->invoke($handler, 16, 'Second-Synthetic!456');
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
$xpath = new DOMXPath($dom);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    ++$checks;
};
$assert($xpath->query('//input[@type="password"]')->length === 2, '모든 임시 비밀번호는 기본 숨김이어야 합니다.');
$assert($xpath->query('//input[@readonly and @autocomplete="off" and not(@name)]')->length === 2, '결과 비밀번호를 수정하거나 폼으로 재전송하면 안 됩니다.');
$assert($xpath->query('//input')->item(0)->getAttribute('value') === $password, '특수문자 비밀번호를 안전하게 보존해야 합니다.');
$assert(! str_contains($dom->textContent, $password), '임시 비밀번호를 일반 텍스트로 노출하면 안 됩니다.');
$assert($xpath->query('//button[@aria-pressed="false" and @type="button"]')->length === 2, '보임 버튼은 비활성 표시 상태로 시작해야 합니다.');
$assert($xpath->query('//button')->item(0)->getAttribute('aria-controls') === 'ojp-temporary-password-15', '버튼은 해당 사용자 입력란만 제어해야 합니다.');
$assert($xpath->query('//input[@id="ojp-temporary-password-16"]')->length === 1, '사용자별로 고유한 입력란 ID가 필요합니다.');
$assert(! str_contains($html, 'data-ojp-password-suggest'), '결과 비밀번호를 새 비밀번호 생성 기능으로 덮어쓰면 안 됩니다.');
$groupField = (new \CodingPanda\OjPlatform\Ui\Component\PasswordFieldView())->result('group-password', '학생의 임시 비밀번호', 'Group-Synthetic!789');
$assert(str_contains($groupField, 'type="password"') && ! str_contains($groupField, '<code'), '그룹 초기화 결과도 같은 숨김 컴포넌트를 사용해야 합니다.');
foreach (['Auth', 'Groups'] as $module) {
    $handlerFile = $module === 'Auth' ? 'PasswordResetActionHandler.php' : 'GroupPasswordResetActionHandler.php';
    $source = file_get_contents($root . '/modules/' . $module . '/Presentation/Action/' . $handlerFile);
    $assert(str_contains($source, 'PasswordFieldView') && ! str_contains($source, '<code class="ojp-one-time-secret">'), '초기화 결과에 일반 텍스트 비밀번호를 출력하면 안 됩니다.');
    $assert(str_contains($source, "'Cache-Control' => 'private, no-store'"), '초기화 결과의 캐시를 제한해야 합니다.');
}
if (in_array('--render', $argv, true)) {
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="/assets/app.css"></head><body class="ojp-app"><main class="ojp-container">'
        . '<h1>비밀번호 초기화 결과</h1><div class="ojp-table-wrap"><table class="ojp-table"><tbody>'
        . '<tr><td>15</td><td>완료</td><td>' . $field->invoke($handler, 15, $password) . '</td></tr>'
        . '<tr><td>16</td><td>완료</td><td>' . $field->invoke($handler, 16, 'Second-Synthetic!456') . '</td></tr>'
        . '<tr><td>그룹</td><td>완료</td><td>' . $groupField . '</td></tr>'
        . '</tbody></table></div>'
        . '<form id="password-form" class="ojp-form"><label class="ojp-field">로그인 비밀번호<input class="ojp-input" type="password" name="login_password" autocomplete="current-password" value="Login-Synthetic!123"></label>'
        . '<label class="ojp-field">새 비밀번호<input class="ojp-input" type="password" name="new_password" autocomplete="new-password"></label>'
        . '<label class="ojp-field">참가 비밀번호<input class="ojp-input" type="password" name="join_password" required></label>'
        . '<label class="ojp-field">서버 비밀번호<input class="ojp-input" type="password" name="server_password"></label>'
        . '<div class="ojp-password-field" data-ojp-password-group><label class="ojp-field">자동 생성<input class="ojp-input" type="password" name="custom_password" data-ojp-password-suggest></label>'
        . '<button type="button" class="ojp-button" data-ojp-password-toggle>표시</button><button type="button" class="ojp-button" data-ojp-password-regenerate>새로 생성</button>'
        . '<span data-ojp-password-strength></span></div></form>'
        . '</main><script src="/assets/app.js"></script></body></html>';
    exit;
}
echo 'Password result visibility: ' . $checks . " checks passed\n";

<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Route\MessagesPageHandler;

require __DIR__ . '/web.php';
require __DIR__ . '/messaging.php';
$navigationStart = $webAssertions;

final class WP_User_Query
{
    public static array $last = [];
    public function __construct(array $args) { self::$last = $args; }
    public function get_results(): array { return [2]; }
}
$search = new \CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPublicUserSearch();
webAssert($search->search('***', 20, 0) === [], '와일드카드만으로 전체 사용자를 조회하면 안 됩니다.');
webAssert($search->search('', 20, 0) === [], '빈 검색은 전체 사용자를 조회하면 안 됩니다.');
webAssert($search->search('learner', 500, -1) === [2], '공개 검색 결과 번호를 반환해야 합니다.');
webAssert(WP_User_Query::$last['search_columns'] === ['user_login', 'display_name'], '이메일·관리용 이름을 검색하면 안 됩니다.');
webAssert(WP_User_Query::$last['number'] === 100 && WP_User_Query::$last['offset'] === 0, '검색 범위를 제한해야 합니다.');

$viewer = new class implements CurrentAccountProvider {
    public bool $active = true;
    public function current(): CurrentAccount {
        return new CurrentAccount(new UserIdentity(1, 'viewer', 'private@example.test', '열람자'),
            new UserProfile(1, null, null, $this->active ? AccessStatus::Active : AccessStatus::Suspended, false));
    }
};
$recipients = new WebMemoryUsers();
$recipients->current = new UserIdentity(2, 'learner02', 'secret-email@example.test', '표시 학습자', [], '비공개 관리 이름');
$pageStub = new class implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder {
    public function guest(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
    public function authenticated(string $title, string $content, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
};
$urls = new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider {
    public function actionUrl(): string { return '/action'; }
    public function ajaxUrl(): string { return '/ajax'; }
};
$security = new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity {
    public function hiddenFields(string $actionKey): string { return ''; }
    public function verify(string $actionKey, HttpRequest $request): bool { return true; }
};
$handler = new MessagesPageHandler($pageStub, $viewer, $messaging, $recipients, $urls, $security, search: $search);
$findRoute = new RouteMatch(webRegisterRoute($routeRegistry, 'messaging.find', '/messages/find', 'messaging', 'authenticated'), []);
$result = $handler->handle($findRoute, new HttpRequest('GET', '/messages/find', ['login' => '학습']));
webAssert(str_contains($result->body(), '표시 학습자') && str_contains($result->body(), 'learner02'), '표시 이름과 아이디를 선택 결과로 보여야 합니다.');
webAssert(!str_contains($result->body(), 'secret-email') && !str_contains($result->body(), '비공개 관리 이름'), '검색 화면에 개인정보를 노출하면 안 됩니다.');
webAssert(str_contains($result->body(), 'href="/messages/new/2"'), '선택한 상대에게 쪽지를 쓸 수 있어야 합니다.');
$serviceProfiles = new MessagingTestProfiles();
$serviceProfiles->create(new UserProfile(1, null, null, AccessStatus::Active, false));
$serviceProfiles->create(new UserProfile(2, null, null, AccessStatus::Active, false));
$groupOnly = new \CodingPanda\OjPlatform\Modules\Messaging\Application\MessagingService(
    $messageRepository, $serviceProfiles, $groupOnlyScopePolicy, $groupOnlySettings
);
$restrictedHandler = new MessagesPageHandler($pageStub, $viewer, $groupOnly, $recipients, $urls, $security, search: $search);
$restricted = $restrictedHandler->handle($findRoute, new HttpRequest('GET', '/messages/find', ['login' => '학습']));
webAssert(!str_contains($restricted->body(), 'learner02'), '공유 그룹이 없는 상대는 검색 결과에 노출하지 않아야 합니다.');
$inboxRoute = new RouteMatch(webRegisterRoute($routeRegistry, 'messaging.inbox', '/messages', 'messaging', 'authenticated'), []);
$notification = $handler->handle($inboxRoute, new HttpRequest('GET', '/messages', ['notification' => '1']));
webAssert(json_decode($notification->body(), true)['unread'] === $messaging->unreadCount(1), '현재 사용자 자신의 개수만 반환해야 합니다.');
webAssert($notification->headers()['Cache-Control'] === 'private, no-store', '알림 응답을 공유 캐시에 저장하면 안 됩니다.');
$viewer->active = false;
webAssert($handler->handle($inboxRoute, new HttpRequest('GET', '/messages', ['notification' => '1']))->status() === 302, '중지된 계정은 알림에 접근하면 안 됩니다.');

if (in_array('--render', $argv, true)) {
    $notificationProbe->enabled = true;
    $notificationProbe->fail = false;
    $notificationProbe->count = 3;
    $menus = [];
    foreach (['홈' => '/', '문제' => '/problems', '제출' => '/submissions', '학습' => '/classes', '커뮤니티' => '/messages/find'] as $label => $url) {
        $menus[] = new \CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuItem($label, $label, $url);
    }
    echo $shell->authenticated('사용자 찾기', $result->body(), $identity, $menus, false);
}
fwrite(STDERR, 'Messaging navigation: ' . ($webAssertions - $navigationStart) . ' checks passed.' . PHP_EOL);

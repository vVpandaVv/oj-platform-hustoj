<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteDispatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AccessDecision;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressWebRuntime;

require __DIR__ . '/web.php';
$startChecks = $webAssertions;
function is_404(): bool { return $GLOBALS['notFoundFixture404'] ?? false; }
function is_feed(): bool { return $GLOBALS['notFoundFixtureFeed'] ?? false; }
function wp_is_json_request(): bool { return $GLOBALS['notFoundFixtureJson'] ?? false; }

$users->current = null;
$guestCalls = $errorPages->guestCalls;
$response = $routeFailures->notFound(new HttpRequest('GET', '/missing/<script>'));
webSame(404, $response->status(), '페이지 없음 응답은 HTTP 404를 유지해야 합니다.');
webSame($guestCalls + 1, $errorPages->guestCalls, '비로그인 방문자에게 로그인 없이 404를 표시해야 합니다.');
webAssert(str_contains($response->body(), '페이지를 찾을 수 없습니다.') && str_contains($response->body(), 'href="/"'), '404 안내와 홈 이동 버튼이 필요합니다.');
webAssert(! str_contains($response->body(), '<script>'), '요청 경로를 그대로 HTML에 출력하면 안 됩니다.');
$users->current = $identity;
$authenticatedCalls = $errorPages->authenticatedCalls;
$routeFailures->notFound(new HttpRequest('GET', '/missing'));
webSame($authenticatedCalls + 1, $errorPages->authenticatedCalls, '로그인 방문자에게 공통 사용자 셸을 사용해야 합니다.');

$allow = new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteAccessPolicy {
    public function decide(RouteMatch $route, HttpRequest $request): AccessDecision { return AccessDecision::allow(); }
};
$reporter = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    public function report(Throwable $error): void { throw $error; }
};
$dispatcher = new RouteDispatcher($routingMatcher, $routeHandlers, $allow, $reporter, $routeFailures);
$emitter = new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ResponseEmitter {
    public array $responses = [];
    public function emit(HttpResponse $response, bool $headRequest = false): void { $this->responses[] = [$response, $headRequest]; }
};
$reflection = new ReflectionClass(WordPressWebRuntime::class);
$runtime = $reflection->newInstanceWithoutConstructor();
foreach (['routes' => $dispatcher, 'emitter' => $emitter, 'transport' => $routeTransport, 'notFound' => $routeFailures] as $property => $value) {
    $reflection->getProperty($property)->setValue($runtime, $value);
}
$_SERVER['REQUEST_URI'] = '/missing-page';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = []; $_POST = []; $_FILES = [];
$GLOBALS['notFoundFixture404'] = false;
$runtime->dispatchRoute();
webSame(0, count($emitter->responses), '정상적인 WordPress 페이지는 테마에 맡겨야 합니다.');
$GLOBALS['notFoundFixture404'] = true;
$runtime->dispatchRoute();
webSame(404, $emitter->responses[0][0]->status(), '알 수 없는 주소는 전용 404 화면으로 응답해야 합니다.');
$_SERVER['REQUEST_METHOD'] = 'HEAD';
$runtime->dispatchRoute();
webSame(true, $emitter->responses[1][1], 'HEAD 요청은 본문 없이 404 헤더를 반환해야 합니다.');
foreach (['notFoundFixtureFeed', 'notFoundFixtureJson'] as $flag) {
    $GLOBALS[$flag] = true;
    $runtime->dispatchRoute();
    $GLOBALS[$flag] = false;
}
webSame(2, count($emitter->responses), '피드와 JSON 요청을 HTML로 덮어쓰면 안 됩니다.');
$_SERVER['REQUEST_METHOD'] = 'POST';
$runtime->dispatchRoute();
webSame(2, count($emitter->responses), '등록되지 않은 POST 동작을 일반 페이지로 처리하면 안 됩니다.');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/problems/1024';
$runtime->dispatchRoute();
webSame(404, $emitter->responses[2][0]->status(), '핸들러가 없는 등록 경로도 공통 404를 사용해야 합니다.');
webAssert(str_contains($emitter->responses[2][0]->body(), '페이지를 찾을 수 없습니다.'), '핸들러 누락 응답을 일반 텍스트로 표시하면 안 됩니다.');
echo 'Not found page: ' . ($webAssertions - $startChecks) . " checks passed\n";

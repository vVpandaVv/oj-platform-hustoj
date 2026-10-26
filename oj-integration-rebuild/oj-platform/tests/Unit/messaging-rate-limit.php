<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAttemptLimiter;
use CodingPanda\OjPlatform\Modules\Messaging\Presentation\Action\MessagingActionHandler;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

require __DIR__ . '/messaging.php';
$startChecks = $messagingAssertions;
$GLOBALS['rateValues'] = []; $GLOBALS['rateTtls'] = []; $GLOBALS['rateOptions'] = [];
function get_transient(string $key): mixed { return $GLOBALS['rateValues'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): bool {
    if ($GLOBALS['rateFailWrite'] ?? false) { return false; }
    $GLOBALS['rateValues'][$key] = $value; $GLOBALS['rateTtls'][$key] = $ttl;
    return ! ($GLOBALS['rateUnchanged'] ?? false);
}
function delete_transient(string $key): bool { $found = isset($GLOBALS['rateValues'][$key]); unset($GLOBALS['rateValues'][$key]); return $found; }
function get_option(string $key): mixed { return $GLOBALS['rateOptions'][$key] ?? false; }
function update_option(string $key, mixed $value, bool $autoload): bool { $GLOBALS['rateOptions'][$key] = $value; return true; }
function delete_option(string $key): bool { unset($GLOBALS['rateOptions'][$key]); return true; }
$clock = new class implements \CodingPanda\OjPlatform\Shared\Clock\Clock {
    public int $timestamp = 100;
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->timestamp); }
};
$limiter = new WordPressAttemptLimiter($clock);
messagingAssert($limiter->consumeFixedWindow('messaging_send', '1', 2, 600), '첫 발송을 허용해야 합니다.');
$key = array_key_first($GLOBALS['rateValues']);
messagingSame(700, $GLOBALS['rateValues'][$key]['reset_at'], '첫 시도부터 정확히 600초 후 초기화해야 합니다.');
$clock->timestamp = 400;
messagingAssert($limiter->consumeFixedWindow('messaging_send', '1', 2, 600), '창 안의 두 번째 발송을 허용해야 합니다.');
messagingSame(300, $GLOBALS['rateTtls'][$key], '두 번째 발송은 남은 시간만 저장해야 합니다.');
for ($i = 0; $i < 36; ++$i) { messagingAssert(! $limiter->consumeFixedWindow('messaging_send', '1', 2, 600), '한도 이후의 반복 요청을 거절해야 합니다.'); }
messagingSame(700, $GLOBALS['rateValues'][$key]['reset_at'], '거절된 요청이 잠금 시간을 늘리면 안 됩니다.');
messagingAssert($limiter->consumeFixedWindow('messaging_send', '2', 2, 600), '다른 사용자는 독립적인 발송 한도를 사용해야 합니다.');
$clock->timestamp = 700;
messagingAssert($limiter->consumeFixedWindow('messaging_send', '1', 2, 600), '정확한 만료 시각부터 다시 발송할 수 있어야 합니다.');
messagingSame(1, $GLOBALS['rateValues'][$key]['count'], '새 시간창은 1건부터 시작해야 합니다.');
messagingSame(2, count($GLOBALS['rateOptions']['oj_platform_rate_index']), '시간창마다 새 카운터 키를 누적하면 안 됩니다.');
$GLOBALS['rateUnchanged'] = true;
messagingAssert($limiter->consumeFixedWindow('messaging_send', '3', 2, 600), '동일 저장 결과를 재조회로 확인할 수 있어야 합니다.');
$GLOBALS['rateUnchanged'] = false;
$limiter->resetAll();
messagingSame([], $GLOBALS['rateValues'], '전체 초기화는 고정 시간창 카운터도 지워야 합니다.');

$clock->timestamp = 1000;
$serviceSettings->save(\CodingPanda\OjPlatform\Modules\Messaging\Domain\MessagingScope::Open, 2, 1000, $serviceSettings->snapshot()->revision());
$guard = new class implements ActionAccessGuard {
    public int $legacyCalls = 0;
    public function verifyNonce(string $actionKey, HttpRequest $request): void {}
    public function actor(string $actionKey, HttpRequest $request, string $capability): UserIdentity { return $this->authenticatedActor($actionKey, $request); }
    public function authenticatedActor(string $actionKey, HttpRequest $request): UserIdentity { return new UserIdentity(1, 'sender', 'sender@example.test', '보내는 사람'); }
    public function throttle(string $scope, string $identifier, int $limit, int $windowSeconds): void { ++$this->legacyCalls; throw new DomainException('신고 제한'); }
};
$responses = new class implements ActionResponse {
    public ?UiMessage $last = null;
    public function redirect(string $path, UiMessage $message, int $userId = 0): HttpResponse { $this->last = $message; return HttpResponse::redirect($path); }
};
$errors = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    public array $items = [];
    public function report(Throwable $error): void { $this->items[] = $error; }
};
$handler = new MessagingActionHandler($messaging, $reports, $serviceSettings, $guard, $responses, $errors, $limiter);
$request = static fn (int $recipient, string $body): HttpRequest => new HttpRequest('POST', '/', [], ['action' => 'oj_message_send', 'recipient_user_id' => $recipient, 'body' => $body]);
$handler->handle($request(2, ''));
$handler->handle($request(3, '중지된 사용자'));
messagingSame([], $GLOBALS['rateValues'], '본문 또는 수신자 검증 실패는 한도에 포함되면 안 됩니다.');
$before = count($messageRepository->rows);
$handler->handle($request(2, '정상 발송 1'));
$handler->handle($request(2, '정상 발송 2'));
$handler->handle($request(2, '거절되는 발송'));
messagingSame($before + 2, count($messageRepository->rows), '한도 이내의 쪽지만 저장해야 합니다.');
messagingAssert(str_contains($responses->last->body(), '10분에 2건'), '사용자에게 실제 발송 한도를 안내해야 합니다.');
messagingSame([], $errors->items, '정상적인 제한·검증 거절을 시스템 장애로 기록하면 안 됩니다.');
messagingSame(0, $guard->legacyCalls, '기존 이동 만료 카운터를 중복 차감하면 안 됩니다.');
$clock->timestamp = 1600;
$handler->handle($request(2, '다음 시간창 발송'));
messagingSame($before + 3, count($messageRepository->rows), '만료 뒤 실제 발송도 성공해야 합니다.');
$GLOBALS['rateFailWrite'] = true;
$handler->handle($request(2, '저장소 장애'));
messagingSame(1, count($errors->items), '실제 카운터 저장 장애는 오류 기록으로 남겨야 합니다.');
messagingSame($before + 3, count($messageRepository->rows), '카운터 저장 장애 시 보호를 우회해 발송하면 안 됩니다.');
$GLOBALS['rateFailWrite'] = false;
echo 'Messaging rate limit: ' . ($messagingAssertions - $startChecks) . " checks passed\n";

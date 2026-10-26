<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Unit/research.php';

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjResearch\Presentation\ResearchActionHandler;
use CodingPanda\OjResearch\Domain\ResearchProtocol;

$features->enabled = true;
$before = $checks;
$actionGuard = new class implements ActionAccessGuard {
    public int $userId = 1;
    public bool $nonceValid = true;
    public function verifyNonce(string $action, HttpRequest $request): void
    {
        if (! $this->nonceValid || $action !== 'oj_research_command') {
            throw new DomainException('인증 실패');
        }
    }
    public function actor(string $action, HttpRequest $request, string $capability): UserIdentity
    {
        return $this->authenticatedActor($action, $request);
    }
    public function authenticatedActor(string $action, HttpRequest $request): UserIdentity
    {
        $this->verifyNonce($action, $request);
        return new UserIdentity($this->userId, 'user' . $this->userId, '', 'test');
    }
    public function throttle(string $scope, string $identity, int $limit, int $window): void {}
};
$errors = new class implements ErrorReporter {
    public function report(Throwable $error): void { throw $error; }
};
$handler = new ResearchActionHandler($service, $actionGuard, $errors);
$request = static fn (array $body): HttpRequest => new HttpRequest('POST', '/action', [], $body);
$response = $handler->handle($request(['operation' => 'export', 'study_id' => $id, 'format' => 'csv']));
researchAssert($response->status() === 200 && $response->headers()['X-Content-Type-Options'] === 'nosniff', '실제 연구 액션이 보호된 CSV를 내려주어야 함');
$actionGuard->userId = 10;
researchAssert($handler->handle($request(['operation' => 'export', 'study_id' => $id]))->status() === 409, '학습자의 연구 내보내기를 거부');
$actionGuard->userId = 1;
$actionGuard->nonceValid = false;
researchAssert($handler->handle($request(['operation' => 'export', 'study_id' => $id]))->status() === 409, '분리 후에도 공통 액션 인증 유지');
$actionGuard->nonceValid = true;
$body = $input + [
    'operation' => 'create', 'practice_problems' => '1000', 'check_prompts' => '독립 확인 문제',
    'rater_1' => '2', 'rater_2' => '3',
];
researchAssert($handler->handle($request($body))->status() === 409, '필수 피드백 지침이 없는 요청 거부');
$body['instructions'] = ResearchProtocol::instructions();
$created = $handler->handle($request($body));
researchAssert($created->status() === 302, '연구 생성 요청 분리 후 정상 이동: ' . strip_tags($created->body()));
preg_match('#/research/(\d+)#', $created->headers()['Location'], $matches);
$newId = (int) $matches[1];
$enroll = ['operation' => 'enroll', 'study_id' => $newId, 'revision' => 1, 'participants' => [10, 11]];
researchAssert($handler->handle($request($enroll))->status() === 409, '동의 누락 등록 거부');
researchAssert($store->participants($newId) === [], '거부된 등록은 참여자를 생성하지 않음');
researchAssert($handler->handle($request($enroll + ['consent_confirmed' => '1']))->status() === 302, '동의 확인 후 등록');
researchAssert(count($store->participants($newId)) === 2, '실제 참여자 등록을 수행');
echo 'Research action dispatch: ' . ($checks - $before) . " assertions passed.\n";

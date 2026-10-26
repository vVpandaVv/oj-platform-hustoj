<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\GuardianConsentRequestService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class GuardianConsentRequestActionHandler implements ActionHandler
{
    private const ACTION = 'oj_send_guardian_consent_request';

    public function __construct(
        private readonly GuardianConsentRequestService $requests,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $returnTo = $request->bodyString('context') === 'settings'
            ? '/user-settings'
            : '/register';
        try {
            $this->guard->throttle(
                'guardian_request',
                ($request->header('x-oj-client-ip') ?? 'unknown')
                    . '|' . strtolower($request->bodyString('child_email')),
                5,
                3600
            );
            $this->guard->verifyNonce(self::ACTION, $request);
            $this->requests->request(
                $request->bodyString('child_email'),
                $request->bodyString('guardian_name'),
                $request->bodyString('guardian_email')
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                $returnTo,
                new UiMessage(
                    'auth.guardian_request_failed',
                    MessageTone::Danger,
                    '동의 요청 실패',
                    '가입 이메일과 법정대리인 이메일을 서로 다르게 입력해 주세요. 계속 실패하면 운영자에게 동의서 게시와 가입 설정을 확인해 달라고 요청해 주세요.'
                )
            );
        }

        return $this->responses->redirect(
            $returnTo,
            new UiMessage(
                'auth.guardian_request_sent',
                MessageTone::Info,
                '동의 요청 전송',
                '법정대리인 확인 주소와 사용자 확인 코드를 각각 보냈습니다.'
            )
        );
    }
}

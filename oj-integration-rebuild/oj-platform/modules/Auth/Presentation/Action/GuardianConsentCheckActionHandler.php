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

final class GuardianConsentCheckActionHandler implements ActionHandler
{
    private const ACTION = 'oj_check_guardian_consent_request';

    public function __construct(
        private readonly GuardianConsentRequestService $requests,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $verified = false;
        try {
            $this->guard->throttle(
                'guardian_check',
                ($request->header('x-oj-client-ip') ?? 'unknown')
                    . '|' . strtolower($request->bodyString('child_email')),
                10,
                600
            );
            $this->guard->verifyNonce(self::ACTION, $request);
            $verified = $this->requests->isVerified(
                $request->bodyString('child_email'),
                $request->bodyString('guardian_code')
            );
        } catch (Throwable) {
        }

        return $this->responses->redirect(
            '/register',
            new UiMessage(
                $verified ? 'auth.guardian_verified' : 'auth.guardian_not_verified',
                $verified ? MessageTone::Success : MessageTone::Warning,
                $verified ? '동의 확인 완료' : '동의 확인 대기',
                $verified
                    ? '이제 같은 이메일과 확인 코드로 회원가입을 완료할 수 있습니다.'
                    : '법정대리인 확인이 끝났는지와 확인 코드를 다시 확인해 주세요.'
            )
        );
    }
}

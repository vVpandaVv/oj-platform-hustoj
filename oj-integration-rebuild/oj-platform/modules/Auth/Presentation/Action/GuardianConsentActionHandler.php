<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\GuardianConsentRequestService;
use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianRelationship;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class GuardianConsentActionHandler implements ActionHandler
{
    private const ACTION = 'oj_guardian_consent';

    public function __construct(
        private readonly GuardianConsentRequestService $requests,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $this->guard->throttle(
                'guardian_consent',
                $request->header('x-oj-client-ip') ?? 'unknown',
                10,
                600
            );
            $this->guard->verifyNonce(self::ACTION, $request);
            if (! $request->bodyBool('consent')) {
                throw new \DomainException('동의 확인이 필요합니다.');
            }

            $relationship = GuardianRelationship::tryFrom($request->bodyString('guardian_relationship'));
            if ($relationship === null || ! $this->requests->confirm(
                $request->bodyString('token'),
                $request->bodyInt('document_id'),
                $relationship
            )) {
                throw new \DomainException('동의 확인 주소가 만료되었습니다.');
            }

            return $this->responses->redirect(
                '/login',
                new UiMessage(
                    'auth.guardian_consent_complete',
                    MessageTone::Success,
                    '동의 확인 완료',
                    '법정대리인 동의를 확인했습니다.'
                )
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/login',
                new UiMessage(
                    'auth.guardian_consent_failed',
                    MessageTone::Danger,
                    '동의 확인 실패',
                    '동의 문서, 법정대리인 관계와 확인 주소를 확인해 주세요. 문서 변경·만료 시 새 동의 요청이 필요합니다.'
                )
            );
        }
    }
}

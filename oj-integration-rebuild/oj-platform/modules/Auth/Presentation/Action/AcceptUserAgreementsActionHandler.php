<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class AcceptUserAgreementsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_accept_user_agreements';

    public function __construct(
        private readonly AgreementRepository $agreements,
        private readonly WordPressUserGateway $users,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $identity = $this->users->currentUser();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }

        try {
            $this->guard->verifyNonce(self::ACTION, $request);
            $this->agreements->recordAgreement(
                $identity->userId(),
                $request->bodyIntList('agreement_document_ids'),
                $request->header('x-oj-client-ip'),
                $request->header('user-agent')
            );

            return $this->responses->redirect(
                '/user-agreements',
                new UiMessage(
                    'auth.agreements_saved',
                    MessageTone::Success,
                    '동의 저장 완료',
                    '현재 문서 버전의 동의를 기록했습니다.'
                ),
                $identity->userId()
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/user-agreements',
                new UiMessage(
                    'auth.agreements_failed',
                    MessageTone::Danger,
                    '동의 저장 실패',
                    '필수 문서를 다시 확인해 주세요.'
                ),
                $identity->userId()
            );
        }
    }
}

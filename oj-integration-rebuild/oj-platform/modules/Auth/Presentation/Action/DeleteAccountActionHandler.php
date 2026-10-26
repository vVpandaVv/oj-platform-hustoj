<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureService;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class DeleteAccountActionHandler implements ActionHandler
{
    private const ACTION = 'oj_delete_account';

    public function __construct(
        private readonly CurrentAccountService $accounts,
        private readonly AccountErasureService $erasures,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }

        try {
            $this->guard->verifyNonce(self::ACTION, $request);
            $result = $this->erasures->request(
                $identity->userId(),
                $request->bodyString('current_password'),
                $request->bodyString('confirm_irreversible') === '1',
                $request->bodyString('confirmation_text')
            );

            return $this->responses->redirect(
                '/user-settings?mode=erasure-requested',
                new UiMessage(
                    'auth.erasure_requested',
                    MessageTone::Success,
                    '계정 삭제 요청 접수',
                    '계정 정리 작업을 등록했습니다. 완료되면 모든 로그인 세션이 종료됩니다. 작업 번호: '
                        . $result->jobId()
                ),
                $identity->userId()
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/user-settings?mode=erasure',
                new UiMessage(
                    'auth.erasure_rejected',
                    MessageTone::Danger,
                    '계정 삭제 요청 확인 필요',
                    $error->getMessage()
                ),
                $identity->userId()
            );
        } catch (Throwable $error) {
            $this->errors->report($error);
            return $this->responses->redirect(
                '/user-settings?mode=erasure',
                new UiMessage(
                    'auth.erasure_failed',
                    MessageTone::Danger,
                    '계정 삭제 요청 실패',
                    '계정 정리 작업을 등록하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                ),
                $identity->userId()
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ChangePasswordActionHandler implements ActionHandler
{
    private const ACTION = 'oj_change_password';

    public function __construct(
        private readonly CurrentAccountService $accounts,
        private readonly UserAccountService $accountService,
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
            $newPassword = $request->bodyString('new_password');
            if ($newPassword !== $request->bodyString('new_password_confirm')) {
                throw new DomainException('새 비밀번호 확인이 일치하지 않습니다.');
            }

            $this->accountService->changePassword(
                $identity->userId(),
                $request->bodyString('current_password'),
                $newPassword
            );

            return $this->responses->redirect(
                '/user-settings',
                new UiMessage(
                    'auth.password_changed',
                    MessageTone::Success,
                    '비밀번호 변경 완료',
                    '새 비밀번호를 저장했습니다.'
                ),
                $identity->userId()
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/user-settings?mode=password',
                new UiMessage(
                    'auth.password_change_failed',
                    MessageTone::Danger,
                    '비밀번호 변경 실패',
                    $error->getMessage()
                ),
                $identity->userId()
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/user-settings?mode=password',
                new UiMessage(
                    'auth.password_change_failed',
                    MessageTone::Danger,
                    '비밀번호 변경 실패',
                    '알 수 없는 오류로 비밀번호를 변경하지 못했습니다. 잠시 후 다시 시도해 주세요.'
                ),
                $identity->userId()
            );
        }
    }
}

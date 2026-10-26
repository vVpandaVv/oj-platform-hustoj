<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class LostPasswordActionHandler implements ActionHandler
{
    private const ACTION = 'oj_lost_password';

    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $this->guard->throttle(
                'password_recovery',
                ($request->header('x-oj-client-ip') ?? 'unknown')
                    . '|' . strtolower($request->bodyString('user_login')),
                5,
                3600
            );
            $this->guard->verifyNonce(self::ACTION, $request);
            if ($request->bodyString('operation') === 'reset') {
                $newPassword = $request->bodyString('new_password');
                if ($newPassword !== $request->bodyString('new_password_confirm')) {
                    throw new \DomainException('새 비밀번호 확인이 일치하지 않습니다.');
                }

                $this->users->resetPasswordWithKey(
                    $request->bodyString('user_login'),
                    $request->bodyString('reset_key'),
                    $newPassword
                );

                return $this->responses->redirect(
                    '/login',
                    new UiMessage(
                        'auth.password_reset_complete',
                        MessageTone::Success,
                        '비밀번호 변경 완료',
                        '새 비밀번호로 로그인해 주세요.'
                    )
                );
            }

            $this->users->requestPasswordReset($request->bodyString('user_login'));
        } catch (Throwable) {
        }

        return $this->responses->redirect(
            '/login',
            new UiMessage(
                'auth.password_reset_requested',
                MessageTone::Info,
                '복구 안내 확인',
                '일치하는 계정과 이메일이 있으면 복구 안내를 보냈습니다.'
            )
        );
    }
}

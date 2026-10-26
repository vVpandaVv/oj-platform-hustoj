<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentSessionTerminator;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class LogoutActionHandler implements ActionHandler
{
    private const ACTION = 'oj_logout';

    public function __construct(
        private readonly CurrentSessionTerminator $sessions,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $this->guard->verifyNonce(self::ACTION, $request);
            $this->sessions->terminate();

            return $this->responses->redirect(
                '/login',
                new UiMessage(
                    'auth.logout_complete',
                    MessageTone::Success,
                    '로그아웃 완료',
                    '안전하게 로그아웃했습니다.'
                )
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/',
                new UiMessage(
                    'auth.logout_validation_failed',
                    MessageTone::Warning,
                    '로그아웃 확인 필요',
                    $error->getMessage()
                )
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            try {
                return $this->responses->redirect(
                    '/',
                    new UiMessage(
                        'auth.logout_failed',
                        MessageTone::Danger,
                        '로그아웃 실패',
                        '로그아웃을 완료하지 못했습니다. 다시 시도해 주세요.'
                    )
                );
            } catch (Throwable) {
                return HttpResponse::redirect('/');
            }
        }
    }
}

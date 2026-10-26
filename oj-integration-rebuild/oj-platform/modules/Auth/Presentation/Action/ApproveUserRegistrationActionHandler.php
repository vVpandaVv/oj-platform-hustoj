<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAdministrationService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class ApproveUserRegistrationActionHandler implements ActionHandler
{
    private const ACTION = 'oj_console_approve_user_registration';

    public function __construct(
        private readonly UserAdministrationService $users,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->actor(
                self::ACTION,
                $request,
                'oj_approve_user_registrations'
            );
            $actorUserId = $actor->userId();
            $this->users->approve($request->bodyInt('user_id'), $actor->userId());

            return $this->responses->redirect(
                '/oj-console/users?status=pending',
                new UiMessage(
                    'auth.registration_approved',
                    MessageTone::Success,
                    '가입 승인 완료',
                    '선택한 계정을 이용 가능 상태로 변경했습니다.'
                ),
                $actor->userId()
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/oj-console/users?status=pending',
                new UiMessage(
                    'auth.registration_approval_failed',
                    MessageTone::Danger,
                    '가입 승인 실패',
                    '현재 계정 상태와 승인 권한을 다시 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}

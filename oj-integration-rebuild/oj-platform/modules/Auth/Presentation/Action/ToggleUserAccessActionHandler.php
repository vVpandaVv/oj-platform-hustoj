<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAdministrationService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class ToggleUserAccessActionHandler implements ActionHandler
{
    private const ACTION = 'oj_console_toggle_user_active';

    public function __construct(
        private readonly UserAdministrationService $users,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $targetUserId = $request->bodyInt('user_id');
        $actorUserId = 0;
        try {
            $actor = $this->guard->actor(
                self::ACTION,
                $request,
                'oj_deactivate_users'
            );
            $actorUserId = $actor->userId();
            if ($targetUserId === $actor->userId()) {
                throw new \DomainException('본인 이용 상태는 이 화면에서 바꿀 수 없습니다.');
            }

            $status = AccessStatus::tryFrom($request->bodyString('access_status'));
            if ($status === null) {
                throw new \DomainException('이용 상태가 올바르지 않습니다.');
            }

            $this->users->changeAccessStatus(
                $targetUserId,
                $status,
                $actor->userId()
            );

            return $this->responses->redirect(
                '/oj-console/users/' . $targetUserId . '/edit',
                new UiMessage(
                    'auth.access_status_changed',
                    MessageTone::Success,
                    '이용 상태 변경 완료',
                    '사용자 이용 상태를 저장했습니다.'
                ),
                $actor->userId()
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/oj-console/users/' . max(1, $targetUserId) . '/edit',
                new UiMessage(
                    'auth.access_status_change_failed',
                    MessageTone::Danger,
                    '이용 상태 변경 실패',
                    '현재 사용자 상태와 권한을 다시 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}

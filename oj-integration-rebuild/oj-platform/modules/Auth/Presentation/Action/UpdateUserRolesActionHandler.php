<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserRoleService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class UpdateUserRolesActionHandler implements ActionHandler
{
    private const ACTION = 'oj_console_update_user_roles';

    public function __construct(
        private readonly UserRoleService $roles,
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
                'oj_manage_roles'
            );
            $actorUserId = $actor->userId();
            if ($targetUserId === $actor->userId()) {
                throw new \DomainException('본인 역할은 이 화면에서 바꿀 수 없습니다.');
            }

            $roles = $request->body()['roles'] ?? [];
            $this->roles->assign(
                $actor->userId(),
                $targetUserId,
                is_array($roles) ? array_values(array_map('strval', $roles)) : []
            );

            return $this->responses->redirect(
                '/oj-console/users/' . $targetUserId . '/roles',
                new UiMessage(
                    'auth.user_roles_updated',
                    MessageTone::Success,
                    '역할 저장 완료',
                    '사용자 역할을 저장했습니다.'
                ),
                $actor->userId()
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/oj-console/users/' . max(1, $targetUserId) . '/roles',
                new UiMessage(
                    'auth.user_roles_update_failed',
                    MessageTone::Danger,
                    '역할 저장 실패',
                    '역할과 권한을 다시 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}

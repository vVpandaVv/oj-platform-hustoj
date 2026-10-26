<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class GroupMembershipActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $action,
        private readonly string $operation,
        private readonly bool $publicRedirect,
        private readonly GroupService $groups,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        $groupId = $request->bodyInt('group_id');
        try {
            $actor = $this->guard->authenticatedActor(
                $this->action,
                $request
            );
            $actorId = $actor->userId();
            $targetId = in_array(
                $this->operation,
                ['join', 'cancel', 'leave'],
                true
            )
                ? $actorId
                : $request->bodyInt('target_user_id');
            if (
                $targetId < 1
                && $request->bodyString('target_user_login') !== ''
            ) {
                $targetId = $this->groups->userIdByLogin(
                    $request->bodyString('target_user_login')
                );
            }
            $this->groups->membership(
                $actorId,
                $groupId,
                $this->operation,
                $targetId,
                $request->bodyString('join_code')
            );

            return $this->responses->redirect(
                $this->path($groupId),
                new UiMessage(
                    'groups.membership_saved',
                    MessageTone::Success,
                    '구성원 상태 변경 완료',
                    '그룹 구성원 상태를 저장했습니다.'
                ),
                $actorId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                $this->path($groupId),
                new UiMessage(
                    'groups.membership_failed',
                    MessageTone::Danger,
                    '구성원 상태 변경 실패',
                    $error->getMessage()
                ),
                $actorId
            );
        }
    }

    private function path(int $groupId): string
    {
        if (
            $this->publicRedirect
            || in_array($this->operation, ['join', 'cancel', 'leave'], true)
        ) {
            return '/groups/' . $groupId;
        }
        if (in_array($this->operation, ['approve', 'reject'], true)) {
            return '/oj-console/groups/' . $groupId . '/approvals';
        }

        return '/oj-console/groups/' . $groupId . '/members';
    }
}

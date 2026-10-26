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

final class GroupManagerActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $action,
        private readonly bool $enabled,
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
            $targetId = $request->bodyInt('target_user_id');
            if (
                $targetId < 1
                && $request->bodyString('target_user_login') !== ''
            ) {
                $targetId = $this->groups->userIdByLogin(
                    $request->bodyString('target_user_login')
                );
            }
            $this->groups->manager(
                $actorId,
                $groupId,
                $targetId,
                $this->enabled
            );

            return $this->responses->redirect(
                '/oj-console/groups/' . $groupId . '/managers',
                new UiMessage(
                    'groups.manager_saved',
                    MessageTone::Success,
                    '운영자 변경 완료',
                    '그룹 운영자 권한을 변경했습니다.'
                ),
                $actorId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/groups/' . $groupId . '/managers',
                new UiMessage(
                    'groups.manager_failed',
                    MessageTone::Danger,
                    '운영자 변경 실패',
                    $error->getMessage()
                ),
                $actorId
            );
        }
    }
}

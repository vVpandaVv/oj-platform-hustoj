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

final class GroupLifecycleActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $action,
        private readonly bool $archived,
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
            $this->groups->setArchived(
                $actorId,
                $groupId,
                $this->archived
            );

            return $this->responses->redirect(
                $this->archived
                    ? '/oj-console/groups'
                    : '/oj-console/groups/' . $groupId . '/edit',
                new UiMessage(
                    'groups.lifecycle_saved',
                    MessageTone::Success,
                    '그룹 상태 변경 완료',
                    $this->archived
                        ? '그룹을 보관했습니다.'
                        : '그룹을 복원했습니다.'
                ),
                $actorId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/groups',
                new UiMessage(
                    'groups.lifecycle_failed',
                    MessageTone::Danger,
                    '그룹 상태 변경 실패',
                    $error->getMessage()
                ),
                $actorId
            );
        }
    }
}

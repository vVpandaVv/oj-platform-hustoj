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
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveGroupActionHandler implements ActionHandler
{
    private const ACTION = 'oj_group_save';

    public function __construct(
        private readonly GroupService $groups,
        private readonly GroupRequestFactory $requests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            $groupId = $this->groups->save(
                $actorId,
                $this->requests->saveCommand($request)
            );

            return $this->responses->redirect(
                '/oj-console/groups/' . $groupId . '/edit',
                new UiMessage(
                    'groups.saved',
                    MessageTone::Success,
                    '그룹 저장 완료',
                    '그룹 설정을 저장했습니다.'
                ),
                $actorId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);
            $groupId = $request->bodyInt('group_id');

            return $this->responses->redirect(
                $groupId > 0
                    ? '/oj-console/groups/' . $groupId . '/edit'
                    : '/oj-console/groups/new',
                new UiMessage(
                    'groups.save_failed',
                    MessageTone::Danger,
                    '그룹 저장 실패',
                    $error->getMessage()
                ),
                $actorId
            );
        }
    }
}

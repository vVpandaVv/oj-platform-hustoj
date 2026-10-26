<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class UpdateUserActionHandler implements ActionHandler
{
    private const ACTION = 'oj_console_update_user';

    public function __construct(
        private readonly UserAccountService $users,
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
                'oj_edit_users'
            );
            $actorUserId = $actor->userId();
            $this->users->updateManagedProfile(
                $targetUserId,
                $request->bodyString('email'),
                $request->bodyString('display_name'),
                $request->bodyString('nickname'),
                $request->bodyString('affiliation'),
                $request->bodyString('birth_date'),
                $request->bodyString('bio'),
                $actorUserId
            );

            return $this->responses->redirect(
                '/oj-console/users/' . $targetUserId . '/edit',
                new UiMessage(
                    'auth.user_updated',
                    MessageTone::Success,
                    '사용자 수정 완료',
                    '사용자 정보를 저장했습니다.'
                ),
                $actor->userId()
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/oj-console/users/' . max(1, $targetUserId) . '/edit',
                new UiMessage(
                    'auth.user_update_failed',
                    MessageTone::Danger,
                    '사용자 수정 실패',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable) {
            return $this->responses->redirect(
                '/oj-console/users/' . max(1, $targetUserId) . '/edit',
                new UiMessage(
                    'auth.user_update_failed',
                    MessageTone::Danger,
                    '사용자 수정 실패',
                    '입력 정보와 수정 권한을 다시 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}

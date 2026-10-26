<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\AdminUserCreationService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class CreateUserActionHandler implements ActionHandler
{
    private const ACTION = 'oj_console_create_user';

    public function __construct(
        private readonly AdminUserCreationService $users,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->actor(
                self::ACTION,
                $request,
                'oj_create_users'
            );
            $actorUserId = $actor->userId();
            $identity = $this->users->create(
                $request->bodyString('user_login'),
                $request->bodyString('email'),
                $request->bodyString('password'),
                $request->bodyString('display_name'),
                $request->bodyString('affiliation'),
                $request->bodyString('birth_date'),
                'subscriber'
            );

            return $this->responses->redirect(
                '/oj-console/users/' . $identity->userId(),
                new UiMessage(
                    'auth.user_created',
                    MessageTone::Success,
                    '사용자 생성 완료',
                    '새 계정을 만들었습니다. 첫 로그인에서 비밀번호를 변경해야 합니다.'
                ),
                $actor->userId()
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/oj-console/users/new',
                new UiMessage(
                    'auth.user_creation_failed',
                    MessageTone::Danger,
                    '사용자 생성 실패',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/users/new',
                new UiMessage(
                    'auth.user_creation_failed',
                    MessageTone::Danger,
                    '사용자 생성 실패',
                    '알 수 없는 오류로 사용자를 만들지 못했습니다. 잠시 후 다시 시도해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}

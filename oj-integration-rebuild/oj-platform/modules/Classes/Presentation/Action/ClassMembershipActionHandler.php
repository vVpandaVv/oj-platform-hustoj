<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ClassMembershipActionHandler implements ActionHandler
{
    private const ACTION = 'oj_class_membership_command';

    public function __construct(
        private readonly ClassService $classes,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        $classId = $request->bodyInt('class_id');
        $operation = $request->bodyString('operation');
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            $targetId = $request->bodyInt('target_user_id');
            if (
                $targetId < 1
                && $request->bodyString('target_user_login') !== ''
            ) {
                $targetId = $this->classes->userIdByLogin(
                    $request->bodyString('target_user_login')
                );
            }
            if ($targetId < 1) {
                if (in_array(
                    $operation,
                    ['request', 'cancel', 'leave', 'accept', 'decline'],
                    true
                )) {
                    $targetId = $actorId;
                } else {
                    throw new DomainException(
                        '대상 사용자의 로그인 아이디를 입력해 주세요.'
                    );
                }
            }
            $this->classes->membership(
                $actorId,
                $classId,
                $operation,
                $targetId,
                $request->bodyString('password')
            );

            return $this->responses->redirect(
                $this->successPath($request, $classId),
                new UiMessage(
                    'classes.membership_saved',
                    MessageTone::Success,
                    '참여 상태 변경 완료',
                    '수업 참여 상태를 저장했습니다.'
                ),
                $actorId
            );
        } catch (DomainException $error) {
            return $this->failure($request, $classId, $error->getMessage(), $actorId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                $request,
                $classId,
                '수업 참여 상태를 변경하지 못했습니다.',
                $actorId
            );
        }
    }

    private function successPath(HttpRequest $request, int $classId): string
    {
        return $request->bodyString('operation') === 'decline'
            ? '/classes'
            : $this->path($request, $classId);
    }

    private function path(HttpRequest $request, int $classId): string
    {
        return in_array(
            $request->bodyString('operation'),
            ['request', 'cancel', 'leave', 'accept', 'decline'],
            true
        )
            ? '/classes/' . $classId
            : '/oj-console/classes/' . $classId . '/participants';
    }

    private function failure(
        HttpRequest $request,
        int $classId,
        string $message,
        int $actorId
    ): HttpResponse {
        return $this->responses->redirect(
            $this->path($request, $classId),
            new UiMessage(
                'classes.membership_failed',
                MessageTone::Danger,
                '참여 상태 변경 실패',
                $message
            ),
            $actorId
        );
    }
}

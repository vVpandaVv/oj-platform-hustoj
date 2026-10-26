<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ContestParticipationActionHandler implements ActionHandler
{
    private const ACTION = 'oj_contest_participation_command';

    public function __construct(
        private readonly ContestService $contests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        $contestId = $request->bodyInt('contest_id');
        $operation = $request->bodyString('operation');
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            $targetId = $request->bodyInt('target_user_id');
            if (
                $targetId < 1
                && $request->bodyString('target_user_login') !== ''
            ) {
                $targetId = $this->contests->userIdByLogin(
                    $request->bodyString('target_user_login')
                );
            }
            if ($targetId < 1) {
                if (in_array($operation, ['request', 'withdraw'], true)) {
                    $targetId = $actorId;
                } else {
                    throw new DomainException(
                        '대상 사용자의 로그인 아이디를 입력해 주세요.'
                    );
                }
            }
            $this->contests->participation(
                $actorId,
                $contestId,
                $operation,
                $targetId
            );

            return $this->responses->redirect(
                $this->path($contestId, $operation),
                new UiMessage(
                    'contests.participation_saved',
                    MessageTone::Success,
                    '참여 상태 변경 완료',
                    '대회 참여 상태를 저장했습니다.'
                ),
                $actorId
            );
        } catch (DomainException $error) {
            return $this->failed(
                $contestId,
                $operation,
                $error->getMessage(),
                $actorId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failed(
                $contestId,
                $operation,
                '대회 참여 상태를 변경하지 못했습니다.',
                $actorId
            );
        }
    }

    private function path(int $contestId, string $operation): string
    {
        return in_array($operation, ['request', 'withdraw'], true)
            ? '/contests/' . $contestId
            : '/oj-console/contests/' . $contestId . '/edit';
    }

    private function failed(
        int $contestId,
        string $operation,
        string $message,
        int $actorId
    ): HttpResponse {
        return $this->responses->redirect(
            $this->path($contestId, $operation),
            new UiMessage(
                'contests.participation_failed',
                MessageTone::Danger,
                '참여 상태 변경 실패',
                $message
            ),
            $actorId
        );
    }
}

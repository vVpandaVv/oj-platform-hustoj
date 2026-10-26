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

final class ContestLifecycleActionHandler implements ActionHandler
{
    private const ACTION = 'oj_contest_lifecycle_command';

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
        $operation = $request->bodyString('operation');
        $contestId = $request->bodyInt('contest_id');
        try {
            if (! in_array($operation, ['start', 'archive', 'restore'], true)) {
                throw new DomainException('정의되지 않은 대회 상태 작업입니다.');
            }
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            if ($operation === 'start') {
                $this->contests->startNow(
                    $actorId,
                    $actor->userLogin(),
                    $contestId
                );
            } else {
                $this->contests->setArchived(
                    $actorId,
                    $contestId,
                    $operation === 'archive'
                );
            }
            $title = match ($operation) {
                'start' => '대회 시작 완료',
                'archive' => '대회 보관 완료',
                default => '대회 복원 완료',
            };

            return $this->responses->redirect(
                $operation === 'start'
                    ? '/oj-console/contests/' . $contestId . '/edit'
                    : ($operation === 'archive' ? '/oj-console/contests/deleted' : '/oj-console/contests'),
                new UiMessage(
                    'contests.lifecycle_saved',
                    MessageTone::Success,
                    $title,
                    $operation === 'start'
                        ? '대회가 진행 중 단계로 전환되어 제출을 받을 수 있습니다.'
                        : '대회 제출과 참가 기록은 유지됩니다.'
                ),
                $actorId
            );
        } catch (DomainException $error) {
            return $this->failed($error->getMessage(), $actorId, $operation, $contestId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failed(
                '대회 상태를 변경하지 못했습니다.',
                $actorId,
                $operation,
                $contestId
            );
        }
    }

    private function failed(
        string $message,
        int $actorId,
        string $operation,
        int $contestId
    ): HttpResponse
    {
        return $this->responses->redirect(
            $operation === 'start' && $contestId > 0
                ? '/oj-console/contests/' . $contestId . '/edit'
                : '/oj-console/contests',
            new UiMessage(
                'contests.lifecycle_failed',
                MessageTone::Danger,
                '대회 상태 변경 실패',
                $message
            ),
            $actorId
        );
    }
}

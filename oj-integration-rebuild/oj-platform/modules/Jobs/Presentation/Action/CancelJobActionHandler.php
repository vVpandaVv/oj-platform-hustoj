<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobControlService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class CancelJobActionHandler implements ActionHandler
{
    private const ACTION = 'oj_job_cancel';

    public function __construct(
        private readonly JobControlService $jobs,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        $jobId = $request->bodyInt('job_id');
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $result = $this->jobs->cancel($actorUserId, $jobId);
            $message = $result->affectedCount() > 0
                ? sprintf('대기 중인 %d개 항목을 취소했습니다.', $result->affectedCount())
                : '취소 요청을 확인했습니다. 실행 중인 항목은 현재 실행을 마친 뒤 종료됩니다.';

            return $this->redirect(
                $jobId,
                new UiMessage(
                    'jobs.cancelled',
                    MessageTone::Success,
                    '작업 취소 요청 완료',
                    $message
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->failure($jobId, $error->getMessage(), $actorUserId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                $jobId,
                '작업 취소 요청을 처리하지 못했습니다.',
                $actorUserId
            );
        }
    }

    private function failure(
        int $jobId,
        string $message,
        int $actorUserId
    ): HttpResponse {
        return $this->redirect(
            $jobId,
            new UiMessage(
                'jobs.cancel_failed',
                MessageTone::Danger,
                '작업 취소 실패',
                $message
            ),
            $actorUserId
        );
    }

    private function redirect(
        int $jobId,
        UiMessage $message,
        int $actorUserId
    ): HttpResponse {
        $path = $jobId > 0
            ? '/oj-console/jobs/' . $jobId
            : '/oj-console';

        return $this->responses->redirect($path, $message, $actorUserId);
    }
}

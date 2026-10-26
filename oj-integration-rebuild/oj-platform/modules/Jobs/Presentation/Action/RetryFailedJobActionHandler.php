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

final class RetryFailedJobActionHandler implements ActionHandler
{
    private const ACTION = 'oj_job_retry_failed';

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
            $result = $this->jobs->retryFailed($actorUserId, $jobId);

            return $this->redirect(
                $jobId,
                new UiMessage(
                    'jobs.retry_started',
                    MessageTone::Success,
                    '실패 항목 재시도 시작',
                    sprintf(
                        '실패하거나 확인이 필요한 %d개 항목을 다시 대기열에 등록했습니다.',
                        $result->affectedCount()
                    )
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->failure($jobId, $error->getMessage(), $actorUserId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                $jobId,
                '실패 항목을 다시 등록하지 못했습니다.',
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
                'jobs.retry_failed',
                MessageTone::Danger,
                '실패 항목 재시도 실패',
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

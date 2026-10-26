<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobControl;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobController;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWakeup;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobControlResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use DomainException;
use Throwable;

final class JobControlService implements JobController
{
    public function __construct(
        private readonly JobQuery $jobs,
        private readonly JobControl $control,
        private readonly JobAccessPolicy $access,
        private readonly FormDraftStore $payloads,
        private readonly ErrorReporter $errors,
        private readonly ?JobWakeup $wakeup = null
    ) {
    }

    public function cancel(int $actorUserId, int $jobId): JobControlResult
    {
        $job = $this->controlledJob($actorUserId, $jobId);
        $result = $this->control->requestCancellation($jobId, $actorUserId);
        foreach ($result->discardedPayloadReferences() as $reference) {
            try {
                $this->payloads->discard(
                    new FormDraftReference($reference),
                    $job->createdByUserId()
                );
            } catch (Throwable $error) {
                $this->errors->report($error);
            }
        }

        return $result;
    }

    public function retryFailed(int $actorUserId, int $jobId): JobControlResult
    {
        $this->controlledJob($actorUserId, $jobId);

        $result = $this->control->retryFailed($jobId, $actorUserId);
        try {
            $this->wakeup?->wake();
        } catch (Throwable $error) {
            $this->errors->report($error);
        }
        return $result;
    }

    private function controlledJob(
        int $actorUserId,
        int $jobId
    ): JobDetails
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || ! $this->access->canControl($actorUserId, $job)) {
            throw new DomainException('이 작업을 변경할 권한이 없습니다.');
        }

        return $job;
    }
}

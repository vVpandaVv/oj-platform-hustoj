<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use DomainException;

final class QuizRevisionReapplyJobHandler implements JobHandler
{
    public function __construct(private readonly QuizReapplyService $service)
    {
    }

    public function jobType(): string
    {
        return 'quiz_revision_reapply';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        try {
            $result = $this->service->execute(
                $item->claim()->createdByUserId(),
                $item->payload()
            );
        } catch (DomainException $error) {
            return JobHandlerResult::needsAttention(
                'quiz.reapply_state_changed',
                $error->getMessage()
            );
        }

        return JobHandlerResult::succeeded(
            'quiz.reapply_completed',
            $result['mode'] === 'graded'
                ? '완료 결과의 새 버전 재채점을 기록했습니다.'
                : '진행 중 응시를 새 버전으로 다시 시작했습니다.',
            $result
        );
    }
}

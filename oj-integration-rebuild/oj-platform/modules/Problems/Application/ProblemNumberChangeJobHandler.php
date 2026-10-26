<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use DomainException;

final class ProblemNumberChangeJobHandler implements JobHandler
{
    public function __construct(
        private readonly ProblemNumberChangeService $service,
        private readonly ProblemNumberChangeCoordinator $coordinator,
        private readonly ProblemAccessPolicy $access
    ) {
    }

    public function jobType(): string
    {
        return 'problem_number_change';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        if (! $this->access->canChangeIds($item->claim()->createdByUserId())) {
            return JobHandlerResult::needsAttention(
                'problem.change_id_permission_changed',
                '현재 권한으로 문제 번호를 변경할 수 없습니다.'
            );
        }
        try {
            $plan = $this->service->planFromPayload($item->payload());
            $result = $this->coordinator->execute($item->claim(), $plan);

            return match ($result->outcome()) {
                'succeeded' => JobHandlerResult::succeeded(
                    'problem.number_changed',
                    $result->message(),
                    [
                        'mapping_count' => $plan->requested()->count(),
                        'plan_hash' => $plan->hash(),
                    ]
                ),
                'compensated' => JobHandlerResult::failed(
                    'problem.number_change_compensated',
                    $result->message()
                ),
                default => JobHandlerResult::needsAttention(
                    'problem.number_change_compensation_failed',
                    $result->message(),
                    ['plan_hash' => $plan->hash()]
                ),
            };
        } catch (DomainException $error) {
            return JobHandlerResult::needsAttention(
                'problem.number_change_state_changed',
                $error->getMessage()
            );
        }
    }
}

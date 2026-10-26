<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Domain;

use DomainException;

final class JobStateMachine
{
    public function canTransition(JobStatus $from, JobStatus $to): bool
    {
        if ($from === $to) {
            return $from === JobStatus::Running;
        }

        return match ($from) {
            JobStatus::Queued => in_array($to, [
                JobStatus::Running,
                JobStatus::Failed,
                JobStatus::Cancelled,
            ], true),
            JobStatus::Running => in_array($to, [
                JobStatus::NeedsAttention,
                JobStatus::Partial,
                JobStatus::Completed,
                JobStatus::Failed,
                JobStatus::Cancelled,
            ], true),
            JobStatus::Partial, JobStatus::Failed => $to === JobStatus::Running,
            JobStatus::NeedsAttention,
            JobStatus::Completed,
            JobStatus::Cancelled => false,
        };
    }

    public function assertTransition(JobStatus $from, JobStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new DomainException(sprintf(
                '허용되지 않은 작업 상태 전이입니다: %s -> %s',
                $from->value,
                $to->value
            ));
        }
    }
}

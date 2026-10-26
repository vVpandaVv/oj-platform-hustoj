<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class SubmissionReservation
{
    public function __construct(
        private readonly int $eventId,
        private readonly bool $new,
        private readonly ?SubmissionId $submissionId,
        private readonly bool $failed
    ) {
        if ($this->eventId < 1) {
            throw new InvalidArgumentException('제출 예약 기록 번호가 올바르지 않습니다.');
        }
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function isNew(): bool
    {
        return $this->new;
    }

    public function submissionId(): ?SubmissionId
    {
        return $this->submissionId;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }
}

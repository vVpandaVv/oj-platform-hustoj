<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Domain;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;

final class ClassReport
{
    /**
     * @param list<UserIdentity> $participants
     * @param list<SubmissionProblem> $problems
     * @param list<SubmissionProjection> $submissions
     * @param array<int, string> $participantApprovedAt user_id => enrollment approval timestamp
     */
    public function __construct(
        private readonly LearningClass $class,
        private readonly array $participants,
        private readonly array $problems,
        private readonly array $submissions,
        private readonly array $participantApprovedAt = []
    ) {
    }

    public function class(): LearningClass
    {
        return $this->class;
    }

    /** @return list<UserIdentity> */
    public function participants(): array
    {
        return $this->participants;
    }

    /** @return list<SubmissionProblem> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<SubmissionProjection> */
    public function submissions(): array
    {
        return $this->submissions;
    }

    /**
     * @return array<int, string> user_id => enrollment approval timestamp (Y-m-d H:i:s)
     */
    public function participantApprovedAt(): array
    {
        return $this->participantApprovedAt;
    }
}

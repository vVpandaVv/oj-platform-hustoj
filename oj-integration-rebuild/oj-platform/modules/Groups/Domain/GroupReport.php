<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Domain;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;

final class GroupReport
{
    /**
     * @param list<UserIdentity> $members
     * @param list<SubmissionProjection> $submissions
     */
    public function __construct(
        private readonly LearningGroup $group,
        private readonly array $members,
        private readonly array $submissions
    ) {
    }

    public function group(): LearningGroup { return $this->group; }

    /** @return list<UserIdentity> */
    public function members(): array { return $this->members; }

    /** @return list<SubmissionProjection> */
    public function submissions(): array { return $this->submissions; }
}

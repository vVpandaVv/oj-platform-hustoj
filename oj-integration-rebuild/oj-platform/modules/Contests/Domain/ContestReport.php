<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;

final class ContestReport
{
    /**
     * @param list<UserIdentity> $participants
     * @param list<ContestProblem> $problems
     * @param list<SubmissionProjection> $submissions
     * @param list<ContestRankingRow> $rankings
     */
    public function __construct(
        private readonly Contest $contest,
        private readonly ContestSettings $settings,
        private readonly array $participants,
        private readonly array $problems,
        private readonly array $submissions,
        private readonly array $rankings,
        private readonly ContestStatistics $statistics,
        private readonly ?string $rankingFreezeCutoff = null
    ) {
    }

    public function contest(): Contest { return $this->contest; }
    public function settings(): ContestSettings { return $this->settings; }

    /** @return list<UserIdentity> */
    public function participants(): array { return $this->participants; }

    /** @return list<ContestProblem> */
    public function problems(): array { return $this->problems; }

    /** @return list<SubmissionProjection> */
    public function submissions(): array { return $this->submissions; }

    /** @return list<ContestRankingRow> */
    public function rankings(): array { return $this->rankings; }
    public function statistics(): ContestStatistics { return $this->statistics; }
    public function rankingFreezeCutoff(): ?string
    {
        return $this->rankingFreezeCutoff;
    }

    public function isRankingFrozen(): bool { return $this->rankingFreezeCutoff !== null; }
}

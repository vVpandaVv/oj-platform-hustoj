<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Domain\LegacyJudgeCandidate;

final class LoginResult
{
    public function __construct(
        private readonly LoginOutcome $outcome,
        private readonly ?int $userId = null,
        private readonly ?LegacyJudgeCandidate $legacyCandidate = null
    ) {
    }

    public function outcome(): LoginOutcome
    {
        return $this->outcome;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function legacyCandidate(): ?LegacyJudgeCandidate
    {
        return $this->legacyCandidate;
    }
}

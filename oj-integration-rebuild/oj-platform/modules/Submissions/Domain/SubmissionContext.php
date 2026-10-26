<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

final class SubmissionContext
{
    public function __construct(
        private readonly int $contestId = 0,
        private readonly int $contestProblemNumber = -1,
        private readonly ?int $classId = null,
        private readonly ?int $groupId = null,
        private readonly ?string $blocklyProfile = null
    ) {
    }

    public function contestId(): int
    {
        return $this->contestId;
    }

    public function contestProblemNumber(): int
    {
        return $this->contestProblemNumber;
    }

    public function classId(): ?int
    {
        return $this->classId;
    }

    public function groupId(): ?int
    {
        return $this->groupId;
    }

    public function blocklyProfile(): ?string
    {
        $profile = $this->blocklyProfile === null
            ? ''
            : strtolower(trim($this->blocklyProfile));

        return $profile === '' ? null : $profile;
    }
    public function isDirect(): bool
    {
        return $this->contestId === 0
            && $this->classId === null
            && $this->groupId === null;
    }
}

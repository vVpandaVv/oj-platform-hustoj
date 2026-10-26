<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

final class ContestSaveCommand
{
    /**
     * @param list<int> $problemIds
     * @param array<int, float> $problemScores
     * @param array<int, string> $managerRoles
     */
    public function __construct(
        private readonly int $contestId,
        private readonly string $title,
        private readonly string $description,
        private readonly string $startsAt,
        private readonly string $endsAt,
        private readonly string $visibility,
        private readonly ?int $groupId,
        private readonly string $resultVisibility,
        private readonly string $rankingVisibility,
        private readonly int $wrongPenaltyPoints,
        private readonly int $timeScorePercent,
        private readonly string $partialScorePolicy,
        private readonly string $scoringPolicy,
        private readonly string $rankingPolicy,
        private readonly int $freezeMinutes,
        private readonly ?string $blocklyProfileKey,
        private readonly bool $allowAiFeedback,
        private readonly string $wrongAnswerPolicy,
        private readonly string $ipPolicy,
        private readonly array $problemIds,
        private readonly array $problemScores,
        private readonly array $managerRoles,
        private readonly bool $approvalRequired = false
    ) {
    }

    public function contestId(): int { return $this->contestId; }
    public function title(): string { return trim($this->title); }
    public function description(): string { return trim($this->description); }
    public function startsAt(): string { return trim($this->startsAt); }
    public function endsAt(): string { return trim($this->endsAt); }
    public function visibility(): string { return $this->visibility; }
    public function requiresApproval(): bool { return $this->approvalRequired; }
    public function groupId(): ?int { return $this->groupId; }
    public function resultVisibility(): string { return $this->resultVisibility; }
    public function rankingVisibility(): string { return $this->rankingVisibility; }
    public function wrongPenaltyPoints(): int { return $this->wrongPenaltyPoints; }
    public function timeScorePercent(): int { return $this->timeScorePercent; }
    public function partialScorePolicy(): string { return $this->partialScorePolicy; }
    public function scoringPolicy(): string { return $this->scoringPolicy; }
    public function rankingPolicy(): string { return $this->rankingPolicy; }
    public function freezeMinutes(): int { return $this->freezeMinutes; }
    public function blocklyProfileKey(): ?string { return self::nullable($this->blocklyProfileKey); }
    public function allowAiFeedback(): bool { return $this->allowAiFeedback; }
    public function wrongAnswerPolicy(): string { return $this->wrongAnswerPolicy; }
    public function ipPolicy(): string { return $this->ipPolicy; }

    /** @return list<int> */
    public function problemIds(): array
    {
        return array_values(array_unique(array_filter(
            $this->problemIds,
            static fn (mixed $value): bool => is_int($value) && $value > 0
        )));
    }

    /** @return array<int, float> */
    public function problemScores(): array { return $this->problemScores; }

    /** @return array<int, string> */
    public function managerRoles(): array { return $this->managerRoles; }

    private static function nullable(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}

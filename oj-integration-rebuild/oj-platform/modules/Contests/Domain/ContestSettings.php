<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Domain;

final class ContestSettings
{
    public function __construct(
        private readonly int $contestId,
        private readonly int $ownerUserId,
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
        private readonly bool $approvalRequired = false
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $scoringPolicy = ContestScoringPolicy::normalize(
            (string) ($row['scoring_policy'] ?? '')
        );

        return new self(
            (int) ($row['contest_id'] ?? 0),
            (int) ($row['owner_user_id'] ?? 0),
            (string) ($row['visibility'] ?? 'private'),
            ($row['group_id'] ?? null) === null ? null : (int) $row['group_id'],
            (string) ($row['result_visibility'] ?? 'participants'),
            (string) ($row['ranking_visibility'] ?? 'private'),
            (int) ($row['wrong_penalty_points'] ?? 5),
            (int) ($row['time_score_percent'] ?? 10),
            (string) ($row['partial_score_policy'] ?? 'none'),
            $scoringPolicy,
            ContestScoringPolicy::rankingFor($scoringPolicy),
            (int) ($row['freeze_minutes'] ?? 0),
            self::nullable($row['blockly_profile_key'] ?? null),
            (int) ($row['allow_ai_feedback'] ?? 1) === 1,
            (string) ($row['wrong_answer_policy'] ?? 'penalty_before_accept'),
            (string) ($row['ip_policy'] ?? 'none'),
            (int) ($row['approval_required'] ?? 0) === 1
        );
    }

    public function contestId(): int { return $this->contestId; }
    public function ownerUserId(): int { return $this->ownerUserId; }
    public function visibility(): string { return $this->visibility; }
    public function groupId(): ?int { return $this->groupId; }
    public function resultVisibility(): string { return $this->resultVisibility; }
    public function rankingVisibility(): string { return $this->rankingVisibility; }
    public function wrongPenaltyPoints(): int { return $this->wrongPenaltyPoints; }
    public function timeScorePercent(): int { return $this->timeScorePercent; }
    public function partialScorePolicy(): string { return $this->partialScorePolicy; }
    public function scoringPolicy(): string { return $this->scoringPolicy; }
    public function rankingPolicy(): string { return $this->rankingPolicy; }
    public function freezeMinutes(): int { return $this->freezeMinutes; }
    public function blocklyProfileKey(): ?string { return $this->blocklyProfileKey; }
    public function allowsAiFeedback(): bool { return $this->allowAiFeedback; }
    public function wrongAnswerPolicy(): string { return $this->wrongAnswerPolicy; }
    public function ipPolicy(): string { return $this->ipPolicy; }
    public function requiresApproval(): bool { return $this->approvalRequired; }

    /**
     * @return array<string, int|string|bool|null>
     */
    public function snapshot(): array
    {
        return [
            'contest_id' => $this->contestId,
            'owner_user_id' => $this->ownerUserId,
            'visibility' => $this->visibility,
            'approval_required' => $this->approvalRequired,
            'group_id' => $this->groupId,
            'result_visibility' => $this->resultVisibility,
            'ranking_visibility' => $this->rankingVisibility,
            'wrong_penalty_points' => $this->wrongPenaltyPoints,
            'time_score_percent' => $this->timeScorePercent,
            'partial_score_policy' => $this->partialScorePolicy,
            'scoring_policy' => $this->scoringPolicy,
            'ranking_policy' => $this->rankingPolicy,
            'freeze_minutes' => $this->freezeMinutes,
            'blockly_profile_key' => $this->blocklyProfileKey,
            'allow_ai_feedback' => $this->allowAiFeedback,
            'wrong_answer_policy' => $this->wrongAnswerPolicy,
            'ip_policy' => $this->ipPolicy,
        ];
    }
    private static function nullable(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === ''
            ? null
            : (string) $value;
    }
}

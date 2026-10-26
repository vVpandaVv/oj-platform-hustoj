<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Domain;

final class RankingEntry
{
    public function __construct(
        private readonly int $rank,
        private readonly int $userId,
        private readonly string $displayName,
        private readonly int $solvedProblemCount,
        private readonly int $acceptedSubmissionCount,
        private readonly int $submissionCount,
        private readonly bool $profileVisible,
        private readonly float $accuracyRate = 0.0
    ) {
    }

    public function rank(): int { return $this->rank; }
    public function userId(): int { return $this->userId; }
    public function displayName(): string { return $this->displayName; }
    public function solvedProblemCount(): int { return $this->solvedProblemCount; }
    public function acceptedSubmissionCount(): int { return $this->acceptedSubmissionCount; }
    public function submissionCount(): int { return $this->submissionCount; }
    public function isProfileVisible(): bool { return $this->profileVisible; }
    /** Accepted submissions divided by completed valid submissions, 0.0–1.0. */
    public function accuracyRate(): float { return $this->accuracyRate; }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rank' => $this->rank,
            'user_id' => $this->userId,
            'display_name' => $this->displayName,
            'solved_problem_count' => $this->solvedProblemCount,
            'accepted_submission_count' => $this->acceptedSubmissionCount,
            'submission_count' => $this->submissionCount,
            'profile_visible' => $this->profileVisible,
            'accuracy_rate' => $this->accuracyRate,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (int) ($row['rank'] ?? 0),
            (int) ($row['user_id'] ?? 0),
            (string) ($row['display_name'] ?? ''),
            (int) ($row['solved_problem_count'] ?? 0),
            (int) ($row['accepted_submission_count'] ?? 0),
            (int) ($row['submission_count'] ?? 0),
            (bool) ($row['profile_visible'] ?? false),
            (float) ($row['accuracy_rate'] ?? 0.0)
        );
    }
}

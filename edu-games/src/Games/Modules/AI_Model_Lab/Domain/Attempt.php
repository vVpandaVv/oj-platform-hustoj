<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab\Domain;

/**
 * Value object mirroring one row of the ai_model_lab_attempts table.
 */
final class Attempt {
    public const STATUS_PHASE1_PENDING = 'phase1_pending';
    public const STATUS_PHASE1_DONE = 'phase1_done';
    public const STATUS_PHASE2_DONE = 'phase2_done';

    /**
     * @param array<int, string> $submittedLabels sampleId => labelKey
     */
    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private readonly string $activityKey,
        private readonly string $status,
        private readonly ?string $modelKey,
        private readonly array $submittedLabels,
        private readonly ?int $correctCount,
        private readonly ?int $totalCount,
        private readonly ?float $phase1Score,
        private readonly int $phase1Attempts,
        private readonly ?float $phase2Accuracy,
        private readonly ?float $phase2Score,
        private readonly int $phase2Attempts,
        private readonly ?float $totalScore,
        private readonly string $startedAt,
        private readonly ?string $phase1SubmittedAt,
        private readonly ?string $phase2SubmittedAt
    ) {
    }

    public function id(): int {
        return $this->id;
    }

    public function userId(): int {
        return $this->userId;
    }

    public function activityKey(): string {
        return $this->activityKey;
    }

    public function status(): string {
        return $this->status;
    }

    public function modelKey(): ?string {
        return $this->modelKey;
    }

    /**
     * @return array<int, string>
     */
    public function submittedLabels(): array {
        return $this->submittedLabels;
    }

    public function correctCount(): ?int {
        return $this->correctCount;
    }

    public function totalCount(): ?int {
        return $this->totalCount;
    }

    public function phase1Score(): ?float {
        return $this->phase1Score;
    }

    public function phase1Attempts(): int {
        return $this->phase1Attempts;
    }

    public function phase2Accuracy(): ?float {
        return $this->phase2Accuracy;
    }

    public function phase2Score(): ?float {
        return $this->phase2Score;
    }

    public function phase2Attempts(): int {
        return $this->phase2Attempts;
    }

    public function totalScore(): ?float {
        return $this->totalScore;
    }

    public function startedAt(): string {
        return $this->startedAt;
    }

    public function phase1SubmittedAt(): ?string {
        return $this->phase1SubmittedAt;
    }

    public function phase2SubmittedAt(): ?string {
        return $this->phase2SubmittedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return array(
            'id' => $this->id,
            'userId' => $this->userId,
            'activityKey' => $this->activityKey,
            'status' => $this->status,
            'modelKey' => $this->modelKey,
            'submittedLabels' => $this->submittedLabels,
            'correctCount' => $this->correctCount,
            'totalCount' => $this->totalCount,
            'phase1Score' => $this->phase1Score,
            'phase1Attempts' => $this->phase1Attempts,
            'phase2Accuracy' => $this->phase2Accuracy,
            'phase2Score' => $this->phase2Score,
            'phase2Attempts' => $this->phase2Attempts,
            'totalScore' => $this->totalScore,
            'startedAt' => $this->startedAt,
            'phase1SubmittedAt' => $this->phase1SubmittedAt,
            'phase2SubmittedAt' => $this->phase2SubmittedAt,
        );
    }
}

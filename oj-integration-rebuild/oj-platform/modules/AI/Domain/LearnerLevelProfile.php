<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Domain;

use InvalidArgumentException;

final class LearnerLevelProfile
{
    public const POLICY_VERSION = 'learner-level-v1';

    /**
     * @param array{
     *   proven_difficulty: ?float,
     *   breadth: ?float,
     *   recent_challenge: ?float,
     *   stability: ?float,
     *   skill_score: ?float
     * } $metrics
     */
    public function __construct(
        private readonly int $userId,
        private readonly string $analysisStatus,
        private readonly ?string $level,
        private readonly ?int $provenTier,
        private readonly array $metrics,
        private readonly int $submissionCount,
        private readonly int $attemptedProblemCount,
        private readonly int $solvedProblemCount,
        private readonly int $recentSampleCount,
        private readonly float $difficultyCoverage,
        private readonly float $topicCoverage,
        private readonly int $lastSubmissionId,
        private readonly string $analyzedAt,
        private readonly string $policyVersion = self::POLICY_VERSION
    ) {
        if (
            $this->userId < 1
            || ! in_array(
                $this->analysisStatus,
                ['insufficient', 'provisional', 'classified', 'reliable'],
                true
            )
            || (
                $this->level !== null
                && ! in_array(
                    $this->level,
                    ['beginner', 'intermediate', 'advanced'],
                    true
                )
            )
            || (
                $this->provenTier !== null
                && ($this->provenTier < 1 || $this->provenTier > 5)
            )
        ) {
            throw new InvalidArgumentException('학습 수준 프로필이 올바르지 않습니다.');
        }
    }

    public function userId(): int { return $this->userId; }
    public function analysisStatus(): string { return $this->analysisStatus; }
    public function level(): ?string { return $this->level; }
    public function provenTier(): ?int { return $this->provenTier; }
    public function submissionCount(): int { return $this->submissionCount; }
    public function attemptedProblemCount(): int { return $this->attemptedProblemCount; }
    public function solvedProblemCount(): int { return $this->solvedProblemCount; }
    public function recentSampleCount(): int { return $this->recentSampleCount; }
    public function difficultyCoverage(): float { return $this->difficultyCoverage; }
    public function topicCoverage(): float { return $this->topicCoverage; }
    public function lastSubmissionId(): int { return $this->lastSubmissionId; }
    public function analyzedAt(): string { return $this->analyzedAt; }
    public function policyVersion(): string { return $this->policyVersion; }

    public function metric(string $key): ?float
    {
        return array_key_exists($key, $this->metrics)
            ? $this->metrics[$key]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'user_id' => $this->userId,
            'analysis_status' => $this->analysisStatus,
            'level' => $this->level,
            'proven_tier' => $this->provenTier,
            'proven_difficulty' => $this->metric('proven_difficulty'),
            'breadth' => $this->metric('breadth'),
            'recent_challenge' => $this->metric('recent_challenge'),
            'stability' => $this->metric('stability'),
            'skill_score' => $this->metric('skill_score'),
            'submission_count' => $this->submissionCount,
            'attempted_problem_count' => $this->attemptedProblemCount,
            'solved_problem_count' => $this->solvedProblemCount,
            'recent_sample_count' => $this->recentSampleCount,
            'difficulty_coverage' => $this->difficultyCoverage,
            'topic_coverage' => $this->topicCoverage,
            'policy_version' => $this->policyVersion,
            'last_submission_id' => $this->lastSubmissionId,
            'analyzed_at' => $this->analyzedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['user_id'] ?? 0),
            (string) ($row['analysis_status'] ?? ''),
            self::nullableString($row['level'] ?? null),
            ($row['proven_tier'] ?? null) === null
                ? null
                : (int) $row['proven_tier'],
            [
                'proven_difficulty' => self::nullableFloat($row['proven_difficulty'] ?? null),
                'breadth' => self::nullableFloat($row['breadth'] ?? null),
                'recent_challenge' => self::nullableFloat($row['recent_challenge'] ?? null),
                'stability' => self::nullableFloat($row['stability'] ?? null),
                'skill_score' => self::nullableFloat($row['skill_score'] ?? null),
            ],
            (int) ($row['submission_count'] ?? 0),
            (int) ($row['attempted_problem_count'] ?? 0),
            (int) ($row['solved_problem_count'] ?? 0),
            (int) ($row['recent_sample_count'] ?? 0),
            (float) ($row['difficulty_coverage'] ?? 0),
            (float) ($row['topic_coverage'] ?? 0),
            (int) ($row['last_submission_id'] ?? 0),
            (string) ($row['analyzed_at'] ?? ''),
            (string) ($row['policy_version'] ?? self::POLICY_VERSION)
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}

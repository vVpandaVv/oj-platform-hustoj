<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Domain;

use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearnerLevelSummary;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;

final class LearningDashboardSnapshot
{
    /**
     * @param list<DashboardProblemItem> $continueProblems
     * @param list<DashboardProblemItem> $reviewProblems
     * @param list<DashboardProblemItem> $recommendations
     * @param list<SubmissionProjection> $recentSubmissions
     * @param array<int, int> $difficultyDistribution
     * @param array<string, int> $topicDistribution
     */
    public function __construct(
        private readonly int $submissionCount,
        private readonly int $attemptedProblemCount,
        private readonly int $solvedProblemCount,
        private readonly int $recentSolvedProblemCount,
        private readonly int $recoveredProblemCount,
        private readonly bool $levelAvailable,
        private readonly ?LearnerLevelSummary $level,
        private readonly array $continueProblems,
        private readonly array $reviewProblems,
        private readonly array $recommendations,
        private readonly array $recentSubmissions,
        private readonly array $difficultyDistribution,
        private readonly array $topicDistribution,
        private readonly int $recommendationPage
    ) {
    }

    public function submissionCount(): int { return $this->submissionCount; }
    public function attemptedProblemCount(): int { return $this->attemptedProblemCount; }
    public function solvedProblemCount(): int { return $this->solvedProblemCount; }
    public function recentSolvedProblemCount(): int { return $this->recentSolvedProblemCount; }
    public function recoveredProblemCount(): int { return $this->recoveredProblemCount; }
    public function levelAvailable(): bool { return $this->levelAvailable; }
    public function level(): ?LearnerLevelSummary { return $this->level; }
    public function recommendationPage(): int { return $this->recommendationPage; }

    /** @return list<DashboardProblemItem> */
    public function continueProblems(): array { return $this->continueProblems; }

    /** @return list<DashboardProblemItem> */
    public function reviewProblems(): array { return $this->reviewProblems; }

    /** @return list<DashboardProblemItem> */
    public function recommendations(): array { return $this->recommendations; }

    /** @return list<SubmissionProjection> */
    public function recentSubmissions(): array { return $this->recentSubmissions; }

    /** @return array<int, int> */
    public function difficultyDistribution(): array { return $this->difficultyDistribution; }

    /** @return array<string, int> */
    public function topicDistribution(): array { return $this->topicDistribution; }
}

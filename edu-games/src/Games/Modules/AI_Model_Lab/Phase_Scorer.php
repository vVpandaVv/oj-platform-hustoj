<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab;

/**
 * Pure scoring functions — no side effects, no persistence. Formulas are
 * deliberate design choices for the hands-on labeling redesign; do not
 * change them without checking with the activity owner.
 */
final class Phase_Scorer {
    /**
     * Phase 1 (labeling accuracy) — a plain percent-correct score. No
     * baseline/best normalization is needed here, since "how many did you
     * personally get right out of the batch" is already intuitive on a
     * 0-100% scale.
     */
    public function scorePhase1(
        int $correctCount,
        int $totalCount,
        int $retryPenaltyPerAttempt,
        int $attemptNumber
    ): float {
        $raw = $totalCount > 0
            ? self::clamp($correctCount / $totalCount, 0.0, 1.0)
            : 0.0;
        $score = ($raw * 100) - ($retryPenaltyPerAttempt * max(0, $attemptNumber - 1));

        return round(self::clamp($score, 0.0, 100.0), 2);
    }

    /**
     * Phase 2 (resulting training performance) — the exact same
     * normalize-against-baseline/best formula the old phase-1 scorer used,
     * reused verbatim but fed the resulting trained-model accuracy instead.
     */
    public function scorePhase2(
        float $resultAccuracy,
        float $baselineAccuracy,
        float $bestAccuracy,
        int $retryPenaltyPerAttempt,
        int $attemptNumber
    ): float {
        $denominator = max($bestAccuracy - $baselineAccuracy, 0.0001);
        $raw = self::clamp(($resultAccuracy - $baselineAccuracy) / $denominator, 0.0, 1.0);
        $score = ($raw * 100) - ($retryPenaltyPerAttempt * max(0, $attemptNumber - 1));

        return round(self::clamp($score, 0.0, 100.0), 2);
    }

    public function totalScore(float $phase1Score, float $phase2Score): float {
        return round(($phase1Score + $phase2Score) / 2, 2);
    }

    private static function clamp(float $value, float $min, float $max): float {
        return max($min, min($max, $value));
    }
}

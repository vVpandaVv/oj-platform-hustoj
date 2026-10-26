<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab;

use EduGames\Games\Modules\AI_Model_Lab\Domain\Attempt;
use EduGames\Scoring\Score_Recorder;

/**
 * Orchestrates one AI Model Lab attempt: starting/resuming it, grading the
 * student's own hands-on labeling (phase 1) against the real ground-truth
 * labels, and looking up the real resulting trained-model performance for
 * however many samples the student got right (phase 2). The client never
 * supplies (and this class never trusts) a correctness verdict or a score —
 * only modelKey / labels / attemptId; every comparison against the answer
 * key happens here.
 *
 * $catalog is optional because the teacher-report use of this service
 * (attempts_for_users()) never needs to resolve an Activity_Definition and
 * so never needs the (relatively large) activity config files loaded.
 */
final class Session_Service {
    private Attempt_Repository $repository;
    private Phase_Scorer $scorer;
    private ?Activity_Catalog $catalog;

    public function __construct(
        ?Activity_Catalog $catalog = null,
        ?Attempt_Repository $repository = null,
        ?Phase_Scorer $scorer = null
    ) {
        $this->catalog = $catalog;
        $this->repository = $repository ?? new Attempt_Repository();
        $this->scorer = $scorer ?? new Phase_Scorer();
    }

    public function start_or_resume(int $userId, string $activityKey): Attempt {
        if (null === $this->catalog()->find($activityKey)) {
            throw new \InvalidArgumentException('알 수 없는 활동입니다.');
        }

        $existing = $this->repository->find_by_user_and_activity($userId, $activityKey);
        if (null !== $existing) {
            return $existing;
        }

        return $this->repository->insert($userId, $activityKey);
    }

    /**
     * @param array<int, string> $submittedLabels sampleId => labelKey
     * @return array{attempt: Attempt, correctCount: int, totalCount: int, perSampleCorrect: array<int, bool>, score: float}
     */
    public function submit_phase1(int $attemptId, int $userId, string $modelKey, array $submittedLabels): array {
        $attempt = $this->repository->find_by_id($attemptId);

        if (null === $attempt) {
            throw new \RuntimeException('시도를 찾을 수 없습니다.');
        }

        if ($attempt->userId() !== $userId) {
            throw new \RuntimeException('이 시도에 접근할 권한이 없습니다.');
        }

        $activity = $this->catalog()->find($attempt->activityKey());
        if (null === $activity) {
            throw new \RuntimeException('활동 정보를 찾을 수 없습니다.');
        }

        if (! $activity->hasModel($modelKey)) {
            throw new \InvalidArgumentException('알 수 없는 모델입니다.');
        }

        // Ignore any submitted id that isn't a real batch sample rather than
        // crashing — a stale/tampered client payload should never break
        // scoring for the rest of the batch.
        $known_ids = array_flip($activity->sampleIds());
        $clean_labels = array();
        foreach ($submittedLabels as $sample_id => $label_key) {
            $sample_id = (int) $sample_id;
            if (isset($known_ids[$sample_id])) {
                $clean_labels[$sample_id] = (string) $label_key;
            }
        }

        $correct_count = 0;
        $per_sample_correct = array();

        foreach ($activity->labelingBatch() as $sample) {
            $sample_id = $sample['id'];
            $submitted = $clean_labels[$sample_id] ?? null;
            // A sample the student never labeled counts as incorrect.
            $is_correct = null !== $submitted && $submitted === $sample['trueLabel'];

            $per_sample_correct[$sample_id] = $is_correct;
            if ($is_correct) {
                $correct_count++;
            }
        }

        $total_count = $activity->totalCount();
        $attempt_number = $attempt->phase1Attempts() + 1;

        $score = $this->scorer->scorePhase1(
            $correct_count,
            $total_count,
            $activity->retryPenaltyPerAttempt(),
            $attempt_number
        );

        $updated = $this->repository->record_phase1($attemptId, $modelKey, $clean_labels, $correct_count, $total_count, $score);

        return array(
            'attempt' => $updated,
            'correctCount' => $correct_count,
            'totalCount' => $total_count,
            'perSampleCorrect' => $per_sample_correct,
            'score' => $score,
        );
    }

    /**
     * @return array{attempt: Attempt, performance: array{accuracy: float, precision: float, recall: float, f1: float, confusionMatrix: array<int, array<int, int>>}, score: float}
     */
    public function submit_phase2(int $attemptId, int $userId): array {
        $attempt = $this->repository->find_by_id($attemptId);

        if (null === $attempt) {
            throw new \RuntimeException('시도를 찾을 수 없습니다.');
        }

        if ($attempt->userId() !== $userId) {
            throw new \RuntimeException('이 시도에 접근할 권한이 없습니다.');
        }

        if (null === $attempt->modelKey() || null === $attempt->correctCount()) {
            throw new \RuntimeException('1단계를 먼저 완료해야 합니다.');
        }

        $activity = $this->catalog()->find($attempt->activityKey());
        if (null === $activity) {
            throw new \RuntimeException('활동 정보를 찾을 수 없습니다.');
        }

        $performance = $activity->performanceFor($attempt->modelKey(), $attempt->correctCount());

        $attempt_number = $attempt->phase2Attempts() + 1;
        $phase2_score = $this->scorer->scorePhase2(
            $performance['accuracy'],
            $activity->baselineAccuracy(),
            $activity->bestAccuracy(),
            $activity->retryPenaltyPerAttempt(),
            $attempt_number
        );

        $phase1_score = (float) $attempt->phase1Score();
        $total_score = $this->scorer->totalScore($phase1_score, $phase2_score);

        $updated = $this->repository->record_phase2($attemptId, $performance['accuracy'], $phase2_score, $total_score);

        $this->record_summary_score($userId, $activity->key(), $performance['accuracy'], $total_score);

        return array(
            'attempt' => $updated,
            'performance' => $performance,
            'score' => $phase2_score,
        );
    }

    /**
     * Writes a summary row into the shared wp_edu_games_scores table
     * (bypassing the session-token/Games_Controller REST path, since this
     * is a server-computed 2-phase result, not a client-reported one) so
     * this game's completed attempts show up in the existing
     * leaderboard/stats blocks for free.
     */
    private function record_summary_score(int $userId, string $activityKey, float $resultAccuracy, float $totalScore): void {
        // Score_Recorder::record()'s own status/validity checks are enough
        // here — a failure (WP_Error) is not fatal to the play flow, since
        // the attempt row itself is already the authoritative record.
        Score_Recorder::record(
            AI_Model_Lab_Module::ID,
            $userId,
            array(
                'score' => (int) round($totalScore),
                'roundsPlayed' => 1,
                // No single "correct/incorrect" concept exists for this
                // game (both phases are continuous 0-100 scores) — treat a
                // total score of 60+ as a completed pass, matching the
                // typical "did reasonably well" bar used elsewhere in this
                // addon's stats. This is a judgment call, not a spec value.
                'correctCount' => $totalScore >= 60 ? 1 : 0,
                'durationSeconds' => 0,
                'accuracy' => round($resultAccuracy * 100, 2),
                'meta' => array(
                    'activityKey' => $activityKey,
                    'totalScore' => $totalScore,
                ),
            )
        );
    }

    /**
     * @param int[] $userIds
     * @return Attempt[]
     */
    public function attempts_for_users(string $activityKey, array $userIds): array {
        return $this->repository->attempts_for_users($activityKey, $userIds);
    }

    private function catalog(): Activity_Catalog {
        if (null === $this->catalog) {
            throw new \RuntimeException('활동 카탈로그가 설정되지 않았습니다.');
        }

        return $this->catalog;
    }
}

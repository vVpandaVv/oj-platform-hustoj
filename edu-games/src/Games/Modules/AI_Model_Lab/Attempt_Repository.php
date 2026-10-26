<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab;

use EduGames\Database\Installer;
use EduGames\Games\Modules\AI_Model_Lab\Domain\Attempt;

/**
 * Plain-PHP $wpdb wrapper for the ai_model_lab_attempts table — this addon
 * has no DI container, so this is instantiated directly (`new Attempt_Repository()`)
 * wherever it is needed, matching the rest of the codebase's style.
 */
final class Attempt_Repository {
    public function find_by_id(int $id): ?Attempt {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Installer::ai_model_lab_attempts_table() . ' WHERE id = %d',
                $id
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function find_by_user_and_activity(int $userId, string $activityKey): ?Attempt {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Installer::ai_model_lab_attempts_table() . ' WHERE user_id = %d AND activity_key = %s',
                $userId,
                $activityKey
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function insert(int $userId, string $activityKey): Attempt {
        global $wpdb;

        $wpdb->insert(
            Installer::ai_model_lab_attempts_table(),
            array(
                'user_id' => $userId,
                'activity_key' => $activityKey,
                'status' => Attempt::STATUS_PHASE1_PENDING,
                'phase1_attempts' => 0,
                'phase2_attempts' => 0,
                'started_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%d', '%s')
        );

        $attempt = $this->find_by_id((int) $wpdb->insert_id);

        if (null === $attempt) {
            throw new \RuntimeException('Failed to create AI Model Lab attempt.');
        }

        return $attempt;
    }

    /**
     * @param array<int, string> $submittedLabels sampleId => labelKey
     */
    public function record_phase1(int $id, string $modelKey, array $submittedLabels, int $correctCount, int $totalCount, float $score): Attempt {
        global $wpdb;

        $existing = $this->find_by_id($id);
        $attempts = null !== $existing ? $existing->phase1Attempts() + 1 : 1;

        $wpdb->update(
            Installer::ai_model_lab_attempts_table(),
            array(
                'status' => Attempt::STATUS_PHASE1_DONE,
                'model_key' => $modelKey,
                'submitted_labels_json' => wp_json_encode($submittedLabels),
                'correct_count' => $correctCount,
                'total_count' => $totalCount,
                'phase1_score' => $score,
                'phase1_attempts' => $attempts,
                'phase1_submitted_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%d', '%d', '%f', '%d', '%s'),
            array('%d')
        );

        $attempt = $this->find_by_id($id);

        if (null === $attempt) {
            throw new \RuntimeException('AI Model Lab attempt disappeared during phase1 update.');
        }

        return $attempt;
    }

    public function record_phase2(int $id, float $accuracy, float $score, float $totalScore): Attempt {
        global $wpdb;

        $existing = $this->find_by_id($id);
        $attempts = null !== $existing ? $existing->phase2Attempts() + 1 : 1;

        $wpdb->update(
            Installer::ai_model_lab_attempts_table(),
            array(
                'status' => Attempt::STATUS_PHASE2_DONE,
                'phase2_accuracy' => $accuracy,
                'phase2_score' => $score,
                'phase2_attempts' => $attempts,
                'total_score' => $totalScore,
                'phase2_submitted_at' => current_time('mysql'),
            ),
            array('id' => $id),
            array('%s', '%f', '%f', '%d', '%f', '%s'),
            array('%d')
        );

        $attempt = $this->find_by_id($id);

        if (null === $attempt) {
            throw new \RuntimeException('AI Model Lab attempt disappeared during phase2 update.');
        }

        return $attempt;
    }

    /**
     * Bulk lookup for the teacher class-report: every attempt row for a
     * given activity, restricted to the roster's user ids.
     *
     * @param int[] $userIds
     * @return Attempt[]
     */
    public function attempts_for_users(string $activityKey, array $userIds): array {
        global $wpdb;

        $user_ids = array_values(array_unique(array_map('intval', $userIds)));

        if (empty($user_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $params = array_merge(array($activityKey), $user_ids);

        $sql = $wpdb->prepare(
            'SELECT * FROM ' . Installer::ai_model_lab_attempts_table() . " WHERE activity_key = %s AND user_id IN ({$placeholders})",
            ...$params
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        $attempts = array();

        foreach ((array) $rows as $row) {
            $attempts[] = $this->hydrate($row);
        }

        return $attempts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Attempt {
        $submitted_labels = array();
        if (! empty($row['submitted_labels_json'])) {
            $decoded = json_decode((string) $row['submitted_labels_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $sample_id => $label_key) {
                    $submitted_labels[(int) $sample_id] = (string) $label_key;
                }
            }
        }

        return new Attempt(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['activity_key'],
            (string) $row['status'],
            null !== $row['model_key'] ? (string) $row['model_key'] : null,
            $submitted_labels,
            null !== $row['correct_count'] ? (int) $row['correct_count'] : null,
            null !== $row['total_count'] ? (int) $row['total_count'] : null,
            null !== $row['phase1_score'] ? (float) $row['phase1_score'] : null,
            (int) $row['phase1_attempts'],
            null !== $row['phase2_accuracy'] ? (float) $row['phase2_accuracy'] : null,
            null !== $row['phase2_score'] ? (float) $row['phase2_score'] : null,
            (int) $row['phase2_attempts'],
            null !== $row['total_score'] ? (float) $row['total_score'] : null,
            (string) $row['started_at'],
            null !== $row['phase1_submitted_at'] ? (string) $row['phase1_submitted_at'] : null,
            null !== $row['phase2_submitted_at'] ? (string) $row['phase2_submitted_at'] : null
        );
    }
}

<?php
declare(strict_types=1);

namespace EduGames\Scoring;

final class Quiz_Scorer {
    public const GAMES = array('outlier-detection', 'missing-values', 'data-visualization', 'data-extraction', 'data-structure-lab', 'ai-classifier-lab');

    /** Regrade submitted answers against server-owned samples, never client answer keys. */
    public static function grade(string $game, array $rules, array $data): array|\WP_Error {
        $meta = $data['meta'];
        $history = $meta['history'] ?? null;
        if (! is_array($history) || ! array_is_list($history) || count($history) !== $rules['rankedRounds']) {
            return Score_Validator::invalid();
        }

        $samples = array_column($rules['samples'], null, 'id');
        $scoring = $rules['scoring'];
        $seen = array();
        $score = $correct = $streak = 0;
        $early = in_array($game, array('outlier-detection', 'data-extraction'), true);

        foreach ($history as $index => &$entry) {
            if (! is_array($entry) || ! is_string($entry['sampleId'] ?? null)) {
                return Score_Validator::invalid();
            }
            $tier = $index < ($early ? 2 : 3) ? 'easy' : ($index < ($early ? 5 : 7) ? $meta['difficulty'] : 'hard');
            $allowed = $rules['difficulties'][$tier]['samples'];
            $fresh = array_diff($allowed, $seen);
            if (! in_array($entry['sampleId'], $fresh ?: $allowed, true)) {
                return Score_Validator::invalid();
            }
            $sample = $samples[$entry['sampleId']];
            $seen[] = $sample['id'];
            $ok = self::is_correct($game, $sample, $entry);
            $gained = 0;
            if ($ok) {
                $blank_bonus = 'missing-values' === $game ? max(0, count($sample['missingIndexes']) - 1) * $scoring['multiBlankBonus'] : 0;
                $gained = max($scoring['minimumCorrectScore'], $rules['difficulties'][$tier]['baseScore']
                    + min($scoring['maxStreakBonus'], $streak * $scoring['streakBonus']) + $blank_bonus);
                $score += $gained;
                $correct++;
                $streak++;
            } else {
                $score = max(0, $score - $scoring['wrongPenalty']);
                $streak = 0;
            }
            $entry['correct'] = $ok;
            $entry['gained'] = $gained;
            $entry['difficulty'] = $tier;
            // Supplied answer keys have no authority and do not belong in saved results.
            foreach (array('answer', 'answerRule', 'answerLabel', 'expectedMethod', 'expectedAnswer', 'expectedChart', 'expectedInsight', 'relevantColumns') as $key) {
                if ('missing-values' !== $game || 'answer' !== $key) {
                    unset($entry[$key]);
                }
            }
        }
        unset($entry);

        $data['score'] = $score;
        $data['roundsPlayed'] = count($history);
        $data['correctCount'] = $correct;
        $data['accuracy'] = round(100 * $correct / count($history), 2);
        $data['meta']['history'] = $history;
        $data['meta']['wrongCount'] = count($history) - $correct;
        return $data;
    }

    private static function is_correct(string $game, array $sample, array $entry): bool {
        return match ($game) {
            'outlier-detection' => self::same_set($entry['selected'] ?? null, $sample['outlierIndexes']),
            'data-structure-lab' => ($entry['selected'] ?? null) === $sample['answer'],
            'data-visualization' => ($entry['chart'] ?? null) === $sample['chartType'] && ($entry['insight'] ?? null) === $sample['insight'],
            'ai-classifier-lab' => ($entry['selectedRule'] ?? null) === $sample['answerRule'] && ($entry['selectedLabel'] ?? null) === $sample['answerLabel'],
            'missing-values' => self::missing_values_correct($sample, $entry),
            'data-extraction' => self::extraction_correct($sample, $entry),
        };
    }

    private static function same_set($actual, array $expected): bool {
        if (! is_array($actual) || ! array_is_list($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($actual as $value) {
            if (! is_int($value) && ! is_string($value)) {
                return false;
            }
        }
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private static function extraction_correct(array $sample, array $entry): bool {
        if (! self::same_set($entry['selectedColumns'] ?? null, $sample['relevantColumns'])) {
            return false;
        }
        foreach ($sample['processingOptions'] as $option) {
            if (! empty($option['correct'])) {
                return ($entry['selectedProcessing'] ?? null) === $option['key'];
            }
        }
        return false;
    }

    private static function missing_values_correct(array $sample, array $entry): bool {
        if (($entry['method'] ?? null) !== $sample['method'] || ! is_array($entry['answer'] ?? null)) {
            return false;
        }
        foreach ($sample['missingIndexes'] as $index) {
            $actual = $entry['answer'][$index] ?? null;
            $expected = $sample['answers'][$index];
            if (! is_string($actual) && ! is_int($actual) && ! is_float($actual)) {
                return false;
            }
            if ('numeric' === $sample['valueType']) {
                $actual = str_replace(',', '.', trim((string) $actual));
                $tolerance = floor((float) $expected) === (float) $expected ? 0.01 : 0.05;
                if (! is_numeric($actual) || ! is_finite((float) $actual) || abs((float) $actual - (float) $expected) > $tolerance) {
                    return false;
                }
            } elseif (strtolower(trim((string) $actual)) !== strtolower(trim((string) $expected))) {
                return false;
            }
        }
        return true;
    }
}

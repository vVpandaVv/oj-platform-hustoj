<?php
declare(strict_types=1);

namespace EduGames\Scoring;

final class Score_Validator {
    /**
     * Fastest pace each arcade game itself allows, with slack: a run may
     * not claim more correct answers than the game could have produced in
     * the time it reports. Seconds per correct answer, plus a fixed bonus
     * for what is on screen at the start / level-up spawns.
     *  - binary-conversion: one new number every 2.64 s at the top level
     *  - keyword-rain: one word every ~0.55 s at the hardest setting (650 ms x 0.84)
     *  - character-code: a typed code answer takes well over 0.8 s
     *  - device-matching: two card flips per pair
     */
    private const PACE = array(
        'binary-conversion' => array(2.6, 12),
        'keyword-rain' => array(0.5, 8),
        'character-code' => array(0.8, 2),
        'device-matching' => array(0.6, 2),
    );
    /** Characters per second nobody types code faster than (900 per minute). */
    private const MAX_TYPING_CPS = 15;
    public static function client_game(string $game): array|\WP_Error {
        $definition = \edu_games_registry()->all()[$game] ?? null;
        if (null === $definition) {
            return new \WP_Error('edu_games_unknown_game', __('등록되지 않은 게임입니다.', 'edu-games'), array('status' => 404));
        }
        if ('playable' !== $definition['status']) {
            return new \WP_Error('edu_games_game_not_playable', __('현재 기록할 수 없는 게임입니다.', 'edu-games'), array('status' => 403));
        }
        // The quiz games are played on the server-run play boards now; nothing
        // in the plugin posts their legacy /scores payloads any more, so the
        // endpoint stays closed for them instead of accepting hand-made runs.
        if (in_array($game, array('ai-model-lab', 'image-data'), true) || in_array($game, Quiz_Scorer::GAMES, true)) {
            return new \WP_Error('edu_games_score_endpoint_not_allowed', __('이 게임은 일반 점수 제출을 지원하지 않습니다.', 'edu-games'), array('status' => 403));
        }
        return $definition;
    }

    public static function validate(string $game, array $data, int $elapsed): array|\WP_Error {
        $definition = self::client_game($game);
        if (is_wp_error($definition)) {
            return $definition;
        }
        $score_limit = 'binary-conversion' === $game ? min(PHP_INT_MAX, 9007199254740991) : Score_Recorder::MAX_SCORE;
        foreach (array('score' => $score_limit, 'roundsPlayed' => 65535, 'correctCount' => 65535, 'durationSeconds' => 86400) as $key => $maximum) {
            if (! self::integer($data[$key] ?? null, 0, $maximum)) {
                return self::invalid();
            }
            $data[$key] = (int) $data[$key];
        }
        if (! is_int($data['accuracy'] ?? null) && ! is_float($data['accuracy'] ?? null)) {
            return self::invalid();
        }
        if (! is_finite((float) $data['accuracy']) || $data['accuracy'] < 0 || $data['accuracy'] > 100
            || $data['correctCount'] > $data['roundsPlayed'] || $data['roundsPlayed'] < 1
            || $data['durationSeconds'] < 1 || $data['durationSeconds'] > $elapsed + 2
            || ! is_array($data['meta'] ?? null)) {
            return self::invalid();
        }
        $encoded = wp_json_encode($data['meta']);
        if (false === $encoded || strlen($encoded) > 131072) {
            return self::invalid();
        }
        $rules = $definition['rules'];
        $meta = $data['meta'];
        if (isset($rules['difficulties']) && (! is_string($meta['difficulty'] ?? null) || ! isset($rules['difficulties'][$meta['difficulty']]))) {
            return self::invalid();
        }

        if (isset(self::PACE[$game])) {
            list($seconds, $bonus) = self::PACE[$game];
            if ($data['correctCount'] > floor($data['durationSeconds'] / $seconds) + $bonus) {
                return self::invalid();
            }
        }

        if (in_array($game, Quiz_Scorer::GAMES, true)) {
            $data = Quiz_Scorer::grade($game, $rules, $data);
        } elseif ('code-typing' === $game) {
            $data = self::typing($rules, $data);
        } else {
            $maximum = self::maximum_score($game, $rules, $data);
            if (null === $maximum || $data['score'] > $maximum) {
                return self::invalid();
            }
            $attempts = $data['roundsPlayed'];
            if ('character-code' === $game) {
                if (! self::integer($meta['mistakes'] ?? null, 0, 1) || $data['roundsPlayed'] !== $data['correctCount']) {
                    return self::invalid();
                }
                $attempts += (int) $meta['mistakes'];
            }
            $data['accuracy'] = round(100 * $data['correctCount'] / $attempts, 2);
        }
        if (is_wp_error($data)) {
            return $data;
        }
        if ($data['score'] <= 0) {
            return new \WP_Error('edu_games_invalid_score', __('기록할 점수가 없습니다.', 'edu-games'), array('status' => 400));
        }
        if ('binary-conversion' === $game) {
            // Validate the original score first, then cap only the ranked value.
            unset($data['meta']['originalScore'], $data['meta']['scoreCapped']);
            if ($data['score'] > Score_Recorder::MAX_SCORE) {
                $data['meta']['originalScore'] = $data['score'];
                $data['meta']['scoreCapped'] = true;
                $data['score'] = Score_Recorder::MAX_SCORE;
            }
        }
        unset($data['sessionToken']);
        return $data;
    }

    private static function maximum_score(string $game, array $rules, array $data): ?int {
        $count = $data['correctCount'];
        $meta = $data['meta'];
        $s = $rules['scoring'];
        $maximum = 0;
        if ('binary-conversion' === $game) {
            if (! self::integer($meta['maxBits'] ?? null, $rules['bits']['minimum'], $rules['bits']['maximum'])) {
                return null;
            }
            $level = 1;
            $left = $rules['levels'][0]['lines'];
            for ($i = 0; $i < $count; $i++) {
                $maximum += max($s['minimumProblemScore'], $meta['maxBits'] * $s['basePerBit'] + $level * $s['levelBonus'] + floor($i / $s['streakStep']) * $s['streakBonusPerStep']);
                if (--$left === 0) {
                    $level++;
                    $left = $rules['levels'][min($level - 1, count($rules['levels']) - 1)]['lines'];
                }
            }
            return (int) $maximum;
        }
        if ('character-code' === $game) {
            $difficulty = $rules['difficulties'][$meta['difficulty']];
            $base = max(array_column($rules['modes'], 'baseScore'));
            $length = max(array_map('strlen', $difficulty['phrases']));
            for ($i = 0; $i < $count; $i++) {
                $maximum += max($s['minimumRoundScore'], round(($base + $length * $s['lengthBonusPerCharacter']) * $difficulty['multiplier'])
                    + min($s['maxStreakBonus'], $i * $s['streakBonusPerAnswer']));
            }
            return (int) $maximum;
        }
        if ('keyword-rain' === $game) {
            if (! is_string($meta['language'] ?? null) || ! isset($rules['languages'][$meta['language']])) {
                return null;
            }
            $difficulty = $rules['difficulties'][$meta['difficulty']];
            $length = max(array_map('strlen', $rules['languages'][$meta['language']]['words']));
            for ($i = 0; $i < $count; $i++) {
                $maximum += $difficulty['score'] + min($s['maxComboBonus'], $i * $s['comboBonus'])
                    + floor($i / $rules['levelEvery']) * $s['levelBonus'] + max(0, $length - 5) * 2;
            }
            return (int) $maximum;
        }
        if ('device-matching' === $game) {
            if ($count > array_sum(array_column($rules['stages'], 'pairs'))) {
                return null;
            }
            $remaining = $count;
            foreach ($rules['stages'] as $stage) {
                $matches = min($remaining, $stage['pairs']);
                $maximum += $matches * ($s['matchBase'] + ($stage['stage'] - 1) * $s['stageBonusPerMatch'] + $s['maxStreakBonus']);
                if ($matches === $stage['pairs']) {
                    $maximum += $s['stageClearBaseBonus'] + $rules['maxStars'] * $s['starBonus'];
                }
                $remaining -= $matches;
            }
            if ($count === array_sum(array_column($rules['stages'], 'pairs'))) {
                $maximum += $s['completionBonus'];
            }
            return $maximum;
        }
        return null;
    }

    private static function typing(array $rules, array $data): array|\WP_Error {
        $meta = $data['meta'];
        if (! is_string($meta['mode'] ?? null) || ! is_string($meta['language'] ?? null)
            || empty($rules['modes'][$meta['mode']]['records']) || ! isset($rules['languages'][$meta['language']])) {
            return self::invalid();
        }
        $mode = $rules['modes'][$meta['mode']];
        $samples = array_filter($rules['samples'], static fn(array $sample): bool => $sample['language'] === $meta['language'] && $sample['mode'] === $meta['mode']);
        $ids = array_column($samples, 'id');
        if (! in_array($meta['sampleId'] ?? null, $ids, true) || $data['durationSeconds'] > $mode['timeLimitSeconds']
            || ! self::integer($meta['typedCharacters'] ?? null, 0, 1000000)
            || ! self::integer($meta['mistakes'] ?? null, 0, (int) $meta['typedCharacters'])
            || ! self::integer($meta['samplesCompleted'] ?? null, 0, 65534)
            || $meta['samplesCompleted'] !== $data['correctCount'] || $data['roundsPlayed'] !== $data['correctCount'] + 1) {
            return self::invalid();
        }
        $length = max(array_map(static fn(array $sample): int => strlen($sample['content']), $samples));
        if ($meta['typedCharacters'] > 2 * $length * $data['roundsPlayed']
            || $meta['typedCharacters'] > $data['durationSeconds'] * self::MAX_TYPING_CPS + 20) {
            return self::invalid();
        }
        $correct = $meta['typedCharacters'] - $meta['mistakes'];
        $accuracy = $meta['typedCharacters'] ? 100 * $correct / $meta['typedCharacters'] : 100;
        $cpm = $correct * 60 / max(1, $data['durationSeconds']);
        $s = $rules['scoring'];
        $data['score'] = (int) max($s['minimumScore'], round($mode['baseScore'] + $correct * $s['characterScore'] + $accuracy * $s['accuracyBonus']
            + $cpm * $s['speedBonus'] - $meta['mistakes'] * $s['mistakePenalty']));
        $data['accuracy'] = round($accuracy, 2);
        $data['meta']['cpm'] = round($cpm, 1);
        $data['meta']['wpm'] = round($cpm / 5, 1);
        $data['meta']['timeLimitSeconds'] = $mode['timeLimitSeconds'];
        return $data;
    }

    private static function integer($value, int $minimum, int $maximum): bool {
        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            && floor((float) $value) === (float) $value && $value >= $minimum && $value <= $maximum;
    }

    public static function invalid(): \WP_Error {
        return new \WP_Error('edu_games_invalid_result', __('제출한 기록이 게임 규칙과 일치하지 않습니다. 게임을 새로 불러온 뒤 다시 시도해 주세요.', 'edu-games'), array('status' => 422));
    }
}

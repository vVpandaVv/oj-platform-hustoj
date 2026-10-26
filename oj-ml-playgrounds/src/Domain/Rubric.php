<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Domain;

/** Manual criteria only: scores use integer hundredths, never model execution. */
final class Rubric
{
    public const MAX_CRITERIA = 25;
    public const MAX_LEVELS = 10;
    public const EVIDENCE_REQUIREMENTS = ['datasetDescription', 'datasetStatistics', 'experimentComparison'];
    public const DEFAULT_EVIDENCE_REQUIREMENTS = ['datasetDescription', 'datasetStatistics'];

    /** Legacy rubric documents remain opaque and unchanged. */
    public static function normalize(mixed $value): array
    {
        if (!is_array($value)) {
            throw new Problem('OJ_ML_RUBRIC_INVALID', '채점 기준은 올바른 목록이어야 합니다.', 400);
        }
        if (!self::structured($value)) {
            $encoded = json_encode($value);
            if (!is_string($encoded) || strlen($encoded) > 1048576) {
                throw new Problem('OJ_ML_RUBRIC_INVALID', '기존 채점 기준이 너무 크거나 올바르지 않습니다.', 400);
            }
            return $value;
        }
        $version = $value['version'] ?? null;
        if (!in_array($version, [1, 2], true) || !is_array($value['criteria'] ?? null)
            || !array_is_list($value['criteria']) || count($value['criteria']) > self::MAX_CRITERIA
            || ($version === 2 && $value['criteria'] === [])) {
            throw new Problem('OJ_ML_RUBRIC_INVALID', '루브릭 버전과 평가 항목을 확인하세요. 등급별 루브릭에는 1~25개 항목이 필요합니다.', 400);
        }
        $criteria = [];
        $seen = [];
        foreach ($value['criteria'] as $criterion) {
            if (!is_array($criterion) || !is_string($criterion['id'] ?? null)
                || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $criterion['id']) !== 1
                || isset($seen[$criterion['id']])) {
                throw new Problem('OJ_ML_RUBRIC_ID_INVALID', '평가 항목 식별자는 영문으로 시작하는 중복 없는 1~64자여야 합니다.', 400);
            }
            $seen[$criterion['id']] = true;
            if ($version === 1 && array_key_exists('levels', $criterion)) {
                throw new Problem('OJ_ML_RUBRIC_LEVELS_VERSION_REQUIRED', '등급별 기준은 루브릭 버전 2로 저장해야 합니다.', 400);
            }
            $maxCents = self::cents($criterion['maxScore'] ?? null);
            if ($maxCents < 1 || $maxCents > 1000000) {
                throw new Problem('OJ_ML_RUBRIC_MAX_SCORE_INVALID', '항목 배점은 0.01~10,000점이며 소수 둘째 자리까지 입력하세요.', 400);
            }
            $normalized = [
                'id' => $criterion['id'],
                'title' => self::text($criterion['title'] ?? null, 200, true),
                'description' => self::text($criterion['description'] ?? '', 2000),
                'maxScore' => $maxCents / 100,
            ];
            if ($version === 2) {
                $normalized['levels'] = self::levels($criterion['levels'] ?? null, $maxCents);
            }
            // Omission preserves existing manual-review snapshots. AI reviews
            // resolve omission conservatively; an explicit empty list is a
            // teacher-selected report-only criterion, not missing metadata.
            if (array_key_exists('evidenceRequirements', $criterion)) {
                $requirements = $criterion['evidenceRequirements'];
                if (!is_array($requirements) || !array_is_list($requirements)
                    || count($requirements) > count(self::EVIDENCE_REQUIREMENTS)) {
                    throw new Problem('OJ_ML_RUBRIC_EVIDENCE_INVALID', '평가에 필요한 근거 목록을 확인하세요.', 400);
                }
                $seenRequirements = [];
                foreach ($requirements as $requirement) {
                    if (!is_string($requirement) || !in_array($requirement, self::EVIDENCE_REQUIREMENTS, true)
                        || isset($seenRequirements[$requirement])) {
                        throw new Problem('OJ_ML_RUBRIC_EVIDENCE_INVALID', '평가 근거는 데이터 설명·통계 요약·비교 실험 중 중복 없이 선택하세요.', 400);
                    }
                    $seenRequirements[$requirement] = true;
                }
                $normalized['evidenceRequirements'] = $requirements;
            }
            $criteria[] = $normalized;
        }
        return ['version' => $version, 'criteria' => $criteria];
    }

    public static function structured(array $rubric): bool
    {
        // rc.2 allowed arbitrary legacy documents, including a "criteria" key.
        // Only an explicit version opts in to this new structured contract.
        return array_key_exists('version', $rubric);
    }

    /** Null means a legacy total-score review; otherwise use these authoritative values. */
    public static function review(array $rubric, array $input): ?array
    {
        if (!self::structured($rubric)) {
            if (array_key_exists('criteriaScores', $input) && $input['criteriaScores'] !== []) {
                throw new Problem('OJ_ML_RUBRIC_NOT_CONFIGURED', '이 과제에는 항목별 루브릭이 없습니다.', 400);
            }
            return null;
        }
        $rubric = self::normalize($rubric);
        if ($rubric['criteria'] === []) {
            if (array_key_exists('criteriaScores', $input) && $input['criteriaScores'] !== []) {
                throw new Problem('OJ_ML_RUBRIC_NOT_CONFIGURED', '이 과제에는 항목별 루브릭이 없습니다.', 400);
            }
            return null;
        }
        $entries = $input['criteriaScores'] ?? [];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::MAX_CRITERIA) {
            throw new Problem('OJ_ML_RUBRIC_SCORES_INVALID', '평가 항목별 점수 목록이 올바르지 않습니다.', 400);
        }
        if ($entries === [] && (($input['score'] ?? null) !== null || ($input['maxScore'] ?? null) !== null)) {
            throw new Problem('OJ_ML_RUBRIC_SCORES_REQUIRED', '총점 대신 각 루브릭 항목의 점수를 입력하세요.', 400);
        }
        $scores = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || isset($scores[$entry['id']])) {
                throw new Problem('OJ_ML_RUBRIC_SCORES_INVALID', '각 평가 항목을 중복 없이 입력하세요.', 400);
            }
            $scores[$entry['id']] = $entry;
        }
        if ($entries !== [] && (count($scores) !== count($rubric['criteria'])
            || array_diff(array_keys($scores), array_column($rubric['criteria'], 'id')) !== [])) {
            throw new Problem('OJ_ML_RUBRIC_SCORES_INVALID', '모든 평가 항목을 빠짐없이 입력하세요.', 400);
        }
        $normalized = [];
        $sum = 0;
        $maximum = 0;
        $scored = 0;
        foreach ($rubric['criteria'] as $criterion) {
            $entry = $scores[$criterion['id']] ?? ['score' => null, 'feedback' => ''];
            if (!array_key_exists('score', $entry)) {
                throw new Problem('OJ_ML_RUBRIC_SCORES_INVALID', '항목 점수 또는 빈 점수를 명시하세요.', 400);
            }
            $score = $entry['score'];
            $levelId = null;
            $maxCents = self::cents($criterion['maxScore']);
            if ($score !== null) {
                $cents = self::cents($score);
                if ($cents < 0 || $cents > $maxCents) {
                    throw new Problem('OJ_ML_RUBRIC_SCORE_RANGE', '항목 점수는 0점부터 해당 배점 사이여야 합니다.', 400);
                }
                if ($rubric['version'] === 2) {
                    $levelId = $entry['levelId'] ?? null;
                    $matching = array_values(array_filter($criterion['levels'], static fn (array $level): bool => $level['id'] === $levelId));
                    if (!is_string($levelId) || count($matching) !== 1) {
                        throw new Problem('OJ_ML_RUBRIC_LEVEL_REQUIRED', '각 평가 항목에서 정의된 등급을 선택하세요.', 400);
                    }
                    if ($cents !== self::cents($matching[0]['score'])) {
                        throw new Problem('OJ_ML_RUBRIC_LEVEL_SCORE_MISMATCH', '선택한 등급의 고정 점수와 입력 점수가 일치하지 않습니다.', 400);
                    }
                }
                $score = $cents / 100;
                $sum += $cents;
                $scored++;
            } elseif ($rubric['version'] === 2 && !in_array($entry['levelId'] ?? null, [null, ''], true)) {
                throw new Problem('OJ_ML_RUBRIC_LEVEL_SCORE_MISMATCH', '등급을 선택하면 해당 등급의 점수도 함께 저장해야 합니다.', 400);
            }
            $maximum += $maxCents;
            $normalizedEntry = [
                'id' => $criterion['id'], 'score' => $score,
                'feedback' => self::text($entry['feedback'] ?? '', 2000),
            ];
            if ($rubric['version'] === 2) { $normalizedEntry['levelId'] = $levelId; }
            $normalized[] = $normalizedEntry;
        }
        if ($scored > 0 && $scored !== count($rubric['criteria'])) {
            throw new Problem('OJ_ML_RUBRIC_SCORES_INCOMPLETE', '일부 점수가 비어 있습니다. 모두 채점하거나 점수를 모두 비워 피드백만 저장하세요.', 400);
        }
        return [
            'score' => $scored > 0 ? $sum / 100 : null,
            'max_score' => $scored > 0 ? $maximum / 100 : null,
            'details' => ['version' => $rubric['version'], 'rubricSnapshot' => $rubric, 'criteriaScores' => $normalized],
        ];
    }

    /** Review snapshots are parsed strictly and projected by allowlist, not echoed. */
    public static function details(mixed $details): ?array
    {
        if (!is_array($details) || !in_array($details['version'] ?? null, [1, 2], true)
            || !is_array($details['rubricSnapshot'] ?? null)
            || ($details['rubricSnapshot']['version'] ?? null) !== $details['version']) {
            return null;
        }
        try {
            $value = self::review($details['rubricSnapshot'], ['criteriaScores' => $details['criteriaScores'] ?? null]);
            return $value['details'] ?? null;
        } catch (Problem) {
            return null;
        }
    }

    /** @return list<array{id:string,label:string,description:string,score:int|float}> */
    private static function levels(mixed $value, int $maxCents): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 2 || count($value) > self::MAX_LEVELS) {
            throw new Problem('OJ_ML_RUBRIC_LEVELS_INVALID', '평가 항목마다 구체적인 기준과 점수가 있는 등급을 2~10개 입력하세요.', 400);
        }
        $levels = [];
        $ids = [];
        $scores = [];
        foreach ($value as $level) {
            if (!is_array($level) || !is_string($level['id'] ?? null)
                || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $level['id']) !== 1
                || isset($ids[$level['id']])) {
                throw new Problem('OJ_ML_RUBRIC_LEVEL_ID_INVALID', '한 평가 항목 안에서 등급 식별자는 중복 없는 영문 시작 1~64자여야 합니다.', 400);
            }
            $ids[$level['id']] = true;
            $cents = self::cents($level['score'] ?? null);
            if ($cents < 0 || $cents > 1000000 || isset($scores[$cents])) {
                throw new Problem('OJ_ML_RUBRIC_LEVEL_SCORE_INVALID', '등급 점수는 중복 없이 0~10,000점, 소수 둘째 자리까지 입력하세요.', 400);
            }
            $scores[$cents] = true;
            $levels[] = [
                'id' => $level['id'], 'label' => self::text($level['label'] ?? null, 200, true),
                'description' => self::text($level['description'] ?? null, 2000, true), 'score' => $cents / 100,
            ];
        }
        if (max(array_keys($scores)) !== $maxCents) {
            throw new Problem('OJ_ML_RUBRIC_LEVEL_MAX_MISMATCH', '항목 배점은 정의한 등급 점수 중 최댓값과 같아야 합니다.', 400);
        }
        return $levels;
    }

    private static function cents(mixed $value): int
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !is_numeric($value) || !is_finite((float) $value)
            || abs((float) $value) > 1000000) {
            throw new Problem('OJ_ML_RUBRIC_SCORE_INVALID', '점수는 소수 둘째 자리까지의 유한한 숫자로 입력하세요.', 400);
        }
        $scaled = (float) $value * 100;
        if (abs($scaled - round($scaled)) > 0.000001) {
            throw new Problem('OJ_ML_RUBRIC_SCORE_PRECISION', '점수는 소수 둘째 자리까지만 입력할 수 있습니다.', 400);
        }
        return (int) round($scaled);
    }

    private static function text(mixed $value, int $maximum, bool $required = false): string
    {
        if (!is_string($value) || strlen($value) > $maximum * 4 || preg_match('//u', $value) !== 1) {
            throw new Problem('OJ_ML_RUBRIC_TEXT_INVALID', '평가 항목의 글자 수와 형식을 확인하세요.', 400);
        }
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $length = preg_match_all('/./us', $value);
        if (($required && $value === '') || $length === false || $length > $maximum) {
            throw new Problem('OJ_ML_RUBRIC_TEXT_INVALID', '평가 제목은 1~200자, 설명과 항목 피드백은 각각 2,000자 이내로 입력하세요.', 400);
        }
        return $value;
    }
}

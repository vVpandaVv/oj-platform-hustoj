<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Domain\Rubric;

/** Deterministic minimum-evidence checks; never infer requirements from prose. */
final class EvidenceReadiness
{
    private const REASONS = [
        'datasetDescription' => '데이터 출처·변수 의미·예측 목표를 설명한 근거가 없어 교사 확인이 필요합니다.',
        'datasetStatistics' => '실습에 사용한 데이터의 전체 통계 요약이나 선택한 열의 연결 정보를 확인할 수 없어 교사 확인이 필요합니다.',
        'experimentComparison' => '동일 데이터·타깃·분할 조건에서 비교할 수 있는 성공한 실행 기록이 부족하여 교사 확인이 필요합니다.',
    ];

    /** $project is the server-owned, allowlisted AI project evidence, never request JSON. */
    public static function assess(array $rubric, array $project): array
    {
        $rubric = Rubric::normalize($rubric);
        $context = is_array($project['datasetContext'] ?? null) ? $project['datasetContext'] : [];
        $available = [
            'datasetDescription' => self::nonempty($context['teacherDescription'] ?? null)
                || self::nonempty($context['studentDescription'] ?? null),
            'datasetStatistics' => self::statisticsAvailable($project),
            'experimentComparison' => self::comparisonAvailable($project),
        ];
        $criteria = [];
        $needsReview = false;
        foreach ($rubric['criteria'] ?? [] as $criterion) {
            $requirements = $criterion['evidenceRequirements'] ?? Rubric::DEFAULT_EVIDENCE_REQUIREMENTS;
            $missing = array_values(array_filter($requirements, static fn (string $key): bool => !$available[$key]));
            $needsReview = $needsReview || $missing !== [];
            $criteria[] = [
                'id' => $criterion['id'], 'status' => $missing === [] ? 'ready' : 'needs_review',
                'requirements' => $requirements, 'missing' => $missing,
                'reasons' => array_map(static fn (string $key): string => self::REASONS[$key], $missing),
            ];
        }
        return ['schemaVersion' => 1, 'status' => $needsReview ? 'needs_review' : 'ready', 'criteria' => $criteria];
    }

    /** Override provider optimism, while leaving queued/failed/stale states intact. */
    public static function guardResult(array $result, array $readiness): array
    {
        if (!in_array($result['state'] ?? '', ['ready', 'needs_review'], true)) {
            return $result;
        }
        $checks = ($readiness['schemaVersion'] ?? null) === 1 && is_array($readiness['criteria'] ?? null)
            ? array_column($readiness['criteria'], null, 'id') : [];
        $criteria = [];
        $blocked = ($result['needs_review'] ?? false) === true;
        $missingRequired = false;
        foreach ($result['criteria'] ?? [] as $criterion) {
            if (!is_array($criterion)) { $blocked = true; $missingRequired = true; continue; }
            $check = $checks[$criterion['id'] ?? ''] ?? null;
            $reasons = is_array($check) && is_array($check['reasons'] ?? null) ? $check['reasons'] : [];
            $missing = !is_array($check) || ($check['status'] ?? '') !== 'ready'
                || ($check['missing'] ?? null) !== [];
            $hasScore = is_numeric($criterion['score'] ?? null) && is_finite((float) $criterion['score']);
            $criterion['reviewStatus'] = $missing || !$hasScore ? 'needs_review' : 'ready';
            $criterion['reviewReasons'] = $reasons;
            if (!$hasScore) {
                $blocked = true;
                $criterion['score'] = null;
                unset($criterion['levelId'], $criterion['level_id']);
                if ($reasons === []) { $criterion['reviewReasons'] = ['AI가 이 항목의 점수를 확정하지 못하여 교사 확인이 필요합니다.']; }
            }
            if ($missing) {
                $blocked = true;
                $missingRequired = true;
                if ($reasons === []) {
                    $reasons = ['이 항목의 평가 근거를 확인할 수 없어 교사 확인이 필요합니다.'];
                    $criterion['reviewReasons'] = $reasons;
                }
                $criterion['score'] = null;
                unset($criterion['levelId'], $criterion['level_id']);
                // A provider's confident explanation must not survive as an
                // apparently valid assessment when its prerequisites are absent.
                $criterion['feedback'] = '교사 확인 필요: ' . implode(' ', $reasons);
            }
            $criteria[] = $criterion;
        }
        if (count($criteria) !== count($checks)) { $blocked = true; $missingRequired = true; }
        $result['criteria'] = $criteria;
        if ($blocked) {
            $result['state'] = 'needs_review';
            $result['needs_review'] = true;
            $result['total_score'] = null;
            if ($missingRequired) {
                $result['error_code'] = 'OJ_ML_AI_EVIDENCE_REVIEW_REQUIRED';
                $result['summary'] = '평가 근거가 부족한 항목은 점수를 산정하지 않았습니다. 교사가 원본과 해당 근거를 확인한 뒤 직접 평가하세요.';
            }
        }
        return $result;
    }

    private static function statisticsAvailable(array $project): bool
    {
        $profiles = self::profiles($project);
        if ($profiles === null) { return false; }
        $experiments = is_array($project['experiments'] ?? null) ? $project['experiments'] : [];
        if ($experiments === []) {
            $contexts = [['role' => 'current', 'index' => null, 'settings' => $project['settings'] ?? []]];
        } else {
            $contexts = [];
            foreach ($experiments as $index => $experiment) {
                if (!is_array($experiment)) { return false; }
                $sourceIndex = $experiment['sourceExperimentIndex'] ?? $index;
                if (!is_int($sourceIndex) || $sourceIndex < 0) { return false; }
                $contexts[] = ['role' => 'experiment', 'index' => $sourceIndex, 'settings' => $experiment];
            }
        }
        foreach ($contexts as $context) {
            if (self::binding($profiles, $context['role'], $context['index'], 'training') === null) { return false; }
            if (($context['settings']['data']['testDatasetConfigured'] ?? false) === true
                && self::binding($profiles, $context['role'], $context['index'], 'test') === null) { return false; }
        }
        return true;
    }

    private static function comparisonAvailable(array $project): bool
    {
        $profiles = self::profiles($project);
        if ($profiles === null) { return false; }
        $groups = [];
        foreach ($project['experiments'] ?? [] as $index => $experiment) {
            if (!is_array($experiment)) { continue; }
            $sourceIndex = $experiment['sourceExperimentIndex'] ?? $index;
            if (!is_int($sourceIndex) || $sourceIndex < 0) { continue; }
            $training = self::binding($profiles, 'experiment', $sourceIndex, 'training');
            $split = is_array($experiment['split'] ?? null) ? $experiment['split'] : [];
            $sampleCount = $experiment['sampleCount'] ?? null;
            // Explicit historical settings only. Current settings, a timestamp,
            // and equal scores do not prove a comparable evaluation split.
            if ($training === null || !in_array($experiment['taskType'] ?? null, ['classification', 'regression'], true)
                || !self::nonempty($split['method'] ?? null)
                || !is_int($sampleCount) || $sampleCount < 1
                || !is_int($split['randomState'] ?? null) || !is_bool($split['stratify'] ?? null)
                || !self::finite($split['testSize'] ?? null)
                || $split['testSize'] <= 0 || $split['testSize'] >= 1) { continue; }
            $test = null;
            if (($experiment['data']['testDatasetConfigured'] ?? false) === true) {
                $test = self::binding($profiles, 'experiment', $sourceIndex, 'test');
                if ($test === null) { continue; }
            }
            $key = json_encode([
                $training['profileId'], $training['targetColumn'], $test['profileId'] ?? null,
                $test['targetColumn'] ?? null, $sampleCount, $experiment['taskType'] ?? null,
                $split['method'], (float) $split['testSize'], $split['randomState'], $split['stratify'],
            ]);
            foreach ($experiment['runs'] ?? [] as $run) {
                if (!is_array($run) || ($run['status'] ?? '') !== 'done') { continue; }
                $metrics = [];
                foreach ($run['metrics'] ?? [] as $name => $value) {
                    // The read-only projector exposes a list of named metrics.
                    // The associative form remains supported for old snapshots.
                    if (is_array($value)) {
                        $name = $value['name'] ?? null;
                        $value = $value['value'] ?? null;
                    }
                    if (is_string($name) && self::finite($value)) { $metrics[] = $name; }
                }
                if ($metrics === []) { continue; }
                foreach ($groups[$key] ?? [] as $previousMetrics) {
                    if (array_intersect($metrics, $previousMetrics) !== []) { return true; }
                }
                $groups[$key][] = $metrics;
            }
        }
        return false;
    }

    private static function profiles(array $project): ?array
    {
        $profiles = $project['datasetProfiles'] ?? null;
        if (!is_array($profiles) || ($profiles['schemaVersion'] ?? null) !== 1
            || ($profiles['source'] ?? '') !== 'server_archive_csv'
            || !is_array($profiles['datasets'] ?? null) || !array_is_list($profiles['datasets'])
            || !is_array($profiles['bindings'] ?? null) || !array_is_list($profiles['bindings'])) { return null; }
        return $profiles;
    }

    /** Resolve a complete server profile and selected columns; no raw data is inspected here. */
    private static function binding(array $profiles, string $role, ?int $index, string $usage): ?array
    {
        $matches = array_values(array_filter($profiles['bindings'], static fn (mixed $binding): bool => is_array($binding)
            && ($binding['role'] ?? '') === $role && ($binding['experimentIndex'] ?? null) === $index
            && ($binding['usage'] ?? '') === $usage));
        if (count($matches) !== 1) { return null; }
        $binding = $matches[0];
        if (($binding['status'] ?? '') !== 'complete' || !is_string($binding['profileId'] ?? null)
            || !is_array($binding['featureColumns'] ?? null) || !array_is_list($binding['featureColumns'])
            || $binding['featureColumns'] === [] || !is_int($binding['targetColumn'] ?? null)) { return null; }
        $matches = array_values(array_filter($profiles['datasets'], static fn (mixed $dataset): bool => is_array($dataset)
            && ($dataset['profileId'] ?? null) === $binding['profileId']));
        if (count($matches) !== 1) { return null; }
        $dataset = $matches[0];
        if (($dataset['status'] ?? '') !== 'complete' || !is_int($dataset['rowCount'] ?? null)
            || $dataset['rowCount'] < 1 || ($dataset['rowsAnalyzed'] ?? null) !== $dataset['rowCount']
            || !is_array($dataset['columns'] ?? null)) { return null; }
        $columns = array_column($dataset['columns'], null, 'index');
        foreach (array_merge($binding['featureColumns'], [$binding['targetColumn']]) as $columnIndex) {
            if (!is_int($columnIndex) || !isset($columns[$columnIndex])) { return null; }
            $column = $columns[$columnIndex];
            if (!is_int($column['missingCount'] ?? null) || !is_int($column['presentCount'] ?? null)
                || $column['missingCount'] < 0 || $column['presentCount'] < 0
                || $column['missingCount'] + $column['presentCount'] !== $dataset['rowCount']) { return null; }
        }
        return $binding;
    }

    private static function nonempty(mixed $value): bool
    {
        return is_string($value) && trim(strip_tags($value)) !== '';
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}

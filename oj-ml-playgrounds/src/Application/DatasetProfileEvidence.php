<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

/** Explicit external boundary. No paths, names, samples, values or arbitrary fields. */
final class DatasetProfileEvidence
{
    public static function project(mixed $profile): array
    {
        $empty = ['schemaVersion' => 1, 'source' => 'server_archive_csv', 'status' => 'unavailable',
            'reasons' => ['profile_unavailable'], 'datasets' => [], 'bindings' => []];
        if (!is_array($profile) || ($profile['schemaVersion'] ?? null) !== 1
            || ($profile['source'] ?? null) !== 'server_archive_csv'
            || !is_array($profile['datasets'] ?? null) || !array_is_list($profile['datasets'])
            || !is_array($profile['bindings'] ?? null) || !array_is_list($profile['bindings'])
            || count($profile['datasets'] ?? []) > 6 || count($profile['bindings'] ?? []) > 42) { return $empty; }
        // Never hash a wall-clock-dependent prefix into an asynchronous AI input.
        // A fresh complete scan may legitimately invalidate this unavailable result.
        foreach (array_merge([$profile], $profile['datasets'] ?? [], $profile['bindings'] ?? []) as $part) {
            if (!is_array($part) || !is_array($part['reasons'] ?? [])) { return $empty; }
            if (in_array('time_limit', $part['reasons'] ?? [], true)) {
                $empty['reasons'] = ['time_limit'];
                return $empty;
            }
        }
        $result = $empty;
        $result['status'] = self::status($profile['status'] ?? null);
        $result['reasons'] = self::codes($profile['reasons'] ?? []);
        $result['privacy'] = ['rawRowsIncluded' => false, 'categoryLabelsIncluded' => false, 'minimumGroupSize' => 5];
        $result['stage'] = 'before_preprocessing';
        $result['missingDefinition'] = 'empty_or_whitespace';
        foreach ($profile['datasets'] ?? [] as $dataset) {
            if (!is_array($dataset) || !self::id($dataset['profileId'] ?? null)
                || !is_array($dataset['columns'] ?? null) || !array_is_list($dataset['columns']) || count($dataset['columns']) > 128) { return $empty; }
            $item = ['profileId' => $dataset['profileId'], 'status' => self::status($dataset['status'] ?? null),
                'reasons' => self::codes($dataset['reasons'] ?? []), 'columns' => []];
            foreach (['rowsAnalyzed', 'rowCount', 'columnCount'] as $key) { $item[$key] = self::number($dataset[$key] ?? null); }
            foreach ($dataset['columns'] ?? [] as $column) {
                if (!is_array($column)) { return $empty; }
                $entry = ['type' => in_array($column['type'] ?? '', ['numeric', 'categorical', 'empty', 'mixed'], true) ? $column['type'] : 'mixed'];
                foreach (['index', 'presentCount', 'missingCount', 'missingRate', 'distinctCount', 'distinctAtLeast'] as $key) {
                    $entry[$key] = self::number($column[$key] ?? null);
                }
                $entry['numeric'] = null;
                if (is_array($column['numeric'] ?? null)) {
                    $entry['numeric'] = ['mean' => self::number($column['numeric']['mean'] ?? null), 'stdDev' => self::number($column['numeric']['stdDev'] ?? null)];
                }
                $entry['distribution'] = null;
                if (is_array($column['distribution'] ?? null)) {
                    $counts = $column['distribution']['counts'] ?? [];
                    if (!is_array($counts) || count($counts) > 128) { return $empty; }
                    $entry['distribution'] = ['counts' => array_values(array_filter($counts, static fn ($n): bool => is_int($n) && $n >= 5)),
                        'suppressed' => ($column['distribution']['suppressed'] ?? true) !== false];
                }
                $entry['suppressionReasons'] = self::codes($column['suppressionReasons'] ?? []);
                $item['columns'][] = $entry;
            }
            $result['datasets'][] = $item;
        }
        foreach ($profile['bindings'] ?? [] as $binding) {
            if (!is_array($binding) || !in_array($binding['role'] ?? '', ['current', 'experiment'], true)
                || !in_array($binding['usage'] ?? '', ['training', 'test'], true)) { return $empty; }
            $features = $binding['featureColumns'] ?? [];
            if (!is_array($features) || count($features) > 128) { return $empty; }
            $result['bindings'][] = [
                'role' => $binding['role'], 'usage' => $binding['usage'],
                'experimentIndex' => self::number($binding['experimentIndex'] ?? null),
                'profileId' => self::id($binding['profileId'] ?? null) ? $binding['profileId'] : null,
                'featureColumns' => array_values(array_filter($features, static fn ($n): bool => is_int($n) && $n >= 0 && $n < 128)),
                'targetColumn' => self::number($binding['targetColumn'] ?? null),
                'status' => self::status($binding['status'] ?? null), 'reasons' => self::codes($binding['reasons'] ?? []),
            ];
        }
        return strlen(json_encode($result) ?: '') <= 49152 ? $result : $empty;
    }

    private static function status(mixed $value): string { return in_array($value, ['complete', 'partial', 'unavailable'], true) ? $value : 'unavailable'; }
    private static function id(mixed $value): bool { return is_string($value) && preg_match('/^dataset-[1-6]$/D', $value) === 1; }
    private static function number(mixed $value): int|float|null { return (is_int($value) || is_float($value)) && is_finite((float) $value) ? $value : null; }
    private static function codes(mixed $value): array
    {
        if (!is_array($value)) { return []; }
        return array_values(array_filter(array_slice($value, 0, 20), static fn ($code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,80}$/D', $code) === 1));
    }
}

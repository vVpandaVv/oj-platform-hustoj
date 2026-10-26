<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Domain;

/** Optional completeness checks only; never judge answer correctness or run documents. */
final class PortfolioRequirements
{
    public static function normalize(mixed $value, ?array $template): array
    {
        if ($value === null || $value === []) { return []; }
        if (!is_array($value) || array_diff(array_keys($value), ['requiredSectionIds', 'minAnswerChars']) !== []
            || !is_array($value['requiredSectionIds'] ?? null) || !array_is_list($value['requiredSectionIds'])
            || count($value['requiredSectionIds']) > 30) {
            throw self::invalid();
        }
        $minimum = $value['minAnswerChars'] ?? 1;
        if (!is_int($minimum) || $minimum < 1 || $minimum > 1000) { throw self::invalid(); }
        $known = [];
        if ($template !== null) {
            preg_match_all('/^##(?!#)[^\n]*\{#([a-zA-Z0-9_-]+)\}[ \t]*$/m', $template['markdown'], $matches);
            $known = $matches[1];
        }
        $ids = [];
        foreach ($value['requiredSectionIds'] as $id) {
            if (!is_string($id) || !in_array($id, $known, true) || in_array($id, $ids, true)) { throw self::invalid(); }
            $ids[] = $id;
        }
        return $ids === [] ? [] : ['requiredSectionIds' => $ids, 'minAnswerChars' => $minimum];
    }

    /** @return list<string> Missing, duplicated, orphaned and blank answers all fail closed. */
    public static function missing(array $portfolio, array $requirements): array
    {
        $required = $requirements['requiredSectionIds'] ?? [];
        if ($required === []) { return []; }
        $sections = is_array($portfolio['template']['sections'] ?? null) ? $portfolio['template']['sections'] : [];
        $counts = [];
        foreach ($sections as $section) {
            if (is_array($section) && is_string($section['id'] ?? null)) {
                $id = $section['id']; $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        $answers = is_array($portfolio['answers'] ?? null) ? $portfolio['answers'] : [];
        $missing = [];
        foreach ($required as $id) {
            $answer = $answers[$id] ?? null;
            // Exclude whitespace/control/format-only padding, including zero-width characters.
            $visible = is_string($answer) ? preg_replace('/[\p{Z}\p{C}\s]/u', '', $answer) : null;
            if (($counts[$id] ?? 0) !== 1 || !is_string($visible)
                || preg_match_all('/./us', $visible) < (int) ($requirements['minAnswerChars'] ?? 1)) { $missing[] = $id; }
        }
        return $missing;
    }

    private static function invalid(): Problem
    {
        return new Problem('OJ_ML_PORTFOLIO_REQUIREMENTS_INVALID', '필수 문항은 이 양식의 고유 ID가 있는 문항에서 선택하고, 최소 답변 길이는 1~1000자로 입력하세요.', 400);
    }
}

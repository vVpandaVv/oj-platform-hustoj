<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Domain\Rubric;

/** Last trust boundary: the browser cannot supply scores or publication state. */
final class AiReviewDraft
{
    public static function build(array $attempt, array $result, string $runId, string $inputHash, ?array $readiness = null): array
    {
        $artifactHash = strtolower((string) ($attempt['artifact']['sha256'] ?? ''));
        if (($result['state'] ?? '') !== 'ready'
            || !preg_match('/^[a-f0-9]{64}$/D', $inputHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $artifactHash)
            || $runId === '' || strlen($runId) > 200
            || !hash_equals($inputHash, (string) ($result['input_hash'] ?? ''))
            || !hash_equals($runId, (string) ($result['run_id'] ?? ''))
            || !hash_equals($artifactHash, (string) ($result['source_sha256'] ?? ''))) {
            throw new Problem('OJ_ML_AI_RESULT_STALE', '이 AI 결과는 현재 제출물에 적용할 수 없습니다. 최신 상태를 확인하세요.', 409);
        }
        if ($readiness !== null) {
            $result = EvidenceReadiness::guardResult($result, $readiness);
        }
        if (($result['state'] ?? '') !== 'ready' || ($result['needs_review'] ?? false) === true) {
            throw new Problem('OJ_ML_AI_REVIEW_REQUIRED', '근거가 부족한 항목은 교사가 직접 확인해야 합니다.', 409);
        }
        $criteria = $result['criteria'] ?? null;
        if (!is_array($criteria) || !array_is_list($criteria) || $criteria === []) {
            throw new Problem('OJ_ML_AI_RESULT_INVALID', 'AI 평가 항목이 올바르지 않습니다. 직접 검토하세요.', 409);
        }
        $rubric = Rubric::normalize($attempt['rubric'] ?? []);
        $scores = [];
        foreach ($criteria as $criterion) {
            if (!is_array($criterion) || !is_numeric($criterion['score'] ?? null)) {
                throw new Problem('OJ_ML_AI_REVIEW_REQUIRED', '근거가 부족한 항목은 교사가 직접 확인해야 합니다.', 409);
            }
            $entry = [
                'id' => $criterion['id'] ?? null,
                'score' => $criterion['score'],
                'feedback' => $criterion['feedback'] ?? '',
            ];
            if (($rubric['version'] ?? null) === 2) {
                $definition = array_values(array_filter($rubric['criteria'], static fn (array $item): bool => $item['id'] === $entry['id']));
                $levels = count($definition) === 1 ? $definition[0]['levels'] : [];
                $matching = array_values(array_filter($levels, static fn (array $level): bool => (float) $level['score'] === (float) $entry['score']));
                if (count($matching) !== 1) {
                    throw new Problem('OJ_ML_AI_LEVEL_SCORE_INVALID', 'AI 점수가 정의된 등급 점수와 일치하지 않습니다. 교사가 등급별 기준으로 직접 평가하세요.', 409);
                }
                $entry['levelId'] = $matching[0]['id'];
                foreach (['levelId', 'level_id'] as $key) {
                    if (array_key_exists($key, $criterion) && $criterion[$key] !== $entry['levelId']) {
                        throw new Problem('OJ_ML_AI_LEVEL_SCORE_INVALID', 'AI가 선택한 등급과 점수가 일치하지 않습니다. 교사가 직접 확인하세요.', 409);
                    }
                }
            }
            $scores[] = $entry;
        }
        $validated = Rubric::review($rubric, ['criteriaScores' => $scores]);
        if ($validated === null || $validated['score'] === null
            || !is_numeric($result['total_score'] ?? null) || !is_numeric($result['max_score'] ?? null)
            || abs($validated['score'] - (float) $result['total_score']) > 0.000001
            || abs($validated['max_score'] - (float) $result['max_score']) > 0.000001) {
            throw new Problem('OJ_ML_AI_RESULT_INVALID', 'AI 결과의 항목 점수와 총점이 일치하지 않습니다.', 409);
        }
        $feedback = $result['summary'] ?? '';
        if (!is_string($feedback) || strlen($feedback) > 20000 || preg_match('//u', $feedback) !== 1) {
            throw new Problem('OJ_ML_AI_RESULT_INVALID', 'AI 피드백 형식이 올바르지 않습니다.', 409);
        }
        $limitations = $result['limitations'] ?? [];
        if (!is_array($limitations) || !array_is_list($limitations) || count($limitations) > 40) {
            throw new Problem('OJ_ML_AI_RESULT_INVALID', 'AI 평가 한계 정보가 올바르지 않습니다.', 409);
        }
        foreach ($limitations as $limitation) {
            if (!is_string($limitation) || strlen($limitation) > 4000 || preg_match('//u', $limitation) !== 1) {
                throw new Problem('OJ_ML_AI_RESULT_INVALID', 'AI 평가 한계 정보가 올바르지 않습니다.', 409);
            }
        }
        if ($limitations !== []) {
            $feedback .= "\n\n검토 한계\n- " . implode("\n- ", $limitations);
        }
        if (strlen($feedback) > 50000) {
            throw new Problem('OJ_ML_AI_RESULT_INVALID', 'AI 피드백이 너무 깁니다. 직접 검토하세요.', 409);
        }
        $validated['details']['aiSource'] = [
            'provider' => 'oj-ai-dashboard', 'runId' => $runId,
            'inputHash' => $inputHash, 'sourceSha256' => (string) $result['source_sha256'],
        ];
        return [
            'status' => 'draft', 'score' => $validated['score'], 'max_score' => $validated['max_score'],
            'feedback' => trim(strip_tags($feedback)), 'details' => $validated['details'],
        ];
    }
}

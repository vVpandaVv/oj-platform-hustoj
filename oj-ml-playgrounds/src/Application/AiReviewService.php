<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\Problem;
use Throwable;

/** Optional AI integration; manual ML review remains independent. */
final class AiReviewService
{
    public function __construct(private readonly AiEvidenceSource $source, private readonly Repository $repository)
    {
    }

    public function request(string $uuid, int $actorId, array $input, string $correlationId): array
    {
        $attempt = $this->source->authorize($uuid, $actorId);
        $action = $input['action'] ?? 'status';
        if (!in_array($action, ['status', 'enqueue', 'apply'], true)) {
            throw new Problem('OJ_ML_AI_ACTION_INVALID', 'AI 평가 작업이 올바르지 않습니다.', 400);
        }
        $gateway = apply_filters('oj_ai_external_assessment_gateway_v1', null);
        if (!is_object($gateway) || !is_callable([$gateway, 'status'])
            || !is_callable([$gateway, 'enqueue']) || !is_callable([$gateway, 'get'])
            || !is_callable([$gateway, 'version']) || $gateway->version() !== '1.0') {
            if ($action === 'apply') {
                throw new Problem('OJ_ML_AI_UNAVAILABLE', 'AI 평가 플러그인에 연결할 수 없습니다.', 503);
            }
            return ['available' => false, 'code' => 'OJ_ML_AI_UNAVAILABLE', 'consentFingerprint' => '', 'result' => null,
                'reviewVersion' => (int) ($attempt['review']['version'] ?? 0)];
        }
        // Validate the original even when the remote gateway has a cached result.
        $snapshot = $this->source->snapshot($uuid, $actorId);
        $readiness = $snapshot['evidence']['project']['evidenceReadiness'];
        try {
            $reply = $action === 'enqueue'
                ? $gateway->enqueue(AiEvidenceSource::KEY, $uuid, $actorId, is_array($input['consent'] ?? null) ? $input['consent'] : [])
                : ($action === 'apply' ? $gateway->get(AiEvidenceSource::KEY, $uuid, $actorId) : $gateway->status(AiEvidenceSource::KEY, $uuid, $actorId));
        } catch (Throwable) {
            throw new Problem('OJ_ML_AI_UNAVAILABLE', 'AI 평가 연결을 확인하지 못했습니다. 수동 평가는 계속할 수 있습니다.', 503);
        }
        if (!is_array($reply)) {
            throw new Problem('OJ_ML_AI_UNAVAILABLE', 'AI 평가 응답이 올바르지 않습니다.', 503);
        }
        if ($action === 'apply') {
            if (($reply['available'] ?? false) !== true || !is_array($reply['result'] ?? null)) {
                throw new Problem('OJ_ML_AI_RESULT_NOT_READY', '적용할 수 있는 AI 결과가 없습니다.', 409);
            }
            $expected = $input['expectedVersion'] ?? null;
            if (!is_int($expected) || $expected < 0) {
                throw new Problem('OJ_ML_REVIEW_VERSION_INVALID', '최신 검토 버전을 확인하세요.', 400);
            }
            $draft = AiReviewDraft::build($attempt, $reply['result'],
                is_string($input['evaluationId'] ?? null) ? $input['evaluationId'] : '',
                is_string($input['inputHash'] ?? null) ? $input['inputHash'] : '', $readiness);
            // Repeated response/retry must not produce another draft or undo a release.
            if (($attempt['review']['details']['aiSource'] ?? null) === $draft['details']['aiSource']) {
                if (($attempt['review']['status'] ?? '') !== 'draft') {
                    throw new Problem('OJ_ML_AI_ALREADY_APPLIED', '이미 반영·공개한 AI 결과입니다. 필요하면 교사 평가를 직접 수정하세요.', 409);
                }
                $current = (int) ($attempt['review']['version'] ?? 0);
                if ($expected !== $current && $expected + 1 !== $current) {
                    throw new Problem('OJ_ML_REVIEW_VERSION_CONFLICT', '다른 검토가 저장되었습니다. 최신 평가를 확인하세요.', 409);
                }
                return ['review' => $attempt['review']];
            }
            return ['review' => $this->repository->appendReview((int) $attempt['id'], $actorId, $expected, $draft, 'review.ai_applied', $correlationId)];
        }
        return self::present($reply, $attempt, $readiness);
    }

    private static function present(array $reply, array $attempt, array $readiness): array
    {
        $raw = is_array($reply['result'] ?? null) ? $reply['result'] : null;
        $result = null;
        if ($raw !== null) {
            $raw = EvidenceReadiness::guardResult($raw, $readiness);
            $levelSelections = [];
            $rubricCriteria = array_column($attempt['rubric']['criteria'] ?? [], null, 'id');
            if (($attempt['rubric']['version'] ?? null) === 2 && ($raw['state'] ?? '') === 'ready') {
                // The optional AI gateway may predate level-based rubrics. Recheck
                // its normalized scores before presenting them as applicable.
                try {
                    $draft = AiReviewDraft::build($attempt, $raw, (string) ($raw['run_id'] ?? ''), (string) ($raw['input_hash'] ?? ''), $readiness);
                    $levelSelections = array_column($draft['details']['criteriaScores'], 'levelId', 'id');
                } catch (Problem $problem) {
                    $code = $problem->problemCode();
                    $raw['state'] = $code === 'OJ_ML_AI_RESULT_STALE' ? 'stale' : 'needs_review';
                    $raw['error_code'] = str_contains($code, 'LEVEL') || str_contains($code, 'SCORE')
                        ? 'OJ_ML_AI_RUBRIC_LEVEL_MISMATCH' : $code;
                    $raw['total_score'] = null;
                }
            }
            $titles = array_column($attempt['rubric']['criteria'] ?? [], 'title', 'id');
            $criteria = [];
            foreach (($raw['criteria'] ?? []) as $criterion) {
                $id = (string) ($criterion['id'] ?? '');
                $entry = [
                    'id' => $id, 'title' => $titles[$id] ?? $id, 'score' => $criterion['score'] ?? null,
                    'feedback' => (string) ($criterion['feedback'] ?? ''), 'evidence' => $criterion['evidence'] ?? [],
                    'reviewStatus' => $criterion['reviewStatus'] ?? (is_numeric($criterion['score'] ?? null) ? 'ready' : 'needs_review'),
                    'reviewReasons' => $criterion['reviewReasons'] ?? [],
                ];
                if (isset($levelSelections[$id])) {
                    foreach ($rubricCriteria[$id]['levels'] ?? [] as $level) {
                        if ($level['id'] === $levelSelections[$id]) {
                            $entry += ['levelId' => $level['id'], 'levelLabel' => $level['label'], 'levelDescription' => $level['description']];
                            break;
                        }
                    }
                }
                $criteria[] = $entry;
            }
            $result = [
                'id' => (string) ($raw['run_id'] ?? ''), 'state' => (string) ($raw['state'] ?? 'failed'),
                'inputHash' => (string) ($raw['input_hash'] ?? ''), 'sourceSha256' => (string) ($raw['source_sha256'] ?? ''),
                'criteriaScores' => $criteria, 'score' => $raw['total_score'] ?? null, 'maxScore' => $raw['max_score'] ?? null,
                'feedback' => (string) ($raw['summary'] ?? ''), 'limitations' => $raw['limitations'] ?? [],
                'createdAt' => (string) ($raw['created_at'] ?? ''), 'errorCode' => (string) ($raw['error_code'] ?? ''),
            ];
        }
        return [
            'available' => ($reply['available'] ?? false) === true,
            'code' => (string) ($reply['code'] ?? ''), 'consentFingerprint' => (string) ($reply['consent_fingerprint'] ?? ''),
            'result' => $result, 'reviewVersion' => (int) ($attempt['review']['version'] ?? 0),
            'evidenceReadiness' => $readiness,
        ];
    }
}

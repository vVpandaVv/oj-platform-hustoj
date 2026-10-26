<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Ops\Options;
use OJML\Playgrounds\Security\Addon_Capabilities;

/** ML owns original artifacts and authorizes every asynchronous read again. */
final class AiEvidenceSource
{
    public const KEY = 'oj-ml-playgrounds';

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly ArtifactPreviewService $preview
    ) {
    }

    public function register(): void
    {
        add_filter('oj_ai_external_assessment_sources_v1', function (array $sources): array {
            $sources[self::KEY] = $this;
            return $sources;
        });
    }

    public function version(): string
    {
        return '1.0';
    }

    public function authorize(string $sourceId, int $actorId): array
    {
        $allowed = false;
        foreach ([Addon_Capabilities::REVIEW, Addon_Capabilities::MANAGE] as $declared) {
            $capability = Addon_Capabilities::wordpress($declared);
            if ($actorId > 0 && $capability !== '' && user_can($actorId, $capability)) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            throw new Problem('OJ_ML_CAPABILITY_REQUIRED', '이 제출물을 평가할 권한이 없습니다.', 403);
        }
        if (Options::all()['maintenanceMode']) {
            throw new Problem('OJ_ML_MAINTENANCE', 'ML 실습 점검 중입니다. 잠시 후 다시 시도하세요.', 503);
        }
        $attempt = $this->assignments->teacherResult($sourceId, $actorId, 'evaluate');
        if (!ArtifactPreviewService::canPreviewStatus((string) ($attempt['status'] ?? ''))) {
            throw new Problem('OJ_ML_AI_SUBMISSION_NOT_READY', '서버 검사를 통과한 제출물만 AI 평가를 요청할 수 있습니다.', 409);
        }
        return $attempt;
    }

    public function snapshot(string $sourceId, int $actorId): array
    {
        $attempt = $this->authorize($sourceId, $actorId);
        $assignment = $this->assignments->teacherAssignment((int) $attempt['assignment_id'], $actorId, 'evaluate');
        // Opens a verified private snapshot; never extracts ZIP entries or executes project code.
        return AiEvidence::build($attempt, $assignment, $this->preview->preview($sourceId, $actorId));
    }
}

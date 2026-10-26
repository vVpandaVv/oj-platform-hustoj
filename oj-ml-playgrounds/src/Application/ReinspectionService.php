<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\AttemptStatus;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Inspection\InspectionResult;
use OJML\Playgrounds\Inspection\MlpxInspector;
use OJML\Playgrounds\Storage\PrivateArtifactStore;

final class ReinspectionService
{
    public function __construct(
        private readonly Repository $repository,
        private readonly AssignmentService $assignments,
        private readonly PrivateArtifactStore $storage,
        private readonly MlpxInspector $inspector
    ) {
    }

    /** @return array{attempt:array<string,mixed>,httpStatus:int} */
    public function reinspect(string $attemptUuid, int $actorId, string $correlationId): array
    {
        $attempt = $this->assignments->teacherResult($attemptUuid, $actorId, 'evaluate');
        if (!in_array(
            (string) $attempt['status'],
            [AttemptStatus::SYSTEM_PENDING, AttemptStatus::QUARANTINED],
            true
        )) {
            throw new Problem(
                'OJ_ML_ATTEMPT_NOT_PENDING',
                '시스템 보류 또는 격리 상태의 제출만 다시 검사할 수 있습니다.',
                409
            );
        }
        $result = (new \OJML\Playgrounds\Ops\InspectionWorker($this->repository, $this->storage, $this->inspector))
            ->process($attemptUuid, $actorId, $correlationId);
        $result['context_may_evaluate'] = true;
        return [
            'attempt' => $result,
            'httpStatus' => in_array($result['status'], [AttemptStatus::SYSTEM_PENDING, AttemptStatus::QUARANTINED], true) ? 202 : 200,
        ];
    }
}



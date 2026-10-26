<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Inspection\InspectionResult;
use OJML\Playgrounds\Inspection\MlpxInspector;
use OJML\Playgrounds\Storage\ArtifactIntegrityVerifier;
use OJML\Playgrounds\Storage\PrivateArtifactStore;

/** Only processes already received artifacts; never starts training or executes MLPX. */
final class InspectionWorker
{
    public function __construct(private readonly Repository $repository, private readonly PrivateArtifactStore $storage, private readonly MlpxInspector $inspector) {}

    public function process(string $uuid, int $actorId, string $correlationId): array
    {
        if (Options::all()['maintenanceMode']) { throw new Problem('OJ_ML_MAINTENANCE', 'ML 점검 중에는 검사를 실행하지 않습니다.', 503); }
        $capacity = Lease::acquire('inspection', (int) Options::all()['inspectionSlots']);
        $lease = null;
        $source = null;
        $snapshot = null;
        try {
            $lease = Lease::acquire('attempt:' . $uuid);
            $attempt = $this->repository->getAttemptResult($uuid);
            if ($attempt === null) { throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '제출 기록을 찾을 수 없습니다.', 404); }
            if (!in_array($attempt['status'], ['quarantined', 'system_pending'], true)) { return $attempt; }
            $assignment = $this->repository->getAssignment((int) $attempt['assignment_id']);
            if ($assignment === null) { throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404); }
            $this->repository->recordInspectionRetry((int) $attempt['id']);
            $artifact = $this->repository->getArtifactForInspection((int) $attempt['id']);
            if ($artifact === null) { throw new Problem('OJ_ML_ARTIFACT_NOT_FOUND', '보관된 파일을 찾을 수 없습니다.', 404); }
            $source = $this->storage->open($artifact['storage_key']);
            $snapshot = tmpfile();
            if ($snapshot === false) { throw new Problem('OJ_ML_INSPECTION_TEMP_FAILED', '검사 임시 공간을 사용할 수 없습니다.', 503); }
            (new ArtifactIntegrityVerifier())->verify($source, (int) $artifact['bytes'], $artifact['sha256'], $snapshot);
            $path = stream_get_meta_data($snapshot)['uri'];
            $inspection = $this->inspector->inspect($path, $assignment['policy']);
            $status = match ($inspection->status()) {
                InspectionResult::ACCEPTED => 'accepted',
                InspectionResult::REJECTED => 'needs_resubmit',
                default => 'system_pending',
            };
            $this->repository->completeInspection((int) $attempt['id'], $inspection->status(), $status,
                $inspection->findings(), $inspection->facts(), $inspection->firstFailureCode(),
                'mlpx-preflight', MlpxInspector::VERSION, null, [
                    'actorUserId' => $actorId, 'type' => 'attempt.inspected', 'payload' => ['status' => $status],
                    'correlationId' => $correlationId,
                ]);
            return $this->repository->getAttemptResult($uuid)
                ?? throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '제출 기록을 찾을 수 없습니다.', 404);
        } finally {
            if (is_resource($source)) { fclose($source); }
            if (is_resource($snapshot)) { fclose($snapshot); }
            $lease?->release();
            $capacity->release();
        }
    }

    public function recover(int $limit = 10): array
    {
        $counts = ['processed' => 0, 'failed' => 0, 'busy' => 0];
        if (Options::all()['maintenanceMode']) { return $counts + ['maintenance' => true]; }
        foreach ($this->repository->pendingAttempts($limit) as $uuid) {
            try { $this->process($uuid, 0, wp_generate_uuid4()); $counts['processed']++; }
            catch (Problem $problem) {
                if ($problem->httpStatus() === 429) { $counts['busy']++; break; }
                $counts['failed']++;
                Audit::log($problem, wp_generate_uuid4());
            }
        }
        return $counts;
    }
}

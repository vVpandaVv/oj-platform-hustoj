<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\AttemptStatus;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Inspection\MlpxInspector;
use OJML\Playgrounds\Storage\ArtifactIntegrityVerifier;
use OJML\Playgrounds\Storage\PrivateArtifactStore;

final class ArtifactPreviewService
{
    public function __construct(
        private readonly Repository $repository,
        private readonly AssignmentService $assignments,
        private readonly PrivateArtifactStore $storage,
        private readonly ArtifactIntegrityVerifier $integrity,
        private readonly MlpxInspector $inspector
    ) {
    }

    public static function canPreviewStatus(string $status): bool
    {
        return in_array($status, [
            AttemptStatus::ACCEPTED,
            AttemptStatus::UNDER_REVIEW,
            AttemptStatus::GRADED,
        ], true);
    }

    /** @return array<string,mixed> */
    public function preview(string $attemptUuid, int $actorId): array
    {
        $attempt = $this->assignments->teacherResult($attemptUuid, $actorId, 'evaluate');
        if (!self::canPreviewStatus((string) ($attempt['status'] ?? ''))) {
            throw new Problem(
                'OJ_ML_ARTIFACT_PREVIEW_NOT_READY',
                '서버 검사를 통과한 제출물만 안전하게 바로 볼 수 있습니다.',
                409
            );
        }
        $artifact = $this->repository->getArtifactForInspection((int) ($attempt['id'] ?? 0));
        if (!is_array($artifact)) {
            throw new Problem('OJ_ML_ARTIFACT_NOT_FOUND', '제출 파일을 찾을 수 없습니다.', 404);
        }

        $storageKey = is_string($artifact['storage_key'] ?? null) ? $artifact['storage_key'] : '';
        $expectedHash = is_string($artifact['sha256'] ?? null) ? strtolower($artifact['sha256']) : '';
        $expectedBytes = isset($artifact['bytes']) && is_numeric($artifact['bytes']) ? (int) $artifact['bytes'] : 0;
        if ($storageKey === '') {
            throw new Problem('OJ_ML_ARTIFACT_RECORD_INVALID', '제출 파일 기록이 올바르지 않습니다.', 500);
        }

        $source = $this->storage->open($storageKey);
        $snapshot = tmpfile();
        if ($snapshot === false) {
            fclose($source);
            throw new Problem('OJ_ML_PREVIEW_SNAPSHOT_FAILED', '바로보기용 안전 복사본을 준비하지 못했습니다.', 503);
        }
        try {
            if (function_exists('fchmod')) {
                @fchmod($snapshot, 0600);
            }
            $this->integrity->verify($source, $expectedBytes, $expectedHash, $snapshot);
            $metadata = stream_get_meta_data($snapshot);
            $path = is_array($metadata) && is_string($metadata['uri'] ?? null) ? $metadata['uri'] : '';
            if ($path === '') {
                throw new Problem('OJ_ML_PREVIEW_SNAPSHOT_FAILED', '바로보기용 안전 복사본을 열지 못했습니다.', 503);
            }
            $content = $this->inspector->readOnlyView($path);
            $assignment = $this->assignments->teacherAssignment((int) $attempt['assignment_id'], $actorId, 'evaluate');
            $content['datasetContext'] = \OJML\Playgrounds\Domain\DatasetContext::from($assignment['policy'] ?? [], $content['portfolio'] ?? []);
            return [
                'source' => 'verified-read-only',
                'content' => $content,
            ];
        } finally {
            fclose($source);
            fclose($snapshot);
        }
    }
}

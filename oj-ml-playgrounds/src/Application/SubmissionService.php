<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use DateTimeImmutable;
use DateTimeZone;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\AssignmentStatus;
use OJML\Playgrounds\Domain\AttemptStatus;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Inspection\InspectionResult;
use OJML\Playgrounds\Inspection\MlpxInspector;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use Throwable;

final class SubmissionService
{
    public function __construct(
        private readonly Repository $repository,
        private readonly AssignmentService $assignments,
        private readonly PrivateArtifactStore $storage,
        private readonly MlpxInspector $inspector
    ) {
    }

    /**
     * Submission transport: one multipart/form-data upload whose file field is
     * named `artifact`. Body-supplied assignment/user IDs are never trusted.
     *
     * @param array<string,mixed> $upload
     * @return array{receipt:array<string,mixed>,attempt:array<string,mixed>,httpStatus:int}
     */
    public function submit(string $attemptUuid, int $userId, array $upload, string $correlationId): array
    {
        $learnerLease = \OJML\Playgrounds\Ops\Lease::acquire('learner:' . $userId);
        $attempt = $this->repository->getOwnedAttempt($attemptUuid, $userId);
        if ($attempt === null) {
            throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
        }
        if ($attempt['artifact_id'] !== null || in_array($attempt['status'], AttemptStatus::terminalForUpload(), true)) {
            return $this->existingReceipt($attemptUuid, $userId);
        }
        if (!AttemptStatus::canReceiveArtifact((string) $attempt['status'])) {
            throw new Problem('OJ_ML_ATTEMPT_IMMUTABLE', '이미 접수된 시도는 변경할 수 없습니다.', 409);
        }

        $assignment = $this->assignments->getForLearner((int) $attempt['assignment_id'], $userId);
        if ($assignment['status'] !== AssignmentStatus::PUBLISHED) {
            throw new Problem('OJ_ML_ASSIGNMENT_CLOSED', '종료된 과제에는 제출할 수 없습니다.', 409);
        }
        $late = $this->isLate($assignment);
        if ($late && (($assignment['policy']['allowLateSubmission'] ?? false) !== true)) {
            throw new Problem('OJ_ML_ASSIGNMENT_CLOSED', '제출 기한이 지난 과제입니다.', 409);
        }
        $this->validateUpload($upload);
        $settings = \OJML\Playgrounds\Ops\Options::all();
        $maximum = min((int) ($assignment['policy']['maxArtifactBytes'] ?? 104857600), (int) $settings['maxArtifactBytes']);
        $free = @disk_free_space($this->storage->root());
        if ($free === false || $free < (int) $settings['minimumFreeBytes'] + $maximum * (4 + (int) $settings['inspectionSlots'])) {
            throw new Problem('OJ_ML_STORAGE_LOW', '서버 저장 공간이 부족합니다. 운영자에게 알려주세요.', 503);
        }
        $uploadLease = \OJML\Playgrounds\Ops\Lease::acquire('upload', 4);
        if (isset($upload['size']) && is_numeric($upload['size']) && (int) $upload['size'] > $maximum) {
            throw new Problem('OJ_ML_ARTIFACT_TOO_LARGE', '제출 파일이 허용 용량을 초과했습니다.', 413, [
                'maxBytes' => $maximum,
            ]);
        }

        $temporaryPath = (string) $upload['tmp_name'];
        $isUploaded = (bool) apply_filters(
            'oj_ml_playgrounds_is_uploaded_file',
            is_uploaded_file($temporaryPath),
            $temporaryPath
        );
        if (!$isUploaded) {
            throw new Problem('OJ_ML_UPLOAD_INVALID', '정상적인 HTTP 업로드 파일이 아닙니다.', 400);
        }

        $stored = $this->storage->putUploadedFile($temporaryPath, $maximum);
        $quotaLease = null;
        try {
            $quotaLease = \OJML\Playgrounds\Ops\Lease::acquire('storage-quota', 1, 1);
            if ($this->repository->storageBytes() + $stored->bytes > (int) $settings['siteQuotaBytes']) {
                $this->storage->deleteOrphan($stored->storageKey);
                throw new Problem('OJ_ML_STORAGE_QUOTA', 'ML 제출물 저장 한도를 초과했습니다. 운영자에게 알려주세요.', 503);
            }
            $attached = $this->repository->attachArtifact($attemptUuid, $userId, [
                'uuid' => $stored->uuid,
                'storage_key' => $stored->storageKey,
                'original_name' => substr(sanitize_file_name((string) $upload['name']), 0, 255),
                'sha256' => $stored->sha256,
                'bytes' => $stored->bytes,
                'mime_type' => $stored->mimeType,
            ]);
            $late = (bool) ($attached['late'] ?? false);
        } finally {
            // An uncertain DB commit must never cause deletion of a possibly attached artifact.
            $quotaLease?->release();
            $uploadLease->release();
        }

        $current = $this->repository->getAttemptResult($attemptUuid, $userId);
        if ($current === null) {
            throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
        }
        if (($current['artifact']['uuid'] ?? null) !== $stored->uuid) {
            $this->storage->deleteOrphan($stored->storageKey);
            return $this->existingReceipt($attemptUuid, $userId);
        }

        try {
            (new \OJML\Playgrounds\Ops\InspectionWorker($this->repository, $this->storage, $this->inspector))
                ->process($attemptUuid, $userId, $correlationId);
        } catch (Throwable $error) {
            // Receipt is durable; WP-Cron/CLI retries inspection after this request.
            \OJML\Playgrounds\Ops\Audit::log($error, $correlationId);
        }
        $result = $this->repository->getAttemptResult($attemptUuid, $userId);
        if ($result === null) {
            throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '접수 결과를 조회하지 못했습니다. 같은 시도로 다시 확인하세요.', 503);
        }
        return [
            'receipt' => $this->receipt($result), 'attempt' => $result,
            'httpStatus' => in_array($result['status'], ['quarantined', 'system_pending'], true) ? 202 : 201,
        ];
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return $this->inspector->health() + ['storageConfigured' => is_writable($this->storage->root())];
    }

    /** @param array<string,mixed> $upload */
    private function validateUpload(array $upload): void
    {
        $error = isset($upload['error']) && is_numeric($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            $tooLarge = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new Problem(
                $tooLarge ? 'OJ_ML_ARTIFACT_TOO_LARGE' : 'OJ_ML_UPLOAD_FAILED',
                'MLPX 파일 업로드에 실패했습니다.',
                $tooLarge ? 413 : 400,
                ['uploadError' => $error]
            );
        }
        $name = isset($upload['name']) && is_string($upload['name']) ? $upload['name'] : '';
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'mlpx') {
            throw new Problem('OJ_ML_ARTIFACT_EXTENSION_INVALID', '.mlpx 파일만 제출할 수 있습니다.', 415);
        }
        if (!isset($upload['tmp_name']) || !is_string($upload['tmp_name']) || $upload['tmp_name'] === '') {
            throw new Problem('OJ_ML_UPLOAD_UNREADABLE', '업로드 파일을 읽을 수 없습니다.', 400);
        }
    }

    /** @param array<string,mixed> $assignment */
    private function isLate(array $assignment): bool
    {
        if (!is_string($assignment['due_at'] ?? null) || $assignment['due_at'] === '') {
            return false;
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))
            > new DateTimeImmutable($assignment['due_at'], new DateTimeZone('UTC'));
    }

    /** @return array{receipt:array<string,mixed>,attempt:array<string,mixed>,httpStatus:int} */
    private function existingReceipt(string $attemptUuid, int $userId): array
    {
        $result = $this->repository->getAttemptResult($attemptUuid, $userId);
        if ($result === null || !is_array($result['artifact'] ?? null)) {
            throw new Problem('OJ_ML_ATTEMPT_IMMUTABLE', '이 시도에는 제출 영수증이 없습니다.', 409);
        }
        return ['receipt' => $this->receipt($result), 'attempt' => $result, 'httpStatus' => 200];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function receipt(array $result): array
    {
        $artifact = is_array($result['artifact'] ?? null) ? $result['artifact'] : [];
        return [
            'attemptId' => (string) $result['uuid'],
            'attemptNo' => (int) $result['attempt_no'],
            'receivedAt' => $this->utcIso($result['received_at'] ?? null),
            'sha256' => (string) ($artifact['sha256'] ?? ''),
            'bytes' => (int) ($artifact['bytes'] ?? 0),
        ];
    }

    private function utcIso(mixed $mysqlDate): ?string
    {
        if (!is_string($mysqlDate) || $mysqlDate === '') {
            return null;
        }
        $timestamp = strtotime($mysqlDate . ' UTC');
        return $timestamp === false ? null : gmdate(DATE_ATOM, $timestamp);
    }
}

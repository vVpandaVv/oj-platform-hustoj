<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Domain\TeachingDataset;
use OJML\Playgrounds\Ops\Lease;
use OJML\Playgrounds\Ops\Options;
use OJML\Playgrounds\Security\Addon_Capabilities;
use OJML\Playgrounds\Storage\TeachingAssetStore;
use Throwable;

final class TeachingDatasetService
{
    public const OPTION = 'oj_ml_teaching_datasets_v1';
    public const OWNER_ACTIVE_LIMIT = 20;
    public const OWNER_BYTE_LIMIT = 52428800;
    public const GLOBAL_COUNT_LIMIT = 200;
    public const GLOBAL_BYTE_LIMIT = 209715200;

    public function __construct(
        private readonly Repository $repository,
        private readonly TeachingAssetStore $store,
        private readonly ContextService $contexts
    ) {}

    /** @return array{items:list<array<string,mixed>>,usage:array<string,int>,limits:array<string,int>} */
    public function listForTeacher(int $actorId): array
    {
        $this->teacher($actorId);
        $records = self::records(); $items = []; $retained = 0; $bytes = 0;
        foreach ($records as $record) {
            if ($record['ownerId'] !== $actorId) { continue; }
            $retained++; $bytes += $record['bytes'];
            if ($record['hiddenAt'] === null) { $items[] = self::dto($record); }
        }
        return ['items' => array_reverse($items), 'usage' => ['activeCount' => count($items), 'retainedCount' => $retained, 'retainedBytes' => $bytes],
            'limits' => ['fileBytes' => TeachingDataset::MAX_BYTES, 'activeCount' => self::OWNER_ACTIVE_LIMIT, 'retainedBytes' => self::OWNER_BYTE_LIMIT,
                'globalCount' => self::GLOBAL_COUNT_LIMIT, 'globalBytes' => self::GLOBAL_BYTE_LIMIT,
                'rowsIncludingHeader' => TeachingDataset::MAX_ROWS, 'columns' => TeachingDataset::MAX_COLUMNS, 'cells' => TeachingDataset::MAX_CELLS]];
    }

    /** Internal WordPress privacy-provider helper, not an independently exposed API. */
    public static function privacyForUser(int $actorId): array
    {
        $items = [];
        if ($actorId > 0) {
            foreach (self::records() as $record) {
                if ($record['ownerId'] === $actorId) { $items[] = self::dto($record) + ['hiddenAt' => $record['hiddenAt'], 'retained' => true]; }
            }
        }
        return ['items' => $items, 'retained' => $items !== [], 'message' => '교사가 등록한 수업 CSV와 과제 연결은 제출 검증 및 수업 기록 보존을 위해 유지됩니다. 라이브러리 숨김은 파일 삭제가 아닙니다. 실제 삭제는 관리자가 연결된 과제와 보존 기간을 확인하여 처리해야 합니다.'];
    }

    /** @param array<string,mixed> $upload @return array<string,mixed> */
    public function upload(int $actorId, array $upload, string $correlationId): array
    {
        $this->teacher($actorId, true);
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_string($upload['name'] ?? null)
            || !is_int($upload['size'] ?? null) || $upload['size'] < 1 || $upload['size'] > TeachingDataset::MAX_BYTES) {
            throw new Problem('OJ_ML_DATASET_UPLOAD_INVALID', '5MiB 이하 CSV 파일 한 개를 선택하세요. PHP 업로드 한도도 확인하세요.', 400);
        }
        $name = TeachingDataset::filename($upload['name']);
        $path = $upload['tmp_name'];
        if (is_link($path) || !is_file($path) || !apply_filters('oj_ml_playgrounds_is_uploaded_file', is_uploaded_file($path), $path)) {
            throw new Problem('OJ_ML_DATASET_UPLOAD_INVALID', '정상적인 HTTP 업로드 파일만 사용할 수 있습니다.', 400);
        }
        $lease = Lease::acquire('teaching-datasets');
        try {
            $input = @fopen($path, 'rb');
            if ($input === false) { throw new Problem('OJ_ML_DATASET_UPLOAD_INVALID', '업로드 파일을 읽지 못했습니다.', 400); }
            try {
                $stat = fstat($input);
                if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $upload['size']) {
                    throw new Problem('OJ_ML_DATASET_UPLOAD_INVALID', '업로드 파일 크기가 일치하지 않습니다.', 400);
                }
                $bytes = stream_get_contents($input, TeachingDataset::MAX_BYTES + 1);
            } finally { fclose($input); }
            if (!is_string($bytes) || strlen($bytes) !== $upload['size']) { throw new Problem('OJ_ML_DATASET_UPLOAD_INVALID', '업로드 파일을 온전히 읽지 못했습니다.', 400); }
            $normalized = TeachingDataset::normalize($bytes);
            $records = self::records(true);
            $this->quota($records, $actorId, $normalized['bytes']);
            $id = bin2hex(random_bytes(16));
            $time = gmdate('Y-m-d H:i:s');
            // Durable intent precedes filesystem/option writes. The committed metadata also
            // carries its audit entry, so a post-commit diagnostic failure cannot erase it.
            $this->repository->insertEvent(null, null, $actorId, 'teaching_dataset.upload_requested', ['datasetId' => $id, 'sha256' => $normalized['sha256'], 'bytes' => $normalized['bytes']], $correlationId);
            $stored = $this->store->put($normalized['content']);
            $record = $stored + [
                'id' => $id, 'ownerId' => $actorId, 'name' => $name, 'createdAt' => $time, 'hiddenAt' => null,
                'rowCount' => $normalized['rowCount'], 'columnCount' => $normalized['columnCount'],
                'audit' => [['action' => 'uploaded', 'actorId' => $actorId, 'at' => $time, 'correlationId' => substr($correlationId, 0, 100)]],
            ];
            $records[$id] = $record;
            try { $this->save($records); }
            catch (Throwable $error) {
                // A known failed option write can clean up the new file. Ambiguous commits
                // retain it for operator reconciliation rather than deleting referenced data.
                try {
                    $latest = self::records(true);
                    if (!isset($latest[$id])) { $this->store->discardNew($stored['storageKey']); }
                } catch (Throwable $cleanup) { $this->diagnostic($cleanup, $correlationId); }
                throw $error;
            }
            $this->completed($actorId, 'teaching_dataset.uploaded', $id, $correlationId);
            return self::dto($record);
        } finally { $lease->release(); }
    }

    /** Hide from new selection; existing assignment references and exact bytes remain retained. */
    public function hide(int $actorId, string $id, string $correlationId): array
    {
        $this->teacher($actorId, true); $this->id($id);
        $lease = Lease::acquire('teaching-datasets');
        try {
            $records = self::records(true); $record = $records[$id] ?? null;
            if ($record === null || $record['ownerId'] !== $actorId) { throw $this->missing(); }
            if ($record['hiddenAt'] === null) {
                $time = gmdate('Y-m-d H:i:s');
                $this->repository->insertEvent(null, null, $actorId, 'teaching_dataset.hide_requested', ['datasetId' => $id, 'retained' => true], $correlationId);
                $record['hiddenAt'] = $time;
                $record['audit'][] = ['action' => 'hidden', 'actorId' => $actorId, 'at' => $time, 'correlationId' => substr($correlationId, 0, 100)];
                $records[$id] = $record; $this->save($records);
                $this->completed($actorId, 'teaching_dataset.hidden', $id, $correlationId);
            }
            return ['id' => $id, 'hidden' => true, 'retained' => true];
        } finally { $lease->release(); }
    }

    /** The caller passes its database-loaded source assignment, never request-provided data. */
    public function resolveReference(mixed $reference, int $actorId, ?array $sourceAssignment = null): ?array
    {
        $this->teacher($actorId);
        if ($reference === null) { return null; }
        if (!is_array($reference) || !is_string($reference['id'] ?? null)) { throw $this->missing(); }
        $id = $reference['id']; $this->id($id);
        $record = self::records()[$id] ?? null;
        if ($record === null) { throw $this->missing(); }
        $preserved = false;
        if (($sourceAssignment['policy']['datasetResource']['id'] ?? null) === $id) {
            // Re-read by ID to prevent a forged/stale array from granting shared ownership.
            $source = $this->repository->getAssignment((int) ($sourceAssignment['id'] ?? 0));
            if ($source !== null && ($source['policy']['datasetResource']['id'] ?? null) === $id) {
                $this->contexts->assertAssignmentAccess($actorId, $source, 'manage');
                $preserved = true;
            }
        }
        if (!$preserved && ($record['ownerId'] !== $actorId || $record['hiddenAt'] !== null)) { throw $this->missing(); }
        $this->store->read($record);
        return self::dto($record);
    }

    /** @return array{name:string,content:string,bytes:int,sha256:string} */
    public function downloadForLearner(int $assignmentId, int $actorId): array
    {
        $this->learner($actorId);
        $assignment = $this->repository->getAssignment($assignmentId);
        if ($assignment === null || !in_array($assignment['status'], ['published', 'closed'], true)
            || !$this->repository->isActiveLearner($assignmentId, $actorId)) { throw $this->missing(); }
        $this->contexts->assertAssignmentAccess($actorId, $assignment, 'participate', $actorId);
        if (($assignment['opens_at'] ?? null) !== null && $assignment['opens_at'] > gmdate('Y-m-d H:i:s')) {
            throw new Problem('OJ_ML_ASSIGNMENT_NOT_OPEN', '과제가 시작된 뒤 수업 자료를 받을 수 있습니다.', 409);
        }
        $reference = $assignment['policy']['datasetResource'] ?? null;
        $id = is_array($reference) && is_string($reference['id'] ?? null) ? $reference['id'] : '';
        $this->id($id);
        $record = self::records()[$id] ?? null;
        if ($record === null) { throw $this->missing(); }
        if (($reference['sha256'] ?? null) !== $record['sha256'] || ($reference['bytes'] ?? null) !== $record['bytes']) {
            throw new Problem('OJ_ML_TEACHING_ASSET_INTEGRITY', '과제에 지정된 수업 자료 정보가 보관본과 일치하지 않습니다.', 503);
        }
        return ['name' => $record['name'], 'content' => $this->store->read($record), 'bytes' => $record['bytes'], 'sha256' => $record['sha256']];
    }

    private function teacher(int $actorId, bool $mutation = false): void
    {
        $this->capability($actorId, [Addon_Capabilities::MANAGE]);
        if ($mutation && Options::all()['maintenanceMode']) { throw new Problem('OJ_ML_MAINTENANCE', '점검 중에는 수업 자료를 변경할 수 없습니다.', 503); }
    }

    private function learner(int $actorId): void { $this->capability($actorId, [Addon_Capabilities::USE, Addon_Capabilities::SUBMIT]); }

    private function capability(int $actorId, array $declared): void
    {
        if ($actorId < 1) { throw new Problem('OJ_ML_AUTHENTICATION_REQUIRED', '로그인이 필요합니다.', 401); }
        foreach ($declared as $key) {
            $cap = Addon_Capabilities::wordpress($key);
            if ($cap !== '' && user_can($actorId, $cap)) { return; }
        }
        throw new Problem('OJ_ML_CAPABILITY_REQUIRED', '수업 자료에 접근할 권한이 없습니다.', 403);
    }

    private static function records(bool $fresh = false): array
    {
        if ($fresh) { wp_cache_delete(self::OPTION, 'options'); wp_cache_delete('notoptions', 'options'); }
        $records = get_option(self::OPTION, []);
        if (!is_array($records) || count($records) > self::GLOBAL_COUNT_LIMIT) { throw new Problem('OJ_ML_DATASET_LIBRARY_INVALID', '수업 자료 목록 상태를 확인할 수 없습니다.', 503); }
        foreach ($records as $id => $record) {
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !is_array($record)
                || ($record['id'] ?? null) !== $id || !is_int($record['ownerId'] ?? null) || $record['ownerId'] < 1
                || !is_int($record['bytes'] ?? null) || $record['bytes'] < 1 || $record['bytes'] > TeachingDataset::MAX_BYTES
                || !is_string($record['name'] ?? null) || !is_string($record['createdAt'] ?? null)
                || !array_key_exists('hiddenAt', $record) || (!is_null($record['hiddenAt']) && !is_string($record['hiddenAt']))
                || !is_string($record['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record['sha256']) !== 1
                || !is_string($record['storageKey'] ?? null) || preg_match('#^teaching-assets/[a-f0-9]{32}\.csv$#D', $record['storageKey']) !== 1
                || !is_int($record['rowCount'] ?? null) || !is_int($record['columnCount'] ?? null) || !is_array($record['audit'] ?? null)) {
                throw new Problem('OJ_ML_DATASET_LIBRARY_INVALID', '수업 자료 목록 상태를 확인할 수 없습니다.', 503);
            }
        }
        return $records;
    }

    private function save(array $records): void
    {
        if (!update_option(self::OPTION, $records, false)) {
            throw new Problem('OJ_ML_DATASET_LIBRARY_SAVE_FAILED', '수업 자료 목록을 저장하지 못했습니다.', 503);
        }
    }

    private function quota(array $records, int $actorId, int $newBytes): void
    {
        $globalBytes = 0; $ownerBytes = 0; $active = 0;
        foreach ($records as $record) {
            $globalBytes += $record['bytes'];
            if ($record['ownerId'] === $actorId) {
                $ownerBytes += $record['bytes'];
                if ($record['hiddenAt'] === null) { $active++; }
            }
        }
        $physical = $this->store->usage();
        if (max(count($records), $physical['count']) >= self::GLOBAL_COUNT_LIMIT || max($globalBytes, $physical['bytes']) + $newBytes > self::GLOBAL_BYTE_LIMIT
            || $ownerBytes + $newBytes > self::OWNER_BYTE_LIMIT || $active >= self::OWNER_ACTIVE_LIMIT) {
            throw new Problem('OJ_ML_DATASET_QUOTA_EXCEEDED', '수업 자료 보관 한도에 도달했습니다. 목록은 교사당 20개, 보관 용량은 교사당 50MiB·사이트 전체 200MiB(200개)입니다. 숨긴 파일도 보관 용량에 포함됩니다.', 413);
        }
    }

    private static function dto(array $record): array
    {
        return ['id' => $record['id'], 'name' => $record['name'], 'sha256' => $record['sha256'], 'bytes' => $record['bytes'],
            'rowCount' => $record['rowCount'], 'columnCount' => $record['columnCount'], 'createdAt' => $record['createdAt'], 'path' => TeachingDataset::DATASET_PATH];
    }

    private function id(string $id): void { if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) { throw $this->missing(); } }
    private function missing(): Problem { return new Problem('OJ_ML_DATASET_NOT_FOUND', '사용 가능한 지정 수업 자료를 찾을 수 없습니다.', 404); }
    private function completed(int $actorId, string $event, string $id, string $correlationId): void
    {
        try { $this->repository->insertEvent(null, null, $actorId, $event, ['datasetId' => $id], $correlationId); }
        catch (Throwable $error) { $this->diagnostic($error, $correlationId); }
    }
    private function diagnostic(Throwable $error, string $correlationId): void
    {
        try { do_action('oj_ml_playgrounds_rest_exception', $error, $correlationId); } catch (Throwable) {}
    }
}

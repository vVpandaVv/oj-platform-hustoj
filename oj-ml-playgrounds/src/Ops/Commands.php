<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Database\Installer;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Database\SchemaUpgrade;
use OJML\Playgrounds\Storage\ArtifactIntegrityVerifier;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use Throwable;

/** WP-CLI operator entry point; no HTTP route can invoke it. */
final class Commands
{
    public function __construct(private readonly Bootstrap $operations) {}

    /**
     * Inspect, migrate, recover, reconcile, retain, or export ML records.
     *
     * ## OPTIONS
     *
     * <operation>
     * : doctor|migrate|recover|reconcile|retention|export-user|manifest|audit
     *
     * [--fix]
     * : Remove orphan/partial files older than one day during reconcile.
     * [--execute]
     * : Execute a retention batch (otherwise dry-run).
     * [--yes]
     * : Explicitly confirm deletion.
     * [--user=<id>]
     * : User ID for a private export.
     * [--assignment=<id>]
     * : Assignment ID for a private export (at most 100 attempts).
     * [--after=<id>]
     * : Audit event cursor.
     */
    public function __invoke(array $args, array $options): void
    {
        try {
            $operation = $args[0] ?? 'doctor';
            if ($operation === 'migrate') { Installer::install(); \WP_CLI::success('ML 데이터베이스 업데이트 및 검증 완료'); return; }
            if ($operation === 'doctor') {
                $health = $this->operations->health();
                $this->json($health);
                if (!$health['ok']) { \WP_CLI::halt(1); }
                return;
            }
            Installer::maybeUpgrade();
            if ($operation === 'recover') { $this->json($this->operations->worker()->recover(100)); return; }
            global $wpdb;
            $storage = new PrivateArtifactStore();
            $maintenance = new StorageMaintenance($wpdb, $storage);
            if ($operation === 'reconcile') {
                $fix = isset($options['fix']);
                if ($fix) { \WP_CLI::confirm('24시간이 지난 고아/부분 파일과 삭제 예정 파일을 정리합니다.', $options); }
                $this->json($maintenance->reconcile($fix)); return;
            }
            if ($operation === 'retention') {
                $execute = isset($options['execute']);
                if ($execute) { \WP_CLI::confirm('설정한 보존기간이 지난 종료/보관 과제의 원본 파일을 최대 100개 삭제합니다.', $options); }
                $this->json($maintenance->retention($execute)); return;
            }
            if ($operation === 'export-user') {
                $this->json($this->exportUser($storage, (int) ($options['user'] ?? 0), (int) ($options['assignment'] ?? 0))); return;
            }
            if ($operation === 'manifest') { $this->json($this->manifest($storage)); return; }
            if ($operation === 'audit') {
                $rows = $wpdb->get_results($wpdb->prepare('SELECT id,assignment_id,attempt_id,actor_user_id,event_type,payload_json,correlation_id,created_at FROM oj_ml_events WHERE id > %d ORDER BY id LIMIT 100',
                    max(0, (int) ($options['after'] ?? 0))), ARRAY_A);
                SchemaUpgrade::assertDatabase($wpdb);
                $this->json(['items' => $rows, 'next' => $rows === [] ? null : (int) end($rows)['id']]); return;
            }
            \WP_CLI::error('지원 명령: doctor, migrate, recover, reconcile, retention, export-user, manifest, audit');
        } catch (Throwable $error) {
            Audit::log($error, wp_generate_uuid4());
            \WP_CLI::error($error->getMessage());
        }
    }

    /** Export files are created exclusively, outside webroot, and never given a public URL. */
    private function exportPath(PrivateArtifactStore $storage, string $suffix): string
    {
        $directory = $storage->root() . DIRECTORY_SEPARATOR . 'exports';
        if (is_link($directory) || (!is_dir($directory) && !wp_mkdir_p($directory))) { throw new \RuntimeException('안전한 내보내기 폴더를 만들지 못했습니다.'); }
        $resolved = realpath($directory);
        if ($resolved !== $directory) { throw new \RuntimeException('내보내기 경로가 안전하지 않습니다.'); }
        @chmod($directory, 0700);
        return $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . $suffix;
    }

    private function exportUser(PrivateArtifactStore $storage, int $userId, int $assignmentId): array
    {
        if ($userId < 1 || $assignmentId < 1 || !class_exists('ZipArchive')) { throw new \RuntimeException('--user, --assignment와 ZIP 확장이 필요합니다.'); }
        global $wpdb;
        $repository = new Repository($wpdb);
        $path = $this->exportPath($storage, '.zip');
        $lease = Lease::acquire('learner:' . $userId);
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) { throw new \RuntimeException('내보내기 ZIP을 생성하지 못했습니다.'); }
        @chmod($path, 0600);
        $snapshots = [];
        $attempts = [];
        try {
            for ($page = 1; $page <= 2; $page++) {
                $result = $repository->listAttemptHistory($assignmentId, $userId, $page, 50, true);
                if ($result['total'] > 100) { throw new \RuntimeException('한 번에 100회를 초과해 내보낼 수 없습니다.'); }
                foreach ($result['items'] as $attempt) {
                    $attempts[] = (new \OJML\Playgrounds\Rest\Presenter())->attempt($attempt);
                    $artifact = $repository->getArtifactForInspection((int) $attempt['id']);
                    if ($artifact === null) { continue; }
                    $free = @disk_free_space($storage->root());
                    if ($free === false || $free < (int) $artifact['bytes'] * 3 + (int) Options::all()['minimumFreeBytes']) {
                        throw new \RuntimeException('내보내기용 디스크 공간이 부족합니다.');
                    }
                    $source = $storage->open($artifact['storage_key']);
                    $snapshot = tmpfile();
                    if ($snapshot === false) { fclose($source); throw new \RuntimeException('임시 파일을 만들 수 없습니다.'); }
                    $snapshots[] = $snapshot;
                    try { (new ArtifactIntegrityVerifier())->verify($source, (int) $artifact['bytes'], $artifact['sha256'], $snapshot); }
                    finally { fclose($source); }
                    if (!$zip->addFile(stream_get_meta_data($snapshot)['uri'], 'attempt-' . (int) $attempt['attempt_no'] . '.mlpx')) {
                        throw new \RuntimeException('ZIP에 원본 파일을 추가하지 못했습니다.');
                    }
                }
                if ($page * 50 >= $result['total']) { break; }
            }
            if (!$zip->addFromString('submissions.json', (string) wp_json_encode(['userId' => $userId, 'assignmentId' => $assignmentId, 'attempts' => $attempts], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) || !$zip->close()) {
                throw new \RuntimeException('내보내기 ZIP을 확정하지 못했습니다.');
            }
            (new StorageMaintenance($wpdb, $storage))->event($assignmentId, null, 'privacy.exported', ['userId' => $userId, 'attempts' => count($attempts)]);
            return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'attempts' => count($attempts), 'notice' => '인증된 당사자에게 전달한 후 이 사본을 직접 삭제하세요.'];
        } catch (Throwable $error) {
            try { $zip->close(); } catch (Throwable) {}
            if (is_file($path) && !is_link($path)) { unlink($path); }
            throw $error;
        } finally {
            foreach ($snapshots as $snapshot) { if (is_resource($snapshot)) { fclose($snapshot); } }
            $lease->release();
        }
    }

    private function manifest(PrivateArtifactStore $storage): array
    {
        global $wpdb;
        $path = $this->exportPath($storage, '.jsonl');
        $handle = fopen($path, 'xb');
        if ($handle === false) { throw new \RuntimeException('Manifest를 만들지 못했습니다.'); }
        @chmod($path, 0600);
        $count = 0; $after = 0;
        $backupId = wp_generate_uuid4();
        try {
            $this->line($handle, ['backupId' => $backupId, 'createdAt' => gmdate(DATE_ATOM), 'schemaVersion' => Installer::DB_VERSION, 'version' => OJ_ML_PLAYGROUNDS_VERSION]);
            do {
                $rows = $wpdb->get_results($wpdb->prepare('SELECT id,uuid,storage_key,bytes,sha256 FROM oj_ml_artifacts WHERE id > %d AND deleted_at IS NULL ORDER BY id LIMIT 200', $after), ARRAY_A);
                SchemaUpgrade::assertDatabase($wpdb);
                foreach ($rows as $row) {
                    $stream = $storage->open($row['storage_key']);
                    try { (new ArtifactIntegrityVerifier())->verify($stream, (int) $row['bytes'], $row['sha256']); }
                    finally { fclose($stream); }
                    $this->line($handle, $row); $count++; $after = (int) $row['id'];
                }
            } while (count($rows) === 200);
            $datasetLease = Lease::acquire('teaching-datasets');
            $teachingRecords = get_option(\OJML\Playgrounds\Application\TeachingDatasetService::OPTION, []);
            if (!is_array($teachingRecords)) { throw new \RuntimeException('수업 CSV 메타데이터를 읽지 못했습니다.'); }
            $teachingStore = new \OJML\Playgrounds\Storage\TeachingAssetStore($storage);
            foreach ($teachingRecords as $record) {
                $teachingStore->read($record);
                $this->line($handle, ['kind' => 'teaching_dataset', 'id' => $record['id'], 'storage_key' => $record['storageKey'], 'bytes' => $record['bytes'], 'sha256' => $record['sha256']]);
                $count++;
            }
            $datasetLease->release();
            fflush($handle);
        } finally { fclose($handle); }
        return ['backupId' => $backupId, 'path' => $path, 'files' => $count, 'sha256' => hash_file('sha256', $path),
            'notice' => '백업 중에는 제출·평가·양식/자료 변경·정리 작업을 중지하세요. WordPress options를 포함한 전체 DB와 비공개 저장소 전체(artifacts + teaching-assets)를 같은 시점에 보관하세요.'];
    }

    private function line($handle, array $data): void
    {
        $line = (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (fwrite($handle, $line) !== strlen($line)) { throw new \RuntimeException('Manifest 저장에 실패했습니다.'); }
    }

    private function json(array $data): void
    {
        \WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

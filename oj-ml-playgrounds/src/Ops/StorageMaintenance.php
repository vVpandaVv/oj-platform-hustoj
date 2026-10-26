<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Database\Installer;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Database\SchemaUpgrade;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Storage\ArtifactIntegrityVerifier;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use Throwable;

/** Operator-only maintenance. Every deletion is scoped to a validated artifact key. */
final class StorageMaintenance
{
    public function __construct(private readonly object $db, private readonly PrivateArtifactStore $storage) {}

    public function reconcile(bool $fix = false): array
    {
        Installer::maybeUpgrade();
        if ($fix && Options::all()['maintenanceMode']) { throw new Problem('OJ_ML_MAINTENANCE', '점검 중에는 파일 정리를 실행하지 않습니다.', 503); }
        $report = ['checked' => 0, 'missing' => 0, 'corrupt' => 0, 'orphan' => 0, 'partial' => 0, 'removed' => 0, 'issues' => []];
        $after = 0;
        do {
            $rows = $this->db->get_results($this->db->prepare('SELECT * FROM oj_ml_artifacts WHERE id > %d ORDER BY id LIMIT 200', $after), ARRAY_A);
            SchemaUpgrade::assertDatabase($this->db);
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $report['checked']++;
                try {
                    if ($row['deleted_at'] !== null) {
                        if ($fix) { $this->removeTombstonedFile($row); }
                        continue;
                    }
                    $stream = $this->storage->open($row['storage_key']);
                    try { (new ArtifactIntegrityVerifier())->verify($stream, (int) $row['bytes'], $row['sha256']); }
                    finally { fclose($stream); }
                } catch (Throwable $error) {
                    $kind = $error instanceof Problem && $error->httpStatus() === 404 ? 'missing' : 'corrupt';
                    $report[$kind]++;
                    if (count($report['issues']) < 1000) { $report['issues'][] = ['artifactId' => $row['uuid'], 'status' => $kind]; }
                }
            }
        } while (count($rows) === 200);
        $directory = $this->storage->root() . DIRECTORY_SEPARATOR . 'artifacts';
        if (is_dir($directory) && !is_link($directory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink() || !$file->isFile()) { continue; }
                $key = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($this->storage->root()) + 1));
                if (!preg_match('~^artifacts/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\\.mlpx(\\.part)?$~D', $key, $match)) { continue; }
                $partial = isset($match[1]);
                $baseKey = $partial ? substr($key, 0, -5) : $key;
                $record = $this->db->get_var($this->db->prepare('SELECT id FROM oj_ml_artifacts WHERE storage_key = %s', $baseKey));
                SchemaUpgrade::assertDatabase($this->db);
                if ($record !== null) { continue; }
                $report[$partial ? 'partial' : 'orphan']++;
                // Files younger than a day may belong to a still-running HTTP request.
                if ($fix && $file->getMTime() < time() - 86400) {
                    $path = $this->storage->pathFor($baseKey) . ($partial ? '.part' : '');
                    if (!is_link($path) && realpath($path) === realpath($file->getPathname()) && unlink($path)) {
                        $report['removed']++;
                    }
                }
            }
        }
        if ($fix) { $this->event(null, null, 'storage.reconciled', array_diff_key($report, ['issues' => true])); }
        return $report;
    }

    /** Dry-run by default; retains grades and metadata, prunes archived/closed original files only. */
    public function retention(bool $execute = false): array
    {
        Installer::maybeUpgrade();
        $settingsLease = Lease::acquire('settings');
        $settings = Options::all();
        if ($settings['maintenanceMode'] || $settings['retentionHold'] || (int) $settings['retentionDays'] === 0) {
            return ['held' => true, 'candidates' => [], 'deleted' => 0, 'hasMore' => false];
        }
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT f.*,a.assignment_id FROM oj_ml_artifacts f JOIN oj_ml_attempts a ON a.id = f.attempt_id
             JOIN oj_ml_assignments t ON t.id = a.assignment_id WHERE f.deleted_at IS NULL
             AND t.status IN ('closed','archived') AND f.created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
             ORDER BY f.id LIMIT 101", (int) $settings['retentionDays']
        ), ARRAY_A);
        SchemaUpgrade::assertDatabase($this->db);
        $report = ['held' => false, 'candidates' => [], 'deleted' => 0, 'hasMore' => count($rows) > 100];
        foreach (array_slice($rows, 0, 100) as $row) {
            $report['candidates'][] = ['artifactId' => $row['uuid'], 'bytes' => (int) $row['bytes'], 'createdAt' => $row['created_at']];
            if ($execute && $this->deleteArtifact($row, 'retention')) { $report['deleted']++; }
        }
        return $report;
    }

    public function deleteArtifact(array $row, string $reason): bool
    {
        $lease = Lease::acquire('attempt:' . $this->attemptUuid((int) $row['attempt_id']));
        try {
            if ($this->db->query('START TRANSACTION') === false) { throw new \RuntimeException('transaction'); }
            try {
                if ($reason === 'retention') {
                    $assignment = $this->db->get_row($this->db->prepare(
                        'SELECT status FROM oj_ml_assignments WHERE id = %d FOR UPDATE', (int) $row['assignment_id']), ARRAY_A);
                    SchemaUpgrade::assertDatabase($this->db);
                    $settings = Options::all();
                    if ($settings['retentionHold'] || (int) $settings['retentionDays'] === 0
                        || !is_array($assignment) || !in_array($assignment['status'], ['closed', 'archived'], true)
                        || $row['created_at'] >= gmdate('Y-m-d H:i:s', time() - (int) $settings['retentionDays'] * 86400)) {
                        if ($this->db->query('ROLLBACK') === false) { throw new \RuntimeException('rollback'); }
                        return false;
                    }
                }
                $fresh = $this->db->get_row($this->db->prepare('SELECT * FROM oj_ml_artifacts WHERE id = %d FOR UPDATE', (int) $row['id']), ARRAY_A);
                SchemaUpgrade::assertDatabase($this->db);
                if (!is_array($fresh)) { throw new \RuntimeException('artifact missing'); }
                if ($fresh['deleted_at'] === null) {
                    if ($this->db->update('oj_ml_artifacts', ['deleted_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $row['id']]) === false) { throw new \RuntimeException('tombstone'); }
                    $this->event(null, (int) $row['attempt_id'], 'artifact.delete_requested', ['artifactId' => $row['uuid'], 'reason' => $reason]);
                }
                if ($this->db->query('COMMIT') === false) { throw new \RuntimeException('commit'); }
            } catch (Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
            // Tombstone is committed before filesystem mutation. Reconcile can resume this step.
            $this->removeTombstonedFile($row);
            return true;
        } finally { $lease->release(); }
    }

    private function removeTombstonedFile(array $row): void
    {
        $path = $this->storage->pathFor($row['storage_key']);
        if (is_link($path)) { throw new \RuntimeException('unsafe artifact'); }
        if (is_file($path) && !unlink($path)) { throw new \RuntimeException('artifact deletion failed'); }
    }

    private function attemptUuid(int $id): string
    {
        $uuid = $this->db->get_var($this->db->prepare('SELECT uuid FROM oj_ml_attempts WHERE id = %d', $id));
        SchemaUpgrade::assertDatabase($this->db);
        if (!is_string($uuid)) { throw new \RuntimeException('attempt missing'); }
        return $uuid;
    }

    public function event(?int $assignmentId, ?int $attemptId, string $type, array $payload): void
    {
        (new Repository($this->db))->insertEvent($assignmentId, $attemptId,
            function_exists('get_current_user_id') ? get_current_user_id() : 0, $type, $payload, wp_generate_uuid4());
    }
}

<?php
declare(strict_types=1);

namespace ReadContents\Library\Editor\ImportExport;

use DirectoryIterator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ServerPackageImporter
{
    public const OPTION = 'rcis_server_import_job';

    public function __construct(private readonly ContentPackageService $packages) {}

    public function info(): array
    {
        $result = ['directory' => $this->configuredDirectory(), 'files' => [], 'error' => null, 'job' => $this->summary($this->load())];
        try {
            $result['directory'] = $this->directory();
            foreach (new DirectoryIterator($result['directory']) as $entry) {
                if ($entry->isDot() || $entry->isLink() || !$entry->isFile() || strtolower($entry->getExtension()) !== 'zip') continue;
                if (count($result['files']) >= 500) throw new InvalidArgumentException('ZIP 파일이 500개를 초과했습니다. 가져온 ZIP을 다른 폴더로 옮겨 주세요.');
                $result['files'][] = ['name' => $entry->getFilename(), 'bytes' => $entry->getSize(), 'modified' => $entry->getMTime()];
            }
            usort($result['files'], static fn ($a, $b) => strcmp($a['name'], $b['name']));
            if (!class_exists(\ZipArchive::class)) throw new InvalidArgumentException('서버 ZIP 가져오기에는 PHP zip 확장이 필요합니다.');
        } catch (Throwable $error) {
            $result['error'] = $error->getMessage();
            $result['files'] = [];
        }
        return $result;
    }

    public function preview(string $name): array
    {
        $path = $this->file($name);
        $before = $this->stamp($path);
        $data = $this->packages->serverPackage($path);
        $this->packages->serverUnits($data['package']);
        $plan = $this->packages->serverPreview($data['package']);
        if ($before !== $this->stamp($path)) throw new InvalidArgumentException('ZIP 업로드가 아직 진행 중이거나 파일이 변경되었습니다. 업로드 완료 후 다시 확인해 주세요.');
        $plan['preview']['assetsDetected'] = count($data['paths']);
        return [
            'fileName' => $name,
            'fingerprint' => hash('sha256', (string) wp_json_encode([$before, $data['hash'], $plan['expected']])),
            'preview' => $plan['preview'],
        ];
    }

    public function start(string $name, string $fingerprint, array $options, string $requestId): array
    {
        if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/', $requestId)) throw new InvalidArgumentException('가져오기 요청 ID가 올바르지 않습니다.');
        return $this->locked(function () use ($name, $fingerprint, $options, $requestId): array {
            $previous = $this->load();
            if ($previous && $previous['requestId'] === $requestId) {
                $this->assertOwner($previous);
                return $this->summary($previous);
            }
            if ($previous && !in_array($previous['status'], ['completed', 'cancelled'], true)) {
                throw new InvalidArgumentException('진행 중인 서버 가져오기를 먼저 완료하거나 취소해 주세요.');
            }
            $preview = $this->preview($name);
            if (!hash_equals($preview['fingerprint'], $fingerprint)) throw new InvalidArgumentException('ZIP 또는 기존 레슨이 변경되었습니다. 미리보기를 다시 확인해 주세요.');
            $path = $this->file($name);
            $data = $this->packages->serverPackage($path);
            $plan = $this->packages->serverPreview($data['package']);
            if (!hash_equals($fingerprint, hash('sha256', (string) wp_json_encode([$this->stamp($path), $data['hash'], $plan['expected']])))) {
                throw new InvalidArgumentException('확인 중 파일 또는 레슨이 변경되었습니다. 새 미리보기가 필요합니다.');
            }
            $options = $this->packages->serverOptions($options);
            $units = $this->packages->serverUnits($data['package']);
            $job = [
                'id' => wp_generate_uuid4(), 'requestId' => $requestId, 'owner' => get_current_user_id(),
                'fileName' => $name, 'stamp' => $this->stamp($path), 'manifestHash' => $data['hash'],
                'status' => 'running', 'cursor' => 0, 'pending' => null, 'error' => null,
                'options' => $options, 'expected' => $plan['expected'],
                'paths' => $this->packages->serverAssetPaths($data['package'], $options, $plan['expected']),
                'assetMap' => [], 'assetIndex' => 0, 'unitIndex' => 0, 'unitTotal' => count($units),
                'current' => '', 'seen' => [], 'result' => null, 'updated' => time(),
            ];
            $this->save($job);
            $this->audit('started', $job);
            return $this->summary($job);
        });
    }

    public function step(string $id, int $cursor): array
    {
        return $this->locked(function () use ($id, $cursor): array {
            $job = $this->requireJob($id);
            if ($cursor !== $job['cursor'] || $job['status'] !== 'running') return $this->summary($job);
            if ($job['pending'] !== null) {
                $job['status'] = 'needs_review';
                $job['error'] = '이전 요청이 항목 처리 중 중단되었습니다. 중복 등록을 막기 위해 자동 재시도를 중지했습니다. 등록된 항목을 확인한 뒤 이 작업을 취소하고 새 미리보기를 진행해 주세요.';
                $this->save($job);
                return $this->summary($job);
            }
            $started = false;
            try {
                $path = $this->file($job['fileName']);
                if ($this->stamp($path) !== $job['stamp']) throw new InvalidArgumentException('처리 중 ZIP 파일이 변경되었습니다. 원본 ZIP을 다시 확인해 주세요.');
                $data = $this->packages->serverPackage($path);
                if (!hash_equals($job['manifestHash'], $data['hash'])) throw new InvalidArgumentException('ZIP 목차가 변경되었습니다.');
                $assetPhase = $job['assetIndex'] < count($job['paths']);
                $units = $assetPhase ? [] : $this->packages->serverUnits($data['package']);
                $unit = $assetPhase ? null : $units[$job['unitIndex']];
                $job['current'] = $assetPhase ? $job['paths'][$job['assetIndex']] : $this->unitTitle($unit);
                $job['pending'] = ['kind' => $assetPhase ? 'asset' : 'content', 'index' => $assetPhase ? $job['assetIndex'] : $job['unitIndex']];
                $this->save($job);
                $started = true;
                if ($assetPhase) {
                    $asset = $job['paths'][$job['assetIndex']];
                    $job['assetMap'][$asset] = $this->packages->importServerAsset($path, $data, $asset);
                    ++$job['assetIndex'];
                } else {
                    $result = $this->packages->importServerUnit($unit, $job['options'], $job['assetMap'], $job['expected']);
                    $this->mergeResult($job, $unit, $result);
                    ++$job['unitIndex'];
                }
                $job['pending'] = null;
                $job['error'] = null;
                ++$job['cursor'];
                if ($job['assetIndex'] === count($job['paths']) && $job['unitIndex'] === $job['unitTotal']) {
                    $job['status'] = 'completed';
                    $job['result']['assetsImported'] = count($job['assetMap']);
                }
                $this->save($job);
                if ($job['status'] === 'completed') $this->audit('completed', $job);
            } catch (Throwable $error) {
                $job['status'] = $started ? 'needs_review' : 'failed';
                $job['error'] = $error->getMessage() . ($started ? ' 이미 등록된 항목은 유지됩니다. 항목을 확인한 뒤 작업을 취소하고 새 미리보기를 진행해 주세요.' : ' 파일과 서버 상태를 확인한 뒤 이어하기를 선택해 주세요.');
                $this->save($job);
                $this->audit('failed', $job);
            }
            return $this->summary($job);
        });
    }

    public function control(string $id, string $action): array
    {
        return $this->locked(function () use ($id, $action): array {
            $job = $this->requireJob($id);
            if (!in_array($action, ['pause', 'resume', 'cancel'], true)) throw new InvalidArgumentException('지원하지 않는 작업입니다.');
            if (in_array($job['status'], ['completed', 'cancelled'], true)) return $this->summary($job);
            if ($action === 'resume') {
                if ($job['pending'] !== null || $job['status'] === 'needs_review') throw new InvalidArgumentException('중단된 항목을 확인해야 합니다. 이 작업은 자동으로 재시도할 수 없습니다.');
                $job['status'] = 'running';
                $job['error'] = null;
            } elseif ($action === 'cancel') {
                $job['status'] = 'cancelled';
            } elseif ($job['status'] === 'running' && $job['pending'] === null) {
                $job['status'] = 'paused';
            }
            ++$job['cursor'];
            $this->save($job);
            $this->audit($action, $job);
            return $this->summary($job);
        });
    }

    private function configuredDirectory(): string
    {
        if (defined('READCONTENTS_LIBRARY_IMPORT_DIR')) return (string) READCONTENTS_LIBRARY_IMPORT_DIR;
        $webRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        return $webRoot === '' ? '' : dirname(rtrim($webRoot, '/\\')) . '/readcontents-imports';
    }

    private function directory(): string
    {
        $path = $this->configuredDirectory();
        if ($path === '' || str_contains($path, "\0") || !preg_match('~^(?:[a-zA-Z]:[/\\\\]|/)~', $path)) {
            throw new InvalidArgumentException('wp-config.php에 웹 공개 폴더 밖의 READCONTENTS_LIBRARY_IMPORT_DIR 절대 경로를 설정해 주세요.');
        }
        if (is_link($path)) throw new InvalidArgumentException('가져오기 폴더는 심볼릭 링크일 수 없습니다.');
        $resolved = realpath($path);
        if ($resolved === false) {
            $parent = realpath(dirname($path));
            if ($parent === false) throw new InvalidArgumentException('서버에 가져오기 폴더를 먼저 만들어 주세요: ' . $path);
            $resolved = $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        $normalized = $this->normalize($resolved);
        if ($normalized === $this->normalize(dirname($resolved))) throw new InvalidArgumentException('파일시스템 루트는 가져오기 폴더로 사용할 수 없습니다.');
        foreach ([ABSPATH, defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH] as $publicRoot) {
            $root = realpath((string) $publicRoot);
            if ($root !== false && ($normalized === $this->normalize($root) || str_starts_with($normalized, $this->normalize($root) . '/'))) {
                throw new InvalidArgumentException('ZIP은 웹에 공개되지 않는 폴더에 보관해야 합니다. READCONTENTS_LIBRARY_IMPORT_DIR를 웹 루트 밖으로 설정해 주세요.');
            }
        }
        if (!is_dir($resolved) && !wp_mkdir_p($resolved)) throw new InvalidArgumentException('가져오기 폴더를 만들 수 없습니다. FTP로 폴더를 만들고 PHP 읽기·쓰기 권한을 확인해 주세요: ' . $path);
        if (!is_readable($resolved) || !is_writable($resolved)) throw new InvalidArgumentException('가져오기 폴더에 PHP 읽기·쓰기 권한이 필요합니다.');
        return $resolved;
    }

    private function file(string $name): string
    {
        if ($name === '' || strlen($name) > 255 || strpbrk($name, "/\\:\0") !== false || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new InvalidArgumentException('목록에서 ZIP 파일을 선택해 주세요.');
        }
        $directory = $this->directory();
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        $resolved = realpath($path);
        if (is_link($path) || $resolved === false || $this->normalize(dirname($resolved)) !== $this->normalize($directory) || !is_file($resolved) || !is_readable($resolved)) {
            throw new InvalidArgumentException('지정 폴더에서 읽을 수 있는 ZIP 파일을 찾지 못했습니다.');
        }
        return $resolved;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    private function stamp(string $path): array
    {
        clearstatcache(true, $path);
        $stat = stat($path);
        if (!$stat || $stat['size'] === 0) throw new InvalidArgumentException('ZIP 파일이 비어 있거나 읽을 수 없습니다.');
        return ['path' => $path, 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime']];
    }

    private function locked(callable $callback): array
    {
        $path = $this->directory() . '/.readcontents-job-' . (int) get_current_blog_id() . '.lock';
        if (is_link($path)) throw new InvalidArgumentException('가져오기 잠금 파일이 올바르지 않습니다.');
        $handle = fopen($path, 'c');
        if (!$handle) throw new RuntimeException('가져오기 잠금 파일을 만들지 못했습니다.');
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) throw new InvalidArgumentException('이전 요청이 처리 중입니다. 잠시 뒤 진행 상황을 새로고침해 주세요.');
            try { return $callback(); } finally { flock($handle, LOCK_UN); }
        } finally { fclose($handle); }
    }

    private function load(): ?array
    {
        wp_cache_delete(self::OPTION, 'options');
        $job = get_option(self::OPTION, null);
        return is_array($job) ? $job : null;
    }

    private function save(array &$job): void
    {
        $job['updated'] = time();
        if (!update_option(self::OPTION, $job, false) && get_option(self::OPTION) !== $job) {
            throw new RuntimeException('진행 상태를 저장하지 못했습니다. 데이터베이스 연결을 확인해 주세요.');
        }
    }

    private function requireJob(string $id): array
    {
        $job = $this->load();
        if (!$job || !hash_equals($job['id'], $id)) throw new InvalidArgumentException('가져오기 작업을 찾지 못했습니다. 진행 상황을 새로고침해 주세요.');
        $this->assertOwner($job);
        return $job;
    }

    private function assertOwner(array $job): void
    {
        if ($job['owner'] !== get_current_user_id()) throw new InvalidArgumentException('이 작업을 시작한 관리자만 이어하기와 취소를 할 수 있습니다.');
    }

    private function summary(?array $job): ?array
    {
        if (!$job) return null;
        return array_intersect_key($job, array_flip(['id', 'fileName', 'status', 'cursor', 'error', 'current', 'updated'])) + [
            'canControl' => $job['owner'] === get_current_user_id(),
            'assets' => ['done' => $job['assetIndex'], 'total' => count($job['paths'])],
            'content' => ['done' => $job['unitIndex'], 'total' => $job['unitTotal']],
            'result' => $job['status'] === 'completed' ? $job['result'] : null,
        ];
    }

    private function unitTitle(array $unit): string
    {
        $chapter = $unit['tree']['chapters'][0] ?? null;
        $section = $chapter['sections'][0] ?? null;
        return (string) ($section['lessons'][0]['title'] ?? $section['section']['title'] ?? $chapter['chapter']['title'] ?? $unit['tree']['topic']['title']);
    }

    private function mergeResult(array &$job, array $unit, array $result): void
    {
        if ($job['result'] === null) {
            $zero = ['topics' => 0, 'chapters' => 0, 'sections' => 0, 'lessons' => 0];
            $job['result'] = ['topic' => $result['topic'], 'idMap' => [], 'created' => $zero, 'reused' => $zero, 'replaced' => $zero, 'skipped' => $zero];
        }
        $chapter = $unit['tree']['chapters'][0] ?? null;
        $section = $chapter['sections'][0] ?? null;
        foreach (['topics' => $unit['tree']['topic'], 'chapters' => $chapter['chapter'] ?? null, 'sections' => $section['section'] ?? null, 'lessons' => $section['lessons'][0] ?? null] as $type => $node) {
            if ($node === null || isset($job['seen'][(string) $node['id']])) continue;
            $job['seen'][(string) $node['id']] = true;
            foreach (['created', 'reused', 'replaced', 'skipped'] as $kind) {
                if (($result[$kind][$type] ?? 0) > 0) ++$job['result'][$kind][$type];
            }
        }
        foreach ($result['idMap'] as $from => $to) $job['result']['idMap'][$from] = $to;
    }

    private function audit(string $event, array $job): void
    {
        if (function_exists('oj_log_audit_event')) {
            oj_log_audit_event('readcontents_server_import_' . $event, '서버 ZIP 가져오기: ' . $event, ['job_id' => $job['id'], 'file' => $job['fileName'], 'status' => $job['status']], $event === 'failed' ? 'warning' : 'info');
        }
    }
}

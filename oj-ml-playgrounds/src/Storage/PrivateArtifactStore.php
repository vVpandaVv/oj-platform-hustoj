<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Storage;

use OJML\Playgrounds\Domain\Problem;

final class PrivateArtifactStore
{
    private const MIME = 'application/vnd.ojml.mlpx+zip';
    private const COPY_CHUNK_BYTES = 1048576;

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $this->initializeRoot($root ?? self::configuredRoot());
    }

    public static function configuredRoot(): string
    {
        if (defined('OJ_ML_PLAYGROUNDS_PRIVATE_DIR')) {
            return (string) constant('OJ_ML_PLAYGROUNDS_PRIVATE_DIR');
        }
        $wordpressRoot = realpath(ABSPATH) ?: rtrim(ABSPATH, '/\\');
        $default = dirname($wordpressRoot) . DIRECTORY_SEPARATOR . 'oj-ml-playgrounds-private';
        return (string) apply_filters('oj_ml_playgrounds_private_storage_dir', $default);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function putUploadedFile(string $temporaryPath, int $maxBytes): StoredArtifact
    {
        if (!is_readable($temporaryPath) || !is_file($temporaryPath)) {
            throw new Problem('OJ_ML_UPLOAD_UNREADABLE', '업로드 파일을 읽을 수 없습니다.', 400);
        }
        $stream = fopen($temporaryPath, 'rb');
        if ($stream === false) {
            throw new Problem('OJ_ML_UPLOAD_UNREADABLE', '업로드 파일을 읽을 수 없습니다.', 400);
        }
        try {
            return $this->putStream($stream, $maxBytes);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $input */
    public function putStream($input, int $maxBytes): StoredArtifact
    {
        if (!is_resource($input)) {
            throw new Problem('OJ_ML_UPLOAD_UNREADABLE', '업로드 스트림이 올바르지 않습니다.', 400);
        }
        if ($maxBytes < 1) {
            throw new Problem('OJ_ML_STORAGE_CONFIGURATION_INVALID', '제출 용량 설정이 올바르지 않습니다.', 500);
        }

        $relativeDirectory = 'artifacts/' . gmdate('Y/m');
        $directory = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            throw new Problem('OJ_ML_PRIVATE_STORAGE_UNAVAILABLE', '비공개 저장소를 만들 수 없습니다.', 503);
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || !$this->isWithin($resolvedDirectory, $this->root)) {
            throw new Problem('OJ_ML_PRIVATE_STORAGE_UNSAFE', '비공개 저장소 경로가 안전하지 않습니다.', 503);
        }
        $permissionPath = $resolvedDirectory;
        while ($this->isWithin($permissionPath, $this->root)) {
            @chmod($permissionPath, 0750);
            if ($permissionPath === $this->root) {
                break;
            }
            $parent = dirname($permissionPath);
            if ($parent === $permissionPath) {
                break;
            }
            $permissionPath = $parent;
        }

        try {
            $randomName = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            throw new Problem('OJ_ML_RANDOM_SOURCE_UNAVAILABLE', '안전한 파일 이름을 만들 수 없습니다.', 503);
        }
        $storageKey = $relativeDirectory . '/' . $randomName . '.mlpx';
        $finalPath = $this->pathFor($storageKey);
        $partialPath = $finalPath . '.part';
        $output = fopen($partialPath, 'x+b');
        if ($output === false) {
            throw new Problem('OJ_ML_PRIVATE_STORAGE_UNAVAILABLE', '제출 파일을 저장할 수 없습니다.', 503);
        }
        @chmod($partialPath, 0600);

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, self::COPY_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new Problem('OJ_ML_UPLOAD_READ_FAILED', '업로드 파일을 읽는 중 오류가 발생했습니다.', 400);
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    throw new Problem('OJ_ML_ARTIFACT_TOO_LARGE', '제출 파일이 허용 용량을 초과했습니다.', 413, ['maxBytes' => $maxBytes]);
                }
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new Problem('OJ_ML_PRIVATE_STORAGE_WRITE_FAILED', '제출 파일을 저장하지 못했습니다.', 503);
                    }
                    $offset += $written;
                }
            }
            if ($bytes === 0) {
                throw new Problem('OJ_ML_ARTIFACT_EMPTY', '빈 파일은 제출할 수 없습니다.', 400);
            }
            if (!fflush($output)) {
                throw new Problem('OJ_ML_PRIVATE_STORAGE_WRITE_FAILED', '제출 파일을 저장하지 못했습니다.', 503);
            }
        } catch (\Throwable $throwable) {
            fclose($output);
            @unlink($partialPath);
            throw $throwable;
        }
        fclose($output);

        if (!rename($partialPath, $finalPath)) {
            @unlink($partialPath);
            throw new Problem('OJ_ML_PRIVATE_STORAGE_WRITE_FAILED', '제출 파일을 확정하지 못했습니다.', 503);
        }
        @chmod($finalPath, 0640);
        return new StoredArtifact(wp_generate_uuid4(), $storageKey, hash_final($hash), $bytes, self::MIME);
    }

    public function pathFor(string $storageKey): string
    {
        if (!preg_match('~\Aartifacts/\d{4}/\d{2}/[a-f0-9]{32}\.mlpx\z~D', $storageKey)) {
            throw new Problem('OJ_ML_STORAGE_KEY_INVALID', '저장소 키가 올바르지 않습니다.', 500);
        }
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);
        $parent = realpath(dirname($path));
        if ($parent === false || !$this->isWithin($parent, $this->root)) {
            throw new Problem('OJ_ML_PRIVATE_STORAGE_UNSAFE', '비공개 저장소 경로가 안전하지 않습니다.', 503);
        }
        return $path;
    }

    /** @return resource */
    public function open(string $storageKey)
    {
        $path = $this->pathFor($storageKey);
        if (is_link($path)) {
            throw new Problem('OJ_ML_ARTIFACT_UNSAFE', '제출 파일 경로가 안전하지 않습니다.', 503);
        }
        $resolved = realpath($path);
        if ($resolved === false || !$this->isWithin($resolved, $this->root) || !is_file($resolved)) {
            throw new Problem('OJ_ML_ARTIFACT_NOT_FOUND', '제출 파일을 찾을 수 없습니다.', 404);
        }
        $stream = fopen($resolved, 'rb');
        if ($stream === false) {
            throw new Problem('OJ_ML_ARTIFACT_NOT_FOUND', '제출 파일을 찾을 수 없습니다.', 404);
        }
        $stat = fstat($stream);
        $mode = is_array($stat) && isset($stat['mode']) ? ((int) $stat['mode'] & 0170000) : 0;
        if (!is_array($stat) || $mode !== 0100000) {
            fclose($stream);
            throw new Problem('OJ_ML_ARTIFACT_UNSAFE', '제출 파일 형식이 안전하지 않습니다.', 503);
        }
        return $stream;
    }

    public function deleteOrphan(string $storageKey): void
    {
        $path = $this->pathFor($storageKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function initializeRoot(string $configured): string
    {
        if ($configured === '' || str_contains($configured, "\0")) {
            throw new Problem('OJ_ML_STORAGE_CONFIGURATION_INVALID', '비공개 저장소 경로가 설정되지 않았습니다.', 503);
        }
        if (!is_dir($configured) && !wp_mkdir_p($configured)) {
            throw new Problem('OJ_ML_PRIVATE_STORAGE_UNAVAILABLE', '비공개 저장소를 만들 수 없습니다.', 503);
        }
        $root = realpath($configured);
        $wordpressRoot = realpath(ABSPATH);
        if ($root === false || $wordpressRoot === false) {
            throw new Problem('OJ_ML_STORAGE_CONFIGURATION_INVALID', '비공개 저장소 경로를 확인할 수 없습니다.', 503);
        }
        $forbiddenRoots = [$wordpressRoot];
        if (isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
            $documentRoot = realpath($_SERVER['DOCUMENT_ROOT']);
            if (is_string($documentRoot)) {
                $forbiddenRoots[] = $documentRoot;
            }
        }
        foreach ($forbiddenRoots as $forbiddenRoot) {
            if ($this->isWithin($root, $forbiddenRoot)) {
                throw new Problem(
                    'OJ_ML_PRIVATE_STORAGE_INSIDE_WEBROOT',
                    '비공개 저장소는 웹 문서 루트 밖에 설정해야 합니다.',
                    503
                );
            }
        }
        if (!is_writable($root)) {
            throw new Problem('OJ_ML_PRIVATE_STORAGE_UNAVAILABLE', '비공개 저장소에 쓸 수 없습니다.', 503);
        }
        @chmod($root, 0750);
        return rtrim($root, '/\\');
    }

    private function isWithin(string $candidate, string $root): bool
    {
        $candidate = str_replace('\\', '/', rtrim($candidate, '/\\'));
        $root = str_replace('\\', '/', rtrim($root, '/\\'));
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }
        return $candidate === $root || str_starts_with($candidate, $root . '/');
    }
}


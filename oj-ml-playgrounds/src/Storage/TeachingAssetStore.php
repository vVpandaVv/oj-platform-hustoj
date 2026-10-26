<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Storage;

use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Domain\TeachingDataset;
use Throwable;

/** No public uploads URL; kept outside the submission reconciler's artifacts namespace. */
final class TeachingAssetStore
{
    public function __construct(private readonly PrivateArtifactStore $private) {}

    /** Count retained crash leftovers too; metadata alone must not bypass the global cap. */
    public function usage(): array
    {
        $count = 0; $bytes = 0;
        foreach (new \DirectoryIterator($this->directory()) as $file) {
            if ($file->isDot()) { continue; }
            if ($file->isLink() || !$file->isFile()) { throw new Problem('OJ_ML_TEACHING_ASSET_UNSAFE', '수업 자료 저장소에 확인이 필요한 경로가 있습니다.', 503); }
            $count++; $bytes += $file->getSize();
            if ($count > 200 || $bytes > 209715200) {
                throw new Problem('OJ_ML_DATASET_QUOTA_EXCEEDED', '수업 자료 저장소의 보관 한도를 초과했습니다. 미등록 보관 파일을 포함하여 관리자 점검이 필요합니다.', 413);
            }
        }
        return ['count' => $count, 'bytes' => $bytes];
    }

    /** @return array{storageKey:string,sha256:string,bytes:int} */
    public function put(string $content): array
    {
        $size = strlen($content);
        if ($size < 1 || $size > TeachingDataset::MAX_BYTES) { throw new Problem('OJ_ML_DATASET_SIZE_INVALID', 'CSV 크기가 허용 범위를 벗어납니다.', 413); }
        $directory = $this->directory();
        $free = @disk_free_space($directory);
        if ($free !== false && $free < $size + 16777216) { throw new Problem('OJ_ML_PRIVATE_STORAGE_FULL', '비공개 저장소의 여유 공간이 부족합니다.', 503); }
        $key = 'teaching-assets/' . bin2hex(random_bytes(16)) . '.csv';
        $path = $this->path($key);
        $part = $path . '.part';
        $stream = @fopen($part, 'x+b');
        if ($stream === false) { throw new Problem('OJ_ML_TEACHING_ASSET_WRITE_FAILED', '수업 자료를 저장하지 못했습니다.', 503); }
        @chmod($part, 0640);
        try {
            $written = 0;
            while ($written < $size) {
                $count = fwrite($stream, substr($content, $written, 1048576));
                if ($count === false || $count === 0) { throw new Problem('OJ_ML_TEACHING_ASSET_WRITE_FAILED', '수업 자료 저장이 중단되었습니다.', 503); }
                $written += $count;
            }
            if (!fflush($stream)) { throw new Problem('OJ_ML_TEACHING_ASSET_WRITE_FAILED', '수업 자료 저장이 중단되었습니다.', 503); }
            fclose($stream); $stream = null;
            if (file_exists($path) || is_link($path) || !@rename($part, $path)) { throw new Problem('OJ_ML_TEACHING_ASSET_WRITE_FAILED', '수업 자료 저장을 완료하지 못했습니다.', 503); }
            $metadata = ['storageKey' => $key, 'sha256' => hash('sha256', $content), 'bytes' => $size];
            $this->read($metadata);
            return $metadata;
        } catch (Throwable $error) {
            if (is_resource($stream)) { fclose($stream); }
            if (is_file($part) && !is_link($part)) { @unlink($part); }
            if (is_file($path) && !is_link($path)) { @unlink($path); }
            throw $error;
        }
    }

    /** Verifies the exact bytes returned, avoiding a hash/read race. @param array<string,mixed> $metadata */
    public function read(array $metadata): string
    {
        $key = $metadata['storageKey'] ?? null; $bytes = $metadata['bytes'] ?? null; $hash = $metadata['sha256'] ?? null;
        if (!is_string($key) || !is_int($bytes) || $bytes < 1 || $bytes > TeachingDataset::MAX_BYTES
            || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new Problem('OJ_ML_TEACHING_ASSET_INVALID', '수업 자료의 저장 정보를 확인할 수 없습니다.', 503);
        }
        $path = $this->path($key);
        if (is_link($path) || !is_file($path)) { throw new Problem('OJ_ML_TEACHING_ASSET_MISSING', '보관된 수업 자료를 찾을 수 없습니다.', 503); }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new Problem('OJ_ML_TEACHING_ASSET_MISSING', '수업 자료를 읽을 수 없습니다.', 503); }
        try {
            $stat = fstat($stream);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $bytes) {
                throw new Problem('OJ_ML_TEACHING_ASSET_INTEGRITY', '수업 자료의 크기 또는 무결성이 일치하지 않습니다.', 503);
            }
            $content = stream_get_contents($stream, TeachingDataset::MAX_BYTES + 1);
            if (!is_string($content) || strlen($content) !== $bytes || !hash_equals($hash, hash('sha256', $content))) {
                throw new Problem('OJ_ML_TEACHING_ASSET_INTEGRITY', '수업 자료의 무결성이 일치하지 않습니다.', 503);
            }
            return $content;
        } finally { fclose($stream); }
    }

    /** Only call for a just-created file whose metadata was not committed. */
    public function discardNew(string $key): void
    {
        $path = $this->path($key);
        if (is_link($path)) { throw new Problem('OJ_ML_TEACHING_ASSET_UNSAFE', '수업 자료 경로가 안전하지 않습니다.', 503); }
        if (is_file($path) && !@unlink($path)) { throw new Problem('OJ_ML_TEACHING_ASSET_CLEANUP_FAILED', '미등록 수업 자료 정리가 필요합니다.', 503); }
    }

    private function directory(): string
    {
        $root = $this->private->root();
        $directory = $root . DIRECTORY_SEPARATOR . 'teaching-assets';
        $created = !is_dir($directory);
        if (is_link($directory) || (!is_dir($directory) && !wp_mkdir_p($directory))) {
            throw new Problem('OJ_ML_TEACHING_ASSET_UNSAFE', '수업 자료 저장소를 안전하게 사용할 수 없습니다.', 503);
        }
        $real = realpath($directory);
        if ($real === false || $this->normalized($real) !== $this->normalized($directory)) {
            throw new Problem('OJ_ML_TEACHING_ASSET_UNSAFE', '수업 자료 저장소 경로가 안전하지 않습니다.', 503);
        }
        if ($created) { @chmod($real, 0750); }
        return $real;
    }

    private function path(string $key): string
    {
        if (preg_match('#^teaching-assets/[a-f0-9]{32}\.csv$#D', $key) !== 1) { throw new Problem('OJ_ML_TEACHING_ASSET_UNSAFE', '수업 자료 경로가 안전하지 않습니다.', 503); }
        return $this->directory() . DIRECTORY_SEPARATOR . substr($key, strlen('teaching-assets/'));
    }

    private function normalized(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}

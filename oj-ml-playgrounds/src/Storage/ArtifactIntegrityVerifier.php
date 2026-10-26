<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Storage;

use OJML\Playgrounds\Domain\Problem;

final class ArtifactIntegrityVerifier
{
    public const HARD_MAX_BYTES = 262144000;

    private const CHUNK_BYTES = 1048576;

    /**
     * Verify an already protected source stream and optionally copy the exact
     * verified bytes into a private snapshot stream.
     *
     * @param resource $source
     * @param resource|null $snapshot
     */
    public function verify($source, int $expectedBytes, string $expectedHash, $snapshot = null): void
    {
        $expectedHash = strtolower($expectedHash);
        if (
            !is_resource($source)
            || ($snapshot !== null && !is_resource($snapshot))
            || $expectedBytes < 1
            || $expectedBytes > self::HARD_MAX_BYTES
            || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
        ) {
            throw new Problem('OJ_ML_ARTIFACT_RECORD_INVALID', '제출 파일 기록이 올바르지 않습니다.', 500);
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        while (!feof($source)) {
            $chunk = fread($source, self::CHUNK_BYTES);
            if ($chunk === false) {
                throw new Problem('OJ_ML_ARTIFACT_UNREADABLE', '제출 파일을 읽을 수 없습니다.', 500);
            }
            if ($chunk === '') {
                continue;
            }
            $bytes += strlen($chunk);
            if ($bytes > $expectedBytes) {
                throw new Problem('OJ_ML_ARTIFACT_INTEGRITY_FAILED', '제출 파일 무결성을 확인하지 못했습니다.', 409);
            }
            hash_update($hash, $chunk);
            if ($snapshot !== null) {
                $this->writeAll($snapshot, $chunk);
            }
        }

        if ($bytes !== $expectedBytes || !hash_equals($expectedHash, hash_final($hash))) {
            throw new Problem('OJ_ML_ARTIFACT_INTEGRITY_FAILED', '제출 파일 무결성을 확인하지 못했습니다.', 409);
        }
        if (fseek($source, 0) !== 0) {
            throw new Problem('OJ_ML_ARTIFACT_UNREADABLE', '제출 파일을 다시 읽을 수 없습니다.', 500);
        }
        if ($snapshot !== null) {
            if (!fflush($snapshot) || fseek($snapshot, 0) !== 0) {
                throw new Problem('OJ_ML_PREVIEW_SNAPSHOT_FAILED', '바로보기용 안전 복사본을 준비하지 못했습니다.', 503);
            }
        }
    }

    /** @param resource $stream */
    private function writeAll($stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written < 1) {
                throw new Problem('OJ_ML_PREVIEW_SNAPSHOT_FAILED', '바로보기용 안전 복사본을 준비하지 못했습니다.', 503);
            }
            $offset += $written;
        }
    }
}

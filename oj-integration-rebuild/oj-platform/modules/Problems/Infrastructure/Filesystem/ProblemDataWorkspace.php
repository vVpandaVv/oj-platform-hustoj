<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;
use RuntimeException;
use Throwable;

final class ProblemDataWorkspace
{
    public function __construct(private readonly ProblemDataRootProvider $roots)
    {
    }

    public function directory(ProblemId $problemId, bool $create): string
    {
        $root = $this->roots->root();
        $directory = $root . DIRECTORY_SEPARATOR . $problemId->value();
        if (! is_dir($directory) && $create) {
            if (! mkdir($directory, 0750, false) && ! is_dir($directory)) {
                throw new RuntimeException('문제 테스트 데이터 폴더를 만들지 못했습니다.');
            }
        }
        if (is_link($directory)) {
            throw new DomainException('심볼릭 링크 문제 폴더는 사용할 수 없습니다.');
        }
        if (is_dir($directory)) {
            $realDirectory = realpath($directory);
            $realRoot = realpath($root);
            if (
                $realDirectory === false
                || $realRoot === false
                || dirname($realDirectory) !== $realRoot
            ) {
                throw new DomainException('허용된 문제 폴더를 벗어날 수 없습니다.');
            }
        }

        return $directory;
    }

    public function path(
        ProblemId $problemId,
        string $fileName,
        bool $createDirectory = false
    ): string {
        $this->assertSafeFileName($fileName);

        return $this->directory($problemId, $createDirectory)
            . DIRECTORY_SEPARATOR . $fileName;
    }

    public function assertSafeFileName(string $fileName): void
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $fileName) !== 1
            || str_starts_with($fileName, '.')
        ) {
            throw new DomainException('테스트 데이터 파일 이름이 올바르지 않습니다.');
        }
    }

    public function currentHash(
        ProblemId $problemId,
        string $fileName
    ): ?string {
        $path = $this->path($problemId, $fileName);
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $hash = hash_file('sha256', $path);

        return is_string($hash) ? $hash : null;
    }

    public function write(
        ProblemId $problemId,
        string $fileName,
        string $contents,
        ?string $expectedSha256,
        int $mode = 0640
    ): void {
        $this->withExclusiveLock(
            $problemId,
            function () use (
                $problemId,
                $fileName,
                $contents,
                $expectedSha256,
                $mode
            ): void {
                $path = $this->path($problemId, $fileName, true);
                $this->assertExpectedHash($path, $expectedSha256);
                $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
                try {
                    $written = file_put_contents($temporary, $contents, LOCK_EX);
                    if ($written !== strlen($contents)) {
                        throw new RuntimeException('테스트 데이터 임시 파일을 저장하지 못했습니다.');
                    }
                    if (! chmod($temporary, $mode)) {
                        throw new RuntimeException('테스트 데이터 파일 권한을 설정하지 못했습니다.');
                    }
                    if (! rename($temporary, $path)) {
                        throw new RuntimeException('테스트 데이터 파일을 교체하지 못했습니다.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
        );
    }

    public function rename(
        ProblemId $problemId,
        string $fileName,
        string $newFileName,
        string $expectedSha256
    ): void {
        $this->assertSafeFileName($fileName);
        $this->assertSafeFileName($newFileName);
        if ($fileName === $newFileName) {
            throw new DomainException('현재 이름과 다른 파일 이름을 입력해 주세요.');
        }
        $this->withExclusiveLock(
            $problemId,
            function () use (
                $problemId,
                $fileName,
                $newFileName,
                $expectedSha256
            ): void {
                $source = $this->path($problemId, $fileName);
                $target = $this->path($problemId, $newFileName);
                $this->assertExpectedHash($source, $expectedSha256);
                if (is_file($target) || is_link($target)) {
                    throw new DomainException('같은 이름의 파일이 이미 있습니다.');
                }
                if (! rename($source, $target)) {
                    throw new RuntimeException('테스트 데이터 파일 이름을 변경하지 못했습니다.');
                }
            }
        );
    }

    /**
     * @param array<string, string> $expectedHashesByFile
     */
    public function deleteMany(
        ProblemId $problemId,
        array $expectedHashesByFile
    ): void {
        if ($expectedHashesByFile === [] || count($expectedHashesByFile) > 200) {
            throw new DomainException('삭제할 파일은 1개부터 200개까지 선택할 수 있습니다.');
        }
        $this->withExclusiveLock(
            $problemId,
            function () use ($problemId, $expectedHashesByFile): void {
                $paths = [];
                foreach ($expectedHashesByFile as $fileName => $expectedSha256) {
                    if (! is_string($fileName) || ! is_string($expectedSha256)) {
                        throw new DomainException('삭제할 파일 정보가 올바르지 않습니다.');
                    }
                    $path = $this->path($problemId, $fileName);
                    $this->assertExpectedHash($path, $expectedSha256);
                    $paths[$fileName] = $path;
                }
                $token = bin2hex(random_bytes(8));
                $staged = [];
                try {
                    foreach ($paths as $fileName => $path) {
                        $temporary = dirname($path) . DIRECTORY_SEPARATOR
                            . '.deleted-' . $token . '-' . $fileName;
                        if (! rename($path, $temporary)) {
                            throw new RuntimeException('삭제할 파일을 준비하지 못했습니다.');
                        }
                        $staged[$fileName] = $temporary;
                    }
                } catch (Throwable $error) {
                    foreach ($staged as $fileName => $temporary) {
                        $original = $paths[$fileName];
                        if (is_file($temporary) && ! is_file($original)) {
                            rename($temporary, $original);
                        }
                    }
                    throw $error;
                }
                foreach ($staged as $temporary) {
                    if (! unlink($temporary)) {
                        throw new RuntimeException('테스트 데이터 파일을 삭제하지 못했습니다.');
                    }
                }
            }
        );
    }

    /**
     * @param array<string, string> $sourcePathsByFile
     * @param array<string, string|null> $expectedHashesByFile
     * @param array<string, int> $modesByFile
     * @param array<string, string> $dependencyHashesByFile
     *
     * @return array<string, string>
     */
    public function replaceMany(
        ProblemId $problemId,
        array $sourcePathsByFile,
        array $expectedHashesByFile,
        array $modesByFile = [],
        array $dependencyHashesByFile = []
    ): array {
        if (
            $sourcePathsByFile === []
            || count($sourcePathsByFile) > 200
            || array_keys($sourcePathsByFile) !== array_keys($expectedHashesByFile)
        ) {
            throw new DomainException('교체할 테스트 데이터 파일 집합이 올바르지 않습니다.');
        }
        foreach ($sourcePathsByFile as $fileName => $sourcePath) {
            $this->assertSafeFileName($fileName);
            if (
                ! is_string($sourcePath)
                || ! is_file($sourcePath)
                || is_link($sourcePath)
                || ! is_readable($sourcePath)
            ) {
                throw new DomainException('교체할 임시 파일을 읽을 수 없습니다.');
            }
        }

        return $this->withExclusiveLock(
            $problemId,
            function () use (
                $problemId,
                $sourcePathsByFile,
                $expectedHashesByFile,
                $modesByFile,
                $dependencyHashesByFile
            ): array {
                $directory = $this->directory($problemId, true);
                foreach ($dependencyHashesByFile as $fileName => $expectedHash) {
                    if (! is_string($fileName) || ! is_string($expectedHash)) {
                        throw new DomainException('참조 파일 해시가 올바르지 않습니다.');
                    }
                    $this->assertExpectedHash(
                        $this->path($problemId, $fileName),
                        $expectedHash
                    );
                }
                $targets = [];
                foreach ($expectedHashesByFile as $fileName => $expectedHash) {
                    if ($expectedHash !== null && ! is_string($expectedHash)) {
                        throw new DomainException('원본 파일 해시가 올바르지 않습니다.');
                    }
                    $target = $this->path($problemId, $fileName);
                    $this->assertExpectedHash($target, $expectedHash);
                    $targets[$fileName] = $target;
                }

                $token = bin2hex(random_bytes(8));
                $temporaryFiles = [];
                $backups = [];
                $installed = [];
                try {
                    foreach ($sourcePathsByFile as $fileName => $sourcePath) {
                        $temporary = $directory . DIRECTORY_SEPARATOR
                            . '.install-' . $token . '-' . $fileName;
                        if (! copy($sourcePath, $temporary)) {
                            throw new RuntimeException('적용할 파일을 임시 영역에 복사하지 못했습니다.');
                        }
                        $mode = $modesByFile[$fileName] ?? 0640;
                        if (
                            ! is_int($mode)
                            || $mode < 0600
                            || $mode > 0770
                            || ! chmod($temporary, $mode)
                        ) {
                            throw new RuntimeException('적용할 파일 권한을 설정하지 못했습니다.');
                        }
                        $temporaryFiles[$fileName] = $temporary;
                    }
                    foreach ($targets as $fileName => $target) {
                        if (is_file($target)) {
                            $backup = $directory . DIRECTORY_SEPARATOR
                                . '.backup-' . $token . '-' . $fileName;
                            if (! rename($target, $backup)) {
                                throw new RuntimeException('기존 파일을 백업하지 못했습니다.');
                            }
                            $backups[$fileName] = $backup;
                        }
                    }
                    foreach ($targets as $fileName => $target) {
                        if (! rename($temporaryFiles[$fileName], $target)) {
                            throw new RuntimeException('검증한 파일을 적용하지 못했습니다.');
                        }
                        unset($temporaryFiles[$fileName]);
                        $installed[$fileName] = $target;
                    }
                } catch (Throwable $error) {
                    foreach ($installed as $target) {
                        if (is_file($target)) {
                            unlink($target);
                        }
                    }
                    foreach ($backups as $fileName => $backup) {
                        $target = $targets[$fileName];
                        if (is_file($backup) && ! is_file($target)) {
                            rename($backup, $target);
                        }
                    }
                    throw $error;
                } finally {
                    foreach ($temporaryFiles as $temporary) {
                        if (is_file($temporary)) {
                            unlink($temporary);
                        }
                    }
                }
                foreach ($backups as $backup) {
                    if (is_file($backup) && ! unlink($backup)) {
                        throw new RuntimeException('교체한 파일의 백업을 정리하지 못했습니다.');
                    }
                }

                $hashes = [];
                foreach ($targets as $fileName => $target) {
                    $hash = hash_file('sha256', $target);
                    if (! is_string($hash)) {
                        throw new RuntimeException('적용한 파일 해시를 확인하지 못했습니다.');
                    }
                    $hashes[$fileName] = $hash;
                }

                return $hashes;
            }
        );
    }

    public function withExclusiveLock(
        ProblemId $problemId,
        callable $operation
    ): mixed {
        $root = $this->roots->root();
        $lockPath = $root . DIRECTORY_SEPARATOR . '.oj-platform-problem-'
            . $problemId->value() . '.lock';
        $handle = fopen($lockPath, 'c');
        if (! is_resource($handle)) {
            throw new RuntimeException('문제 테스트 데이터 잠금을 만들지 못했습니다.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('문제 테스트 데이터 잠금을 얻지 못했습니다.');
            }

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function assertExpectedHash(
        string $path,
        ?string $expectedSha256
    ): void {
        if ($expectedSha256 === null) {
            if (is_file($path) || is_link($path)) {
                throw new DomainException('같은 이름의 파일이 이미 있습니다.');
            }

            return;
        }
        if (
            preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1
            || ! is_file($path)
            || is_link($path)
        ) {
            throw new DomainException('원본 테스트 데이터 파일을 찾을 수 없습니다.');
        }
        $current = hash_file('sha256', $path);
        if (! is_string($current) || ! hash_equals($expectedSha256, $current)) {
            throw new DomainException(
                '다른 작업에서 파일이 변경되었습니다. 새로고침 후 다시 시도해 주세요.'
            );
        }
    }
}

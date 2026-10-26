<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemFileManager;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDataFile;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;
use RuntimeException;

final class RootOnlyProblemFileManager implements ProblemFileManager
{
    private const MAX_TEXT_BYTES = 16777216;
    /**
     * Non-secret grader settings must stay readable by the judge account, which is
     * not the web server's group.
     */
    private const WORLD_READABLE_FILES = ['image-spj-settings.txt'];
    private const MAX_BINARY_BYTES = 134217728;
    private const TEXT_EXTENSIONS = [
        'in',
        'out',
        'ans',
        'txt',
        'c',
        'cc',
        'cpp',
        'log',
    ];

    public function __construct(private readonly ProblemDataWorkspace $workspace)
    {
    }

    public function files(ProblemId $problemId): array
    {
        $directory = $this->workspace->directory($problemId, false);
        if (! is_dir($directory)) {
            return [];
        }
        $entries = scandir($directory);
        if (! is_array($entries)) {
            throw new RuntimeException('테스트 데이터 파일 목록을 읽지 못했습니다.');
        }

        $files = [];
        foreach ($entries as $entry) {
            if (
                $entry === '.'
                || $entry === '..'
                || str_starts_with($entry, '.')
                || str_contains($entry, '.tmp-')
            ) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (! is_file($path) || is_link($path)) {
                continue;
            }
            $files[] = new ProblemDataFile(
                $entry,
                (int) filesize($path),
                (string) hash_file('sha256', $path),
                gmdate('c', (int) filemtime($path))
            );
        }
        usort(
            $files,
            static fn (ProblemDataFile $left, ProblemDataFile $right): int =>
                strnatcasecmp($left->name(), $right->name())
        );

        return $files;
    }

    public function read(ProblemId $problemId, string $fileName): string
    {
        $this->assertTextFileName($fileName);
        $path = $this->workspace->path($problemId, $fileName);
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new DomainException('테스트 데이터 파일을 찾을 수 없습니다.');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_TEXT_BYTES) {
            throw new DomainException('브라우저에서 편집할 수 있는 파일 크기를 초과했습니다.');
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('테스트 데이터 파일을 읽지 못했습니다.');
        }
        if (preg_match('//u', $contents) !== 1) {
            throw new DomainException('UTF-8 텍스트 파일만 편집할 수 있습니다.');
        }

        return $contents;
    }

    public function readBinary(ProblemId $problemId, string $fileName): string
    {
        $path = $this->workspace->path($problemId, $fileName);
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new DomainException('테스트 데이터 파일을 찾을 수 없습니다.');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BINARY_BYTES) {
            throw new DomainException('다운로드할 수 있는 파일 크기를 초과했습니다.');
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('테스트 데이터 파일을 읽지 못했습니다.');
        }

        return $contents;
    }

    public function write(
        ProblemId $problemId,
        string $fileName,
        string $contents,
        ?string $expectedSha256
    ): void {
        $this->assertTextFileName($fileName);
        if (strlen($contents) > self::MAX_TEXT_BYTES) {
            throw new DomainException('테스트 데이터 파일 크기가 제한을 초과했습니다.');
        }
        if (preg_match('//u', $contents) !== 1) {
            throw new DomainException('테스트 데이터는 UTF-8로 저장해야 합니다.');
        }
        $this->workspace->write(
            $problemId,
            $fileName,
            $contents,
            $expectedSha256,
            in_array($fileName, self::WORLD_READABLE_FILES, true) ? 0644 : 0640
        );
    }

    public function import(
        ProblemId $problemId,
        string $fileName,
        string $temporaryPath
    ): void {
        $this->assertImportFileName($fileName);
        if (
            ! is_file($temporaryPath)
            || is_link($temporaryPath)
            || ! is_readable($temporaryPath)
        ) {
            throw new DomainException('업로드한 파일을 읽을 수 없습니다.');
        }
        $size = filesize($temporaryPath);
        if ($size === false || $size < 1 || $size > self::MAX_BINARY_BYTES) {
            throw new DomainException('업로드 파일 크기가 제한을 초과했습니다.');
        }
        $contents = file_get_contents($temporaryPath);
        if (! is_string($contents)) {
            throw new RuntimeException('업로드한 파일을 읽지 못했습니다.');
        }
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
            if (strlen($contents) > self::MAX_TEXT_BYTES || preg_match('//u', $contents) !== 1) {
                throw new DomainException('업로드할 텍스트 파일은 16MB 이하 UTF-8이어야 합니다.');
            }
        } elseif ($extension === 'zip' && ! str_starts_with($contents, "PK")) {
            throw new DomainException('ZIP 파일 서명이 올바르지 않습니다.');
        }

        $this->workspace->write($problemId, $fileName, $contents, null);
    }

    public function rename(
        ProblemId $problemId,
        string $fileName,
        string $newFileName,
        string $expectedSha256
    ): void {
        $this->workspace->assertSafeFileName($fileName);
        $this->assertImportFileName($newFileName);
        if ($fileName === $newFileName) {
            throw new DomainException('현재 이름과 다른 파일 이름을 입력해 주세요.');
        }
        $this->workspace->rename(
            $problemId,
            $fileName,
            $newFileName,
            $expectedSha256
        );
    }

    public function delete(
        ProblemId $problemId,
        string $fileName,
        string $expectedSha256
    ): void {
        $this->deleteMany($problemId, [$fileName => $expectedSha256]);
    }

    public function deleteMany(
        ProblemId $problemId,
        array $expectedHashesByFile
    ): void {
        $this->workspace->deleteMany($problemId, $expectedHashesByFile);
    }

    public function replaceMany(
        ProblemId $problemId,
        array $contentsByFile,
        array $expectedHashesByFile
    ): array {
        if (
            $contentsByFile === []
            || count($contentsByFile) > 40
            || array_keys($contentsByFile) !== array_keys($expectedHashesByFile)
        ) {
            throw new DomainException('적용할 테스트데이터 파일 집합이 올바르지 않습니다.');
        }
        $totalBytes = 0;
        foreach ($contentsByFile as $fileName => $contents) {
            if (! is_string($fileName) || ! is_string($contents)) {
                throw new DomainException('적용할 테스트데이터 내용이 올바르지 않습니다.');
            }
            $this->assertTextFileName($fileName);
            $bytes = strlen($contents);
            $totalBytes += $bytes;
            if (
                $bytes > 8388608
                || $totalBytes > 16777216
                || preg_match('//u', $contents) !== 1
            ) {
                throw new DomainException(
                    '테스트데이터는 파일당 8MB, 전체 16MB 이하 UTF-8이어야 합니다.'
                );
            }
            $expected = $expectedHashesByFile[$fileName];
            if (
                $expected !== null
                && (
                    ! is_string($expected)
                    || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
                )
            ) {
                throw new DomainException('테스트데이터 원본 해시가 올바르지 않습니다.');
            }
        }

        $directory = $this->workspace->directory($problemId, true);
        $token = bin2hex(random_bytes(8));
        $staged = [];
        try {
            foreach ($contentsByFile as $fileName => $contents) {
                $path = $directory . DIRECTORY_SEPARATOR
                    . '.draft-' . $token . '-' . $fileName;
                $staged[$fileName] = $path;
                $written = file_put_contents($path, $contents, LOCK_EX);
                if ($written !== strlen($contents) || ! chmod($path, 0640)) {
                    throw new RuntimeException(
                        '테스트데이터 적용 파일을 준비하지 못했습니다.'
                    );
                }
            }

            return $this->workspace->replaceMany(
                $problemId,
                $staged,
                $expectedHashesByFile
            );
        } finally {
            foreach ($staged as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function assertTextFileName(string $fileName): void
    {
        $this->workspace->assertSafeFileName($fileName);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (! in_array($extension, self::TEXT_EXTENSIONS, true)) {
            throw new DomainException('허용된 테스트 데이터 텍스트 파일 형식이 아닙니다.');
        }
    }

    private function assertImportFileName(string $fileName): void
    {
        $this->workspace->assertSafeFileName($fileName);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (
            ! in_array($extension, self::TEXT_EXTENSIONS, true)
            && $extension !== 'zip'
        ) {
            throw new DomainException('허용된 테스트 데이터 파일 형식이 아닙니다.');
        }
    }

}

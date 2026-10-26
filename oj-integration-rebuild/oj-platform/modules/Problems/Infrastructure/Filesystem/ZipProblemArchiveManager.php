<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemArchiveManager;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveEntry;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveInspection;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;
use RuntimeException;
use ZipArchive;

final class ZipProblemArchiveManager implements ProblemArchiveManager
{
    private const MAX_ENTRIES = 200;
    private const MAX_ENTRY_BYTES = 16777216;
    private const MAX_TOTAL_BYTES = 134217728;
    private const MAX_COMPRESSION_RATIO = 1000;
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

    public function inspect(
        ProblemId $problemId,
        string $archiveName,
        ?string $expectedArchiveSha256 = null
    ): ProblemArchiveInspection {
        $this->assertZipName($archiveName);
        $this->assertZipSupport();
        $path = $this->workspace->path($problemId, $archiveName);
        $archiveHash = $this->workspace->currentHash($problemId, $archiveName);
        if ($archiveHash === null) {
            throw new DomainException('검사할 ZIP 파일을 찾을 수 없습니다.');
        }
        if (
            $expectedArchiveSha256 !== null
            && ! hash_equals($expectedArchiveSha256, $archiveHash)
        ) {
            throw new DomainException('ZIP 파일이 변경되었습니다. 내용을 다시 확인해 주세요.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new DomainException('ZIP 파일 구조가 올바르지 않습니다.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new DomainException('ZIP 항목은 1개부터 200개까지 허용합니다.');
            }
            $entries = [];
            $seen = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat)) {
                    throw new DomainException('ZIP 항목 정보를 읽지 못했습니다.');
                }
                $name = (string) ($stat['name'] ?? '');
                $this->assertArchiveEntryName($name);
                if ($this->isSymlink($zip, $index)) {
                    throw new DomainException('ZIP의 심볼릭 링크 항목은 허용하지 않습니다.');
                }
                $folded = strtolower($name);
                if (isset($seen[$folded])) {
                    throw new DomainException('ZIP에 대소문자만 다른 중복 파일이 있습니다.');
                }
                $seen[$folded] = true;
                $size = (int) ($stat['size'] ?? -1);
                $compressedSize = (int) ($stat['comp_size'] ?? -1);
                if (
                    $size < 0
                    || $compressedSize < 0
                    || $size > self::MAX_ENTRY_BYTES
                    || (
                        $size > 0
                        && $size > max(1, $compressedSize) * self::MAX_COMPRESSION_RATIO
                    )
                ) {
                    throw new DomainException('ZIP 항목의 크기 또는 압축 비율이 제한을 초과했습니다.');
                }
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new DomainException('ZIP 전체 해제 크기가 128MB를 초과했습니다.');
                }
                $entries[] = new ProblemArchiveEntry(
                    $name,
                    $size,
                    $compressedSize,
                    $this->workspace->currentHash($problemId, $name)
                );
            }

            return new ProblemArchiveInspection(
                $archiveName,
                $archiveHash,
                $entries,
                $total
            );
        } finally {
            $zip->close();
        }
    }

    public function create(
        ProblemId $problemId,
        string $archiveName,
        array $expectedHashesByFile
    ): ProblemArchiveResult {
        $this->assertZipName($archiveName);
        $this->assertZipSupport();
        if (
            $expectedHashesByFile === []
            || count($expectedHashesByFile) > self::MAX_ENTRIES
            || isset($expectedHashesByFile[$archiveName])
        ) {
            throw new DomainException('ZIP으로 묶을 파일 집합이 올바르지 않습니다.');
        }
        $seen = [];
        foreach ($expectedHashesByFile as $fileName => $expectedHash) {
            if (
                ! is_string($fileName)
                || ! is_string($expectedHash)
                || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
            ) {
                throw new DomainException('ZIP 대상 파일 정보가 올바르지 않습니다.');
            }
            $this->assertArchiveEntryName($fileName);
            $folded = strtolower($fileName);
            if (isset($seen[$folded])) {
                throw new DomainException('ZIP 대상에 대소문자만 다른 중복 파일이 있습니다.');
            }
            $seen[$folded] = true;
        }
        $temporaryDirectory = $this->temporaryDirectory();
        $temporary = $temporaryDirectory . DIRECTORY_SEPARATOR . 'archive.zip';
        $total = 0;
        try {
            $this->workspace->withExclusiveLock(
                $problemId,
                function () use (
                    $problemId,
                    $expectedHashesByFile,
                    $temporary,
                    &$total
                ): void {
                    $zip = new ZipArchive();
                    $opened = $zip->open(
                        $temporary,
                        ZipArchive::CREATE | ZipArchive::OVERWRITE
                    );
                    if ($opened !== true) {
                        throw new RuntimeException('ZIP 임시 파일을 만들지 못했습니다.');
                    }
                    try {
                        foreach ($expectedHashesByFile as $fileName => $expectedHash) {
                            if (! is_string($fileName) || ! is_string($expectedHash)) {
                                throw new DomainException('ZIP 대상 파일 정보가 올바르지 않습니다.');
                            }
                            $this->assertArchiveEntryName($fileName);
                            $path = $this->workspace->path($problemId, $fileName);
                            $currentHash = $this->workspace->currentHash(
                                $problemId,
                                $fileName
                            );
                            if (
                                $currentHash === null
                                || ! hash_equals($expectedHash, $currentHash)
                                || is_link($path)
                            ) {
                                throw new DomainException(
                                    'ZIP 대상 파일이 변경되었습니다. 새로고침 후 다시 시도해 주세요.'
                                );
                            }
                            $size = filesize($path);
                            if ($size === false || $size > self::MAX_ENTRY_BYTES) {
                                throw new DomainException('ZIP 대상 파일 크기가 제한을 초과했습니다.');
                            }
                            $total += (int) $size;
                            if ($total > self::MAX_TOTAL_BYTES) {
                                throw new DomainException('ZIP 대상 파일 합계가 128MB를 초과했습니다.');
                            }
                            if (! $zip->addFile($path, $fileName)) {
                                throw new RuntimeException('ZIP에 파일을 추가하지 못했습니다.');
                            }
                        }
                    } finally {
                        if (! $zip->close()) {
                            throw new RuntimeException('ZIP 임시 파일을 완료하지 못했습니다.');
                        }
                    }
                }
            );
            $hashes = $this->workspace->replaceMany(
                $problemId,
                [$archiveName => $temporary],
                [$archiveName => null]
            );

            return new ProblemArchiveResult(
                count($expectedHashesByFile),
                $total,
                $hashes[$archiveName]
            );
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    public function extract(
        ProblemId $problemId,
        string $archiveName,
        string $expectedArchiveSha256,
        array $overwriteHashesByFile
    ): ProblemArchiveResult {
        $inspection = $this->inspect(
            $problemId,
            $archiveName,
            $expectedArchiveSha256
        );
        foreach ($overwriteHashesByFile as $fileName => $hash) {
            if (
                ! is_string($fileName)
                || ! is_string($hash)
                || ($inspection->collisionHashes()[$fileName] ?? null) !== $hash
            ) {
                throw new DomainException('덮어쓸 파일의 확인 정보가 올바르지 않습니다.');
            }
        }

        $temporaryDirectory = $this->temporaryDirectory();
        $sources = [];
        $expectedTargets = [];
        $total = 0;
        try {
            $zip = new ZipArchive();
            $archivePath = $this->workspace->path($problemId, $archiveName);
            $opened = $zip->open($archivePath, ZipArchive::CHECKCONS);
            if ($opened !== true) {
                throw new DomainException('ZIP 파일을 다시 열지 못했습니다.');
            }
            try {
                foreach ($inspection->entries() as $index => $entry) {
                    $collisionHash = $entry->collisionSha256();
                    if (
                        $collisionHash !== null
                        && ! isset($overwriteHashesByFile[$entry->name()])
                    ) {
                        continue;
                    }
                    $stream = $zip->getStream($entry->name());
                    if (! is_resource($stream)) {
                        throw new DomainException('ZIP 항목을 읽지 못했습니다.');
                    }
                    $target = $temporaryDirectory . DIRECTORY_SEPARATOR
                        . sprintf('%03d-', $index) . $entry->name();
                    $output = fopen($target, 'xb');
                    if (! is_resource($output)) {
                        fclose($stream);
                        throw new RuntimeException('ZIP 해제 임시 파일을 만들지 못했습니다.');
                    }
                    $written = 0;
                    try {
                        while (! feof($stream)) {
                            $chunk = fread($stream, 65536);
                            if (! is_string($chunk)) {
                                throw new RuntimeException('ZIP 항목을 읽지 못했습니다.');
                            }
                            $written += strlen($chunk);
                            if ($written > self::MAX_ENTRY_BYTES) {
                                throw new DomainException('ZIP 항목 해제 크기가 제한을 초과했습니다.');
                            }
                            if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                                throw new RuntimeException('ZIP 해제 임시 파일을 기록하지 못했습니다.');
                            }
                        }
                    } finally {
                        fclose($stream);
                        fclose($output);
                    }
                    if (
                        $written !== $entry->uncompressedBytes()
                        || ! $this->isUtf8File($target)
                    ) {
                        throw new DomainException('ZIP 항목의 크기 또는 UTF-8 형식이 올바르지 않습니다.');
                    }
                    $sources[$entry->name()] = $target;
                    $expectedTargets[$entry->name()] = $collisionHash;
                    $total += $written;
                }
            } finally {
                $zip->close();
            }
            if ($sources === []) {
                throw new DomainException('새로 적용하거나 덮어쓸 ZIP 항목이 없습니다.');
            }
            $hashes = $this->workspace->replaceMany(
                $problemId,
                $sources,
                $expectedTargets,
                [],
                [$archiveName => $expectedArchiveSha256]
            );

            return new ProblemArchiveResult(
                count($hashes),
                $total,
                hash('sha256', implode('', array_values($hashes)))
            );
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    private function assertZipSupport(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('서버의 PHP ZIP 확장을 활성화해야 합니다.');
        }
    }

    private function assertZipName(string $archiveName): void
    {
        $this->workspace->assertSafeFileName($archiveName);
        if (strtolower(pathinfo($archiveName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new DomainException('ZIP 파일 이름은 .zip 확장자를 사용해야 합니다.');
        }
    }

    private function assertArchiveEntryName(string $fileName): void
    {
        $this->workspace->assertSafeFileName($fileName);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (! in_array($extension, self::TEXT_EXTENSIONS, true)) {
            throw new DomainException('ZIP에는 허용된 UTF-8 텍스트 파일만 포함할 수 있습니다.');
        }
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $operationsSystem = 0;
        $attributes = 0;
        if (
            ! $zip->getExternalAttributesIndex(
                $index,
                $operationsSystem,
                $attributes,
                ZipArchive::FL_UNCHANGED
            )
        ) {
            return false;
        }
        $mode = ($attributes >> 16) & 0170000;

        return $mode === 0120000;
    }

    private function isUtf8File(string $path): bool
    {
        $contents = file_get_contents($path);

        return is_string($contents) && preg_match('//u', $contents) === 1;
    }

    private function temporaryDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'oj-platform-zip-'
            . bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700, false)) {
            throw new RuntimeException('ZIP 해제 임시 폴더를 만들지 못했습니다.');
        }

        return $directory;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'oj-platform-zip-';
        if (! str_starts_with($directory, $prefix) || ! is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (is_file($path) && ! is_link($path)) {
                    unlink($path);
                }
            }
        }
        rmdir($directory);
    }
}

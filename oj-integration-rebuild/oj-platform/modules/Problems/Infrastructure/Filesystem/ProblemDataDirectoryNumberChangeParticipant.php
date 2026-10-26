<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNumberChangeParticipant;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemNumberMap;
use DomainException;
use RuntimeException;
use Throwable;

final class ProblemDataDirectoryNumberChangeParticipant implements ProblemNumberChangeParticipant
{
    public function __construct(private readonly ProblemDataRootProvider $roots)
    {
    }

    public function key(): string
    {
        return 'files';
    }

    public function inspect(ProblemNumberMap $mapping): array
    {
        $root = $this->roots->root();
        $sourceSet = array_fill_keys($mapping->sourceIds(), true);
        $directoryCount = 0;
        $fileCount = 0;
        foreach ($mapping->pairs() as $source => $target) {
            $sourcePath = $root . DIRECTORY_SEPARATOR . $source;
            $targetPath = $root . DIRECTORY_SEPARATOR . $target;
            if (is_link($sourcePath) || is_link($targetPath)) {
                throw new DomainException('심볼릭 링크 문제 폴더는 번호 변경할 수 없습니다.');
            }
            if (file_exists($targetPath) && ! isset($sourceSet[$target])) {
                throw new DomainException('새 문제 번호의 데이터 폴더가 이미 존재합니다.');
            }
            if (! is_dir($sourcePath)) {
                continue;
            }
            ++$directoryCount;
            $entries = scandir($sourcePath);
            if (! is_array($entries)) {
                throw new RuntimeException('문제 데이터 폴더를 검사하지 못했습니다.');
            }
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    ++$fileCount;
                }
            }
        }

        return [
            'directories' => $directoryCount,
            'files' => $fileCount,
        ];
    }

    public function move(ProblemNumberMap $mapping): void
    {
        $root = $this->roots->root();
        $sourceSet = array_fill_keys($mapping->sourceIds(), true);
        $paths = [];
        foreach ($mapping->pairs() as $source => $target) {
            $sourcePath = $root . DIRECTORY_SEPARATOR . $source;
            $targetPath = $root . DIRECTORY_SEPARATOR . $target;
            if (is_link($sourcePath) || is_link($targetPath)) {
                throw new DomainException('심볼릭 링크 문제 폴더는 번호 변경할 수 없습니다.');
            }
            if (file_exists($targetPath) && ! isset($sourceSet[$target])) {
                throw new DomainException('새 문제 번호의 데이터 폴더가 이미 존재합니다.');
            }
            if (is_dir($sourcePath)) {
                $paths[$sourcePath] = $targetPath;
            }
        }
        $moved = [];
        try {
            foreach ($paths as $sourcePath => $targetPath) {
                if (! rename($sourcePath, $targetPath)) {
                    throw new RuntimeException('문제 데이터 폴더 번호를 변경하지 못했습니다.');
                }
                $moved[$sourcePath] = $targetPath;
            }
        } catch (Throwable $error) {
            foreach (array_reverse($moved, true) as $sourcePath => $targetPath) {
                if (is_dir($targetPath) && ! file_exists($sourcePath)) {
                    rename($targetPath, $sourcePath);
                }
            }
            throw $error;
        }
    }

    public function verifyMoved(ProblemNumberMap $mapping): void
    {
        $root = $this->roots->root();
        foreach ($mapping->sourceIds() as $source) {
            if (file_exists($root . DIRECTORY_SEPARATOR . $source)) {
                throw new RuntimeException('이전 문제 번호의 데이터 폴더가 남아 있습니다.');
            }
        }
    }
}

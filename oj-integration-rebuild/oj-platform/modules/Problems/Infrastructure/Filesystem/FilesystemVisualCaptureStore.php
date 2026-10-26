<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\Filesystem;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDataRootProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\VisualCaptureStore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;
use RuntimeException;

final class FilesystemVisualCaptureStore implements VisualCaptureStore
{
    public const DIRECTORY_NAME = '_capture';
    private const MAX_BYTES = 12582912;
    private const MAX_VERDICT_BYTES = 2048;

    public function __construct(private readonly ProblemDataRootProvider $roots)
    {
    }

    public function directoryPath(): string
    {
        return $this->roots->root() . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME;
    }

    public function directoryExists(): bool
    {
        $directory = $this->directoryPath();

        return is_dir($directory) && ! is_link($directory);
    }

    public function read(ProblemId $problemId): ?string
    {
        $path = $this->candidatePath($problemId);
        if ($path === null) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new DomainException('캡처 후보 파일 크기가 올바르지 않습니다.');
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException(
                '캡처 후보 파일을 읽지 못했습니다. 파일 읽기 권한을 확인해 주세요.'
            );
        }

        return $contents;
    }

    public function delete(ProblemId $problemId): void
    {
        $path = $this->candidatePath($problemId);
        if ($path !== null && ! unlink($path)) {
            throw new RuntimeException(
                '캡처 후보 파일을 지우지 못했습니다. _capture 폴더의 소유자와 권한을 확인해 주세요.'
            );
        }
    }

    public function lastVerdict(ProblemId $problemId): ?array
    {
        if (! $this->directoryExists()) {
            return null;
        }
        $path = $this->directoryPath() . DIRECTORY_SEPARATOR . $problemId->value() . '.last.txt';
        if (is_link($path) || ! is_file($path) || (int) filesize($path) > self::MAX_VERDICT_BYTES) {
            return null;
        }
        $contents = file_get_contents($path);
        if (
            ! is_string($contents)
            || preg_match('/^(\d{1,12}) ([^\r\n]{1,1000})\r?\n?$/', $contents, $matches) !== 1
        ) {
            return null;
        }

        return ['time' => (int) $matches[1], 'line' => $matches[2]];
    }

    public function lastRejectedImage(ProblemId $problemId): ?string
    {
        if (! $this->directoryExists()) {
            return null;
        }
        $path = $this->directoryPath() . DIRECTORY_SEPARATOR . $problemId->value() . '.actual.png';
        $size = is_file($path) && ! is_link($path) ? filesize($path) : false;
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            return null;
        }
        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }

    private function candidatePath(ProblemId $problemId): ?string
    {
        if (! $this->directoryExists()) {
            return null;
        }
        $path = $this->directoryPath() . DIRECTORY_SEPARATOR . $problemId->value() . '.txt';
        if (is_link($path) || ! is_file($path)) {
            return null;
        }

        return $path;
    }
}

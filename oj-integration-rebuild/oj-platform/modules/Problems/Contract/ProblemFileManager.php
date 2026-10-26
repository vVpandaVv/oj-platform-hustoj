<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDataFile;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

interface ProblemFileManager
{
    /**
     * @return list<ProblemDataFile>
     */
    public function files(ProblemId $problemId): array;

    public function read(ProblemId $problemId, string $fileName): string;

    public function readBinary(ProblemId $problemId, string $fileName): string;

    public function write(
        ProblemId $problemId,
        string $fileName,
        string $contents,
        ?string $expectedSha256
    ): void;

    public function import(
        ProblemId $problemId,
        string $fileName,
        string $temporaryPath
    ): void;

    public function rename(
        ProblemId $problemId,
        string $fileName,
        string $newFileName,
        string $expectedSha256
    ): void;

    public function delete(
        ProblemId $problemId,
        string $fileName,
        string $expectedSha256
    ): void;

    /**
     * @param array<string, string> $expectedHashesByFile
     */
    public function deleteMany(
        ProblemId $problemId,
        array $expectedHashesByFile
    ): void;

    /**
     * @param array<string, string> $contentsByFile
     * @param array<string, string|null> $expectedHashesByFile
     * @return array<string, string>
     */
    public function replaceMany(
        ProblemId $problemId,
        array $contentsByFile,
        array $expectedHashesByFile
    ): array;
}

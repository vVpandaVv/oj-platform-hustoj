<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveInspection;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemArchiveResult;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

interface ProblemArchiveManager
{
    public function inspect(
        ProblemId $problemId,
        string $archiveName,
        ?string $expectedArchiveSha256 = null
    ): ProblemArchiveInspection;

    /**
     * @param array<string, string> $expectedHashesByFile
     */
    public function create(
        ProblemId $problemId,
        string $archiveName,
        array $expectedHashesByFile
    ): ProblemArchiveResult;

    /**
     * @param array<string, string> $overwriteHashesByFile
     */
    public function extract(
        ProblemId $problemId,
        string $archiveName,
        string $expectedArchiveSha256,
        array $overwriteHashesByFile
    ): ProblemArchiveResult;
}

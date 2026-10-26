<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

/**
 * Holds image-capture candidates written by the grader outside the problem's
 * own (read-only for the grader) data folder.
 */
interface VisualCaptureStore
{
    public function directoryPath(): string;

    public function directoryExists(): bool;

    /**
     * Returns the candidate text or null when the grader has not written one.
     */
    public function read(ProblemId $problemId): ?string;

    public function delete(ProblemId $problemId): void;

    /**
     * Latest verdict line the grader recorded for this problem, if any.
     *
     * @return array{time: int, line: string}|null
     */
    public function lastVerdict(ProblemId $problemId): ?array;

    /**
     * PNG bytes of the latest submission the grader rejected, if it kept one.
     */
    public function lastRejectedImage(ProblemId $problemId): ?string;
}

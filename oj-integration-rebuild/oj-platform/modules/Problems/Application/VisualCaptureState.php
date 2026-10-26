<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

/**
 * Snapshot of the reference-image capture workflow for one problem.
 */
final class VisualCaptureState
{
    /**
     * @param array{case: string, width: int, height: int, source: string}|null $candidate
     * @param array{time: int, line: string}|null $lastVerdict
     * @param array{width: int, height: int, source: string}|null $lastRejected
     */
    public function __construct(
        public readonly bool $armed,
        public readonly bool $expired,
        public readonly int $remainingSeconds,
        public readonly ?array $candidate,
        public readonly ?string $candidateProblem,
        public readonly bool $directoryReady,
        public readonly string $directoryPath,
        public readonly ?array $lastVerdict = null,
        public readonly ?array $lastRejected = null
    ) {
    }
}

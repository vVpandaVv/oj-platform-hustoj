<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface RecoverableJobStepJournal
{
    /**
     * @return array<string, mixed>|null
     */
    public function completedCheckpoint(
        ClaimedJobItem $claim,
        string $stepKey,
        string $inputHash
    ): ?array;

    /**
     * @param array<string, mixed> $checkpoint
     * @param array<string, mixed> $validationResult
     */
    public function completeWithCheckpoint(
        ClaimedJobItem $claim,
        string $stepKey,
        array $checkpoint,
        array $validationResult = []
    ): void;
}
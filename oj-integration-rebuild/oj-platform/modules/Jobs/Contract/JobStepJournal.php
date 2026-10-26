<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;

interface JobStepJournal
{
    /**
     * @param array<string, mixed> $checkpoint
     */
    public function start(
        ClaimedJobItem $claim,
        string $stepKey,
        int $stepOrder,
        string $inputHash,
        array $checkpoint = []
    ): void;

    /**
     * @param array<string, mixed> $validationResult
     */
    public function complete(
        ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void;

    /**
     * @param array<string, mixed> $validationResult
     */
    public function fail(
        ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void;

    /**
     * @param array<string, mixed> $compensationResult
     */
    public function compensate(
        ClaimedJobItem $claim,
        string $stepKey,
        bool $succeeded,
        array $compensationResult = []
    ): void;
}

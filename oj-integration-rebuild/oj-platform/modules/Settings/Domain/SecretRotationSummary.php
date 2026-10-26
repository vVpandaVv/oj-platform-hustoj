<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Domain;

final class SecretRotationSummary
{
    /**
     * @param list<string> $failedRecordIds
     */
    public function __construct(
        private readonly int $rotatedCount,
        private readonly int $alreadyCurrentCount,
        private readonly array $failedRecordIds
    ) {
    }

    public function rotatedCount(): int
    {
        return $this->rotatedCount;
    }

    public function alreadyCurrentCount(): int
    {
        return $this->alreadyCurrentCount;
    }

    /**
     * Record IDs that could not be re-encrypted (e.g. an old key was already
     * removed from the ring). Left untouched with their previous key and
     * ciphertext so nothing is lost; needs a follow-up confirmation.
     *
     * @return list<string>
     */
    public function failedRecordIds(): array
    {
        return $this->failedRecordIds;
    }

    public function hasFailures(): bool
    {
        return $this->failedRecordIds !== [];
    }
}

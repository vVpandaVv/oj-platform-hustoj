<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Domain\UserImportCandidate;

final class UserImportPreview
{
    /**
     * @param list<UserImportCandidate> $candidates
     */
    public function __construct(private readonly array $candidates)
    {
    }

    /**
     * @return list<UserImportCandidate>
     */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /**
     * @return list<UserImportCandidate>
     */
    public function validCandidates(): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn (UserImportCandidate $candidate): bool => $candidate->isValid()
        ));
    }

    public function validCount(): int
    {
        return count($this->validCandidates());
    }

    public function invalidCount(): int
    {
        return count($this->candidates) - $this->validCount();
    }
}

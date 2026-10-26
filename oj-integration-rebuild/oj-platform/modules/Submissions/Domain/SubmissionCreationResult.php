<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

final class SubmissionCreationResult
{
    public function __construct(
        private readonly SubmissionId $submissionId,
        private readonly bool $reused,
        private readonly bool $auditWarning = false
    ) {
    }

    public function submissionId(): SubmissionId
    {
        return $this->submissionId;
    }

    public function wasReused(): bool
    {
        return $this->reused;
    }

    public function hasAuditWarning(): bool
    {
        return $this->auditWarning;
    }
}

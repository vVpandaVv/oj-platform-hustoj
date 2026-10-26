<?php
declare(strict_types=1);
namespace OjPrimm\Application;

/** Optional narrow submission lookup; legacy PlatformAccess implementations remain valid. */
interface SubmissionLookupAccess
{
    public function submission(int $problemId, string $contextType, string $contextId, ?int $submissionId = null): array;
}

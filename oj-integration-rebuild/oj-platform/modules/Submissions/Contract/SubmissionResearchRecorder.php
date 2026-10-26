<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;
interface SubmissionResearchRecorder
{
    /** Called before publishing to the judge; failures abort an unpublished submission. */
    public function bindSubmission(int $actor, int $problemId, int $submissionId): void;
}

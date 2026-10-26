<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;

final class SubmissionFeedbackReusePolicy
{
    public function matches(
        ?SubmissionFeedback $existing,
        AiFeedbackSubmission $submission,
        LearningProblem $problem,
        LearnerLevelProfile $profile,
        string $guideKey,
        int $guideRevision,
        AiTaskAssignmentSnapshot $assignment,
        string $inputHash
    ): bool {
        if ($existing === null) {
            return false;
        }
        $allowedProfiles = array_filter([
            $assignment->primaryProfileKey(),
            $assignment->fallbackProfileKey(),
        ]);

        return hash_equals($existing->inputHash(), $inputHash)
            && hash_equals($existing->sourceHash(), $submission->sourceHash())
            && hash_equals($existing->problemHash(), $problem->contentHash())
            && $existing->resultCode() === $submission->resultCode()
            && $existing->guideKey() === $guideKey
            && $existing->guideRevision() === $guideRevision
            && $existing->analysisStatus() === $profile->analysisStatus()
            && $existing->level() === $profile->level()
            && $existing->levelPolicyVersion() === $profile->policyVersion()
            && $existing->profileLastSubmissionId()
                === $profile->lastSubmissionId()
            && $existing->assignmentRevision()
                === $assignment->routingRevision()
            && hash_equals(
                $existing->profileFingerprint(),
                $assignment->profileFingerprint()
            )
            && in_array($existing->profileKey(), $allowedProfiles, true);
    }
}

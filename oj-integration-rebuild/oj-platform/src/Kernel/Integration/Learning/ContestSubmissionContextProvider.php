<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextProfileProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmitSubmissionCommand;

final class ContestSubmissionContextProvider implements
    SubmissionContextProvider,
    SubmissionContextProfileProvider
{
    public function __construct(
        private readonly ContestSubmissionAccess $contests
    ) {
    }

    public function supports(SubmitSubmissionCommand $command): bool
    {
        return $command->contestId() > 0
            && $command->classId() === null
            && $command->groupId() === null;
    }

    public function supportsContext(
        int $contestId,
        ?int $classId,
        ?int $groupId
    ): bool {
        return $contestId > 0 && $classId === null && $groupId === null;
    }

    public function blocklyProfile(
        int $contestId,
        ?int $classId,
        ?int $groupId
    ): ?string {
        unset($classId, $groupId);

        return $this->contests->submissionBlocklyProfile($contestId);
    }
    public function validate(SubmitSubmissionCommand $command): SubmissionContext
    {
        $number = $this->contests->validateSubmission(
            $command->actorUserId(),
            $command->contestId(),
            $command->problemId(),
            $command->contestProblemNumber(),
            $command->ipAddress()
        );

        return new SubmissionContext(
            $command->contestId(),
            $number,
            null,
            null,
            $this->contests->submissionBlocklyProfile($command->contestId())
        );
    }
}

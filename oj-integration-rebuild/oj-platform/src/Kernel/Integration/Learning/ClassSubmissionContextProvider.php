<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextProfileProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmitSubmissionCommand;

final class ClassSubmissionContextProvider implements
    SubmissionContextProvider,
    SubmissionContextProfileProvider
{
    public function __construct(
        private readonly ClassSubmissionAccess $classes
    ) {
    }

    public function supports(SubmitSubmissionCommand $command): bool
    {
        return $command->classId() !== null
            && $command->contestId() === 0
            && $command->groupId() === null;
    }

    public function supportsContext(
        int $contestId,
        ?int $classId,
        ?int $groupId
    ): bool {
        return $contestId === 0 && $classId !== null && $groupId === null;
    }

    public function blocklyProfile(
        int $contestId,
        ?int $classId,
        ?int $groupId
    ): ?string {
        unset($contestId, $groupId);

        return $this->classes->submissionBlocklyProfile($classId ?? 0);
    }
    public function validate(SubmitSubmissionCommand $command): SubmissionContext
    {
        $classId = $command->classId() ?? 0;
        $this->classes->validateSubmission(
            $command->actorUserId(),
            $classId,
            $command->problemId()
        );

        return new SubmissionContext(
            0,
            -1,
            $classId,
            null,
            $this->classes->submissionBlocklyProfile($classId)
        );
    }
}

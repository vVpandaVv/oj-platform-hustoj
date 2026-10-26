<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;

final class ProblemAccessPolicy
{
    public function __construct(
        private readonly Authorization $permissions,
        private readonly ProblemAssignmentRepository $assignments,
        private readonly ProblemAiAvailability $ai,
        private readonly ProblemContextAccessRegistry $contexts
    ) {
    }

    public function canView(
        int $userId,
        ProblemId $problemId,
        ProblemVisibility $visibility,
        ?ProblemAccessContext $context = null
    ): bool {
        if ($userId < 1) {
            return false;
        }
        $context ??= ProblemAccessContext::general();
        if (! $context->isGeneral()) {
            return $this->contexts->canView(
                $userId,
                $problemId->value(),
                $context
            );
        }
        if ($visibility === ProblemVisibility::Published) {
            return true;
        }
        if ($this->permissions->can($userId, 'oj_view_hidden_problems')) {
            return true;
        }

        $assignment = $this->assignments->find($problemId);

        return $assignment->isOwner($userId)
            || $assignment->collaboratorRole($userId) !== null;
    }

    public function canEdit(int $userId, ProblemId $problemId): bool
    {
        if ($this->permissions->can($userId, 'oj_edit_problems')) {
            return true;
        }
        $assignment = $this->assignments->find($problemId);

        return $assignment->isOwner($userId)
            || $assignment->collaboratorRole($userId) === CollaboratorRole::Editor;
    }

    public function canManageTestData(int $userId, ProblemId $problemId): bool
    {
        if ($this->permissions->can($userId, 'oj_manage_testcases')) {
            return true;
        }
        $assignment = $this->assignments->find($problemId);

        return $assignment->isOwner($userId)
            || $assignment->collaboratorRole($userId) === CollaboratorRole::TestcaseManager;
    }

    public function canArchive(int $userId, ProblemId $problemId): bool
    {
        return $this->permissions->can($userId, 'oj_delete_problems')
            || $this->assignments->find($problemId)->isOwner($userId);
    }

    public function canManageAssignments(int $userId, ProblemId $problemId): bool
    {
        return $this->permissions->can($userId, 'oj_manage_problems')
            || $this->assignments->find($problemId)->isOwner($userId);
    }

    public function canCreate(int $userId): bool
    {
        return $this->permissions->can($userId, 'oj_create_problems');
    }

    public function canRunAi(int $userId, ProblemId $problemId): bool
    {
        $canEditWithAi = (
            $this->ai->isAvailable('problem_metadata_generate')
            || $this->ai->isAvailable('problem_authoring_generate')
        ) && $this->canEdit($userId, $problemId);
        $canManageTestDataWithAi = $this->ai->isAvailable(
            'problem_testcase_generate'
        ) && $this->canManageTestData($userId, $problemId);

        return $this->permissions->can($userId, 'oj_manage_problem_ai_assist')
            && ($canEditWithAi || $canManageTestDataWithAi);
    }

    public function canGenerateMetadataAi(
        int $userId,
        ProblemId $problemId
    ): bool {
        return $this->ai->isAvailable('problem_metadata_generate')
            && $this->permissions->can($userId, 'oj_manage_problem_ai_assist')
            && $this->canEdit($userId, $problemId);
    }

    public function canRunAuthoringAi(int $userId, ProblemId $problemId): bool
    {
        return $this->ai->isAvailable('problem_authoring_generate')
            && $this->permissions->can($userId, 'oj_manage_problem_ai_assist')
            && $this->canEdit($userId, $problemId);
    }

    public function canRunNewAuthoringAi(int $userId): bool
    {
        return $this->ai->isAvailable('problem_authoring_generate')
            && $this->permissions->can($userId, 'oj_manage_problem_ai_assist')
            && $this->canCreate($userId);
    }

    public function canRunTestcaseAi(int $userId, ProblemId $problemId): bool
    {
        return $this->ai->isAvailable('problem_testcase_generate')
            && $this->permissions->can($userId, 'oj_manage_problem_ai_assist')
            && $this->canManageTestData($userId, $problemId);
    }

    public function canChangeIds(int $userId): bool
    {
        return $this->permissions->can($userId, 'oj_change_problem_ids');
    }

    public function canManageXml(int $userId): bool
    {
        return $this->permissions->can($userId, 'oj_manage_problem_xml');
    }

    public function canManageTopics(int $userId): bool
    {
        return $this->permissions->isSuperAdmin($userId);
    }
}

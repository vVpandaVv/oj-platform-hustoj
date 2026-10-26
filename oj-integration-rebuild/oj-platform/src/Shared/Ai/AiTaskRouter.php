<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Ai;

interface AiTaskRouter
{
    public function canRoute(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): bool;

    public function snapshot(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): AiTaskAssignmentSnapshot;

    public function execute(
        AiTaskAssignmentSnapshot $assignment,
        AiTaskRequest $request
    ): AiTaskResponse;
}

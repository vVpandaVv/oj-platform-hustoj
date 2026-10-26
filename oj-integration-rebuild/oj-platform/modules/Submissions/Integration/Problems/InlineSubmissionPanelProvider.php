<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Integration\Problems;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemSubmissionPanelProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionContextProviderRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionFormRenderer;
use Throwable;

final class InlineSubmissionPanelProvider implements ProblemSubmissionPanelProvider
{
    public function __construct(
        private readonly UserSubmissionPreferenceProvider $preferences,
        private readonly SubmissionProblemProvider $problems,
        private readonly SubmissionFormRenderer $formRenderer,
        private readonly SubmissionContextProviderRegistry $contexts
    ) {
    }

    public function render(
        int $userId,
        int $problemId,
        ProblemNavigationContext $navigation
    ): ?string {
        if ($userId < 1) {
            return null;
        }
        $preferences = $this->preferences->forUser($userId);
        if (! $preferences->inlineSubmissionPanel()) {
            return null;
        }

        try {
            $policy = $this->problems->codePolicy(
                $userId,
                $problemId,
                $navigation->accessContext()
            );
            $contestId = $navigation->contestId() ?? 0;
            $formContext = [
                'contest_id' => $contestId,
                'contest_problem_number' => $navigation->contestProblemNumber() ?? -1,
                'class_id' => $navigation->classId(),
                'group_id' => null,
            ];
            $forcedBlocklyProfile = $this->contexts->blocklyProfile(
                $contestId,
                $navigation->classId(),
                null
            );

            return $this->formRenderer->render(
                $problemId,
                $policy,
                $forcedBlocklyProfile,
                $preferences,
                $formContext,
                $userId
            );
        } catch (Throwable) {
            return null;
        }
    }
}

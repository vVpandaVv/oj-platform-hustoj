<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;

final class FeatureRoutedProblemAiAvailability implements ProblemAiAvailability
{
    private const TASK_TYPES = [
        'problem_metadata_generate',
        'problem_authoring_generate',
        'problem_testcase_generate',
    ];

    public function __construct(
        private readonly FeatureFlags $features,
        private readonly AiTaskRouter $router
    ) {
    }

    public function isAvailable(string $taskType): bool
    {
        return in_array($taskType, self::TASK_TYPES, true)
            && $this->features->isEnabled('problem_ai_assist')
            && $this->router->canRoute($taskType);
    }
}

<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

interface ProblemSubmissionPanelProvider
{
    /**
     * Renders an inline submission panel (code editor + submit form) for
     * the problem detail page, or null when nothing should be shown (e.g.
     * the viewer has not opted in, or the problem has no usable code
     * policy).
     */
    public function render(
        int $userId,
        int $problemId,
        ProblemNavigationContext $navigation
    ): ?string;
}
